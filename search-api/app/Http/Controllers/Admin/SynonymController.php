<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Synonym;
use Illuminate\Http\Request;

class SynonymController extends Controller
{
    public function edit(string $tenant_id)
    {
        $row = Synonym::find($tenant_id);
        $data = $row ? $row->synonym_data : [];
        return view('admin.merch.synonyms', ['tenant_id'=>$tenant_id, 'data'=>$data]);
    }

    public function update(Request $request, string $tenant_id)
    {
        $payload = $request->validate([
            'synonym_json' => 'nullable|string'
        ]);
        $json = $payload['synonym_json'] ?? '[]';
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return back()->withErrors(['synonym_json'=>'Invalid JSON'])->withInput();
        }
        Synonym::updateOrCreate(['tenant_id'=>$tenant_id], ['synonym_data'=>$data]);
        return back()->with('ok','Synonyms saved');
    }
}


