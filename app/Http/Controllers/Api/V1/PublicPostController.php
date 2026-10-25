<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
class PublicPostController {
 public function __invoke(string $id) {
  abort_if(strlen($id)>191,404);
  $record=Post::where('firebase_id',$id)->orWhere('id',ctype_digit($id)?(int)$id:-1)->first();
  $canonical=(string)($record?->firebase_id ?: $id);
  $row=DB::table('firebase_documents')->where('collection_path','posts')->where('document_id',$canonical)->first();
  if(!$row && $canonical!==$id){$row=DB::table('firebase_documents')->where('collection_path','posts')->where('document_id',$id)->first();}
  abort_unless($row||$record,404);
  $p=$row?(json_decode($row->payload,true) ?: []):(array)($record->legacy_data??[]);
  $status=strtolower((string)($p['status']??$record?->status??'published'));
  $audience=strtolower((string)($p['audience']??$p['visibility']??$record?->audience??'public'));
  abort_unless(in_array($status,['published','active','approved'],true) && $audience==='public',404);
  abort_if(!empty($p['isPrivate']) || !empty($p['deletedAt']) || !empty($p['deleted']) || !empty($p['isDeleted']) || ($record && ($record->audience!=='public' || !in_array($record->status,['published','active','approved'],true))),404);
  $allowed=['title','content','body','text','description','shareTitle','shareDescription','shareImage','image','imageUrl','images','media','videoUrl','thumbnailUrl','posterUrl','previewUrl','smallUrl','variants','type','authorName','posterName','createdAt','createdAtMs'];
  abort_if(in_array(strtolower((string)($p['moderationStatus']??'')),['rejected','blocked','removed'],true),404);
  $result=array_intersect_key($p,array_flip($allowed));$result['id']=$canonical;
  $result['content']=$p['content']??$p['body']??$p['text']??$record?->text_content??'';
  if(empty($result['media']) && $record)$result['media']=$record->media()->get(['url','type','metadata'])->map(fn($m)=>array_merge((array)$m->metadata,['url'=>$m->url,'type'=>$m->type]));
  abort_unless($record || !empty($result['content']) || !empty($result['title']) || !empty($result['media']) || !empty($result['videoUrl']) || !empty($result['images']) || !empty($result['imageUrl']) || !empty($result['image']),404);
  $result=json_decode(json_encode($result),true);
  return response()->json(['data'=>\App\Services\PublicVideoPreview::decorate($result)])->header('Cache-Control','public, max-age=30');
 }
}
