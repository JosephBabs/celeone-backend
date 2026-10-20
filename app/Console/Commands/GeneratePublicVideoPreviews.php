<?php
namespace App\Console\Commands;
use App\Http\Controllers\Api\V1\{PublicPostController,PublicReelController};
use App\Services\PublicVideoPreview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache,DB,Storage};
use Symfony\Component\HttpKernel\Exception\HttpException;
class GeneratePublicVideoPreviews extends Command {
 protected $signature='media:share-previews {--limit=10}';
 protected $description='Generate fifth-second thumbnails and play-overlay share previews for public videos';
 public function handle(PublicVideoPreview $service): int {
  $lock=Cache::lock('public-video-preview-generation',3600);
  if(!$lock->get())return self::SUCCESS;
  $generated=0;$failed=0;$attempts=0;$limit=max(1,min(100,(int)$this->option('limit')));
  try {
   foreach(DB::table('firebase_documents')->whereIn('collection_path',['posts','reels'])->orderByDesc('id')->cursor() as $row){
    try {$controller=$row->collection_path==='reels'?new PublicReelController:new PublicPostController;$p=$controller($row->document_id)->getData(true)['data'];}
    catch(HttpException $e){if($e->getStatusCode()===404)continue;throw $e;}
    $video=PublicVideoPreview::video($p);if(!$video)continue;
    $key=PublicVideoPreview::key($video);
    if(Storage::disk('public')->exists($key.'-share.jpg') || Cache::has($key.'-failed'))continue;
    try {if($service->generate($video))$generated++;}
    catch(\Throwable $e){$failed++;Cache::put($key.'-failed',true,now()->addHour());$this->warn('Preview failed for '.$row->collection_path.'/'.$row->document_id.': '.class_basename($e));}
    if(++$attempts >= $limit)break;
   }
  } finally {$lock->release();}
  $this->info(json_encode(['generated'=>$generated,'failed'=>$failed]));return $failed?self::FAILURE:self::SUCCESS;
 }
}
