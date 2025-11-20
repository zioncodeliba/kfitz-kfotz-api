<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogPluginApiAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('Plugin API request received', [
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
            'has_bearer' => $request->bearerToken() !== null,
        ]);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::warning('Plugin API request terminated before controller', [
                'path' => $request->path(),
                'method' => $request->method(),
                'user_id' => Auth::user()?->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;

        Log::log(
            $status && $status >= 400 ? 'warning' : 'info',
            'Plugin API request completed',
            [
                'path' => $request->path(),
                'method' => $request->method(),
                'user_id' => Auth::user()?->id ?? $request->user()?->id,
                'status' => $status,
            ]
        );

        return $response;
    }
}
