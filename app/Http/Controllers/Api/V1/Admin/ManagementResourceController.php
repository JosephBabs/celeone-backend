<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ManagementResourceController extends Controller
{
    public function index(Request $request)
    {
        $path = $this->path($request);
        $limit = min(1000, max(1, (int) $request->query('limit', 250)));
        $filters = (array) $request->query('filter', []);

        if ($request->boolean('collection_group')) {
            if (Schema::hasTable($this->nativeTable($path))) {
                $table = $this->nativeTable($path);
                $rows = DB::table($table)->limit($limit)->get()->map(function ($row) use ($table) {
                    $presented = $this->presentNative($table, (array) $row);
                    $presented['_parentId'] = (string) ($presented['userId'] ?? $presented['ownerId'] ?? '');
                    $presented['_collectionPath'] = $table;
                    return $presented;
                })->values();
                return response()->json(['data' => $rows]);
            }

            $rows = DB::table('managed_documents')
                ->where(function ($q) use ($path) {
                    $q->where('collection_path', $path)
                      ->orWhere('collection_path', 'like', '%/' . $path);
                })
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->map(function ($row) {
                    $segments = explode('/', trim((string) $row->collection_path, '/'));
                    $parentId = count($segments) >= 2 ? $segments[count($segments) - 2] : '';
                    return [
                        'id' => $row->document_id,
                        '_parentId' => $parentId,
                        '_collectionPath' => $row->collection_path,
                        ...$this->decode($row->payload),
                    ];
                })->values();

            return response()->json(['data' => $rows]);
        }

        if ($this->isNativeRoot($path)) {
            $table = $this->nativeTable($path);
            $query = DB::table($table);

            foreach ($filters as $key => $value) {
                $column = $this->columnFor($table, (string) $key);
                if ($column) $query->where($column, $value);
            }

            $updatedColumn = $this->firstColumn($table, ['updated_at','updatedAtMs','created_at','createdAtMs']);
            if ($updatedColumn) $query->orderByDesc($updatedColumn);

            return response()->json(['data' => $query->limit($limit)->get()->map(
                fn ($row) => $this->presentNative($table, (array) $row)
            )->values()]);
        }

        $query = DB::table('managed_documents')->where('collection_path', $path);
        $rows = $query->orderByDesc('updated_at')->limit($limit)->get()
            ->map(fn ($row) => $this->presentManaged($row))
            ->filter(function (array $row) use ($filters) {
                foreach ($filters as $key => $value) {
                    if ((string) data_get($row, $key) !== (string) $value) return false;
                }
                return true;
            })->values();

        return response()->json(['data' => $rows]);
    }

    public function count(Request $request)
    {
        $path = $this->path($request);
        $filters = (array) $request->query('filter', []);

        if ($this->isNativeRoot($path)) {
            $table = $this->nativeTable($path);
            $query = DB::table($table);
            foreach ($filters as $key => $value) {
                $column = $this->columnFor($table, (string) $key);
                if ($column) $query->where($column, $value);
            }
            return response()->json(['data' => ['count' => $query->count()]]);
        }

        $rows = DB::table('managed_documents')->where('collection_path', $path)->get();
        $count = $rows->filter(function ($row) use ($filters) {
            $payload = $this->decode($row->payload);
            foreach ($filters as $key => $value) {
                if ((string) data_get($payload, $key) !== (string) $value) return false;
            }
            return true;
        })->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function show(Request $request, string $id)
    {
        $path = $this->path($request);

        if ($this->isNativeRoot($path)) {
            $table = $this->nativeTable($path);
            $row = $this->findNative($table, $id);
            if ($row) {
                return response()->json(['data' => $this->presentNative($table, (array) $row)]);
            }
        }

        $row = DB::table('managed_documents')
            ->where(['collection_path' => $path, 'document_id' => $id])->first();
        abort_unless($row, 404, 'Resource not found.');
        return response()->json(['data' => $this->presentManaged($row)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'path' => ['required','string','max:512'],
            'document_id' => ['nullable','string','max:191'],
            'payload' => ['required','array'],
        ]);
        $path = $this->assertAllowed($data['path']);
        $requestedDocumentId = trim((string) ($data['document_id'] ?? ''));
        $id = $requestedDocumentId !== '' ? $requestedDocumentId : (string) Str::uuid();
        $payload = $this->resolveOperations($data['payload'], []);

        if (
            $this->isNativeRoot($path)
            && ($requestedDocumentId === '' || $this->canAddressNative($this->nativeTable($path), $id))
        ) {
            $table = $this->nativeTable($path);
            $columns = Schema::getColumnListing($table);
            $native = $this->nativePayload($table, $payload);
            $pk = $this->primaryKey($table);

            if ($requestedDocumentId !== '' && ! ctype_digit($id)) {
                foreach (['firebase_id','public_id','uid','slug'] as $externalIdColumn) {
                    if (in_array($externalIdColumn, $columns, true)) {
                        $native[$externalIdColumn] ??= $id;
                        break;
                    }
                }
            }

            if ($pk && in_array($pk, $columns, true) && !ctype_digit($id) && $pk === 'id') {
                // Let bigint identity columns generate themselves.
            } elseif ($pk && in_array($pk, $columns, true)) {
                $native[$pk] = $id;
            }

            if (in_array('created_at', $columns, true)) $native['created_at'] ??= now();
            if (in_array('updated_at', $columns, true)) $native['updated_at'] = now();

            $insertedId = DB::table($table)->insertGetId($native, $pk ?: 'id');
            $resolvedId = (string) ($insertedId ?: ($native[$pk] ?? $id));
            $this->audit($request, 'create', $path, $resolvedId, $payload);
            $row = $this->findNative($table, $resolvedId);
            return response()->json(['data' => $this->presentNative($table, (array) $row)], 201);
        }

        DB::table('managed_documents')->insert([
            'collection_path' => $path,
            'document_id' => $id,
            'payload' => json_encode($payload),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'create', $path, $id, $payload);
        return response()->json(['data' => ['id' => $id, ...$payload]], 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'path' => ['required','string','max:512'],
            'payload' => ['required','array'],
            'merge' => ['nullable','boolean'],
        ]);
        $path = $this->assertAllowed($data['path']);
        $merge = $data['merge'] ?? true;

        if ($this->isNativeRoot($path)) {
            $table = $this->nativeTable($path);
            $existing = $this->findNative($table, $id);
            if ($existing) {
                $current = $this->presentNative($table, (array) $existing);
                $payload = $this->resolveOperations($data['payload'], $current);
                $native = $this->nativePayload($table, $payload);
                if (Schema::hasColumn($table, 'updated_at')) $native['updated_at'] = now();

                $this->nativeWhere($table, $id)->update($native);
                $this->audit($request, 'update', $path, $id, $payload);
                return response()->json(['data' => $this->presentNative($table, (array) $this->findNative($table, $id))]);
            }
        }

        $row = DB::table('managed_documents')
            ->where(['collection_path' => $path, 'document_id' => $id])->first();

        if (! $row) {
            DB::table('managed_documents')->insert([
                'collection_path' => $path,
                'document_id' => $id,
                'payload' => json_encode($this->resolveOperations($data['payload'], [])),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $current = $this->decode($row->payload);
            $patch = $this->resolveOperations($data['payload'], $current);
            $next = $merge ? array_replace_recursive($current, $patch) : $patch;
            DB::table('managed_documents')->where('id', $row->id)->update([
                'payload' => json_encode($next),
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
        }

        $this->audit($request, 'update', $path, $id, $data['payload']);
        return $this->show($request->merge(['path' => $path]), $id);
    }

    public function destroy(Request $request, string $id)
    {
        $path = $this->path($request);

        $deleted = 0;
        if ($this->isNativeRoot($path)) {
            $table = $this->nativeTable($path);
            if ($this->findNative($table, $id)) {
                $deleted = $this->nativeWhere($table, $id)->delete();
            }
        }
        if (! $deleted) {
            $deleted = DB::table('managed_documents')
                ->where(['collection_path' => $path, 'document_id' => $id])->delete();
        }

        abort_unless($deleted, 404, 'Resource not found.');
        $this->audit($request, 'delete', $path, $id, []);
        return response()->json(['data' => ['deleted' => true, 'id' => $id]]);
    }

    private function path(Request $request): string
    {
        return $this->assertAllowed((string) $request->query('path', $request->input('path', '')));
    }

    private function assertAllowed(string $path): string
    {
        $path = trim($path, '/');
        abort_if($path === '' || str_contains($path, '..'), 422, 'Invalid resource path.');
        $root = explode('/', $path)[0];
        abort_unless(in_array($root, config('management.collections', []), true), 403, 'Collection is not managed.');
        return $path;
    }

    private function isNativeRoot(string $path): bool
    {
        return ! str_contains($path, '/') && Schema::hasTable($this->nativeTable($path));
    }

    private function nativeTable(string $path): string
    {
        $explicit = [
            'user_data' => 'user_data',
            'weeklyThemes' => 'weekly_themes',
            'weeklyServices' => 'weekly_services',
            'churchEvents' => 'church_events',
            'churchCelebrations' => 'church_celebrations',
            'subscription_packages' => 'subscription_packages',
            'user_subscriptions' => 'user_subscriptions',
            'friend_requests' => 'friend_requests',
            'blocked_users' => 'blocked_users',
            'notification_inbox' => 'notification_inbox',
            'user_devices' => 'user_devices',
            'payment_transactions' => 'payment_transactions',
            'payment_refunds' => 'payment_refunds',
        ];
        return $explicit[$path] ?? Str::snake($path);
    }

    private function canAddressNative(string $table, string $id): bool
    {
        if (ctype_digit($id) && Schema::hasColumn($table, 'id')) return true;
        foreach (['public_id','firebase_id','uid','slug'] as $column) {
            if (Schema::hasColumn($table, $column)) return true;
        }
        return false;
    }

    private function primaryKey(string $table): ?string
    {
        $columns = Schema::getColumnListing($table);
        foreach (['id','public_id','firebase_id','uid','slug'] as $candidate) {
            if (in_array($candidate, $columns, true)) return $candidate;
        }
        return null;
    }

    private function findNative(string $table, string $id): ?object
    {
        return $this->nativeWhere($table, $id)->first();
    }

    private function nativeWhere(string $table, string $id)
    {
        $columns = Schema::getColumnListing($table);
        $query = DB::table($table);
        $used = false;
        $query->where(function ($q) use ($columns, $id, &$used) {
            foreach (['id','public_id','firebase_id','uid','slug'] as $column) {
                if (! in_array($column, $columns, true)) continue;
                if ($column === 'id' && ! ctype_digit($id)) continue;
                $used ? $q->orWhere($column, $id) : $q->where($column, $id);
                $used = true;
            }
        });
        if (! $used) $query->whereRaw('1 = 0');
        return $query;
    }

    private function nativePayload(string $table, array $payload): array
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $result = [];
        $overflow = [];

        foreach ($payload as $key => $value) {
            if ($key === 'id') continue;
            $snake = Str::snake((string) $key);
            $column = isset($columns[$key]) ? $key : (isset($columns[$snake]) ? $snake : null);
            if ($column) {
                $result[$column] = is_array($value) || is_object($value) ? json_encode($value) : $value;
            } else {
                $overflow[$key] = $value;
            }
        }

        foreach (['payload','metadata','data'] as $jsonColumn) {
            if ($overflow && isset($columns[$jsonColumn])) {
                $current = [];
                $result[$jsonColumn] = json_encode(array_replace_recursive($current, $overflow));
                break;
            }
        }

        return $result;
    }

    private function presentNative(string $table, array $row): array
    {
        $presented = [];
        foreach ($row as $key => $value) {
            $camel = Str::camel((string) $key);
            if (in_array($key, ['payload','metadata','data'], true) && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $presented = array_replace_recursive($presented, $decoded);
                    continue;
                }
            }
            $decoded = is_string($value) && str_starts_with(trim($value), '{') ? json_decode($value, true) : null;
            $presented[$camel] = is_array($decoded) ? $decoded : $value;
        }
        $pk = $this->primaryKey($table);
        if (! isset($presented['id']) && $pk && isset($row[$pk])) $presented['id'] = (string) $row[$pk];
        return $presented;
    }

    private function presentManaged(object $row): array
    {
        return ['id' => $row->document_id, ...$this->decode($row->payload)];
    }

    private function decode($payload): array
    {
        if (is_array($payload)) return $payload;
        return json_decode((string) $payload, true) ?: [];
    }

    private function firstColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) if (Schema::hasColumn($table, $column)) return $column;
        return null;
    }

    private function columnFor(string $table, string $field): ?string
    {
        if (Schema::hasColumn($table, $field)) return $field;
        $snake = Str::snake($field);
        return Schema::hasColumn($table, $snake) ? $snake : null;
    }

    private function resolveOperations(array $payload, array $current): array
    {
        foreach ($payload as $key => $value) {
            if (! is_array($value) || ! isset($value['__op'])) continue;
            $existing = data_get($current, $key);
            if ($value['__op'] === 'increment') {
                $payload[$key] = (float) ($existing ?? 0) + (float) ($value['by'] ?? 1);
            } elseif ($value['__op'] === 'arrayUnion') {
                $payload[$key] = array_values(array_unique(array_merge((array) $existing, (array) ($value['values'] ?? [])), SORT_REGULAR));
            } elseif ($value['__op'] === 'arrayRemove') {
                $remove = (array) ($value['values'] ?? []);
                $payload[$key] = array_values(array_filter((array) $existing, fn ($item) => ! in_array($item, $remove, true)));
            }
        }
        return $payload;
    }

    private function audit(Request $request, string $action, string $path, string $id, array $changes): void
    {
        if (! Schema::hasTable('management_audit_logs')) return;
        DB::table('management_audit_logs')->insert([
            'admin_user_id' => $request->user()?->id,
            'action' => $action,
            'collection_path' => $path,
            'document_id' => $id,
            'changes' => json_encode($changes),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 4000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
