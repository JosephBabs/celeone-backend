<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) ($request->header('X-Request-ID') ?: 'req_'.Str::ulid());
        $request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        return $response;
    }
}
