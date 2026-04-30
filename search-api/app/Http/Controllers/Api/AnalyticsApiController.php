<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsApiController extends Controller
{
    public function topQueries(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        [$start,$end] = $this->parseDateRange($request);
        $q = DB::table('wk_search_analytics')->where('tenant_id',$tenant);
        if ($start && $end) { $q->whereBetween('last_searched', [$start, $end]); }
        $rows = $q->orderByDesc('count')->limit(100)->get();
        return response()->json(['rows'=>$rows]);
    }

    public function zeroResults(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        [$start,$end] = $this->parseDateRange($request);
        $q = DB::table('wk_search_analytics')->where('tenant_id',$tenant)->where('count','>',0);
        if ($start && $end) { $q->whereBetween('last_searched', [$start, $end]); }
        $rows = $q->orderByDesc('last_searched')->limit(100)->get();
        return response()->json(['rows'=>$rows]);
    }

    public function performance(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        [$start,$end] = $this->parseDateRange($request);
        $q = DB::table('wk_analytics_hourly')->where('tenant_id',$tenant)->where('event_type','search_perf');
        if ($start && $end) { $q->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')]); }
        $rows = $q->orderByDesc('date')->orderByDesc('hour')->limit(500)->get();
        return response()->json(['rows'=>$rows]);
    }

    private function parseDateRange(Request $request): array
    {
        $start = $request->query('start');
        $end = $request->query('end');
        try {
            if ($start && $end) {
                $s = new \DateTime($start);
                $e = new \DateTime($end);
                if ($s > $e) { [$s,$e] = [$e,$s]; }
                // normalize end to end of day
                $e->setTime(23,59,59);
                return [$s, $e];
            }
        } catch (\Throwable $e) {}
        // default last 30 days
        $e = new \DateTime('now');
        $s = (clone $e)->modify('-30 days');
        return [$s,$e];
    }
}


