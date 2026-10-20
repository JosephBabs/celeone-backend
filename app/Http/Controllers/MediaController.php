<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:1228800'], 'type' => ['required', 'in:image,video,audio,document'], 'post_id' => ['nullable', 'exists:posts,id']]);
        $file = $data['file'];
        $path = $file->storeAs('uploads/'.$data['type'].'s', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $asset = MediaAsset::create(['user_id' => $request->user()->id, 'post_id' => $data['post_id'] ?? null, 'type' => $data['type'], 'url' => Storage::disk('public')->url($path), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
        return response()->json(['success' => true, 'asset' => $asset], 201);
    }
}
