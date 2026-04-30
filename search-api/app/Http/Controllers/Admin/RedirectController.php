<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedirectController extends Controller
{
    public function index(string $tenant_id)
    {
        $rows = DB::table('wk_redirects')->where('tenant_id',$tenant_id)->orderByDesc('updated_at')->limit(200)->get();
        return view('admin.merch.redirects', compact('tenant_id','rows'));
    }

    public function store(Request $request, string $tenant_id)
    {
        $data = $request->validate([
            'query' => 'required|string|max:255',
            'url' => 'required|url|max:500',
            'active' => 'nullable|boolean'
        ]);
        DB::table('wk_redirects')->insert([
            'tenant_id'=>$tenant_id,
            'query'=>$data['query'],
            'url'=>$data['url'],
            'active'=> $request->boolean('active', true),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
        return back()->with('ok','Redirect added');
    }

    public function update(Request $request, string $tenant_id, int $id)
    {
        $data = $request->validate([
            'url' => 'required|url|max:500',
            'active' => 'nullable|boolean'
        ]);
        DB::table('wk_redirects')->where('id',$id)->where('tenant_id',$tenant_id)->update([
            'url'=>$data['url'],
            'active'=>$request->boolean('active', true),
            'updated_at'=>now(),
        ]);
        return back()->with('ok','Updated');
    }

    public function destroy(string $tenant_id, int $id)
    {
        DB::table('wk_redirects')->where('id',$id)->where('tenant_id',$tenant_id)->delete();
        return back()->with('ok','Deleted');
    }
}


