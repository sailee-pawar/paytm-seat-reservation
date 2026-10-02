<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'message' => 'Admin authorization token is required.',
            ], 401);
        }

        $token = trim(substr($authorization, 7));

        $adminToken = config('app.admin_token');

        if ($token === '' || !hash_equals($adminToken, $token)) {
            return response()->json([
                'message' => 'Invalid admin authorization token.',
            ], 403);
        }

        return $next($request);
    }
}