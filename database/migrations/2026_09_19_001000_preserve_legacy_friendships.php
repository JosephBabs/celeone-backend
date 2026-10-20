<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\SocialNotification;
return new class extends Migration {
 public function up(): void {DB::transaction(function(){
  foreach(DB::table('firebase_documents')->where('collection_path','friendships')->get() as $row){
   $p=json_decode($row->payload,true) ?: [];if(!in_array($p['status']??'active',['active','accepted'],true))continue;
   $uids=$p['users']??[];if(count($uids)!==2)continue;$a=SocialNotification::user((string)$uids[0]);$b=SocialNotification::user((string)$uids[1]);if(!$a||!$b||$a->id===$b->id)continue;
   $ids=[$a->id,$b->id];sort($ids);
   $existing=DB::table('friendships')->where(fn($q)=>$q->where(fn($x)=>$x->where('user_id',$ids[0])->where('friend_id',$ids[1]))->orWhere(fn($x)=>$x->where('user_id',$ids[1])->where('friend_id',$ids[0])))->orderByDesc('updated_at')->first();
   DB::table('friendships')->updateOrInsert(['user_id'=>$ids[0],'friend_id'=>$ids[1]],['status'=>$existing->status??'accepted','created_at'=>$existing->created_at??$row->created_at??now(),'updated_at'=>$existing->updated_at??$row->updated_at??now()]);
   DB::table('friendships')->where('user_id',$ids[1])->where('friend_id',$ids[0])->delete();
  }
 });}
 public function down(): void {}
};
