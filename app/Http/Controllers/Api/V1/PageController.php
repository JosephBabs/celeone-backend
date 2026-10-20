<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;
class PageController extends DomainResourceController{protected string $table='pages';public function store(Request $r){$d=$r->validate(['name'=>'required|string|max:255','description'=>'nullable|string','avatar_url'=>'nullable|url','data'=>'nullable|array']);$id=(string)Str::ulid();DB::table('pages')->insert(['public_id'=>$id,'firebase_id'=>$id,'owner_id'=>$r->user()->id,'name'=>$d['name'],'title'=>$d['name'],'description'=>$d['description']??null,'body'=>$d['description']??null,'avatar_url'=>$d['avatar_url']??null,'status'=>'active','data'=>json_encode($d['data']??[]),'created_at'=>now(),'updated_at'=>now()]);return $this->show($id);}}
