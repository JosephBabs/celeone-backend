<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        $limit = min(100, max(1, $request->integer('limit', 30)));
        $rows = DB::table('chat_rooms as c')
            ->join('chat_room_members as m', 'm.chat_room_id', '=', 'c.id')
            ->where('m.user_id', $request->user()->id)
            ->orderByDesc('c.updated_at')
            ->limit($limit)
            ->select('c.*', 'm.role', 'm.unread_count', 'm.last_read_at')
            ->get();
        return $this->page($rows, null, false, $limit);
    }

    public function direct(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'nullable',
            'user_uid' => 'nullable|string|max:255',
            'firebase_uid' => 'nullable|string|max:255',
            'public_id' => 'nullable|string|max:191',
        ]);
        $raw = (string) ($data['user_uid'] ?? $data['firebase_uid'] ?? $data['user_id'] ?? '');
        abort_if($raw === '', 422, 'A recipient user id or uid is required.');

        $other = DB::table('users')->where(function ($q) use ($raw): void {
            if (ctype_digit($raw)) $q->orWhere('id', (int) $raw);
            $q->orWhere('firebase_uid', $raw)->orWhere('legacy_firebase_uid', $raw)->orWhere('email', $raw);
        })->first();
        abort_unless($other, 404, 'Recipient not found.');
        abort_if((int) $other->id === (int) $request->user()->id, 422, 'Cannot create a direct chat with yourself.');

        $members = [(int) $request->user()->id, (int) $other->id];
        sort($members);
        $pair = implode(':', $members);
        $preferredId = trim((string) ($data['public_id'] ?? ''));

        $room = DB::transaction(function () use ($pair, $members, $preferredId) {
            $room = DB::table('chat_rooms')->where('pair_key', $pair)->first();
            if (! $room && $preferredId !== '') {
                $room = DB::table('chat_rooms')->where('firebase_id', $preferredId)->first();
            }
            if (! $room) {
                $firebaseId = $preferredId !== '' ? $preferredId : (string) Str::ulid();
                $id = DB::table('chat_rooms')->insertGetId([
                    'firebase_id' => $firebaseId,
                    'type' => 'direct',
                    'pair_key' => $pair,
                    'metadata' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $room = DB::table('chat_rooms')->find($id);
            } elseif (! $room->pair_key) {
                DB::table('chat_rooms')->where('id', $room->id)->update(['pair_key' => $pair, 'updated_at' => now()]);
                $room = DB::table('chat_rooms')->find($room->id);
            }
            foreach ($members as $uid) {
                DB::table('chat_room_members')->updateOrInsert(
                    ['chat_room_id' => $room->id, 'user_id' => $uid],
                    ['role' => 'member', 'updated_at' => now(), 'created_at' => now()],
                );
            }
            return $room;
        });
        return $this->success($room, 201);
    }

    public function show(Request $request, string $id)
    {
        $room = DB::table('chat_rooms')->where(fn ($q) => $q->where('firebase_id', $id)->orWhere('id', ctype_digit($id) ? (int) $id : -1))->first();
        abort_unless($room && DB::table('chat_room_members')->where(['chat_room_id' => $room->id, 'user_id' => $request->user()->id])->exists(), 404);
        return $this->success(['conversation' => $room, 'members' => DB::table('chat_room_members')->where('chat_room_id', $room->id)->get()]);
    }
}
