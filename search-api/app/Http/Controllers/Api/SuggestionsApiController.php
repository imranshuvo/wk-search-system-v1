<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuggestionsApiController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $rows = DB::table('wk_synonym_suggestions')
            ->where('tenant_id',$tenant)
            ->where('status','suggested')
            ->orderByDesc('score')
            ->limit(500)
            ->get();
        return response()->json(['rows'=>$rows]);
    }

    public function approve(Request $request, int $id)
    {
        $tenant = $request->header('X-Tenant-Id');
        $row = DB::table('wk_synonym_suggestions')->where('tenant_id',$tenant)->where('id',$id)->first();
        if (!$row) return response()->json(['error'=>'Not found'], 404);

        $syn = DB::table('wk_synonyms')->where('tenant_id',$tenant)->first();
        $data = $syn ? json_decode($syn->synonym_data ?? '[]', true) : [];
        if (!is_array($data)) { $data = []; }
        $data[] = [ 'from' => [ (string)$row->from_term ], 'to' => (string)$row->to_term ];
        if ($syn) {
            DB::table('wk_synonyms')->where('tenant_id',$tenant)->update([
                'synonym_data'=>json_encode($data), 'updated_at'=>now()
            ]);
        } else {
            DB::table('wk_synonyms')->insert([
                'tenant_id'=>$tenant, 'synonym_data'=>json_encode($data), 'created_at'=>now(), 'updated_at'=>now()
            ]);
        }
        DB::table('wk_synonym_suggestions')->where('id',$id)->update(['status'=>'approved','updated_at'=>now()]);
        return response()->json(['success'=>true]);
    }

    public function reject(Request $request, int $id)
    {
        $tenant = $request->header('X-Tenant-Id');
        $exists = DB::table('wk_synonym_suggestions')->where('tenant_id',$tenant)->where('id',$id)->exists();
        if (!$exists) return response()->json(['error'=>'Not found'],404);
        DB::table('wk_synonym_suggestions')->where('id',$id)->update(['status'=>'rejected','updated_at'=>now()]);
        return response()->json(['success'=>true]);
    }
}



