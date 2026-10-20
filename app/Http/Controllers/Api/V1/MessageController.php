<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    use ApiResponses;

    private function room(Request $request, string $id): object
    {
        $room = DB::table('chat_rooms')->where(fn ($q) => $q->where('firebase_id', $id)->orWhere('id', ctype_digit($id) ? (int) $id : -1))->first();
        abort_unless($room && DB::table('chat_room_members')->where(['chat_room_id' => $room->id, 'user_id' => $request->user()->id])->exists(), 404);
        return $room;
    }

    public function index(Request $request, string $conversation)
    {
        $room = $this->room($request, $conversation);
        $limit = min(100, max(1, $request->integer('limit', 40)));
        $query = DB::table('chat_messages')->where('chat_room_id', $room->id)->orderByDesc('id');
        if ($request->filled('cursor')) $query->where('id', '<', (int) base64_decode((string) $request->input('cursor')));
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        return $this->page($items, $more ? base64_encode((string) $items->last()->id) : null, $more, $limit);
    }

    public function store(Request $request, string $conversation, Outbox $outbox)
    {
        $room = $this->room($request, $conversation);
        $data = $request->validate([
            // Mobile/XMPP client ids are intentionally opaque idempotency keys,
            // not guaranteed RFC UUIDs.
            'client_id' => 'required|string|max:150',
            'type' => 'nullable|string|max:30',
            'text' => 'nullable|string|max:30000',
            'reply_to_id' => 'nullable',
            'metadata' => 'nullable|array',
        ]);
        $publicConversationId = (string) ($room->firebase_id ?: $room->id);
        $recipientIds = DB::table('chat_room_members')
            ->where('chat_room_id', $room->id)
            ->where('user_id', '!=', $request->user()->id)
            ->pluck('user_id')->map(fn ($id) => (string) $id)->values()->all();

        $message = DB::transaction(function () use ($request, $room, $data, $outbox, $publicConversationId, $recipientIds) {
            $existing = DB::table('chat_messages')->where(['chat_room_id' => $room->id, 'client_id' => $data['client_id']])->first();
            if ($existing) return $existing;

            $replyTo = null;
            if (! empty($data['reply_to_id'])) {
                $rawReply = (string) $data['reply_to_id'];
                $reply = DB::table('chat_messages')->where('chat_room_id', $room->id)->where(function ($q) use ($rawReply): void {
                    if (ctype_digit($rawReply)) $q->orWhere('id', (int) $rawReply);
                    $q->orWhere('firebase_id', $rawReply)->orWhere('client_id', $rawReply);
                })->first();
                $replyTo = $reply?->id;
            }

            $id = DB::table('chat_messages')->insertGetId([
                'firebase_id' => (string) Str::ulid(),
                'client_id' => $data['client_id'],
                'chat_room_id' => $room->id,
                'user_id' => $request->user()->id,
                'body' => $data['text'] ?? null,
                'message_type' => $data['type'] ?? 'text',
                'reply_to_id' => $replyTo,
                'metadata' => json_encode($data['metadata'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('chat_rooms')->where('id', $room->id)->update(['updated_at' => now()]);
            DB::table('chat_room_members')->where('chat_room_id', $room->id)->where('user_id', '!=', $request->user()->id)->increment('unread_count');
            $saved = DB::table('chat_messages')->find($id);
            $outbox->record('message.created', 'conversation', $publicConversationId, [
                'conversation_id' => $publicConversationId,
                'recipient_ids' => $recipientIds,
                'message' => $saved,
            ]);
            return $saved;
        });
        return $this->success([
            'message' => $message,
            'conversation_id' => $publicConversationId,
            'recipient_ids' => $recipientIds,
        ], 201);
    }

    public function read(Request $request, string $conversation, Outbox $outbox)
    {
        $room = $this->room($request, $conversation);
        $publicConversationId = (string) ($room->firebase_id ?: $room->id);
        DB::table('chat_room_members')->where(['chat_room_id' => $room->id, 'user_id' => $request->user()->id])->update(['unread_count' => 0, 'last_read_at' => now(), 'updated_at' => now()]);
        $outbox->record('message.read', 'conversation', $publicConversationId, [
            'conversation_id' => $publicConversationId,
            'user_id' => $request->user()->id,
            'read_at' => now()->toISOString(),
        ]);
        return $this->success(['read' => true]);
    }
}
