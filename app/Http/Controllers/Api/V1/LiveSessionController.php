<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveSessionController extends DomainResourceController
{
    protected string $table='social_live_sessions';

    public function store(Request $request)
    {
        $outbox = app(Outbox::class);
        $data = $request->validate([
            'public_id' => ['required', 'string', 'max:191'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:40'],
            'data' => ['nullable', 'array'],
        ]);
        $id = (string) $data['public_id'];
        $credential = DB::table('social_live_credentials')->where('session_id', $id)->where('expires_at', '>', now())->first();
        abort_unless($credential && (int)$credential->user_id === (int)$request->user()->id, 403, 'Live session must be provisioned by its host.');
        $now = now();
        $extra = (array) ($data['data'] ?? []);
        $provisioned = $this->payload(DB::table('firebase_documents')->where(['collection_path'=>'social_live_sessions','document_id'=>$id])->first());
        foreach (['hostId','hostType','hostName','hostAvatar','playbackUrl'] as $key) $extra[$key] = $provisioned[$key] ?? ''; 
        $status = (string) ($data['status'] ?? 'live');

        DB::transaction(function () use ($request, $outbox, $data, $id, $now, $extra, $status): void {
            DB::table($this->table)->updateOrInsert(
                ['public_id' => $id],
                [
                    'user_id' => $request->user()->id,
                    'status' => $status,
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    'data' => json_encode($extra),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            // The mobile discovery rail and live interaction subcollections use
            // the Firestore-compat resource namespace. Keep a single mirror in
            // PostgreSQL so starting a SQL/domain live session is immediately
            // discoverable by the social UI as well.
            $this->syncMirror($request, $id, $data['title'] ?? null, $data['body'] ?? null, $status, $extra, $now);

            $outbox->record('live.started', 'live', $id, [
                'session_id' => $id,
                'title' => $data['title'] ?? null,
                'data' => $extra,
            ]);
        });

        return $this->show($id);
    }

    public function update(Request $request, string $id)
    {
        abort_unless(DB::table($this->table)->where('public_id',$id)->where('user_id',$request->user()->id)->exists(),403);
        return parent::update($request,$id);
    }

    public function destroy(string $id)
    {
        $owner = DB::table($this->table)->where('public_id', $id)->value('user_id');
        abort_unless((int)$owner === (int)request()->user()->id, 403);
        $outbox = app(Outbox::class);
        $now = now();
        $count = DB::table($this->table)
            ->where('public_id', $id)
            ->whereNull('deleted_at')
            ->update(['status' => 'ended', 'deleted_at' => $now, 'updated_at' => $now]);
        abort_unless($count, 404);

        $row = DB::table('firebase_documents')->where([
            'collection_path' => 'social_live_sessions',
            'document_id' => $id,
        ])->first();
        if ($row) {
            $payload = $this->payload($row);
            $payload['status'] = 'ended';
            $payload['endedAt'] = $now->toISOString();
            $payload['endedAtMs'] = $now->getTimestampMs();
            $payload['updatedAt'] = $now->toISOString();
            $payload['updatedAtMs'] = $now->getTimestampMs();
            DB::table('firebase_documents')->where('id', $row->id)->update([
                'payload' => json_encode($payload),
                'firebase_updated_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $outbox->record('live.ended', 'live', $id, ['session_id' => $id]);
        return $this->success(['ended' => true]);
    }

    private function syncMirror(Request $request, string $id, ?string $title, ?string $body, string $status, array $data, $now): void
    {
        $uid = (string) ($request->user()->firebase_uid ?: $request->user()->legacy_firebase_uid ?: $request->user()->id);
        $hostType = (string) ($data['hostType'] ?? 'user');
        $hostId = (string) ($data['hostId'] ?? $uid);
        $hostName = (string) ($data['hostName'] ?? $request->user()->name ?? 'Member');
        $hostAvatar = (string) ($data['hostAvatar'] ?? $request->user()->avatar_url ?? '');
        $existing = DB::table('firebase_documents')->where([
            'collection_path' => 'social_live_sessions',
            'document_id' => $id,
        ])->first();
        $old = $existing ? $this->payload($existing) : [];
        $payload = array_merge($old, [
            'id' => $id,
            'sessionId' => $id,
            'hostId' => $hostId,
            'hostType' => $hostType,
            'hostOwnerId' => $hostType === 'page' ? $uid : null,
            'hostName' => $hostName,
            'hostAvatar' => $hostAvatar,
            'title' => (string) ($title ?: $hostName),
            'description' => (string) ($body ?? ''),
            'playbackUrl' => (string) ($data['playbackUrl'] ?? $old['playbackUrl'] ?? ''),
            'status' => $status,
            'likes' => (int) ($old['likes'] ?? 0),
            'viewerCount' => (int) ($old['viewerCount'] ?? 0),
            'commentCount' => (int) ($old['commentCount'] ?? 0),
            'hostPaused' => (bool) ($old['hostPaused'] ?? false),
            'startedAtMs' => (int) ($old['startedAtMs'] ?? $now->getTimestampMs()),
            'createdAt' => $old['createdAt'] ?? $now->toISOString(),
            'createdAtMs' => $old['createdAtMs'] ?? $now->getTimestampMs(),
            'updatedAt' => $now->toISOString(),
            'updatedAtMs' => $now->getTimestampMs(),
        ]);
        DB::table('firebase_documents')->updateOrInsert(
            ['collection_path' => 'social_live_sessions', 'document_id' => $id],
            [
                'parent_document_path' => null,
                'payload' => json_encode($payload),
                'firebase_updated_at' => $now,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ],
        );
    }

    private function payload(object $row): array
    {
        return is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
    }
}
