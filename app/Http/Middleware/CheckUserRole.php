<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // בדיקה אם למשתמש יש לפחות אחד מהתפקידים הנדרשים
        $hasRole = in_array($user->role, $roles, true);

        if (!$hasRole) {
            return response()->json(['message' => 'Insufficient permissions'], 403);
        }

        return $next($request);
    }
}
