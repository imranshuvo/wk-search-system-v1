<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantAuth
{
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();

        $tenantId = $request->header('X-Tenant-Id');
        $apiKey = $this->getBearer($request->header('Authorization'));
        if (empty($tenantId) || empty($apiKey)) {
            return response()->json(['error' => 'Missing authentication headers'], 401);
        }

        $site = DB::table('wk_tenants')->where('tenant_id', $tenantId)->where('api_key', $apiKey)->first();
        if (!$site) {
            return response()->json(['error' => 'Invalid API key or tenant ID'], 401);
        }
        if (($site->status ?? 'active') !== 'active') {
            return response()->json(['error' => 'Site is not active'], 403);
        }

        if (in_array($request->method(), ['POST','PUT','PATCH','DELETE'])) {
            $needsHmac = $this->pathNeedsHmac($path);
            if ($needsHmac && !$this->validateHmac($request, $apiKey)) {
                return response()->json(['error' => 'Invalid HMAC signature'], 401);
            }
        }

        return $next($request);
    }

    private function getBearer(?string $auth): ?string
    {
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    private function pathNeedsHmac(string $path): bool
    {
        foreach (['ingest/', 'track', 'data/products'] as $p) {
            if (str_starts_with('/'.ltrim($path,'/'), '/'.$p)) return true;
        }
        return false;
    }

    private function validateHmac(Request $request, string $secret): bool
    {
        $nonce = $request->header('X-Nonce');
        $timestamp = (int) $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');
        if (!$nonce || !$timestamp || !$signature) return false;
        if (abs(time() - $timestamp) > 300) return false;

        $body = $request->getContent();
        $message = $request->method()."\n".
                   '/'.ltrim($request->path(),'/')."\n".
                   $body."\n".
                   $nonce."\n".
                   $timestamp;
        $expected = hash_hmac('sha256', $message, $secret);
        return hash_equals($expected, $signature);
    }
}
