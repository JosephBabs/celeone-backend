<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendDiscoveryController
{
    public function __invoke(Request $r)
    {
        $input=$r->validate(['country'=>'nullable|string|max:100','location'=>'nullable|string|max:150','q'=>'nullable|string|max:100','mutual'=>'nullable|boolean','page'=>'nullable|integer|min:1','limit'=>'nullable|integer|min:1|max:50']);
        // Alias map contains identifiers only. Public profile fields are selected below.
        $aliases=[];
        foreach (User::get(['id','firebase_uid','legacy_firebase_uid']) as $user) {
            $uid=(string)($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);
            foreach (array_filter([(string)$user->id,$user->firebase_uid,$user->legacy_firebase_uid]) as $key) $aliases[$key]=$uid;
        }
        $self=$aliases[(string)$r->user()->id];
        $graph=[];
        $edge=function($a,$b) use(&$graph,$aliases) {
            $a=$aliases[(string)$a] ?? (string)$a; $b=$aliases[(string)$b] ?? (string)$b;
            if ($a && $b && $a!==$b) { $graph[$a][$b]=true; $graph[$b][$a]=true; }
        };
        foreach (DB::table('friendships')->where('status','accepted')->get(['user_id','friend_id']) as $row) $edge($row->user_id,$row->friend_id);
        foreach (DB::table('firebase_documents')->whereIn('collection_path',['friendships','friends'])->get(['payload']) as $row) {
            $d=json_decode($row->payload,true) ?: [];
            if (!in_array($d['status'] ?? 'active',['accepted','active','friends'],true)) continue;
            $ids=$d['users'] ?? [$d['userId'] ?? '',$d['friendId'] ?? ''];
            if (count($ids)===2) $edge($ids[0],$ids[1]);
        }
        $mine=$graph[$self] ?? []; $mutual=[];
        foreach ($mine as $friend=>$_) foreach ($graph[$friend] ?? [] as $candidate=>$unused) $mutual[$candidate]=($mutual[$candidate] ?? 0)+1;
        $ownKeys=array_keys(array_filter($aliases,fn($uid)=>$uid===$self));
        $excluded=[$self,...array_keys($mine)];
        foreach (DB::table('firebase_documents')->whereIn('collection_path',array_map(fn($uid)=>'users/'.$uid.'/blocked',$ownKeys))->get(['document_id','payload']) as $row) {
            $d=json_decode($row->payload,true) ?: []; $key=$d['userId'] ?? $d['targetId'] ?? $row->document_id; $excluded[]=$aliases[$key] ?? $key;
        }
        $uid="COALESCE(NULLIF(u.firebase_uid,''), NULLIF(u.legacy_firebase_uid,''),u.id::text)";
        $query=DB::table('users as u')->leftJoin('firebase_documents as p',function($join) use($uid) { $join->on('p.document_id','=',DB::raw($uid))->where('p.collection_path','user_data'); })
            ->whereRaw('COALESCE(u.firebase_disabled,false)=false')->whereRaw("COALESCE(p.payload->>'discoverable','true') <> 'false'")
            ->whereNotIn(DB::raw($uid),$excluded);
        $country="COALESCE(p.payload->>'country','')";
        $region="COALESCE(p.payload->>'region','')";
        $city="COALESCE(p.payload->>'city','')";
        $countries=(clone $query)->selectRaw("DISTINCT $country AS country")->whereRaw("$country <> ''")->orderBy('country')->pluck('country');
        if (!empty($input['country'])) $query->whereRaw("LOWER($country)=LOWER(?)",[$input['country']]);
        if (!empty($input['location'])) $query->whereRaw("CONCAT_WS(' ', $country,$region,$city, p.payload->>'location') ILIKE ?",['%'.$input['location'].'%']);
        if (!empty($input['q'])) $query->whereRaw("CONCAT_WS(' ',u.name,p.payload->>'firstName',p.payload->>'lastName',p.payload->>'username') ILIKE ?",['%'.$input['q'].'%']);
        if ($r->boolean('mutual')) $query->whereIn(DB::raw($uid),array_keys($mutual));
        $own=DB::table('firebase_documents')->where('collection_path','user_data')->whereIn('document_id',$ownKeys)->first();
        $profile=$own ? (json_decode($own->payload,true) ?: []) : [];
        // Interleave countries before pagination; a large local population must
        // not fill every page. Daily stable order rotates discovery without
        // random duplicates between consecutive pages on the same day.
        if (empty($input['country']) && empty($input['location']) && !$r->boolean('mutual')) {
            $seed=now()->format('Y-m-d').':'.$self;
            $query->orderByRaw("ROW_NUMBER() OVER (PARTITION BY LOWER($country) ORDER BY md5(u.id::text || ?), u.id)",[$seed]);
            $query->orderByRaw("md5(LOWER($country) || ?)",[$seed]);
        }
        $limit=$input['limit'] ?? 30; $page=$input['page'] ?? 1;
        $rows=$query->selectRaw("u.id, u.name, u.avatar_url, $uid AS uid, $country AS country, $region AS region, $city AS city, p.payload->>'firstName' AS first_name,p.payload->>'lastName' AS last_name,COALESCE(p.payload->>'profileImage',p.payload->>'avatar',p.payload->>'photoURL',u.avatar_url) AS avatar")
            ->orderBy('u.id')->offset(($page-1)*$limit)->limit($limit+1)->get();
        return response()->json(['items'=>$rows->take($limit)->map(fn($u)=>['uid'=>$u->uid,'name'=>$u->name,'displayName'=>$u->name,'firstName'=>$u->first_name,'lastName'=>$u->last_name,'avatar'=>$u->avatar,'profileImage'=>$u->avatar,'country'=>$u->country,'region'=>$u->region,'city'=>$u->city,'mutualCount'=>$mutual[$u->uid] ?? 0])->values(),'has_more'=>$rows->count()>$limit,'page'=>$page,'countries'=>$countries]);
    }
}
