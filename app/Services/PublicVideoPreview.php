<?php
namespace App\Services;

use Illuminate\Support\Facades\{Http,Storage};
use Symfony\Component\Process\Process;

class PublicVideoPreview
{
 public static function video(array $post): ?string {
  if(!empty($post['videoUrl']))return (string)$post['videoUrl'];
  foreach(($post['media']??[]) as $m)if(is_array($m) && (str_starts_with(strtolower((string)($m['type']??$m['mime']??'')),'video') || preg_match('~\.(mp4|mov|webm)(\?|$)~i',(string)($m['url']??''))))return $m['url']??$m['uri']??null;
  return null;
 }
 public static function key(string $url): string {return 'video-previews/'.hash('sha256',$url);}
 public static function decorate(array $post): array {
  if(!$video=self::video($post))return $post;
  $key=self::key($video);$disk=Storage::disk('public');
  if($disk->exists($key.'.jpg'))$post['thumbnailUrl']=$disk->url($key.'.jpg');
  if($disk->exists($key.'-share.jpg'))$post['videoShareImage']=$disk->url($key.'-share.jpg');
  return $post;
 }
 public function generate(string $url): bool {
  $disk=Storage::disk('public');$key=self::key($url);
  if($disk->exists($key.'.jpg') && $disk->exists($key.'-share.jpg'))return false;
  // Only fetch media from configured storage hosts; never arbitrary URLs or redirects.
  $host=strtolower((string)parse_url($url,PHP_URL_HOST));
  $hosts=array_filter(['firebasestorage.googleapis.com','storage.googleapis.com',parse_url(config('app.url'),PHP_URL_HOST),...explode(',',(string)config('services.video_previews.hosts','api.celeonetv.com,celeonetv.com'))]);
  if(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP) || $host==='localhost' || parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,$hosts,true) || parse_url($url,PHP_URL_USER) || (parse_url($url,PHP_URL_PORT) && parse_url($url,PHP_URL_PORT)!==443))throw new \RuntimeException('Unsupported video storage host');
  $temp=tempnam(sys_get_temp_dir(),'celeone-preview-');
  $disk->makeDirectory('video-previews');
  $frame=$disk->path($key.'.pending.jpg');$share=$disk->path($key.'-share.pending.jpg');
  try {
   $response=Http::timeout(90)->connectTimeout(10)->withOptions(['allow_redirects'=>false,'sink'=>$temp,'progress'=>function($total,$downloaded){if($total>300*1024*1024 || $downloaded>300*1024*1024)throw new \RuntimeException('Video exceeds preview download limit');}])->get($url);
   if(!$response->successful())throw new \RuntimeException('Video could not be downloaded');
   $filter='scale=1200:630:force_original_aspect_ratio=decrease,pad=1200:630:(ow-iw)/2:(oh-ih)/2:black,setsar=1';
   foreach(['5','0'] as $time){
    $process=new Process(['ffmpeg','-v','error','-y','-protocol_whitelist','file,pipe','-ss',$time,'-i',$temp,'-frames:v','1','-vf',$filter,'-q:v','3',$frame]);$process->setTimeout(90);$process->run();
    if($process->isSuccessful() && is_file($frame) && filesize($frame)>0)break;
   }
   if(!is_file($frame)||!filesize($frame))throw new \RuntimeException('No video frame extracted');
   $overlay="drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:text='\u{25B6}':fontcolor=white:fontsize=100:x=(w-tw)/2:y=(h-th)/2:box=1:boxcolor=black@0.65:boxborderw=24";
   $process=new Process(['ffmpeg','-v','error','-y','-i',$frame,'-vf',$overlay,'-frames:v','1','-q:v','3',$share]);$process->setTimeout(30);$process->mustRun();
   $disk->move($key.'.pending.jpg',$key.'.jpg');$disk->move($key.'-share.pending.jpg',$key.'-share.jpg');
   return true;
  } finally {@unlink($temp);@unlink($frame);@unlink($share);}
 }
}
