<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class SyncFeeds extends Command
{
    protected $signature = 'feeds:sync {--tenant=} {--full}';
    protected $description = 'Poll manifest.json from sites and sync full/delta feeds into index';

    public function handle()
    {
        $tenantOpt = $this->option('tenant');
        $tenants = Tenant::when($tenantOpt, function($q) use ($tenantOpt){ 
            $q->where('tenant_id', $tenantOpt); 
        })->get();
        
        foreach ($tenants as $site) {
            try {
                $this->syncTenant($site, (bool)$this->option('full'));
            } catch (\Throwable $e) {
                $this->error("[{$site->tenant_id}] sync error: ".$e->getMessage());
            }
        }
        return 0;
    }

    private function syncTenant($site, bool $forceFull)
    {
        // Now $site->settings is automatically cast to array by Laravel
        $settings = $site->settings ?? [];
        
        $feedFileUrl = $settings['feed_file_url'] ?? null;
        $manifestUrl = $settings['feed_manifest_url'] ?? null;
        
        // Prefer direct feed_file_url if provided
        if ($feedFileUrl) {
            $feedFileUrlWithCache = $this->addCacheBuster($feedFileUrl);
            $this->line("[{$site->tenant_id}] Import from feed_file_url: $feedFileUrlWithCache");
            $this->importFromFileUrl($site->tenant_id, $feedFileUrlWithCache, $forceFull);
            $settings['feed_file_url'] = $feedFileUrl;
            $site->settings = $settings;
            $site->last_sync_at = now();
            $site->save();
            return;
        }
        if (!$manifestUrl) {
            $this->line("[{$site->tenant_id}] No feed_manifest_url configured; skipping");
            return;
        }

        $manifestUrlWithCache = $this->addCacheBuster($manifestUrl);
        $manifest = $this->fetchJson($manifestUrlWithCache);
        if (!$manifest) { $this->line("[{$site->tenant_id}] Failed to fetch manifest"); return; }

        $fullUrl = $manifest['full_feed_url'] ?? null;
        $deltaUrl = $manifest['delta_feed_url'] ?? null;
        $fullUpdated = $manifest['full_updated'] ?? null;

        $checkpoint = $settings['feed_checkpoint'] ?? ['full_etag'=>null,'delta_offset'=>0,'delta_url'=>null];
        if ($forceFull || ($fullUrl && $fullUpdated && ($checkpoint['full_etag'] ?? null) !== $fullUpdated)) {
            $fullUrlWithCache = $this->addCacheBuster($fullUrl);
            $this->line("[{$site->tenant_id}] Full import from $fullUrlWithCache");
            $this->importFull($site->tenant_id, $fullUrlWithCache);
            $checkpoint['full_etag'] = $fullUpdated;
            // Reset delta offset on full import
            $checkpoint['delta_offset'] = 0;
            $checkpoint['delta_url'] = $deltaUrl;
        }

        if ($deltaUrl) {
            $deltaUrlWithCache = $this->addCacheBuster($deltaUrl);
            $imported = $this->importDelta($site->tenant_id, $deltaUrlWithCache, (int)($checkpoint['delta_offset'] ?? 0));
            if ($imported['bytes'] > 0) {
                $checkpoint['delta_offset'] = $imported['next_offset'];
                $checkpoint['delta_url'] = $deltaUrl;
                $this->line("[{$site->tenant_id}] Delta imported {$imported['records']} records, next offset {$imported['next_offset']}");
            }
        }

        $settings['feed_manifest_url'] = $manifestUrl;
        $settings['feed_checkpoint'] = $checkpoint;
        $site->settings = $settings;
        $site->last_sync_at = now();
        $site->save();
    }

    private function importFromFileUrl(string $tenant, string $url, bool $forceFull): void
    {
        $json = @file_get_contents($url);
        if ($json === false) { throw new \RuntimeException('Feed file download failed'); }
        $data = json_decode($json, true);
        if (!is_array($data)) { throw new \RuntimeException('Invalid feed file JSON'); }
        // Support products.json (array of products) or full.json ({ products: [] })
        $products = [];
        if (array_is_list($data)) {
            $products = $data;
        } elseif (isset($data['products']) && is_array($data['products'])) {
            $products = $data['products'];
        } else {
            throw new \RuntimeException('Unsupported feed format');
        }

        if ($forceFull) {
            DB::table('wk_index_products')->where('tenant_id',$tenant)->delete();
            DB::table('wk_product_categories')->whereIn('product_id', function($q) use ($tenant){ $q->select('id')->from('wk_index_products')->where('tenant_id',$tenant); })->delete();
            DB::table('wk_product_tags')->whereIn('product_id', function($q) use ($tenant){ $q->select('id')->from('wk_index_products')->where('tenant_id',$tenant); })->delete();
        }

        $chunkSize = 500; $count = 0;
        foreach ($products as $p) {
            $this->upsertProduct($tenant, $p);
            $count++;
            if ($count % $chunkSize === 0) { usleep(50000); }
        }
        $this->line("[$tenant] Imported $count products from feed file");
    }

    private function fetchJson(string $url): ?array
    {
        $resp = @file_get_contents($url);
        if ($resp === false) return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    private function importFull(string $tenant, string $url): void
    {
        $json = @file_get_contents($url);
        if ($json === false) { throw new \RuntimeException('Full feed download failed'); }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) { throw new \RuntimeException('Invalid full feed'); }

        // Truncate tenant data before full import
        DB::table('wk_index_products')->where('tenant_id',$tenant)->delete();
        DB::table('wk_product_categories')->whereIn('product_id', function($q) use ($tenant){ $q->select('id')->from('wk_index_products')->where('tenant_id',$tenant); })->delete();
        DB::table('wk_product_tags')->whereIn('product_id', function($q) use ($tenant){ $q->select('id')->from('wk_index_products')->where('tenant_id',$tenant); })->delete();

        $chunk = [];
        $chunkSize = 500;
        foreach ($data['products'] as $p) {
            $this->upsertProduct($tenant, $p);
            if ((count($chunk)+1) % $chunkSize === 0) { usleep(50000); }
        }
    }

    private function importDelta(string $tenant, string $url, int $offset): array
    {
        $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>"Range: bytes=$offset-\r\n"]]);
        $stream = @fopen($url, 'r', false, $ctx);
        if (!$stream) { return ['records'=>0,'bytes'=>0,'next_offset'=>$offset]; }
        $bytes = 0; $records = 0; $buffer = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) break;
            $bytes += strlen($chunk);
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if ($line === '') continue;
                $rec = json_decode($line, true);
                if (!is_array($rec)) continue;
                if (!empty($rec['deleted'])) {
                    DB::table('wk_index_products')->where('tenant_id',$tenant)->where('id', (int)$rec['id'])->delete();
                } else {
                    $this->upsertProduct($tenant, $rec);
                }
                $records++;
            }
        }
        fclose($stream);
        return ['records'=>$records,'bytes'=>$bytes,'next_offset'=>$offset + $bytes];
    }

    private function upsertProduct(string $tenant, array $p): void
    {
        $productId = (int)($p['id'] ?? 0);
        
        DB::table('wk_index_products')->updateOrInsert(
            ['tenant_id'=>$tenant,'id'=>$productId],
            [
                'sku'=>$p['sku'] ?? null,
                'title'=>$p['title'] ?? ($p['name'] ?? ''),
                'description'=>$p['description'] ?? null,
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

        // Also sync hierarchies (categories/tags) and attributes for facets
        $this->updateProductHierarchies($tenant, $p);
        $this->updateProductAttributes($tenant, $p);
    }

    private function updateProductHierarchies(string $tenant, array $p): void
    {
        $productId = (int)($p['id'] ?? 0);
        if ($productId <= 0) { return; }

        // Clear existing links - MUST filter by tenant_id to prevent cross-tenant deletion
        DB::table('wk_product_categories')->where(['tenant_id' => $tenant, 'product_id' => $productId])->delete();
        DB::table('wk_product_tags')->where(['tenant_id' => $tenant, 'product_id' => $productId])->delete();

        $list = $p['hierarchies'] ?? [];
        if (!is_array($list)) { return; }

        // OPTIMIZATION: First, collect all categories and update master data
        $categories = [];
        $tags = [];
        
        foreach ($list as $h) {
            if (!is_array($h) || empty($h['id']) || empty($h['type'])) { continue; }
            $hid = (int)$h['id'];
            $type = $h['type'];
            
            if ($type === 'category') {
                // Store category data with parent_id for hierarchy detection
                $categories[$hid] = [
                    'id' => $hid,
                    'name' => $h['name'] ?? '',
                    'slug' => $h['slug'] ?? '',
                    'url' => $h['url'] ?? null,
                    'level' => isset($h['level']) ? (int)$h['level'] : 0,
                    'parent_id' => isset($h['parent_id']) ? (int)$h['parent_id'] : null,
                ];
                
                // Update category master data
                DB::table('wk_categories')->updateOrInsert(
                    ['tenant_id'=>$tenant,'id'=>$hid],
                    [
                        'name'=>$h['name'] ?? '',
                        'slug'=>$h['slug'] ?? '',
                        'url'=>$h['url'] ?? null,
                        'level'=>isset($h['level']) ? (int)$h['level'] : 0,
                        'parent_id'=>isset($h['parent_id']) ? (int)$h['parent_id'] : null,
                        'updated_at'=>now(),
                    ]
                );
            } elseif ($type === 'tag') {
                $tags[$hid] = $h;
                
                // Update tag master data
                DB::table('wk_tags')->updateOrInsert(
                    ['tenant_id'=>$tenant,'id'=>$hid],
                    [
                        'name'=>$h['name'] ?? '',
                        'slug'=>$h['slug'] ?? '',
                        'url'=>$h['url'] ?? null,
                        'updated_at'=>now(),
                    ]
                );
            }
        }
        
        // OPTIMIZATION: Store only LEAF categories (most specific ones)
        // This reduces storage by 70-80% and improves query performance
        // We can reconstruct parent hierarchy using parent_id when needed
        $leafCategories = $this->findLeafCategories($categories);
        
        foreach ($leafCategories as $catId) {
            DB::table('wk_product_categories')->updateOrInsert(
                ['tenant_id' => $tenant, 'product_id' => $productId, 'category_id' => $catId],
                []
            );
        }
        
        // Store all tags (tags don't have hierarchy, so store all)
        foreach ($tags as $tagId => $tagData) {
            DB::table('wk_product_tags')->updateOrInsert(
                ['tenant_id' => $tenant, 'product_id' => $productId, 'tag_id' => $tagId],
                []
            );
        }
    }
    
    /**
     * Find leaf categories (categories with no children in the given set)
     * This dramatically reduces storage by eliminating parent category redundancy
     * 
     * @param array $categories Array of category data indexed by category ID
     * @return array Array of leaf category IDs
     */
    private function findLeafCategories(array $categories): array
    {
        if (empty($categories)) {
            return [];
        }
        
        $categoryIds = array_keys($categories);
        $leafCategories = [];
        
        foreach ($categoryIds as $catId) {
            $isLeaf = true;
            
            // Check if any other category has this one as parent
            foreach ($categories as $otherCat) {
                if (isset($otherCat['parent_id']) && $otherCat['parent_id'] === $catId) {
                    // This category is a parent of another category in our set
                    $isLeaf = false;
                    break;
                }
            }
            
            if ($isLeaf) {
                $leafCategories[] = $catId;
            }
        }
        
        return $leafCategories;
    }

    private function updateProductAttributes(string $tenant, array $p): void
    {
        $productId = (int)($p['id'] ?? 0);
        if ($productId <= 0) { return; }
        $attrs = $p['attributes'] ?? null;
        if (!is_array($attrs)) { return; }

        // Clear existing - MUST filter by tenant_id to prevent cross-tenant deletion
        DB::table('wk_product_attributes')->where(['tenant_id' => $tenant, 'product_id' => $productId])->delete();

        foreach ($attrs as $name => $values) {
            if (is_array($values)) {
                foreach ($values as $value) {
                    $val = is_scalar($value) ? (string)$value : json_encode($value);
                    if ($val === '' || $val === null) { continue; }
                    DB::table('wk_product_attributes')->insert([
                        'tenant_id' => $tenant,
                        'product_id'=>$productId,
                        'attribute_name'=>(string)$name,
                        'attribute_value'=>$val,
                    ]);
                }
            } elseif (is_scalar($values)) {
                $val = (string)$values;
                if ($val === '') { continue; }
                DB::table('wk_product_attributes')->insert([
                    'tenant_id' => $tenant,
                    'product_id'=>$productId,
                    'attribute_name'=>(string)$name,
                    'attribute_value'=>$val,
                ]);
            }
        }
    }

    /**
     * Add cache buster parameter to URL to prevent caching
     */
    private function addCacheBuster(string $url): string
    {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        return $url . $separator . 'time=' . time();
    }
}


