<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'message' => 'Authorization token is required.',
            ], 401);
        }

        $token = trim(substr($authorization, 7));

        if ($token === '') {
            return response()->json([
                'message' => 'Authorization token is required.',
            ], 401);
        }

        // For this assignment, the token itself represents the user identity.
        $request->attributes->set('user_id', $token);

        return $next($request);
    }
}