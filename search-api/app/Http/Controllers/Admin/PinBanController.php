<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PinBanController extends Controller
{
    public function index(string $tenant_id)
    {
        $pins = DB::table('wk_pins')->where('tenant_id',$tenant_id)->orderByDesc('updated_at')->limit(200)->get();
        $bans = DB::table('wk_bans')->where('tenant_id',$tenant_id)->orderByDesc('updated_at')->limit(200)->get();
        return view('admin.merch.pins_bans', compact('tenant_id','pins','bans'));
    }

    public function addPin(Request $request, string $tenant_id)
    {
        $data = $request->validate([
            'query' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'position' => 'nullable|integer'
        ]);
        DB::table('wk_pins')->insert([
            'tenant_id'=>$tenant_id,
            'query'=>$data['query'],
            'product_id'=>$data['product_id'],
            'position'=>$data['position'] ?? 1,
            'active'=>1,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
        return back()->with('ok','Pin added');
    }

    public function removePin(string $tenant_id, int $id)
    {
        DB::table('wk_pins')->where('tenant_id',$tenant_id)->where('id',$id)->delete();
        return back()->with('ok','Pin removed');
    }

    public function addBan(Request $request, string $tenant_id)
    {
        $data = $request->validate([
            'query' => 'required|string|max:255',
            'product_id' => 'required|integer'
        ]);
        DB::table('wk_bans')->insert([
            'tenant_id'=>$tenant_id,
            'query'=>$data['query'],
            'product_id'=>$data['product_id'],
            'active'=>1,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
        return back()->with('ok','Ban added');
    }

    public function removeBan(string $tenant_id, int $id)
    {
        DB::table('wk_bans')->where('tenant_id',$tenant_id)->where('id',$id)->delete();
        return back()->with('ok','Ban removed');
    }
}


