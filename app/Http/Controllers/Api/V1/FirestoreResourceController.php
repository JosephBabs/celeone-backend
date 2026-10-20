<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Firestore-compatible JSONB resource bridge.
 *
 * IMPORTANT: this controller is used by the RN Firestore compatibility layer.
 * It therefore needs to understand Firestore-style transforms (increment,
 * arrayUnion/arrayRemove, serverTimestamp), dotted field paths and nested
 * filtering/sorting. It is not just a blind JSON merge endpoint.
 */
class FirestoreResourceController
{
    private const MAX_ITEMS = 500;

    public function __construct(private \App\Services\ProfilePrivacy $privacy) {}

    public function read(Request $request, string $path): JsonResponse
    {
        return $request->query('__kind') === 'doc'
            ? $this->show($request, $path)
            : $this->index($request, $path);
    }

    public function index(Request $request, string $path): JsonResponse
    {
        $collectionPath = $this->cleanPath($path);
        $query = DB::table('firebase_documents')->where('collection_path', $collectionPath);
        if ($collectionPath === 'documents') $query->where('document_id', '!=', 'revenue_policy');
        if ($collectionPath === 'friend_requests') { $keys = \App\Services\SocialNotification::keys($request->user()); $query->where(fn($q) => $q->whereIn('payload->senderId', $keys)->orWhereIn('payload->receiverId', $keys)); }

        $this->applyRequestFilters($query, $request);

        if ($request->boolean('meta')) {
            return response()->json([
                'count' => (clone $query)->count(),
                'latest_updated_at' => (string) ((clone $query)->max('firebase_updated_at') ?? ''),
            ]);
        }

        $sort = $this->safeField((string) $request->query('sort', ''));
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cursor = $request->query('cursor');

        if ($sort !== '') {
            $sortExpression = $this->jsonTextExpression($sort);
            if ($cursor !== null && $cursor !== '') {
                $operator = $direction === 'asc' ? '>' : '<';
                $query->whereRaw("{$sortExpression} {$operator} ?", [(string) $cursor]);
            }
            $query->orderByRaw("{$sortExpression} {$direction} NULLS LAST");
        } else {
            $query->orderByDesc('firebase_updated_at');
        }
        $query->orderByDesc('id');

        $limit = min(self::MAX_ITEMS, max(1, (int) $request->query('per_page', self::MAX_ITEMS)));
        $page = max(1, (int) $request->query('page', 1));
        $rows = $query->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        if (in_array($collectionPath, ['users', 'user_data'], true)) $this->privacy->prime($rows->pluck('document_id')->all());
        $items = $rows->map(fn ($row) => $this->document($row))->values();

        return response()->json([
            'items' => $items->take($limit)->values(),
            'has_more' => $items->count() > $limit,
            'page' => $page,
        ]);
    }

    public function show(Request $request, string $path): JsonResponse
    {
        [$collectionPath, $documentId] = $this->documentPath($path);
        $row = $this->documentQuery($collectionPath, $documentId)->first();
        if (! $row) return response()->json(['message' => 'Resource not found.'], 404);
        return response()->json($this->document($row));
    }

    public function store(Request $request, string $path): JsonResponse
    {
        [$collectionPath, $documentId] = $this->documentPath($path);
        $input = $request->validate(['data' => ['required', 'array'], 'merge' => ['sometimes', 'boolean']]);
        $existing = $this->documentQuery($collectionPath, $documentId)->first();
        $current = $existing ? $this->payload($existing) : [];
        $payload = ($input['merge'] ?? false)
            ? $this->applyPatch($current, $input['data'])
            : $this->applyPatch([], $input['data']);

        $now = now()->toISOString();
        DB::table('firebase_documents')->updateOrInsert(
            ['collection_path' => $collectionPath, 'document_id' => $documentId],
            [
                'parent_document_path' => $this->parentPath($collectionPath),
                'payload' => json_encode($payload),
                'firebase_updated_at' => $now,
                'updated_at' => $now,
                'created_at' => $existing?->created_at ?? $now,
            ],
        );

        app(\App\Services\SocialCounts::class)->changed($collectionPath);
        \App\Jobs\NotifyPageFollowers::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
        \App\Jobs\NotifyCommentActivity::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
        $row = $this->documentQuery($collectionPath, $documentId)->first();
        return response()->json($this->document($row), $existing ? 200 : 201);
    }

