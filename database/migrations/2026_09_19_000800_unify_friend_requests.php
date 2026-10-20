<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\SocialNotification;
use App\Http\Controllers\Api\V1\FriendController;
return new class extends Migration {
 public function up(): void {
  DB::transaction(function(){
   foreach(DB::table('firebase_documents')->where('collection_path','friend_requests')->orderBy('updated_at')->get() as $source){
    $p=json_decode($source->payload,true) ?: [];$a=SocialNotification::user((string)($p['senderId']??''));$b=SocialNotification::user((string)($p['receiverId']??''));if(!$a||!$b||$a->id===$b->id)continue;
    $old=DB::table('friend_requests')->where('public_id',$source->document_id)->first();
    $status=$old && in_array($old->status,['accepted','rejected','cancelled','removed'],true)?$old->status:($p['status']??'pending');
    if(!in_array($status,['pending','accepted','rejected','cancelled','removed'],true))$status='cancelled';
    $pa=FriendController::profile($a);$pb=FriendController::profile($b);
    $data=['senderId'=>$pa['uid'],'receiverId'=>$pb['uid'],'senderName'=>$pa['name'],'receiverName'=>$pb['name'],'senderAvatar'=>$pa['avatar'],'receiverAvatar'=>$pb['avatar']];
    DB::table('friend_requests')->updateOrInsert(['public_id'=>$source->document_id],['user_id'=>$a->id,'parent_type'=>'receiver','parent_id'=>(string)$b->id,'status'=>$status,'data'=>json_encode($data),'created_at'=>$old->created_at??$source->created_at??now(),'updated_at'=>$source->updated_at??now()]);
   }
   $seen=[];
   foreach(DB::table('friend_requests')->whereNull('deleted_at')->orderByDesc('updated_at')->orderByDesc('id')->get() as $row){
    $pair=[(int)$row->user_id,(int)$row->parent_id];sort($pair);$key=implode(':',$pair);
    if(isset($seen[$key]) && in_array($row->status,['pending','accepted'],true)){
     $row->status='cancelled';DB::table('friend_requests')->where('id',$row->id)->update(['status'=>'cancelled']);
    }
    $seen[$key]=true;
    if($row->status==='accepted')DB::table('friendships')->updateOrInsert(['user_id'=>$pair[0],'friend_id'=>$pair[1]],['status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
    FriendController::mirror($row);
   }
  });
 }
 public function down(): void {}
};
