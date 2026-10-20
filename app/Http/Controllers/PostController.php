<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Post::with(['user:id,name,avatar_url', 'page:id,name,avatar_url'])->where('status', 'published')->latest()->cursorPaginate(min((int) $request->integer('limit', 20), 50)));
    }
    public function show(Post $post): JsonResponse { return response()->json($post->load(['user:id,name,avatar_url', 'page', 'media', 'comments.user:id,name,avatar_url', 'reactions'])); }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['text_content' => ['nullable', 'string'], 'post_type' => ['nullable', 'string', 'max:50'], 'audience' => ['nullable', 'string', 'max:50'], 'page_id' => ['nullable', 'exists:pages,id']]);
        return response()->json($request->user()->posts()->create($data + ['status' => 'published']), 201);
    }
    public function destroy(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $post->delete();
        return response()->json(['success' => true]);
    }
    public function comment(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'parent_id' => ['nullable', 'exists:comments,id']]);
        return response()->json($post->comments()->create($data + ['user_id' => $request->user()->id]), 201);
    }
    public function react(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['reaction_type' => ['required', 'string', 'max:30']]);
        return response()->json(Reaction::updateOrCreate(['user_id' => $request->user()->id, 'post_id' => $post->id], $data));
    }
}
