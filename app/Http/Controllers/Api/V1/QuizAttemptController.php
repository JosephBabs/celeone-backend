<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Services\SocialNotification as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache};
class QuizAttemptController extends Controller {
 use ApiResponses;
 public function index(Request $r) {
  $query=DB::table('firebase_documents')->where('collection_path','jeunesse_quiz_attempts')->whereIn('payload->userId',N::keys($r->user()));
  if($r->filled('quizId'))$query->where('payload->quizId',(string)$r->input('quizId'));
  return $this->success($query->orderByDesc('id')->limit(100)->get()->map(fn($row)=>['id'=>$row->document_id,...N::payload($row)]));
 }
 public function store(Request $r,string $quiz) {
  $d=$r->validate(['answers'=>'present|array|max:200','answers.*'=>'integer|min:0|max:100','autoSubmitted'=>'sometimes|boolean','startedAt'=>'nullable|numeric']);
  $uid=(string)($r->user()->firebase_uid ?: $r->user()->legacy_firebase_uid ?: $r->user()->id);
  $id=hash('sha256',$r->user()->id.':'.$quiz);
  return Cache::lock('quiz-attempt:'.$id,15)->block(5,function()use($r,$quiz,$d,$uid,$id){
   $existing=DB::table('firebase_documents')->where(['collection_path'=>'jeunesse_quiz_attempts','document_id'=>$id])->first();
   if($existing)return $this->success(['id'=>$id,...N::payload($existing)]);
   $q=N::payload(DB::table('firebase_documents')->where(['collection_path'=>'jeunesse_quizzes','document_id'=>$quiz])->first());abort_unless($q,404);
   $questions=$q['questions']??[];
   if(!$questions){$questions=[['id'=>$quiz,'answerIndex'=>array_search(strtoupper($q['correct']??'A'),['A','B','C','D'],true)]];}
   $score=0;foreach($questions as $index=>$question){$key=(string)($question['id']??'q'.$index);if(array_key_exists($key,$d['answers']) && (int)$d['answers'][$key]===(int)($question['answerIndex']??-1))$score++;}
   $result=['userId'=>$uid,'quizId'=>$quiz,'answers'=>$d['answers'],'score'=>$score,'total'=>count($questions),'durationSec'=>(int)($q['durationSec']??60),'startedAt'=>$d['startedAt']??now()->getTimestampMs(),'submittedAt'=>now()->getTimestampMs(),'autoSubmitted'=>$d['autoSubmitted']??false];
   DB::table('firebase_documents')->insert(['collection_path'=>'jeunesse_quiz_attempts','document_id'=>$id,'parent_document_path'=>null,'payload'=>json_encode($result),'firebase_updated_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   return $this->success(['id'=>$id,...$result],201);
  });
 }
}
