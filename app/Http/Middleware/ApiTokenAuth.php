<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiTokenAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $expectedToken = env('API_TOKEN');
        
        if (!$expectedToken) {
            return response()->json([
                'error' => 'API token not configured'
            ], 500);
        }
        
        $providedToken = $request->header('Authorization');
        
        // Support both "Bearer token" and "token" formats
        if (str_starts_with($providedToken, 'Bearer ')) {
            $providedToken = substr($providedToken, 7);
        }
        
        if (!$providedToken || !hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'error' => 'Invalid API token'
            ], 401);
        }
        
        return $next($request);
    }
}