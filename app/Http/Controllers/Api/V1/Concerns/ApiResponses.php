<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiResponses
{
    protected function success(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['ok'=>true,'data'=>$data,'meta'=>array_merge([
            'request_id'=>request()->attributes->get('request_id'),
            'server_time'=>now()->toISOString(),
        ], $meta)], $status);
    }

    protected function page(iterable $items, ?string $nextCursor, bool $hasMore, int $limit): JsonResponse
    {
        return $this->success($items, 200, ['page'=>['next_cursor'=>$nextCursor,'has_more'=>$hasMore,'limit'=>$limit]]);
    }

    protected function failure(string $code, string $message, int $status, array $fields = [], bool $retryable = false): JsonResponse
    {
        return response()->json(['ok'=>false,'error'=>compact('code','message','fields','retryable'),'meta'=>[
            'request_id'=>request()->attributes->get('request_id'),'server_time'=>now()->toISOString(),
        ]], $status);
    }
}
