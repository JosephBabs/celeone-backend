<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\DB;
class PublicReelController {
 public function __invoke(string $id) {
  abort_if(strlen($id)>191,404);
  $row=DB::table('firebase_documents')->where('collection_path','reels')->where('document_id',$id)->first();
  abort_unless($row,404);
  $p=json_decode($row->payload,true) ?: [];
  abort_if(isset($row->feed_status) && $row->feed_status!=='published',404);
  abort_if(isset($row->feed_audience) && $row->feed_audience!=='public',404);
  abort_unless(in_array(strtolower((string)($p['status']??'published')),['published','active','approved'],true),404);
  abort_unless(strtolower((string)($p['audience']??$p['visibility']??'public'))==='public',404);
  abort_if(!empty($p['deletedAt'])||!empty($p['deleted'])||!empty($p['isDeleted'])||!empty($p['isPrivate']),404);
  abort_if(in_array(strtolower((string)($p['moderationStatus']??'')),['rejected','blocked','removed'],true),404);
  $media=array_values(array_filter(is_array($p['media']??null)?$p['media']:[],fn($m)=>is_array($m)));
  $video=$p['videoUrl']??null;
  foreach($media as &$m){if(str_starts_with(strtolower((string)($m['type']??$m['mime']??'')),'video')){$m['type']='video';$video=$video?:($m['url']??$m['uri']??null);}}unset($m);
  abort_unless(is_string($video) && preg_match('~^https?://~i',$video),404);
  $result=array_intersect_key($p,array_flip(['title','caption','content','description','text','shareTitle','shareDescription','shareImage','videoUrl','thumbnailUrl','posterUrl','previewUrl','media','duration','createdAt','createdAtMs','authorName','postId']));
  $result['id']=$id;$result['type']='reel';$result['content']=$p['content']??$p['caption']??$p['description']??'';
  $result['videoUrl']=$video;$result['media']=$media;
  if(empty($result['media']) && !empty($p['videoUrl']))$result['media']=[['type'=>'video','url'=>$p['videoUrl'],'thumbnailUrl'=>$p['thumbnailUrl']??$p['posterUrl']??null]];
  return response()->json(['data'=>\App\Services\PublicVideoPreview::decorate($result)])->header('Cache-Control','public, max-age=30');
 }
}
