<?php
namespace App\Jobs;
use App\Models\User;
use App\Services\SocialNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
class NotifyFriendActivity implements ShouldQueue {
 use Queueable;public int $tries=5;public function backoff(): array{return [30,120,300,900];}
 public function __construct(public string $requestId,public string $status){}
 public function handle(): void {
  $row=DB::table('friend_requests')->where('public_id',$this->requestId)->first();if(!$row||$row->status!==$this->status)return;
  $d=json_decode($row->data,true);$accepted=$this->status==='accepted';$target=User::find($accepted?$row->user_id:$row->parent_id);if(!$target)return;
  $name=$accepted?$d['receiverName']:$d['senderName'];$type=$accepted?'friend_accepted':'friend_request';
  app(SocialNotification::class)->deliver($target,['id'=>$type.'_'.$this->requestId,'type'=>$type,'requestId'=>$this->requestId,'sourceId'=>$this->requestId,'friendUid'=>$accepted?$d['receiverId']:$d['senderId'],'title'=>$accepted?'Friend request accepted':'New friend request','description'=>$accepted?$name.' accepted your friend request':$name.' sent you a friend request']);
 }
}
