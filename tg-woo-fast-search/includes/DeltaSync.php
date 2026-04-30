<?php
/**
 * Delta Sync - Real-time product synchronization
 * Hooks into WooCommerce product save/delete events
 * Sends updates to Search API asynchronously
 */

if (!defined('ABSPATH')) exit;

class WK_Fast_Search_Delta_Sync {
    
    private static $log_file = null;
    
    /**
     * Get log file path
     */
    private static function get_log_file() {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/wk-search/logs/delta-sync.log';
        }
        return self::$log_file;
    }
    
    /**
     * Log message to file if debug enabled
     */
    private static function log($message, $level = 'INFO') {
        $settings = wk_fast_search_get_all_settings();
        if (empty($settings['debug_delta_sync']) || $settings['debug_delta_sync'] !== '1') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}\n";
        
        error_log($log_entry, 3, self::get_log_file());
    }
    
    /**
     * Whether each enabled variation is indexed as its own row.
     * Toggle lives in WP options; cached per-request to avoid repeated reads inside hot hooks.
     */
    private static function index_variations_enabled() {
        static $cached = null;
        if ($cached !== null) { return $cached; }
        if (function_exists('wk_fast_search_get_all_settings')) {
            $settings = wk_fast_search_get_all_settings();
            $cached = (($settings['index_variations'] ?? '1') === '1');
        } else {
            $cached = true; // safe default — match the toggle's default-on
        }
        return $cached;
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        error_log("WK DELTA: Initializing DeltaSync hooks");
        self::log("🚀 INITIALIZING DELTA SYNC HOOKS");

        // Product saved (created or updated)
        add_action('woocommerce_update_product', [__CLASS__, 'on_product_saved'], 10, 1);
        add_action('woocommerce_new_product', [__CLASS__, 'on_product_saved'], 10, 1);

        // Variation saved (created or updated). Only meaningful when toggle is ON; the handler no-ops otherwise.
        add_action('woocommerce_save_product_variation', [__CLASS__, 'on_variation_saved'], 10, 1);
        add_action('woocommerce_update_product_variation', [__CLASS__, 'on_variation_saved'], 10, 1);

        error_log("WK DELTA: Registered woocommerce_update_product and woocommerce_new_product hooks");
        self::log("✅ Registered product save hooks");

        // Stock quantity changed (orders, inventory adjustments, etc.)
        add_action('woocommerce_product_set_stock', [__CLASS__, 'on_stock_changed'], 10, 1);
        add_action('woocommerce_variation_set_stock', [__CLASS__, 'on_stock_changed'], 10, 1);
        
        // Stock status changed (in stock, out of stock, on backorder)
        add_action('woocommerce_product_set_stock_status', [__CLASS__, 'on_stock_status_changed'], 10, 2);
        add_action('woocommerce_variation_set_stock_status', [__CLASS__, 'on_stock_status_changed'], 10, 2);
        
        self::log("✅ Registered stock change hooks");
        
        // Product deleted
        add_action('before_delete_post', [__CLASS__, 'on_product_deleted'], 10, 1);
        
        // Product status changed (draft -> publish, publish -> draft)
        add_action('transition_post_status', [__CLASS__, 'on_product_status_changed'], 10, 3);
        
        // Process async queue
        add_action('wkfs_delta_sync_process', [__CLASS__, 'process_sync_queue']);
        
        // Retry failed syncs (every 5 minutes)
        add_action('wkfs_delta_sync_retry', [__CLASS__, 'retry_failed_syncs']);
        if (!wp_next_scheduled('wkfs_delta_sync_retry')) {
            wp_schedule_event(time(), 'wkfs_5min', 'wkfs_delta_sync_retry');
        }
        
        error_log("WK DELTA: All hooks registered successfully");
        self::log("✅ All delta sync hooks registered");
    }
    
    /**
     * Product saved (created or updated)
     */
    public static function on_product_saved($product_id) {
        error_log("WK DELTA: Hook fired for product {$product_id}");
        self::log("🔔 HOOK FIRED: on_product_saved for product {$product_id}");
        
        error_log("WK DELTA: Getting product object...");
        $product = wc_get_product($product_id);
        
        if (!$product) {
            error_log("WK DELTA: Product not found!");
            self::log("Product {$product_id} not found, skipping sync", 'WARNING');
            return;
        }
        
        error_log("WK DELTA: Product found, status: " . $product->get_status());
        
        // Only sync published products that should be searchable
        if ($product->get_status() === 'publish' && wk_fast_search_is_product_searchable($product)) {
            // Variation indexing: a variable parent's identity (price band, attributes, image) cascades
            // to all its variation rows in the index. Re-emit each visible variation; skip the parent itself.
            // (When toggle is OFF, fall through to the normal parent-upsert below.)
            if (self::index_variations_enabled() && $product->is_type('variable')) {
                // Same rationale as the full-feed loop: use get_children() not get_visible_children() so WC's
                // "Hide OOS from catalog" core option does not silently fight this plugin's hide_out_of_stock setting.
                $queued_any = false;
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if (!$variation || !$variation->variation_is_active()) {
                        // Variation is disabled — make sure any prior row in the index is removed.
                        self::queue_product_sync($variation_id, 'delete');
                        continue;
                    }
                    self::queue_product_sync($variation_id, 'upsert');
                    $queued_any = true;
                }
                if ($queued_any) {
                    self::log("Variable parent {$product_id} saved — cascading upsert to enabled variations");
                    // Make sure the parent isn't lingering in the index from a pre-toggle era.
                    self::queue_product_sync($product_id, 'delete');
                    return;
                }
                // No enabled variations — fall through and emit the parent so we don't lose the product.
            }
            error_log("WK DELTA: Status is publish and searchable, queuing upsert");
            self::log("Product {$product_id} ({$product->get_name()}) saved with status 'publish' and is searchable - queuing upsert");
            self::queue_product_sync($product_id, 'upsert');
            error_log("WK DELTA: Queued!");
        } else {
            error_log("WK DELTA: Status is not publish or not searchable, queuing delete");
            $reason = $product->get_status() !== 'publish' ? "status '{$product->get_status()}'" : "not searchable (exclude-from-search)";
            self::log("Product {$product_id} ({$product->get_name()}) - {$reason} - queuing delete");
            // If not published or not searchable, remove from search.
            // Also drop any variation rows that may have been individually indexed.
            self::queue_product_sync($product_id, 'delete');
            if (self::index_variations_enabled() && $product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    self::queue_product_sync($variation_id, 'delete');
                }
            }
        }
        error_log("WK DELTA: on_product_saved complete");
    }

    /**
     * Variation saved (created or updated).
     * Only relevant when index_variations is ON — otherwise the parent's existing flow already covers it.
     */
    public static function on_variation_saved($variation_id) {
        if (!self::index_variations_enabled()) {
            self::log("Skipping on_variation_saved for {$variation_id} — variation indexing is OFF");
            return;
        }
        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            return;
        }
        $parent = wc_get_product($variation->get_parent_id());
        if (!$parent || $parent->get_status() !== 'publish' || !wk_fast_search_is_product_searchable($parent)) {
            // Parent is gone, unpublished, or hidden — drop this variation from the index.
            self::log("Variation {$variation_id}: parent {$variation->get_parent_id()} not eligible — queuing delete");
            self::queue_product_sync($variation_id, 'delete');
            return;
        }
        if (!$variation->variation_is_active()) {
            self::log("Variation {$variation_id} is disabled — queuing delete");
            self::queue_product_sync($variation_id, 'delete');
            return;
        }
        self::log("Variation {$variation_id} saved — queuing upsert");
        self::queue_product_sync($variation_id, 'upsert');
    }
    
    /**
     * Stock quantity changed (triggered by orders, inventory updates, etc.)
     */
    public static function on_stock_changed($product) {
        $product_id = is_numeric($product) ? $product : $product->get_id();
        
        self::log("Stock quantity changed for product {$product_id}");
        
        // Get the actual product object
        $product_obj = wc_get_product($product_id);
        
        if (!$product_obj) {
            self::log("Product {$product_id} not found after stock change", 'WARNING');
            return;
        }
        
        // Variation routing: if the variation is its own row in the index, sync IT.
        // Otherwise (toggle OFF), bump the parent so its min-price band stays accurate.
        if ($product_obj->is_type('variation')) {
            if (self::index_variations_enabled()) {
                self::log("Variation {$product_id} stock changed — syncing the variation directly (variation indexing is ON)");
                // Keep $product_id / $product_obj as the variation; checks below use the parent's eligibility.
                $parent_for_check = wc_get_product($product_obj->get_parent_id());
                if (!$parent_for_check) {
                    self::log("Parent for variation {$product_id} not found", 'WARNING');
                    return;
                }
                if ($parent_for_check->get_status() === 'publish' && wk_fast_search_is_product_searchable($parent_for_check) && $product_obj->variation_is_active()) {
                    self::log("Queuing upsert for variation {$product_id}");
                    self::queue_product_sync($product_id, 'upsert');
                } else {
                    self::log("Variation {$product_id} not eligible (parent unpublished/hidden or variation disabled) — queuing delete");
                    self::queue_product_sync($product_id, 'delete');
                }
                return;
            }
            $parent_id = $product_obj->get_parent_id();
            self::log("Product {$product_id} is a variation, syncing parent {$parent_id} instead");
            $product_id = $parent_id;
            $product_obj = wc_get_product($parent_id);

            if (!$product_obj) {
                self::log("Parent product {$parent_id} not found", 'WARNING');
                return;
            }
        }

        // Only sync if product is published AND searchable
        if ($product_obj->get_status() === 'publish' && wk_fast_search_is_product_searchable($product_obj)) {
            self::log("Queuing upsert for product {$product_id}");
            self::queue_product_sync($product_id, 'upsert');
        } else {
            $reason = $product_obj->get_status() !== 'publish' ? "not published" : "not searchable";
            self::log("Product {$product_id} is {$reason}, skipping sync");
        }
    }

    /**
     * Stock status changed (in stock, out of stock, on backorder)
     */
    public static function on_stock_status_changed($product_id, $stock_status) {
        self::log("Stock status changed to '{$stock_status}' for product {$product_id}");

        $product = wc_get_product($product_id);

        if (!$product) {
            self::log("Product {$product_id} not found after stock status change", 'WARNING');
            return;
        }

        // Same variation routing as on_stock_changed: sync the variation directly when toggle is ON.
        if ($product->is_type('variation')) {
            if (self::index_variations_enabled()) {
                self::log("Variation {$product_id} stock-status changed — syncing the variation directly");
                $parent_for_check = wc_get_product($product->get_parent_id());
                if (!$parent_for_check) {
                    self::log("Parent for variation {$product_id} not found", 'WARNING');
                    return;
                }
                if ($parent_for_check->get_status() === 'publish' && wk_fast_search_is_product_searchable($parent_for_check) && $product->variation_is_active()) {
                    self::log("Queuing upsert for variation {$product_id}");
                    self::queue_product_sync($product_id, 'upsert');
                } else {
                    self::log("Variation {$product_id} not eligible — queuing delete");
                    self::queue_product_sync($product_id, 'delete');
                }
                return;
            }
            $parent_id = $product->get_parent_id();
            self::log("Product {$product_id} is a variation, syncing parent {$parent_id} instead");
            $product_id = $parent_id;
            $product = wc_get_product($parent_id);

            if (!$product) {
                self::log("Parent product {$parent_id} not found", 'WARNING');
                return;
            }
        }
        
        // Only sync if product is published AND searchable
        if ($product->get_status() === 'publish' && wk_fast_search_is_product_searchable($product)) {
            self::log("Queuing upsert for product {$product_id}");
            self::queue_product_sync($product_id, 'upsert');
        } else {
            self::log("Product {$product_id} is not published (status: {$product->get_status()}), skipping sync");
        }
    }
    
    /**
     * Product status changed
     */
    public static function on_product_status_changed($new_status, $old_status, $post) {
        if ($post->post_type !== 'product') return;
        
        $product_id = $post->ID;
        
        self::log("Product {$product_id} status changed: {$old_status} → {$new_status}");
        
        // Published -> remove from search
        if ($old_status === 'publish' && $new_status !== 'publish') {
            self::log("Product {$product_id} unpublished - queuing delete");
            self::queue_product_sync($product_id, 'delete');
        }
        
        // Not published -> Published = add to search
        if ($old_status !== 'publish' && $new_status === 'publish') {
            self::log("Product {$product_id} published - queuing upsert");
            self::queue_product_sync($product_id, 'upsert');
        }
    }
    
    /**
     * Product deleted
     */
    public static function on_product_deleted($post_id) {
        if (get_post_type($post_id) !== 'product') return;
        
        self::log("Product {$post_id} deleted - queuing delete from search index");
        self::queue_product_sync($post_id, 'delete');
    }
    
    /**
     * Queue a product for sync (async)
     */
    private static function queue_product_sync($product_id, $action) {
        // Host-gate: only the production hostnames may push to the search API.
        // On staging/dev/local, drop silently — never queue, never push.
        if (function_exists('wk_fast_search_sync_allowed') && !wk_fast_search_sync_allowed()) {
            self::log("Skipping queue for product {$product_id} ({$action}) — sync disabled on this host");
            return;
        }
        // Get current queue
        $queue = get_option('wkfs_delta_sync_queue', []);
        
        // Add to queue (keyed by product_id to avoid duplicates)
        $queue[$product_id] = [
            'product_id' => $product_id,
            'action' => $action,
            'queued_at' => time()
        ];
        
        update_option('wkfs_delta_sync_queue', $queue, false);
        
        self::log("Queued product {$product_id} for {$action} (queue size: " . count($queue) . ")");
        
        // Process immediately for local environment, or schedule for live/staging
        if (defined('WK_ENVIRONMENT') && WK_ENVIRONMENT === 'local') {
            self::log("Local environment detected - processing queue immediately");
            self::process_sync_queue();
        } else {
            // Schedule async processing (runs in 10 seconds)
            if (!wp_next_scheduled('wkfs_delta_sync_process')) {
                wp_schedule_single_event(time() + 10, 'wkfs_delta_sync_process');
                self::log("Scheduled async processing in 10 seconds");
            }
        }
    }
    
    /**
     * Process queued syncs (runs async)
     */
    public static function process_sync_queue() {
        // Host-gate (defence-in-depth): a fresh staging clone may inherit a non-empty queue from production.
        // Without this check, scheduled wkfs_delta_sync_process events on staging would still try to push.
        if (function_exists('wk_fast_search_sync_allowed') && !wk_fast_search_sync_allowed()) {
            self::log("Skipping queue processing — sync disabled on this host");
            return;
        }
        self::log("=== Starting delta sync queue processing ===");

        $queue = get_option('wkfs_delta_sync_queue', []);
        
        if (empty($queue)) {
            self::log("Queue is empty, nothing to process");
            return;
        }
        
        self::log("Processing queue with " . count($queue) . " items");
        
        $settings = wk_fast_search_get_all_settings();
        $edge_url = $settings['edge_url'];
        $tenant_id = $settings['tenant_id'];
        $api_key = $settings['api_key'];
        
        if (empty($edge_url) || empty($tenant_id) || empty($api_key)) {
            self::log('Missing configuration (edge_url, tenant_id, or api_key)', 'ERROR');
            return;
        }
        
        self::log("Using API endpoint: {$edge_url}");
        
        // Process in batches of 10
        $batch = array_slice($queue, 0, 10, true);
        $remaining = array_slice($queue, 10, null, true);
        
        self::log("Processing batch of " . count($batch) . " items, " . count($remaining) . " remaining");
        
        $upserts = [];
        $deletes = [];
        
        foreach ($batch as $product_id => $item) {
            if ($item['action'] === 'delete') {
                $deletes[] = $product_id;
                self::log("Added product {$product_id} to delete batch");
            } else {
                // Prepare product data
                $product = wc_get_product($product_id);
                if ($product) {
                    $upserts[] = self::prepare_product_data($product);
                    self::log("Added product {$product_id} ({$product->get_name()}) to upsert batch");
                } else {
                    self::log("Product {$product_id} no longer exists, skipping", 'WARNING');
                }
            }
            
            // Remove from queue
            unset($queue[$product_id]);
        }
        
        // Send batch upserts
        if (!empty($upserts)) {
            self::log("Sending batch upsert for " . count($upserts) . " products");
            $success = self::send_batch_upsert($upserts, $edge_url, $tenant_id, $api_key);
            if (!$success) {
                self::log("Batch upsert failed, logging for retry", 'ERROR');
                self::log_failed_sync($upserts, 'batch_upsert');
            } else {
                self::log("✓ Batch upsert successful for " . count($upserts) . " products");
                self::log("  - Updated/inserted into wk_index_products table");
                self::log("  - Deleted and re-inserted hierarchies (categories) in wk_index_hierarchies");
                self::log("  - Deleted and re-inserted attributes in wk_index_product_attributes");
            }
        }
        
        // Send deletes
        foreach ($deletes as $product_id) {
            self::log("Sending delete for product {$product_id}");
            $success = self::send_delete($product_id, $edge_url, $tenant_id, $api_key);
            if (!$success) {
                self::log("Delete failed for product {$product_id}, logging for retry", 'ERROR');
                self::log_failed_sync(['product_id' => $product_id], 'delete');
            } else {
                self::log("✓ Delete successful for product {$product_id}");
                self::log("  - Removed from wk_index_products table");
                self::log("  - Removed all hierarchies from wk_index_hierarchies");
                self::log("  - Removed all attributes from wk_index_product_attributes");
            }
        }
        
        // Update queue
        update_option('wkfs_delta_sync_queue', $remaining, false);
        
        // If more items remain, schedule another run
        if (!empty($remaining)) {
            self::log("Scheduling next batch processing in 5 seconds (" . count($remaining) . " items remaining)");
            wp_schedule_single_event(time() + 5, 'wkfs_delta_sync_process');
        } else {
            self::log("=== Queue processing complete ===");
        }
    }
    
    /**
     * Prepare product data for API
     */
    private static function prepare_product_data($product) {
        try {
            // Reuse existing product data preparation logic
            $settings = wk_fast_search_get_all_settings();
            $render_mode = $settings['render_mode'];
            $loop_id = (int) $settings['elementor_loop_id'];
            
            $rendered_html = '';
            if ($render_mode === 'elementor' && $loop_id > 0 && class_exists('Elementor\\Plugin')) {
                $rendered_html = wkfs_render_elementor_loop_item_for_product($product->get_id(), $loop_id);
            } else {
                $rendered_html = wkfs_render_woo_product_card($product->get_id());
            }
            
            $price = (float) $product->get_price();
            $price_old = (float) $product->get_regular_price();
            if ($price_old <= $price) $price_old = null;
            
            return [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'title' => $product->get_name(),
                'description' => wk_fast_search_get_description($product),
                'slug' => $product->get_slug(),
                'url' => get_permalink($product->get_id()),
                'brand' => wk_fast_search_get_brand($product),
                'hierarchies' => wk_fast_search_get_hierarchies($product),
                'attributes' => wk_fast_search_get_attributes($product),
                'price' => $price,
                'price_old' => $price_old,
                'currency' => get_woocommerce_currency(),
                'in_stock' => $product->is_in_stock(),
                'rating' => (float) $product->get_average_rating(),
                'image' => wp_get_attachment_url($product->get_image_id()),
                'popularity' => (int) $product->get_total_sales(),
                'html' => $rendered_html ? do_shortcode($rendered_html) : null,
                'created_at' => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            ];
        } catch (\Exception $e) {
            self::log("Failed to prepare product data for {$product->get_id()}: {$e->getMessage()}", 'ERROR');
            // Return minimal data to prevent complete failure
            return [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'title' => $product->get_name(),
                'price' => (float) $product->get_price(),
                'in_stock' => $product->is_in_stock(),
                'created_at' => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            ];
        }
    }
    
    /**
     * Send batch upsert to API
     */
    private static function send_batch_upsert($products, $edge_url, $tenant_id, $api_key) {
        $url = rtrim($edge_url, '/') . '/api/admin/delta-sync/batch';
        self::log("POST {$url} with " . count($products) . " products");
        
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Tenant-Id' => $tenant_id,
            ],
            'body' => json_encode(['products' => $products]),
        ]);
        
        if (is_wp_error($response)) {
            self::log('Batch upsert failed: ' . $response->get_error_message(), 'ERROR');
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            self::log("Batch upsert failed: HTTP {$code} - {$body}", 'ERROR');
            return false;
        }
        
        $result = json_decode($body, true);
        self::log("Batch upsert response: " . json_encode($result));
        
        return true;
    }
    
    /**
     * Send delete to API
     */
    private static function send_delete($product_id, $edge_url, $tenant_id, $api_key) {
        $url = rtrim($edge_url, '/') . '/api/admin/delta-sync/delete/' . $product_id;
        self::log("DELETE {$url}");
        
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'X-Tenant-Id' => $tenant_id,
            ],
        ]);
        
        if (is_wp_error($response)) {
            self::log('Delete failed: ' . $response->get_error_message(), 'ERROR');
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            self::log("Delete failed: HTTP {$code} - {$body}", 'ERROR');
            return false;
        }
        
        self::log("Delete response: {$body}");
        
        return true;
    }
    
    /**
     * Log failed sync for retry
     */
    private static function log_failed_sync($data, $action) {
        $failed = get_option('wkfs_delta_sync_failed', []);
        $failed[] = [
            'data' => $data,
            'action' => $action,
            'failed_at' => time(),
            'retry_count' => 0,
        ];
        update_option('wkfs_delta_sync_failed', $failed, false);
    }
    
    /**
     * Retry failed syncs
     */
    public static function retry_failed_syncs() {
        // Host-gate: same reasoning as process_sync_queue — wkfs_delta_sync_failed may also clone in.
        if (function_exists('wk_fast_search_sync_allowed') && !wk_fast_search_sync_allowed()) {
            return;
        }
        $failed = get_option('wkfs_delta_sync_failed', []);

        if (empty($failed)) return;
        
        self::log("=== Retrying " . count($failed) . " failed syncs ===");
        
        $settings = wk_fast_search_get_all_settings();
        $edge_url = $settings['edge_url'];
        $tenant_id = $settings['tenant_id'];
        $api_key = $settings['api_key'];
        
        $still_failed = [];
        
        foreach ($failed as $item) {
            // Max 5 retries
            if ($item['retry_count'] >= 5) {
                self::log('Giving up after 5 retries: ' . json_encode($item['data']), 'ERROR');
                continue;
            }
            
            self::log("Retrying {$item['action']} (attempt " . ($item['retry_count'] + 1) . "/5)");
            
            $success = false;
            
            if ($item['action'] === 'delete') {
                $success = self::send_delete($item['data']['product_id'], $edge_url, $tenant_id, $api_key);
            } else if ($item['action'] === 'batch_upsert') {
                $success = self::send_batch_upsert($item['data'], $edge_url, $tenant_id, $api_key);
            }
            
            if (!$success) {
                $item['retry_count']++;
                $still_failed[] = $item;
                self::log("Retry failed, will try again (retry count: {$item['retry_count']})", 'WARNING');
            } else {
                self::log("Retry successful!");
            }
        }
        
        update_option('wkfs_delta_sync_failed', $still_failed, false);
        self::log("=== Retry complete: " . count($still_failed) . " still failing ===");
    }
}

// Initialize
WK_Fast_Search_Delta_Sync::init();
