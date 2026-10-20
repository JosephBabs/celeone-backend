<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse { return response()->json(['users' => User::count(), 'posts' => Post::count(), 'firebase_documents' => DB::table('firebase_documents')->count()]); }
    public function users(Request $request): JsonResponse
    {
        $query=User::query()->orderByDesc('created_at')->orderByDesc('id');
        if($request->filled('q')) { $q='%'.$request->input('q').'%';$query->where(fn($query)=>$query->where('name','like',$q)->orWhere('email','like',$q)); }
        if($request->filled('country')) $query->whereExists(fn($q)=>$q->selectRaw('1')->from('user_data')->whereColumn('user_data.user_id','users.id')->where('profile_data->country',$request->input('country')));
        $page=$query->paginate(min(200,max(1,$request->integer('limit',100))));
        $page->getCollection()->transform(function(User $user) {
            $profile=DB::table('user_data')->where('user_id',$user->id)->latest('id')->first();
            $data=$profile ? (json_decode((string)$profile->profile_data,true) ?: []) : [];
            $uid=(string)($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);
            return array_merge($data,['id'=>$uid,'uid'=>$uid,'backendId'=>$user->id,'displayName'=>$user->name,'email'=>$user->email,'role'=>$user->role,'created_at'=>$user->created_at,'createdAtMs'=>$user->created_at?->getTimestampMs()]);
        });
        return response()->json($page);
    }
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data=$request->validate(['role'=>['required','in:user,admin,super_admin'],'name'=>['sometimes','string','max:255']]);
        if($request->user()->role!=='super_admin') abort_if($data['role']==='super_admin' || $user->role==='super_admin',403);
        DB::transaction(function()use($user,$data){
            $legacy=(array)($user->legacy_data??[]);$legacy['backendRoleManaged']=true;
            $user->forceFill($data+['legacy_data'=>$legacy])->save();
            DB::table('user_data')->where('user_id',$user->id)->update(['role'=>$user->role,'updated_at'=>now()]);
            foreach(\App\Services\SocialNotification::keys($user) as $uid) foreach(['users','user_data'] as $collection) {
                $row=DB::table('firebase_documents')->where(['collection_path'=>$collection,'document_id'=>$uid])->first();
                if(!$row)continue;$payload=\App\Services\SocialNotification::payload($row);$payload['role']=$user->role;
                DB::table('firebase_documents')->where('id',$row->id)->update(['payload'=>json_encode($payload),'firebase_updated_at'=>now(),'updated_at'=>now()]);
            }
        });
        return response()->json($user);
    }

    public function posts(): JsonResponse { return response()->json(Post::with('user:id,name')->latest()->paginate(50)); }
    public function deletePost(Post $post): JsonResponse { $post->delete(); return response()->json(['success' => true]); }
    public function firebaseSchema(): JsonResponse { return response()->json(config('firebase_collections')); }
    public function domain(Request $request, string $domain): JsonResponse
    {
        $table = config('celeone_domains.collections.'.$domain, $domain);
        abort_unless(in_array($table, array_unique(array_values(config('celeone_domains.collections', []))), true), 404);
        return response()->json(DB::table($table)->orderByDesc('id')->paginate(min(100, max(1, $request->integer('limit', 50)))));
    }
    public function updateDomain(Request $request, string $domain, string $id): JsonResponse
    {
        $table = config('celeone_domains.collections.'.$domain, $domain);
        abort_unless(in_array($table, array_unique(array_values(config('celeone_domains.collections', []))), true), 404);
        $data = $request->validate(['status'=>'sometimes|nullable|string|max:50','title'=>'sometimes|nullable|string|max:255','body'=>'sometimes|nullable|string','data'=>'sometimes|array']);
        if (isset($data['data'])) $data['data'] = json_encode($data['data']);
        $data['updated_at'] = now();
        abort_unless(DB::table($table)->where('public_id', $id)->update($data), 404);
        return response()->json(DB::table($table)->where('public_id', $id)->first());
    }
}
