<?php

namespace App\Http\Controllers\Serve;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrackingController extends Controller
{
    public function track(Request $request)
    {
        // Support both header-based auth (regular requests) and body-based auth (sendBeacon)
        $tenant = $request->header('X-Tenant-Id') ?? $request->input('tenant_id');
        $apiKey = $request->header('Authorization') ?? $request->input('api_key');
        $userId = $request->header('X-User') ?? $request->input('user_id') ?? 'default';
        
        // For sendBeacon requests, we need to validate the API key from the body
        if (!$request->header('X-Tenant-Id') && $apiKey) {
            // Extract API key from "Bearer token" format or use as-is
            $apiKey = str_replace('Bearer ', '', $apiKey);
            
            // Validate API key against wk_sites table
            $site = DB::table('wk_tenants')->where('api_key', $apiKey)->first();
            if (!$site) {
                return response()->json(['error' => 'Invalid API key'], 401);
            }
            $tenant = $site->tenant_id;
        }
        
        if (!$tenant) {
            return response()->json(['error' => 'Missing tenant ID'], 400);
        }
        
        $eventType = (string)($request->input('event_type') ?? 'unknown');
        $eventData = $request->input('event_data') ?? null;
        
        DB::table('wk_events')->insert([
            'tenant_id' => $tenant,
            'user_id' => $userId,
            'event_type' => $eventType,
            'event_data' => $eventData ? json_encode($eventData) : null,
            'created_at' => now(),
        ]);
        
        if ($eventType === 'search' && isset($eventData['query'])) {
            $q = (string)$eventData['query'];
            DB::statement('INSERT INTO wk_search_analytics (tenant_id, `query`, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1, last_searched = CURRENT_TIMESTAMP', [$tenant, $q]);
        }
        
        return response()->json(['success'=>true]);
    }
}
