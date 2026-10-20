<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Http};
class SocialNotification
{
    public static function user(?string $key): ?User {
        if (! $key) return null;
        return User::where('firebase_uid',$key)->orWhere('legacy_firebase_uid',$key)->orWhere('id',ctype_digit($key)?(int)$key:-1)->first();
    }
    public static function keys(User $user): array { return array_values(array_unique(array_filter([(string)$user->id,$user->firebase_uid,$user->legacy_firebase_uid]))); }
    public static function payload(?object $row): array { return $row ? (is_array($row->payload)?$row->payload:(json_decode($row->payload,true) ?: [])) : []; }
    public function deliver(User $user, array $data): void {
        if ($user->firebase_disabled) return;
        $id=$data['id']; $uid=(string)($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);
        $data += ['targetUid'=>$uid,'userId'=>$uid,'createdAtMs'=>now()->getTimestampMs(),'read'=>false];
        $existing=DB::table('notification_inbox')->where('public_id',$id)->first();
        if ($existing && in_array($existing->status,['sent','read'],true)) return;
        $parentType=str_starts_with((string)($data['type'] ?? ''),'friend_') ? 'friend_request' : (string)($data['parentType'] ?? 'post');
        $parentId=(string)($data['postId'] ?? $data['requestId'] ?? $data['sourceId'] ?? $data['parentId'] ?? $id);
        DB::table('notification_inbox')->updateOrInsert(['public_id'=>$id],['user_id'=>$user->id,'parent_type'=>$parentType,'parent_id'=>$parentId,'status'=>'pending','title'=>$data['title'],'body'=>$data['description'],'data'=>json_encode($data),'created_at'=>$existing?->created_at ?? now(),'updated_at'=>now()]);
        DB::table('firebase_documents')->updateOrInsert(['collection_path'=>'notification_inbox','document_id'=>$id],['parent_document_path'=>null,'payload'=>json_encode($data),'firebase_updated_at'=>now(),'created_at'=>$existing?->created_at ?? now(),'updated_at'=>now()]);
        $tokens=[]; $excluded=[];
        foreach(DB::table('firebase_documents')->whereIn('collection_path',array_map(fn($key)=>'users/'.$key.'/push_devices',self::keys($user)))->get(['payload']) as $device) {
            $d=self::payload($device);$token=$d['fcmToken'] ?? $d['token'] ?? null;
            if (! $token) continue;
            if (($d['enabled'] ?? true)===false || ($d['pushEnabled'] ?? true)===false || ($d['invalidToken'] ?? false)===true || ($d['notificationsEnabled'] ?? true)===false || in_array($d['permissionStatus'] ?? '',['denied','blocked'],true)) $excluded[]=$token;
            else $tokens[]=$token;
        }
        $tokens=array_values(array_unique(array_diff($tokens,$excluded)));
        foreach(array_chunk($tokens,500) as $chunk) {
            Http::timeout(40)->withHeaders(['X-Celeone-Internal'=>trim(file_get_contents(config('services.notifications.internal_key_path','/etc/celeone/notification-internal-key')))])
                ->post('http://127.0.0.1:4000/api/notifications/internal/page-post',['tokens'=>$chunk,'title'=>$data['title'],'body'=>$data['description'],'data'=>$data])->throw();
        }
        DB::table('notification_inbox')->where('public_id',$id)->update(['status'=>'sent','updated_at'=>now()]);
    }
}
