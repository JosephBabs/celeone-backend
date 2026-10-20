<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaAnalyticsController extends Controller {
 use ApiResponses;
 public function adminMetrics(Request $r) {
  abort_unless(in_array($r->user()->role,['admin','super_admin'],true),403);
  $days=max(1,min(365,(int)$r->query('days',30)));
  $rows=DB::table('media_playback_sessions')->where('watched_seconds','>',0)->where('created_at','>=',now()->subDays($days))->groupBy('media_type','content_id')->selectRaw('media_type, content_id, COUNT(*) as plays, COUNT(DISTINCT user_id) as unique_users, SUM(watched_seconds) as seconds, AVG(watched_seconds) as average_seconds')->get();
  $items=[];foreach($rows as $row){$plays=(int)$row->plays;$unique=(int)$row->unique_users;$seconds=(float)$row->seconds;$average=(float)$row->average_seconds;
   $items[$row->media_type.':'.$row->content_id]=['playCount'=>$plays,'plays'=>$plays,'totalPlays'=>$plays,'vues'=>$plays,'views'=>$plays,'totalViews'=>$plays,'uniqueAccountStreams'=>$unique,'uniqueAccountViews'=>$unique,'totalListenTimeSec'=>$seconds,'totalWatchTimeSec'=>$seconds,'avgListenTimeSec'=>$average,'avgWatchTimeSec'=>$average,'averageWatchTimeSec'=>$average];
  }
  return $this->success(['items'=>$items,'source'=>'laravel_playback_sessions','periodDays'=>$days]);
 }
 public function consume(Request $r) {
  $d=$r->validate(['eventType'=>'required|in:start,watch','mediaType'=>'required|in:audio,video','contentId'=>'required|string|max:191','sourceCollection'=>'nullable|string|max:80','sessionId'=>'required|string|max:191','clientEventId'=>'required|string|max:191','watchedSeconds'=>'nullable|numeric|min:0|max:900','durationSec'=>'nullable|numeric|min:0|max:86400','positionSec'=>'nullable|numeric|min:0|max:86400','startedAtMs'=>'required|numeric|min:0','endedAtMs'=>'required|numeric|min:0']);
  $nowMs=now()->getTimestampMs();abort_if($d['endedAtMs']<$d['startedAtMs'] || $d['endedAtMs']>$nowMs+300000 || $d['endedAtMs']<$nowMs-604800000,422,'Invalid playback interval.');
  $table=$d['mediaType']==='audio'?'songs':'videos';
  $id='media_'.substr(hash('sha256',$r->user()->id.':'.$d['clientEventId']),0,48);
  return DB::transaction(function()use($r,$d,$table,$id){
   $content=DB::table($table)->where('public_id',$d['contentId'])->whereNull('deleted_at')->lockForUpdate()->first();abort_unless($content,404,'Media not found.');
   if(DB::table('media_consumption_events')->where('public_id',$id)->exists())return $this->success(['recorded'=>true,'duplicate'=>true,'event_id'=>$id]);
   $key=['user_id'=>$r->user()->id,'session_id'=>$d['sessionId'],'media_type'=>$d['mediaType'],'content_id'=>$d['contentId']];
   $session=DB::table('media_playback_sessions')->where($key)->first();
   $newSession=!$session;
   if(!$session){abort_unless($d['eventType']==='start',422,'Playback session must start first.');DB::table('media_playback_sessions')->insert($key+['created_at'=>now(),'updated_at'=>now()]);$session=DB::table('media_playback_sessions')->where($key)->first();}
   $end=(int)$d['endedAtMs'];$start=max((int)$d['startedAtMs'],(int)$session->last_end_ms);
   $seconds=$d['eventType']==='watch'?min((float)($d['watchedSeconds']??0),max(0,($end-$start)/1000),60):0;
   DB::table('media_playback_sessions')->where('id',$session->id)->update(['watched_seconds'=>$session->watched_seconds+$seconds,'duration_seconds'=>$d['durationSec']??$session->duration_seconds,'position_seconds'=>$d['positionSec']??0,'last_end_ms'=>max($end,(int)$session->last_end_ms),'updated_at'=>now()]);
   $payload=$d;$payload['watchedSeconds']=$seconds;
   // Creator ownership comes from stored content, never the caller.
   $stored=json_decode((string)$content->data,true) ?: [];$payload['creatorId']=$stored['creatorId']??$stored['ownerId']??$content->user_id;
   DB::table('media_consumption_events')->insert(['public_id'=>$id,'user_id'=>$r->user()->id,'parent_type'=>$d['mediaType'],'parent_id'=>$d['contentId'],'status'=>$d['eventType'],'data'=>json_encode($payload),'created_at'=>now(),'updated_at'=>now()]);
   $sessions=DB::table('media_playback_sessions')->where('media_type',$d['mediaType'])->where('content_id',$d['contentId']);
   $plays=(clone $sessions)->count();$unique=(clone $sessions)->distinct()->count('user_id');$watched=(float)(clone $sessions)->sum('watched_seconds');$average=$plays?$watched/$plays:0;
   $metrics=['plays'=>$plays,'uniqueStarts'=>$unique,'watchTimeSec'=>$watched,'averageWatchTimeSec'=>$average,'durationSec'=>(float)($d['durationSec']??0),'source'=>'laravel_playback_sessions'];
   $policy=array_replace(config('revenue_policy'),json_decode((string)DB::table('app_configs')->where('key','revenue_policy')->value('value'),true) ?: []);
   $duration=(float)($d['durationSec']??0);$completion=$duration>0?min(1,$average/$duration):0;
   $score=$plays*$policy['playsWeight']+$unique*$policy['uniqueAudienceWeight']+($watched/3600)*$policy['totalWatchHoursWeight']+($average/60)*$policy['avgWatchMinutesWeight']+$completion*$policy['completionRateWeight'];
   $kind=$d['mediaType']==='audio'?'song':'video';
   $metrics['engagementScore']=$score;$metrics['completionRate']=$completion;
   $metrics['monetizationEligible']=$plays>=$policy[$kind.'MinPlays'] && $unique>=$policy[$kind.'MinUniqueAudience'] && $score>=$policy[$kind.'ScoreThreshold'];
   $display=['plays'=>$plays,'totalPlays'=>$plays,'uniqueAccountStreams'=>$unique,'uniqueAccountViews'=>$unique,'totalListenTimeSec'=>$watched,'totalWatchTimeSec'=>$watched,'avgListenTimeSec'=>$average,'avgWatchTimeSec'=>$average,'metrics'=>$metrics];
   if($d['mediaType']==='video')$display+=['vues'=>$plays,'views'=>$plays,'totalViews'=>$plays];
   DB::table($table)->where('id',$content->id)->update(['data'=>json_encode(array_replace($stored,$display)),'updated_at'=>now()]);
   $mirror=DB::table('firebase_documents')->where('collection_path',$table)->where('document_id',$d['contentId'])->first();
   if($mirror){$m=json_decode($mirror->payload,true) ?: [];DB::table('firebase_documents')->where('id',$mirror->id)->update(['payload'=>json_encode(array_replace($m,$display)),'firebase_updated_at'=>now(),'updated_at'=>now()]);}
   $creator=(string)($payload['creatorId']??'');
   if($creator!=='') {
    DB::table('creator_metrics')->insertOrIgnore(['public_id'=>$creator,'data'=>'{}','created_at'=>now(),'updated_at'=>now()]);
    $owner=DB::table('creator_metrics')->where('public_id',$creator)->lockForUpdate()->first();$aggregate=json_decode($owner->data,true) ?: [];
    $aggregate['creatorId']=$creator;$aggregate['totalWatchTimeSec']=(float)($aggregate['totalWatchTimeSec']??0)+$seconds;
    $aggregate['measuredPlays']=(int)($aggregate['measuredPlays']??0)+($newSession?1:0);
    $aggregate['measuredWatchTimeSec']=(float)($aggregate['measuredWatchTimeSec']??0)+$seconds;
    $aggregate['averageWatchTimeSec']=$aggregate['measuredPlays']?$aggregate['measuredWatchTimeSec']/$aggregate['measuredPlays']:0;
    if($newSession){$field=$d['mediaType']==='audio'?'audioPlays':'videoViews';$aggregate[$field]=(int)($aggregate[$field]??0)+1;$total=$d['mediaType']==='audio'?'totalPlays':'totalViews';$aggregate[$total]=(int)($aggregate[$total]??0)+1;}
    $aggregate['source']='laravel_playback_sessions';$aggregate['updatedAtMs']=now()->getTimestampMs();
    DB::table('creator_metrics')->where('id',$owner->id)->update(['data'=>json_encode($aggregate),'updated_at'=>now()]);
    DB::table('firebase_documents')->updateOrInsert(['collection_path'=>'creator_metrics','document_id'=>$creator],['payload'=>json_encode($aggregate),'firebase_updated_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   }
   return $this->success(['recorded'=>true,'event_id'=>$id,'watched_seconds'=>$seconds,'metrics'=>$metrics]);
  });
 }
}
