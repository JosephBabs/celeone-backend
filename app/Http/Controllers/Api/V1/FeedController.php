<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Feed\{FeedService,FeedInteractions};
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function items(Request $request, FeedService $feed)
    {
        $data=$request->validate(['ids'=>'required|array|max:50','ids.*'=>'string|max:191']);
        return response()->json(['ok'=>true,'data'=>$feed->items($request->user(),array_values(array_unique($data['ids'])))]);
    }

    public function index(Request $request, FeedService $feed)
    {
        $data=$request->validate(['cursor'=>'nullable|uuid','limit'=>'sometimes|integer|min:1|max:50']);
        $page=$feed->page($request->user(),$data['cursor'] ?? null,(int)($data['limit'] ?? 20));
        return response()->json(['ok'=>true,'data'=>$page['items'],'nextCursor'=>$page['nextCursor'],'hasMore'=>$page['hasMore'],'snapshotAt'=>$page['snapshotAt']]);
    }

    public function interactions(Request $request, FeedInteractions $interactions)
    {
        $data=$request->validate([
            'actorUid'=>'sometimes|string|max:191',
            'events'=>'required|array|min:1|max:50',
            'events.*.eventId'=>'required|string|max:80|regex:/^[A-Za-z0-9_-]+$/',
            'events.*.postId'=>'required|string|max:191',
            'events.*.type'=>'required|in:VIEW,CLICK,LIKE,COMMENT,SHARE,SAVE,WATCH,SKIP',
            'events.*.durationMs'=>'sometimes|integer|min:0|max:300000',
        ]);
        if (isset($data['actorUid'])) {
            $user=$request->user();
            abort_unless($data['actorUid']===(string)($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id),403,'Session changed; retry with the current account.');
        }
        return response()->json(['ok'=>true,'accepted'=>$interactions->record($request->user(),$data['events'])]);
    }
}
