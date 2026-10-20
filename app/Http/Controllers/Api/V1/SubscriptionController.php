<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\SocialNotification;
class SubscriptionController extends DomainResourceController {
 protected string $table='user_subscriptions';
 public function index(Request $r) {
  $rows=DB::table($this->table)->where('user_id',$r->user()->id)->whereNull('deleted_at')->orderByDesc('created_at')->get();
  return $this->success($rows->map(function($row){$d=json_decode((string)$row->data,true) ?: [];if(($d['endAt']??0)<now()->getTimestampMs() && $row->status==='active')$row->status='expired';return array_merge($d,['id'=>$row->public_id,'status'=>$row->status]);})->sortByDesc(fn($x)=>[$x['status']==='active'?1:0,$x['endAt']??0])->values());
 }
 public function show(string $id){$row=DB::table($this->table)->where('public_id',$id)->where('user_id',request()->user()->id)->whereNull('deleted_at')->first();abort_unless($row,404);return $this->success(json_decode((string)$row->data,true));}
 public function store(Request $r){abort(403,'Subscriptions are activated only by verified payments.');}
 public function update(Request $r,string $id){abort(403,'Subscriptions are managed by the payment service.');}
 public function destroy(string $id){abort(403,'Subscriptions are managed by the payment service.');}
 public function confirmed(Request $r) {
  $path=config('services.notifications.internal_key_path','/etc/celeone/notification-internal-key');$key=is_file($path)?trim(file_get_contents($path)):'';
  abort_unless($key!=='' && hash_equals($key,(string)$r->header('X-Celeone-Internal')),403);
  $d=$r->validate(['uid'=>'required|string|max:191','transactionId'=>'required|string|max:150','packageId'=>'required|string|max:191','packageName'=>'required|string|max:255','amount'=>'required|numeric|min:0.01','durationDays'=>'required|integer|min:1|max:3660','currency'=>'required|in:XOF','startAt'=>'required|integer|min:1']);
  $user=SocialNotification::user($d['uid']);abort_unless($user,404);
  $id='fedapay_'.$d['transactionId'];
  return DB::transaction(function()use($d,$user,$id){
   $payload=['uid'=>FriendController::uid($user),'packageId'=>$d['packageId'],'packageName'=>$d['packageName'],'price'=>(float)$d['amount'],'currency'=>$d['currency'],'startAt'=>$d['startAt'],'endAt'=>$d['startAt']+$d['durationDays']*86400000,'status'=>'active','paymentProvider'=>'fedapay','providerTransactionId'=>$d['transactionId'],'verifiedBy'=>'provider-server'];
   DB::table($this->table)->insertOrIgnore(['public_id'=>$id,'user_id'=>$user->id,'status'=>'active','data'=>json_encode($payload),'created_at'=>now(),'updated_at'=>now()]);
   $existing=DB::table($this->table)->where('public_id',$id)->first();abort_unless((int)$existing->user_id===$user->id,409);
   $payload=json_decode($existing->data,true);
   DB::table('firebase_documents')->updateOrInsert(['collection_path'=>'user_subscriptions','document_id'=>$id],['payload'=>json_encode($payload),'firebase_updated_at'=>now(),'created_at'=>$existing->created_at,'updated_at'=>now()]);
   return $this->success($payload);
  });
 }
}
