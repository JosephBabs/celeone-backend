<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
class PublicContentIndexController {
 public function __invoke(Request $request) {
  $input=$request->validate(['page'=>'nullable|integer|min:1|max:50000']);
  $query=DB::table('firebase_documents')->whereIn('collection_path',['posts','reels']);
  $pages=max(1,(int)ceil((clone $query)->count()/100));
  if(!$request->has('page'))return response()->json(['pages'=>$pages]);
  $items=[];
  foreach($query->orderBy('id')->offset(($input['page']-1)*100)->limit(100)->get(['collection_path','document_id']) as $row){
   try {
    $controller=$row->collection_path==='reels'?new PublicReelController:new PublicPostController;
    $data=$controller($row->document_id)->getData(true)['data'];
    $items[]=['id'=>$data['id'],'type'=>$row->collection_path];
   } catch(HttpException $e){if($e->getStatusCode()!==404)throw $e;}
  }
  return response()->json(['items'=>$items,'pages'=>$pages])->header('Cache-Control','public, max-age=60');
 }
}
