<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\User;
use App\Services\SocialNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache};
use Illuminate\Support\Str;

class FriendController extends Controller
{
 use ApiResponses;
 public static function uid(User $user): string {return (string)($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);}
 public static function profile(User $user): array {
  $raw=DB::table('user_data')->where('user_id',$user->id)->value('profile_data');$p=json_decode((string)$raw,true) ?: [];
  return ['uid'=>self::uid($user),'name'=>$p['displayName'] ?? $user->name,'avatar'=>$p['photoURL'] ?? $p['avatar'] ?? $user->avatar_url];
 }
 public static function row(object $row): array {
  $data=json_decode((string)$row->data,true) ?: [];
  return array_merge($data,['id'=>$row->public_id,'status'=>$row->status,'createdAt'=>strtotime($row->created_at)*1000,'updatedAt'=>strtotime($row->updated_at)*1000]);
 }
 public static function mirror(object $row): void {
  DB::table('firebase_documents')->updateOrInsert(['collection_path'=>'friend_requests','document_id'=>$row->public_id],['payload'=>json_encode(self::row($row)),'firebase_updated_at'=>now(),'updated_at'=>now(),'created_at'=>$row->created_at]);
 }
 private function link(User $a,User $b,string $status='accepted'): array {
  $ids=[$a->id,$b->id];sort($ids);DB::table('friendships')->updateOrInsert(['user_id'=>$ids[0],'friend_id'=>$ids[1]],['status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
  $uids=[self::uid($a),self::uid($b)];sort($uids);$id=implode('_',$uids);
  $payload=['id'=>$id,'users'=>$uids,'status'=>$status==='accepted'?'active':'removed','profiles'=>[self::uid($a)=>self::profile($a),self::uid($b)=>self::profile($b)],'updatedAt'=>now()->getTimestampMs()];
  DB::table('firebase_documents')->updateOrInsert(['collection_path'=>'friendships','document_id'=>$id],['payload'=>json_encode($payload),'firebase_updated_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  return $payload;
 }
 public function index(Request $r) {
  $rows=DB::table('friendships')->where(fn($q)=>$q->where('user_id',$r->user()->id)->orWhere('friend_id',$r->user()->id))->where('status','accepted')->get();
  $items=[];foreach($rows as $row){$other=User::find($row->user_id===$r->user()->id?$row->friend_id:$row->user_id);if(!$other)continue;$uids=[self::uid($r->user()),self::uid($other)];sort($uids);$items[]=['id'=>implode('_',$uids),'users'=>$uids,'status'=>'active','profiles'=>[self::uid($r->user())=>self::profile($r->user()),self::uid($other)=>self::profile($other)]];}
  return $this->success($items);
 }
 public function requests(Request $r) {
  $rows=DB::table('friend_requests')->whereNull('deleted_at')->where(fn($q)=>$q->where('user_id',$r->user()->id)->orWhere('parent_id',(string)$r->user()->id))->orderByDesc('updated_at')->get();
  return $this->success(['incoming'=>$rows->filter(fn($x)=>(string)$x->parent_id===(string)$r->user()->id && $x->status==='pending')->map(fn($x)=>self::row($x))->values(),'outgoing'=>$rows->filter(fn($x)=>(int)$x->user_id===$r->user()->id)->map(fn($x)=>self::row($x))->values()]);
 }
 public function request(Request $r) {
  $d=$r->validate(['receiverId'=>'required|string|max:191']);$other=SocialNotification::user($d['receiverId']);abort_unless($other && !$other->firebase_disabled,404);abort_if($other->id===$r->user()->id,422,'Cannot send a request to yourself.');
  $ids=[$other->id,$r->user()->id];sort($ids);
  return Cache::lock('friend-pair:'.implode(':',$ids),15)->block(5,function()use($r,$other,$ids){return DB::transaction(function()use($r,$other,$ids){
   $existing=DB::table('friend_requests')->whereNull('deleted_at')->where(fn($q)=>$q->where(fn($x)=>$x->where('user_id',$ids[0])->where('parent_id',(string)$ids[1]))->orWhere(fn($x)=>$x->where('user_id',$ids[1])->where('parent_id',(string)$ids[0])))->whereIn('status',['pending','accepted'])->orderByDesc('updated_at')->first();
   if($existing)return $this->success(self::row($existing));
   $a=self::profile($r->user());$b=self::profile($other);$id=(string)Str::ulid();$data=['senderId'=>$a['uid'],'receiverId'=>$b['uid'],'senderName'=>$a['name'],'receiverName'=>$b['name'],'senderAvatar'=>$a['avatar'],'receiverAvatar'=>$b['avatar']];
   DB::table('friend_requests')->insert(['public_id'=>$id,'user_id'=>$r->user()->id,'parent_type'=>'receiver','parent_id'=>(string)$other->id,'status'=>'pending','data'=>json_encode($data),'created_at'=>now(),'updated_at'=>now()]);
   $row=DB::table('friend_requests')->where('public_id',$id)->first();self::mirror($row);\App\Jobs\NotifyFriendActivity::dispatchSync($id,'pending');return $this->success(self::row($row),201);
  });});
 }
 private function transition(Request $r,string $id,string $status) {
  return DB::transaction(function()use($r,$id,$status){
   $row=DB::table('friend_requests')->where('public_id',$id)->whereNull('deleted_at')->lockForUpdate()->first();abort_unless($row,404);
   $actor=$status==='cancelled'?$row->user_id:$row->parent_id;abort_unless((string)$actor===(string)$r->user()->id,403);
   if($row->status===$status)return $this->success(self::row($row));
   abort_unless($row->status==='pending',409,'This request is no longer pending.');
   DB::table('friend_requests')->where('id',$row->id)->update(['status'=>$status,'updated_at'=>now()]);$row->status=$status;$row->updated_at=now()->toDateTimeString();self::mirror($row);
   $friendship=null;if($status==='accepted'){$friendship=$this->link(User::findOrFail($row->user_id),$r->user());\App\Jobs\NotifyFriendActivity::dispatchSync($id,'accepted');}
   return $this->success(array_merge(self::row($row),['friendship'=>$friendship]));
  });
 }
 public function accept(Request $r,string $id){return $this->transition($r,$id,'accepted');}
 public function reject(Request $r,string $id){return $this->transition($r,$id,'rejected');}
 public function cancel(Request $r,string $id){return $this->transition($r,$id,'cancelled');}
 public function remove(Request $r,string $uid){$other=SocialNotification::user($uid);abort_unless($other,404);return DB::transaction(function()use($r,$other){
  $rows=DB::table('friend_requests')->where('status','accepted')->where(fn($q)=>$q->where(fn($x)=>$x->where('user_id',$r->user()->id)->where('parent_id',(string)$other->id))->orWhere(fn($x)=>$x->where('user_id',$other->id)->where('parent_id',(string)$r->user()->id)))->get();
  foreach($rows as $row){DB::table('friend_requests')->where('id',$row->id)->update(['status'=>'removed','updated_at'=>now()]);$row->status='removed';$row->updated_at=now()->toDateTimeString();self::mirror($row);}
  DB::table('friendships')->where('user_id',$other->id)->where('friend_id',$r->user()->id)->update(['status'=>'removed','updated_at'=>now()]);
  DB::table('friendships')->where('user_id',$r->user()->id)->where('friend_id',$other->id)->update(['status'=>'removed','updated_at'=>now()]);
  return $this->success($this->link($r->user(),$other,'removed'));
 });}
}
