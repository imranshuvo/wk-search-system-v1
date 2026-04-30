<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function topQueries(string $tenant_id)
    {
        $rows = DB::table('wk_search_analytics')->where('tenant_id',$tenant_id)->orderByDesc('count')->limit(100)->get();
        return view('admin.analytics.top_queries', compact('tenant_id','rows'));
    }

    public function zeroResults(string $tenant_id)
    {
        $rows = DB::table('wk_search_analytics')->where('tenant_id',$tenant_id)->where('count','>',0)->orderByDesc('last_searched')->limit(100)->get();
        return view('admin.analytics.zero_results', compact('tenant_id','rows'));
    }

    public function performance(string $tenant_id)
    {
        $rows = DB::table('wk_analytics_hourly')->where('tenant_id',$tenant_id)->where('event_type','search_perf')->orderByDesc('date')->orderByDesc('hour')->limit(48)->get();
        return view('admin.analytics.performance', compact('tenant_id','rows'));
    }
}


