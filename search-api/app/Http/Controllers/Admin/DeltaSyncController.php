<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeltaSyncController
{
    /**
     * Upsert a single product (create or update)
     * POST /api/admin/delta-sync/upsert
     */
    public function upsert(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $apiKey = $request->bearerToken();
        
        // Validate tenant and API key
        $tenantRecord = DB::table('wk_tenants')
            ->where('tenant_id', $tenant)
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();
            
        if (!$tenantRecord) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $product = $request->input('product');
        
        if (!$product || !isset($product['id'])) {
            return response()->json(['error' => 'Product data required'], 400);
        }
        
        try {
            DB::beginTransaction();
            
            // Extract all fields
            $data = [
                'tenant_id' => $tenant,
                'id' => $product['id'],
                'sku' => $product['sku'] ?? '',
                'title' => $product['title'] ?? '',
                'description' => $product['description'] ?? '',
                'slug' => $product['slug'] ?? '',
                'url' => $product['url'] ?? '',
                'brand' => $product['brand'] ?? '',
                'image' => $product['image'] ?? null,
                'price' => isset($product['price']) ? (float)$product['price'] : 0.0,
                'price_old' => isset($product['price_old']) ? (float)$product['price_old'] : null,
                'currency' => $product['currency'] ?? 'USD',
                'in_stock' => isset($product['in_stock']) ? (int)$product['in_stock'] : 1,
                'rating' => isset($product['rating']) ? (float)$product['rating'] : 0.0,
                'popularity' => isset($product['popularity']) ? (int)$product['popularity'] : 0,
                'html' => $product['html'] ?? null,
                'updated_at' => now(),
            ];
            
            // Check if product exists to set created_at only for new products
            $exists = DB::table('wk_index_products')
                ->where('tenant_id', $tenant)
                ->where('id', $product['id'])
                ->exists();
            
            if (!$exists) {
                // Use provided created_at from WordPress, fallback to now()
                // Parse ISO 8601 datetime with timezone to MySQL datetime format
                if (isset($product['created_at'])) {
                    try {
                        $data['created_at'] = \Carbon\Carbon::parse($product['created_at'])->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $data['created_at'] = now();
                    }
                } else {
                    $data['created_at'] = now();
                }
            }
            
            // Upsert main product
            DB::table('wk_index_products')
                ->updateOrInsert(
                    ['tenant_id' => $tenant, 'id' => $product['id']],
                    $data
                );
            
            // Delete existing categories, tags, and attributes for this product
            DB::table('wk_product_categories')->where('tenant_id', $tenant)->where('product_id', $product['id'])->delete();
            DB::table('wk_product_tags')->where('tenant_id', $tenant)->where('product_id', $product['id'])->delete();
            DB::table('wk_product_attributes')->where('tenant_id', $tenant)->where('product_id', $product['id'])->delete();
            
            // Insert hierarchies (categories and tags)
            if (!empty($product['hierarchies']) && is_array($product['hierarchies'])) {
                foreach ($product['hierarchies'] as $hierarchy) {
                    if (isset($hierarchy['type']) && isset($hierarchy['id'])) {
                        if ($hierarchy['type'] === 'category') {
                            DB::table('wk_product_categories')->insert([
                                'tenant_id' => $tenant,
                                'product_id' => $product['id'],
                                'category_id' => $hierarchy['id']
                            ]);
                        } elseif ($hierarchy['type'] === 'tag') {
                            DB::table('wk_product_tags')->insert([
                                'tenant_id' => $tenant,
                                'product_id' => $product['id'],
                                'tag_id' => $hierarchy['id']
                            ]);
                        }
                    }
                }
            }
            
            // Insert attributes
            if (!empty($product['attributes']) && is_array($product['attributes'])) {
                foreach ($product['attributes'] as $attr) {
                    if (isset($attr['name']) && isset($attr['value'])) {
                        DB::table('wk_product_attributes')->insert([
                            'tenant_id' => $tenant,
                            'product_id' => $product['id'],
                            'attribute_name' => $attr['name'],
                            'attribute_value' => $attr['value']
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            Log::info("Delta sync upsert: Product {$product['id']} for tenant {$tenant}");
            
            return response()->json([
                'success' => true,
                'product_id' => $product['id'],
                'action' => 'upserted'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Delta sync upsert failed: " . $e->getMessage());
            return response()->json(['error' => 'Upsert failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Delete a product
     * DELETE /api/admin/delta-sync/delete/{id}
     */
    public function delete(Request $request, $productId)
    {
        $tenant = $request->header('X-Tenant-Id');
        $apiKey = $request->bearerToken();
        
        // Validate tenant and API key
        $tenantRecord = DB::table('wk_tenants')
            ->where('tenant_id', $tenant)
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();
            
        if (!$tenantRecord) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        try {
            DB::beginTransaction();
            
            // Delete from all tables
            DB::table('wk_index_products')->where('tenant_id', $tenant)->where('id', $productId)->delete();
            DB::table('wk_product_categories')->where('tenant_id', $tenant)->where('product_id', $productId)->delete();
            DB::table('wk_product_tags')->where('tenant_id', $tenant)->where('product_id', $productId)->delete();
            DB::table('wk_product_attributes')->where('tenant_id', $tenant)->where('product_id', $productId)->delete();
            
            DB::commit();
            
            Log::info("Delta sync delete: Product {$productId} for tenant {$tenant}");
            
            return response()->json([
                'success' => true,
                'product_id' => $productId,
                'action' => 'deleted'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Delta sync delete failed: " . $e->getMessage());
            return response()->json(['error' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Batch upsert multiple products
     * POST /api/admin/delta-sync/batch
     */
    public function batch(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $apiKey = $request->bearerToken();
        
        // Validate tenant and API key
        $tenantRecord = DB::table('wk_tenants')
            ->where('tenant_id', $tenant)
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();
            
        if (!$tenantRecord) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $products = $request->input('products', []);
        
        if (!is_array($products) || empty($products)) {
            return response()->json(['error' => 'Products array required'], 400);
        }
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($products as $product) {
            try {
                // Reuse upsert logic
                $fakeRequest = new Request();
                $fakeRequest->headers->set('X-Tenant-Id', $tenant);
                $fakeRequest->headers->set('Authorization', "Bearer {$apiKey}");
                $fakeRequest->merge(['product' => $product]);
                
                $response = $this->upsert($fakeRequest);
                
                if ($response->getStatusCode() === 200) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Product {$product['id']}: " . $response->getContent();
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Product {$product['id']}: " . $e->getMessage();
            }
        }
        
        return response()->json($results);
    }
}
