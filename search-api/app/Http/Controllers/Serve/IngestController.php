<?php

namespace App\Http\Controllers\Serve;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngestController extends Controller
{
    public function ingestProducts(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $items = (array) ($request->input('products') ?? []);

        // Also support URL-based ingest for products.json or manifest.json
        $productsUrl = $request->input('products_url');
        $manifestUrl = $request->input('manifest_url');
        if (empty($items) && ($productsUrl || $manifestUrl)) {
            try {
                if ($manifestUrl) {
                    $manifest = json_decode(@file_get_contents($manifestUrl), true) ?: [];
                    if (!empty($manifest['products_url'])) { $productsUrl = $manifest['products_url']; }
                }
                if ($productsUrl) {
                    $json = @file_get_contents($productsUrl);
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) { $items = $decoded; }
                }
            } catch (\Throwable $e) {
                return response()->json(['success'=>false,'error'=>'Failed to fetch products.json','message'=>$e->getMessage()], 400);
            }
        }

        $upserted = 0;
        $skipped = 0;
        foreach ($items as $p) {
            // Skip products with invalid prices
            if (!self::isValidPrice($p['price']??0) || !self::isValidPrice($p['price_old']??0)) {
                \Log::warning("Skipping product with invalid price", [
                    'product_id' => $p['id']??0,
                    'price' => $p['price']??0,
                    'price_old' => $p['price_old']??0,
                    'title' => $p['title']??''
                ]);
                $skipped++;
                continue;
            }

            DB::table('wk_index_products')->updateOrInsert(
                ['tenant_id'=>$tenant,'id'=>$p['id']??0],
                [
                    'sku'=>$p['sku']??null,
                    'title'=>$p['title']??'',
                    'description'=>$p['description']??null,
                    'slug'=>$p['slug']??null,
                    'url'=>$p['url']??null,
                    'brand'=>$p['brand']??null,
                    'price'=>self::sanitizePrice($p['price']??0),
                    'price_old'=>self::sanitizePrice($p['price_old']??0),
                    'currency'=>$p['currency']??'USD',
                    'in_stock'=>isset($p['in_stock'])?(int)$p['in_stock']:1,
                    'rating'=>$p['rating']??0,
                    'image'=>$p['image']??null,
                    'html'=>$p['html']??null,
                    'popularity'=>isset($p['popularity']) ? (int)$p['popularity'] : 0,
                    'view_count'=>isset($p['view_count']) ? (int)$p['view_count'] : 0,
                    'purchase_count'=>isset($p['purchase_count']) ? (int)$p['purchase_count'] : 0,
                    'updated_at'=>now(),
                ]
            );
            $upserted++;
        }
        return response()->json(['success'=>true,'upserted'=>$upserted,'skipped'=>$skipped]);
    }

    public function ingestOrders(Request $request)
    {
        return response()->json(['success'=>true]);
    }

    public function ingestContent(Request $request)
    {
        return response()->json(['success'=>true]);
    }

    public function ingestTopCategoriesUrl(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $url = (string) $request->input('categories_url');
        if (!$url) { return response()->json(['success'=>false,'error'=>'missing categories_url'], 400); }
        try {
            $res = self::importTopCategoriesFromUrl($tenant, $url);
            return response()->json(['success'=>true,'count'=>$res['count'] ?? 0]);
        } catch (\Throwable $e) {
            return response()->json(['success'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    public static function importPopularFromUrl(string $tenant, string $url): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) { throw new \RuntimeException('failed to fetch url'); }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) { throw new \RuntimeException('invalid json'); }
        $upserted = 0;
        foreach ($decoded as $row) {
            $q = trim(strtolower((string)($row['query'] ?? '')));
            if (strlen($q) < 2) { continue; }
            $count = (int) ($row['count'] ?? 0);
            if ($count <= 0) { continue; }
            DB::statement('INSERT INTO wk_search_analytics (tenant_id, `query`, `count`, last_searched) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE `count` = `count` + VALUES(`count`), last_searched = GREATEST(COALESCE(last_searched, VALUES(last_searched)), VALUES(last_searched))', [
                    $tenant,
                    $q,
                    $count,
                    $row['last_searched'] ?? now(),
                ]);
            $upserted++;
        }
        return ['upserted'=>$upserted];
    }

    public static function importTopCategoriesFromUrl(string $tenant, string $url): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) { throw new \RuntimeException('failed to fetch url'); }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) { throw new \RuntimeException('invalid json'); }
        // Ensure table exists
        self::ensureTopCategoriesTable();
        $count = 0;
        foreach ($decoded as $row) {
            $slug = trim((string)($row['slug'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            $c = (int)($row['count'] ?? 0);
            if ($slug === '' || $c <= 0) { continue; }
            DB::statement('INSERT INTO wk_top_categories (tenant_id, slug, name, count, last_updated) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), count = VALUES(count), last_updated = NOW()', [
                $tenant, $slug, $name, $c
            ]);
            $count++;
        }
        return ['count'=>$count];
    }

    private static function ensureTopCategoriesTable(): void
    {
        try {
            $exists = DB::select("SHOW TABLES LIKE 'wk_top_categories'");
            if (!empty($exists)) { return; }
        } catch (\Throwable $e) { /* proceed to attempt create */ }
        DB::statement("CREATE TABLE IF NOT EXISTS wk_top_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(191) NULL,
            count INT NOT NULL DEFAULT 0,
            last_updated DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_tenant_slug (tenant_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function ingestPopularQueries(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $items = (array) ($request->input('items') ?? []);
        $upserted = 0;
        foreach ($items as $row) {
            $q = trim(strtolower((string)($row['query'] ?? '')));
            if (strlen($q) < 2) { continue; }
            $count = (int) ($row['count'] ?? 0);
            if ($count <= 0) { continue; }
            DB::statement('INSERT INTO wk_search_analytics (tenant_id, `query`, `count`, last_searched) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE `count` = `count` + VALUES(`count`), last_searched = GREATEST(COALESCE(last_searched, VALUES(last_searched)), VALUES(last_searched))', [
                    $tenant,
                    $q,
                    $count,
                    $row['last_searched'] ?? now(),
                ]);
            $upserted++;
        }
        return response()->json(['success'=>true,'upserted'=>$upserted]);
    }

    public function ingestPopularQueriesUrl(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $url = (string) $request->input('popular_url');
        if (!$url) { return response()->json(['success'=>false,'error'=>'missing popular_url'], 400); }
        try {
            $res = self::importPopularFromUrl($tenant, $url);
            return response()->json(['success'=>true,'upserted'=>$res['upserted'] ?? 0]);
        } catch (\Throwable $e) {
            return response()->json(['success'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    /**
     * Check if price is valid (not too large or invalid)
     */
    private static function isValidPrice($price) {
        // Allow 0 and empty prices - they are valid
        if ($price === '' || $price === null) {
            return true;
        }
        
        if (!is_numeric($price)) {
            return false;
        }
        
        $price = floatval($price);
        
        // Skip products with prices that are too large for decimal(10,2) column
        $max_price = 1000000;
        
        if ($price > $max_price) {
            return false;
        }
        
        // Allow negative prices - they might be valid (returns, etc.)
        return true;
    }

    /**
     * Sanitize price to prevent database overflow
     * Caps price at 99,999,999.99 to fit decimal(10,2) column
     */
    private static function sanitizePrice($price) {
        // Allow 0 and empty prices - they are valid
        if ($price === '' || $price === null) {
            return 0.00;
        }
        
        if (!is_numeric($price)) {
            return 0.00;
        }
        
        $price = floatval($price);
        
        // Cap at maximum value for decimal(10,2) column
        $max_price = 1000000;
        
        if ($price > $max_price) {
            \Log::warning("Price too large, capping: {$price} -> {$max_price}");
            return $max_price;
        }
        
        // Allow negative prices - they might be valid (returns, etc.)
        return round($price, 2);
    }
}
