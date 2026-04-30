<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SuggestionController extends Controller
{
    public function index(string $tenant_id)
    {
        $rows = DB::table('wk_synonym_suggestions')
            ->where('tenant_id', $tenant_id)
            ->where('status', 'suggested')
            ->orderByDesc('score')
            ->limit(500)
            ->get();
        return view('admin.merch.suggestions', compact('tenant_id','rows'));
    }

    public function approve(string $tenant_id, int $id)
    {
        $row = DB::table('wk_synonym_suggestions')->where('tenant_id',$tenant_id)->where('id',$id)->first();
        if ($row) {
            // merge into wk_synonyms
            $syn = DB::table('wk_synonyms')->where('tenant_id',$tenant_id)->first();
            $data = $syn ? json_decode($syn->synonym_data ?? '[]', true) : [];
            if (!is_array($data)) { $data = []; }
            $data[] = [ 'from' => [ (string)$row->from_term ], 'to' => (string)$row->to_term ];
            if ($syn) {
                DB::table('wk_synonyms')->where('tenant_id',$tenant_id)->update([
                    'synonym_data' => json_encode($data), 'updated_at'=>now()
                ]);
            } else {
                DB::table('wk_synonyms')->insert([
                    'tenant_id'=>$tenant_id, 'synonym_data'=> json_encode($data), 'updated_at'=>now(), 'created_at'=>now()
                ]);
            }
            DB::table('wk_synonym_suggestions')->where('id',$id)->update(['status'=>'approved','updated_at'=>now()]);
        }
        return back()->with('ok','Suggestion approved');
    }

    public function reject(string $tenant_id, int $id)
    {
        DB::table('wk_synonym_suggestions')->where('tenant_id',$tenant_id)->where('id',$id)->update(['status'=>'rejected','updated_at'=>now()]);
        return back()->with('ok','Suggestion rejected');
    }
}


