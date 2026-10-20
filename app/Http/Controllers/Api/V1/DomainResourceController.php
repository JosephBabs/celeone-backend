<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DomainResourceController extends Controller
{
    use ApiResponses;
    protected string $table='';

    public function index(Request $request)
    {
        $limit=min(100,max(1,(int)$request->integer('limit',20)));
        $query=DB::table($this->table)->whereNull('deleted_at')->orderByDesc('id');
        if ($request->filled('cursor')) $query->where('id','<',(int)base64_decode((string)$request->input('cursor')));
        foreach (['status','user_id','parent_type','parent_id'] as $field) if ($request->filled($field)) $query->where($field,$request->input($field));
        if ($request->filled('q')) $query->where(fn($q)=>$q->whereILike('title','%'.$request->input('q').'%')->orWhereILike('body','%'.$request->input('q').'%'));
        $rows=$query->limit($limit+1)->get(); $hasMore=$rows->count()>$limit; $items=$rows->take($limit)->values();
        $next=$hasMore ? base64_encode((string)$items->last()->id) : null;
        return $this->page($items,$next,$hasMore,$limit);
    }

    public function show(string $id)
    {
        $row=DB::table($this->table)->where(fn($q)=>$q->where('public_id',$id)->orWhere('id',ctype_digit($id)?(int)$id:-1))->whereNull('deleted_at')->first();
        return $row ? $this->success($row) : $this->failure('NOT_FOUND','Resource not found.',404);
    }

    public function store(Request $request)
    {
        $data=$request->validate(['public_id'=>'nullable|string|max:191','status'=>'nullable|string|max:50','title'=>'nullable|string|max:255','body'=>'nullable|string','parent_type'=>'nullable|string|max:80','parent_id'=>'nullable|string|max:191','data'=>'nullable|array']);
        $publicId=$data['public_id'] ?? (string) Str::ulid(); $now=now();
        DB::table($this->table)->insert(['public_id'=>$publicId,'user_id'=>$request->user()?->id,'status'=>$data['status']??'active','title'=>$data['title']??null,'body'=>$data['body']??null,'parent_type'=>$data['parent_type']??null,'parent_id'=>$data['parent_id']??null,'data'=>json_encode($data['data']??[]),'created_at'=>$now,'updated_at'=>$now]);
        return $this->show($publicId);
    }

    public function update(Request $request,string $id)
    {
        $data=$request->validate(['status'=>'sometimes|nullable|string|max:50','title'=>'sometimes|nullable|string|max:255','body'=>'sometimes|nullable|string','data'=>'sometimes|array']);
        if (isset($data['data'])) $data['data']=json_encode($data['data']); $data['updated_at']=now();
        $count=DB::table($this->table)->where('public_id',$id)->whereNull('deleted_at')->update($data);
        return $count ? $this->show($id) : $this->failure('NOT_FOUND','Resource not found.',404);
    }

    public function destroy(string $id)
    {
        $count=DB::table($this->table)->where('public_id',$id)->whereNull('deleted_at')->update(['deleted_at'=>now(),'updated_at'=>now()]);
        return $count ? $this->success(['deleted'=>true]) : $this->failure('NOT_FOUND','Resource not found.',404);
    }
}
