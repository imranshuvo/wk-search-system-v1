<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Filesystem\Filesystem;

class HealthController extends Controller
{
    public function healthz()
    {
        return response()->json(['status' => 'ok', 'timestamp' => time()]);
    }

    public function readyz()
    {
        $dbOk = false; $cacheOk = false; $indexOk = false;
        try { DB::select('SELECT 1'); $dbOk = true; } catch(\Throwable $e) { $dbOk = false; }
        try { cache()->set('readyz_probe','ok',10); $cacheOk = cache()->get('readyz_probe')==='ok'; } catch(\Throwable $e) { $cacheOk=false; }
        $tntPath = env('TNT_INDEX_PATH', storage_path('tnt_indexes'));
        $indexOk = is_dir($tntPath);
        $checks = ['database'=>$dbOk,'cache'=>$cacheOk,'search_index'=>$indexOk];
        $all = $dbOk && $cacheOk && $indexOk;
        return response()->json(['status'=>$all?'ready':'not ready','checks'=>$checks], $all?200:503);
    }
}


