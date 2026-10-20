<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) $request->getUser();
        $password = (string) $request->getPassword();
        if ($user === '' || $password === '' || ! hash_equals((string) env('ADMIN_USERNAME'), $user) || ! hash_equals((string) env('ADMIN_PASSWORD'), $password)) {
            return response('Admin authentication required.', 401, ['WWW-Authenticate' => 'Basic realm="CeleOne Admin"']);
        }
        return $next($request);
    }
}
