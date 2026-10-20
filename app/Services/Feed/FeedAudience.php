<?php
namespace App\Services\Feed;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FeedAudience
{
    public array $self;
    public array $friends = [];
    public array $following = [];

    public function __construct(User $user)
    {
        $this->self = array_values(array_unique(array_filter([(string)$user->id, $user->firebase_uid, $user->legacy_firebase_uid])));
        $friendIds = DB::table('friendships')->where('status', 'accepted')
            ->where(fn($q) => $q->where('user_id', $user->id)->orWhere('friend_id', $user->id))
            ->get()->flatMap(fn($row) => [$row->user_id, $row->friend_id])->unique()->all();
        foreach (User::whereIn('id', $friendIds)->get(['id','firebase_uid','legacy_firebase_uid']) as $friend) {
            $this->friends = [...$this->friends, (string)$friend->id, $friend->firebase_uid, $friend->legacy_firebase_uid];
        }
        $this->following = DB::table('following')->where('user_id', $user->id)->where('status','active')->pluck('parent_id')->all();
        foreach ($this->self as $uid) {
            foreach (DB::table('firebase_documents')->where('collection_path','following')->where('payload->followerId',$uid)->get(['payload']) as $row) {
                $data=json_decode($row->payload,true) ?: [];
                if (($data['status'] ?? 'active')==='active' && !empty($data['targetId'])) $this->following[]=(string)$data['targetId'];
            }
            $nested = DB::table('firebase_documents')->whereIn('collection_path', ['users/'.$uid.'/friends', 'users/'.$uid.'/following'])->get(['collection_path','document_id','payload']);
            foreach ($nested as $row) {
                $data = json_decode($row->payload, true) ?: [];
                if (!in_array($data['status'] ?? 'active', ['active','accepted','friends','following'])) continue;
                if (str_ends_with($row->collection_path, '/friends')) $this->friends[] = (string)($data['friendId'] ?? $data['userId'] ?? $row->document_id);
                else $this->following[] = (string)($data['targetId'] ?? $data['pageId'] ?? $data['userId'] ?? $row->document_id);
            }
            $legacy = DB::table('firebase_documents')->whereIn('collection_path', ['friends','friendships'])
                ->where(fn($q) => $q->where('payload->userId',$uid)->orWhere('payload->friendId',$uid)->orWhereJsonContains('payload->users',$uid))
                ->get(['payload']);
            foreach ($legacy as $row) {
                $data = json_decode($row->payload, true) ?: [];
                if (!in_array($data['status'] ?? 'active', ['active','accepted','friends'])) continue;
                $this->friends = [...$this->friends, ...($data['users'] ?? []), $data['userId'] ?? null, $data['friendId'] ?? null];
            }
        }
        $this->friends = array_values(array_diff(array_unique(array_filter(array_map('strval',$this->friends))), $this->self));
        $this->following = array_values(array_unique(array_filter(array_map('strval',$this->following))));
    }

    public function query(string $collection = 'posts'): Builder
    {
        return DB::table('firebase_documents')->where('collection_path',$collection)->where('feed_status','published')
            ->where(function($q): void {
                $q->where('feed_audience','public')->orWhereIn('feed_owner_id',$this->self);
                if ($this->friends) $q->orWhere(fn($q) => $q->where('feed_audience','friends')->whereIn('feed_owner_id',$this->friends));
            });
    }

    public function reason(object $row): string
    {
        if (in_array($row->feed_owner_id,$this->self,true)) return 'self';
        if (in_array($row->feed_owner_id,$this->friends,true)) return 'friend';
        if (in_array($row->feed_author_id,$this->following,true) || in_array($row->feed_page_id,$this->following,true)) return 'following';
        return 'discovery';
    }
}
