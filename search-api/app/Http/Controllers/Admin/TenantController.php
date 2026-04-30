<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Serve\IngestController as ServeIngest;

class TenantController extends Controller
{
    public function index()
    {
        $sites = Tenant::orderBy('created_at','desc')->limit(100)->get();
        return view('admin.tenants.index', compact('sites'));
    }

    public function create()
    {
        return view('admin.tenants.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:500',
            'status' => 'nullable|in:active,inactive,suspended',
        ]);
        
        $apiKey = 'wk_' . Str::random(64);
        $site = Tenant::create([
            'site_name' => $data['site_name'],
            'site_url' => rtrim($data['site_url'],'/'),
            'status' => $data['status'] ?? 'active',
            'api_key' => $apiKey,
            'feed_frequency' => 'hourly',
            'settings' => [],
            'search_config' => [],
        ]);
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant created with ID: ' . $site->tenant_id);
    }

    public function edit(string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        return view('admin.tenants.edit', compact('site'));
    }

    public function update(Request $request, string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        $data = $request->validate([
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:500',
            'status' => 'required|in:active,inactive,suspended',
            'feed_file_url' => 'nullable|url|max:1000',
            'popular_url' => 'nullable|url|max:1000',
            'top_categories_url' => 'nullable|url|max:1000',
        ]);
        $site->update([
            'site_name' => $data['site_name'],
            'site_url' => rtrim($data['site_url'],'/'),
            'status' => $data['status'],
        ]);
        
        // Settings is automatically cast to array by Eloquent
        $settings = $site->settings ?? [];
        
        if (!empty($data['feed_file_url'])) {
            $settings['feed_file_url'] = rtrim($data['feed_file_url']);
        } else {
            unset($settings['feed_file_url']);
        }
        if (!empty($data['popular_url'])) {
            $settings['popular_url'] = rtrim($data['popular_url']);
        } else {
            unset($settings['popular_url']);
        }
        if (!empty($data['top_categories_url'])) {
            $settings['top_categories_url'] = rtrim($data['top_categories_url']);
        } else {
            unset($settings['top_categories_url']);
        }
        
        // Eloquent will automatically json_encode when saving
        $site->settings = $settings;
        $site->save();
        return back()->with('ok','Saved');
    }

    public function destroy(string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        $site->delete();
        return redirect()->route('admin.tenants.index')->with('ok','Deleted');
    }

    public function regenerate(string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        $newKey = 'wk_'.Str::random(64);
        $site->api_key = $newKey;
        $site->save();
        return back()->with('ok', 'API key regenerated: '.$newKey);
    }

    public function sync(string $tenant_id, Request $request)
    {
        $site = Tenant::findOrFail($tenant_id);
        $full = (bool) $request->input('full', false);
        $params = [
            '--tenant' => $tenant_id
        ];
        if ($full) { $params['--full'] = true; }
        Artisan::call('feeds:sync', $params);
        $output = Artisan::output();
        return back()->with('ok', 'Feed sync triggered'.($full?' (full)':'').'. Output: '.trim($output));
    }

    public function uploadFeed(string $tenant_id, Request $request)
    {
        $site = Tenant::findOrFail($tenant_id);
        $request->validate([
            'feed_file' => 'required|file|mimetypes:application/json,text/plain,application/octet-stream|max:51200'
        ]);
        $json = file_get_contents($request->file('feed_file')->getRealPath());
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return back()->withErrors(['feed_file' => 'JSON decode error: ' . json_last_error_msg()]);
        }
        // Support products.json (array) or full.json ({ products: [] })
        if (is_array($data) && array_is_list($data)) {
            $products = $data;
        } elseif (is_array($data) && isset($data['products']) && is_array($data['products'])) {
            $products = $data['products'];
        } else {
            return back()->withErrors(['feed_file' => 'Unsupported feed format. Provide an array of products or an object with a products[] key.']);
        }
        // Import directly with light throttling
        $count = 0; $chunkSize = 500;
        foreach ($products as $p) {
            \Illuminate\Support\Facades\DB::table('wk_index_products')->updateOrInsert(
                ['tenant_id'=>$tenant_id,'id'=>(int)($p['id'] ?? 0)],
                [
                    'sku'=>$p['sku'] ?? null,
                    'title'=>$p['title'] ?? ($p['name'] ?? ''),
                    'slug'=>$p['slug'] ?? null,
                    'url'=>$p['url'] ?? null,
                    'brand'=>$p['brand'] ?? null,
                    'price'=>isset($p['price']) ? (float)$p['price'] : 0,
                    'price_old'=>isset($p['price_old']) ? (float)$p['price_old'] : 0,
                    'currency'=>$p['currency'] ?? 'USD',
                    'in_stock'=>isset($p['in_stock']) ? (int)$p['in_stock'] : 1,
                    'rating'=>isset($p['rating']) ? (float)$p['rating'] : 0,
                    'image'=>$p['image'] ?? null,
                    'html'=>$p['html'] ?? null,
                    'popularity'=>isset($p['popularity']) ? (int)$p['popularity'] : 0,
                    'view_count'=>isset($p['view_count']) ? (int)$p['view_count'] : 0,
                    'purchase_count'=>isset($p['purchase_count']) ? (int)$p['purchase_count'] : 0,
                    'updated_at'=>now(),
                ]
            );
            $count++;
            if ($count % $chunkSize === 0) { usleep(50000); }
        }
        return back()->with('ok', 'Uploaded feed imported: '.$count.' products');
    }

    public function syncPopularUrl(string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        $settings = $site->settings ?? [];
        $url = $settings['popular_url'] ?? '';
        if (empty($url)) { return back()->withErrors(['popular_url'=>'Set Popular Searches URL then try again.']); }
        try {
            $res = ServeIngest::importPopularFromUrl($tenant_id, $url);
            return back()->with('ok', 'Popular searches synced from URL ('.$res['upserted'].' entries)');
        } catch (\Throwable $e) {
            return back()->withErrors(['popular_url' => 'Sync failed: '.$e->getMessage()]);
        }
    }

    public function syncTopCategoriesUrl(string $tenant_id)
    {
        $site = Tenant::findOrFail($tenant_id);
        $settings = $site->settings ?? [];
        $url = $settings['top_categories_url'] ?? '';
        if (empty($url)) { return back()->withErrors(['top_categories_url'=>'Set Top Categories URL then try again.']); }
        try {
            $res = ServeIngest::importTopCategoriesFromUrl($tenant_id, $url);
            return back()->with('ok', 'Top categories synced from URL ('.$res['count'].' entries)');
        } catch (\Throwable $e) {
            return back()->withErrors(['top_categories_url' => 'Sync failed: '.$e->getMessage()]);
        }
    }
}


