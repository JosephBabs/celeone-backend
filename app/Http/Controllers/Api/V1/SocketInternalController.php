<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocketInternalController extends Controller
{
    use ApiResponses;

    private function authorized(Request $r): void
    {
        abort_unless(hash_equals((string) config('celeone_domains.socket.internal_secret'), (string) $r->header('X-Socket-Internal-Secret')), 403);
    }

    public function authorizeChannel(Request $r)
    {
        $this->authorized($r);
        $d = $r->validate(['user_id' => 'required|integer', 'channel' => 'required|string|max:255']);
        $allowed = $d['channel'] === 'private-user.' . $d['user_id'];
        if (! $allowed && str_starts_with($d['channel'], 'private-conversation.')) {
            $id = substr($d['channel'], strlen('private-conversation.'));
            $room = DB::table('chat_rooms')->where(fn ($q) => $q->where('firebase_id', $id)->orWhere('id', ctype_digit($id) ? (int) $id : -1))->first();
            $allowed = $room && DB::table('chat_room_members')->where(['chat_room_id' => $room->id, 'user_id' => $d['user_id']])->exists();
        }
        if (! $allowed && str_starts_with($d['channel'], 'private-call.')) $allowed = true;
        if (! $allowed && (str_starts_with($d['channel'], 'public-post.') || str_starts_with($d['channel'], 'public-live.'))) $allowed = true;
        return $this->success(['authorized' => (bool) $allowed]);
    }

    public function message(Request $r, Outbox $outbox)
    {
        $this->authorized($r);
        $d = $r->validate([
            'user_id' => 'required|integer', 'conversation_id' => 'required|string|max:255',
            'client_id' => 'required|string|max:150', 'type' => 'nullable|string|max:30',
            'text' => 'nullable|string|max:30000', 'metadata' => 'nullable|array',
        ]);
        $room = DB::table('chat_rooms')->where(fn ($q) => $q->where('firebase_id', $d['conversation_id'])->orWhere('id', ctype_digit($d['conversation_id']) ? (int) $d['conversation_id'] : -1))->first();
        abort_unless($room && DB::table('chat_room_members')->where(['chat_room_id' => $room->id, 'user_id' => $d['user_id']])->exists(), 404);
        $publicConversationId = (string) ($room->firebase_id ?: $room->id);
        $recipientIds = DB::table('chat_room_members')->where('chat_room_id', $room->id)->where('user_id', '!=', $d['user_id'])->pluck('user_id')->map(fn ($id) => (string) $id)->values()->all();
        $message = DB::transaction(function () use ($d, $room, $outbox, $publicConversationId, $recipientIds) {
            $existing = DB::table('chat_messages')->where(['chat_room_id' => $room->id, 'client_id' => $d['client_id']])->first();
            if ($existing) return $existing;
            $id = DB::table('chat_messages')->insertGetId([
                'firebase_id' => (string) Str::ulid(), 'client_id' => $d['client_id'], 'chat_room_id' => $room->id,
                'user_id' => $d['user_id'], 'body' => $d['text'] ?? null, 'message_type' => $d['type'] ?? 'text',
                'metadata' => json_encode($d['metadata'] ?? []), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('chat_rooms')->where('id', $room->id)->update(['updated_at' => now()]);
            DB::table('chat_room_members')->where('chat_room_id', $room->id)->where('user_id', '!=', $d['user_id'])->increment('unread_count');
            $message = DB::table('chat_messages')->find($id);
            $outbox->record('message.created', 'conversation', $publicConversationId, ['conversation_id' => $publicConversationId, 'recipient_ids' => $recipientIds, 'message' => $message]);
            return $message;
        });
        return $this->success(['message' => $message, 'conversation_id' => $publicConversationId, 'recipient_ids' => $recipientIds]);
    }

    public function sync(Request $r)
    {
        $this->authorized($r);
        $d = $r->validate(['after' => 'nullable|string', 'limit' => 'nullable|integer|min:1|max:500']);
        $q = DB::table('outbox_events')->whereNotNull('published_at')->orderBy('created_at');
        if (! empty($d['after'])) $q->where('id', '>', $d['after']);
        return $this->success($q->limit($d['limit'] ?? 100)->get());
    }
}
