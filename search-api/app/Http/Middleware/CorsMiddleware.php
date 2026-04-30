<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Handle preflight requests early
        if ($request->isMethod('OPTIONS')) {
            $origin = $request->headers->get('Origin');
            $reqHeaders = $request->headers->get('Access-Control-Request-Headers', 'Content-Type, Authorization, X-Tenant-Id, X-User, X-Nonce, X-Bypass-Cache');
            return response('', 204)
                ->header('Access-Control-Allow-Origin', $origin ?: '')
                ->header('Vary', 'Origin')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', $reqHeaders)
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Add CORS headers to the response
        $origin = $request->headers->get('Origin');
        if ($origin) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
        }
        
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Tenant-Id, X-User, X-Nonce, X-Bypass-Cache');
        $response->header('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