    public function update(Request $request, string $path): JsonResponse
    {
        [$collectionPath, $documentId] = $this->documentPath($path);
        $input = $request->validate(['data' => ['required', 'array']]);
        $existing = $this->documentQuery($collectionPath, $documentId)->first();
        if (! $existing) return response()->json(['message' => 'Resource not found.'], 404);

        $payload = $this->applyPatch($this->payload($existing), $input['data']);
        DB::table('firebase_documents')->where('id', $existing->id)->update([
            'payload' => json_encode($payload),
            'firebase_updated_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);

        \App\Jobs\NotifyPageFollowers::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
        \App\Jobs\NotifyCommentActivity::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
        return response()->json($this->document(DB::table('firebase_documents')->find($existing->id)));
    }

    public function destroy(Request $request, string $path): JsonResponse
    {
        [$collectionPath, $documentId] = $this->documentPath($path);
        $deleted = $this->documentQuery($collectionPath, $documentId)->delete();
        app(\App\Services\SocialCounts::class)->changed($collectionPath);
        return $deleted
            ? response()->json(['deleted' => true])
            : response()->json(['message' => 'Resource not found.'], 404);
    }

    public function count(Request $request, string $path): JsonResponse
    {
        $collectionPath = $this->cleanPath($path);
        $query = DB::table('firebase_documents')->where('collection_path', $collectionPath);
        if ($collectionPath === 'documents') $query->where('document_id', '!=', 'revenue_policy');
        if ($collectionPath === 'friend_requests') { $keys = \App\Services\SocialNotification::keys($request->user()); $query->where(fn($q) => $q->whereIn('payload->senderId', $keys)->orWhereIn('payload->receiverId', $keys)); }
        $this->applyRequestFilters($query, $request);
        return response()->json(['count' => $query->count()]);
    }

    public function batch(Request $request): JsonResponse
    {
        $operations = $request->validate(['operations' => ['required', 'array', 'max:500']])['operations'];
        DB::transaction(function () use ($operations, $request): void {
            foreach ($operations as $operation) {
                $path = (string) ($operation['path'] ?? '');
                $method = strtoupper((string) ($operation['method'] ?? 'PUT'));
                if ($method === 'DELETE') {
                    [$collectionPath, $documentId] = $this->documentPath($path);
                    $this->documentQuery($collectionPath, $documentId)->delete();
                    continue;
                }

                [$collectionPath, $documentId] = $this->documentPath($path);
                $existing = $this->documentQuery($collectionPath, $documentId)->first();
                $current = $existing ? $this->payload($existing) : [];
                $incoming = is_array($operation['data'] ?? null) ? $operation['data'] : [];
                $merge = $method === 'PATCH' || (bool) ($operation['merge'] ?? false);
                $payload = $merge ? $this->applyPatch($current, $incoming) : $this->applyPatch([], $incoming);
                $now = now()->toISOString();
                DB::table('firebase_documents')->updateOrInsert(
                    ['collection_path' => $collectionPath, 'document_id' => $documentId],
                    [
                        'parent_document_path' => $this->parentPath($collectionPath),
                        'payload' => json_encode($payload),
                        'firebase_updated_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $existing?->created_at ?? $now,
                    ],
                );
                \App\Jobs\NotifyPageFollowers::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
        \App\Jobs\NotifyCommentActivity::changed($collectionPath, $documentId, $existing, (int)$request->user()->id);
            }
        });
        foreach (array_unique(array_map(fn($op) => $this->documentPath((string)$op['path'])[0], $operations)) as $collection) app(\App\Services\SocialCounts::class)->changed($collection);
        return response()->json(['committed' => true]);
    }

    public function transaction(Request $request): JsonResponse
    {
        return $this->batch($request);
    }

    private function queryGroups(Request $request, string $name): array
    {
        $values = [];
        foreach (explode('&', (string)$request->server('QUERY_STRING', '')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (in_array(urldecode($key), [$name, $name.'[]'], true)) $values[] = urldecode($value);
        }
        return $values ?: (array)$request->query($name, []);
    }

    private function applyRequestFilters($query, Request $request): void
    {
        foreach ($this->queryGroups($request, 'filter') as $filter) {
            $value = is_string($filter) ? json_decode($filter, true) : $filter;
            if (is_array($value)) $this->applyFilter($query, $value);
        }

        foreach ($this->queryGroups($request, 'or') as $orGroup) {
            $items = is_string($orGroup) ? json_decode($orGroup, true) : $orGroup;
            if (! is_array($items) || ! $items) continue;
            $query->where(function ($nested) use ($items): void {
                foreach ($items as $index => $filter) {
                    if (! is_array($filter)) continue;
                    $callback = function ($branch) use ($filter): void { $this->applyFilter($branch, $filter); };
                    if ($index === 0) $nested->where($callback);
                    else $nested->orWhere($callback);
                }
            });
        }
    }

    private function applyFilter($query, array $filter): void
    {
        if (empty($filter['field'])) return;
        $field = (string) $filter['field'];
        $operator = (string) ($filter['operator'] ?? '==');
        $expected = $filter['value'] ?? null;
        $documentId = $field === '__name__';
        $safeField = $documentId ? '__name__' : $this->safeField($field);
        if (! $documentId && $safeField === '') return;

        $textExpression = $documentId ? 'document_id' : $this->jsonTextExpression($safeField);
        $jsonExpression = $documentId ? null : $this->jsonValueExpression($safeField);
        $text = $this->filterText($expected);

        if ($operator === '==') {
            if ($expected === null && ! $documentId) $query->whereRaw("{$jsonExpression} IS NULL");
            else $query->whereRaw("{$textExpression} = ?", [$text]);
            return;
        }
        if ($operator === '!=') {
            if ($expected === null && ! $documentId) $query->whereRaw("{$jsonExpression} IS NOT NULL");
            else $query->whereRaw("COALESCE({$textExpression}, '') <> ?", [$text]);
            return;
        }
        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            // Firestore comparison fields used by this client are numeric/time
            // counters. Guard the cast so one malformed legacy string cannot
            // make the entire collection query fail.
            $numeric = "CASE WHEN ({$textExpression}) ~ '^-?[0-9]+(?:\\.[0-9]+)?$' THEN ({$textExpression})::numeric ELSE NULL END";
            $query->whereRaw("{$numeric} {$operator} ?", [(float) $expected]);
            return;
        }
        if ($operator === 'array-contains' && $jsonExpression) {
            $query->whereRaw("COALESCE({$jsonExpression}, '[]'::jsonb) @> ?::jsonb", [json_encode([$expected])]);
            return;
        }
        if ($operator === 'array-contains-any' && $jsonExpression) {
            $values = array_values((array) $expected);
            if (! $values) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->where(function ($nested) use ($jsonExpression, $values): void {
                foreach ($values as $value) {
                    $nested->orWhereRaw("COALESCE({$jsonExpression}, '[]'::jsonb) @> ?::jsonb", [json_encode([$value])]);
                }
            });
            return;
        }
        if (in_array($operator, ['in', 'not-in'], true)) {
            $values = array_values((array) $expected);
            if (! $values) {
                $query->whereRaw($operator === 'in' ? '1 = 0' : '1 = 1');
                return;
            }
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $bindings = array_map(fn ($value) => $this->filterText($value), $values);
            $query->whereRaw("{$textExpression} " . ($operator === 'not-in' ? 'NOT IN' : 'IN') . " ({$placeholders})", $bindings);
            return;
        }
    }

    private function filterText(mixed $value): string
    {
        if ($value === true) return 'true';
        if ($value === false) return 'false';
        if ($value === null) return '';
        if (is_scalar($value)) return (string) $value;
        return json_encode($value) ?: '';
    }

    private function jsonValueExpression(string $field): string
    {
        $parts = array_values(array_filter(explode('.', $field), fn ($part) => $part !== ''));
        if (count($parts) <= 1) return "payload->'" . str_replace("'", "''", $parts[0] ?? 'id') . "'";
        $path = implode(',', array_map(fn ($part) => str_replace(["'", ','], '', $part), $parts));
        return "payload#>'{".$path."}'";
    }

    private function documentQuery(string $collectionPath, string $documentId)
    {
        return DB::table('firebase_documents')->where([
            'collection_path' => $collectionPath,
            'document_id' => $documentId,
        ]);
    }

    private function applyPatch(array $current, array $patch): array
    {
        foreach ($patch as $field => $value) {
            $path = array_values(array_filter(explode('.', (string) $field), fn ($part) => $part !== ''));
            if (! $path) continue;
            $existing = $this->getPath($current, $path);
            $resolved = $this->resolveTransform($existing, $value);
            if ($resolved === self::deleteSentinel()) $this->unsetPath($current, $path);
            else $this->setPath($current, $path, $resolved);
        }
        return $current;
    }

    private function resolveTransform(mixed $existing, mixed $value): mixed
    {
        if (! is_array($value) || ! isset($value['__op'])) return $value;
        return match ((string) $value['__op']) {
            'serverTimestamp' => now()->toISOString(),
            'increment' => (is_numeric($existing) ? (float) $existing : 0) + (float) ($value['value'] ?? 0),
            'arrayUnion' => array_values(array_reduce((array) ($value['values'] ?? []), function (array $carry, mixed $item): array {
                foreach ($carry as $present) if ($present === $item) return $carry;
                $carry[] = $item;
                return $carry;
            }, array_values(is_array($existing) ? $existing : []))),
            'arrayRemove' => array_values(array_filter(
                is_array($existing) ? $existing : [],
                fn ($item) => ! in_array($item, (array) ($value['values'] ?? []), true),
            )),
            'deleteField' => self::deleteSentinel(),
            default => $value,
        };
    }

    private static function deleteSentinel(): object
    {
        static $sentinel;
        return $sentinel ??= new \stdClass();
    }

    private function getPath(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $part) {
            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) return null;
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    private function setPath(array &$data, array $path, mixed $value): void
    {
        $cursor =& $data;
        $last = array_pop($path);
        foreach ($path as $part) {
            if (! isset($cursor[$part]) || ! is_array($cursor[$part])) $cursor[$part] = [];
            $cursor =& $cursor[$part];
        }
        $cursor[$last] = $value;
    }

    private function unsetPath(array &$data, array $path): void
    {
        $cursor =& $data;
        $last = array_pop($path);
        foreach ($path as $part) {
            if (! isset($cursor[$part]) || ! is_array($cursor[$part])) return;
            $cursor =& $cursor[$part];
        }
        unset($cursor[$last]);
    }

    private function cleanPath(string $path): string
    {
        $path = trim(rawurldecode($path), '/');
        if (preg_match('~^(users|user_data)/([^/]+)$~', $path, $profile) && !request()->isMethod('GET')) {
            abort_unless(request()->user()?->isAdmin() || in_array($profile[2], \App\Services\SocialNotification::keys(request()->user()), true), 403);
        }

        if (in_array(explode('/', $path)[0], ['subscription_packages', 'payment_config', 'app_config'], true) && !request()->isMethod('GET')) abort_unless(request()->user()?->isAdmin(), 403);
        if (in_array(explode('/', $path)[0], ['friend_requests', 'friendships', 'user_subscriptions', 'media_consumption_events', 'creator_metrics'], true) && !request()->isMethod('GET')) abort(410, 'Use the canonical friends API.');
        abort_if($path === 'documents/revenue_policy', 410, 'Revenue policy moved to app-config/revenue_policy.');
        if (explode('/', $path)[0] === 'jeunesse_quiz_attempts') abort_unless(request()->user()?->isAdmin(), 403);
        if (preg_match('~^users/([^/]+)/(push_devices|post_alerts)(/|$)~', $path, $m)) {
            abort_unless(in_array($m[1], \App\Services\SocialNotification::keys(request()->user()), true), 403);
        }

        abort_unless($path !== '' && ! Str::contains($path, ['..', '\\']), 422, 'Invalid resource path.');
        return $path;
    }

    private function documentPath(string $path): array
    {
        $path = $this->cleanPath($path);
        $parts = explode('/', $path);
        abort_unless(count($parts) >= 2, 422, 'A document path is required.');
        $id = array_pop($parts);
        return [implode('/', $parts), $id];
    }

    private function parentPath(string $collectionPath): ?string
    {
        $parts = explode('/', $collectionPath);
        array_pop($parts);
        return $parts ? implode('/', $parts) : null;
    }

    private function payload(object $row): array
    {
        return is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
    }

    private function document(object $row): array
    {
        return ['id' => (string) $row->document_id, 'data' => in_array($row->collection_path, ['users', 'user_data'], true)
            ? $this->privacy->redact($this->payload($row), (string) $row->document_id, request()->user())
            : $this->payload($row)];
    }

    private function safeField(string $field): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $field), '.');
    }

    private function jsonTextExpression(string $field): string
    {
        $parts = array_values(array_filter(explode('.', $field), fn ($part) => $part !== ''));
        if (count($parts) <= 1) return "payload->>'" . str_replace("'", "''", $parts[0] ?? 'id') . "'";
        $path = implode(',', array_map(fn ($part) => str_replace(["'", ','], '', $part), $parts));
        return "payload#>>'{".$path."}'";
    }
}
