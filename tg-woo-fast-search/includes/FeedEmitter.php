<?php

namespace WKSearchSystem;

class FeedEmitter {
    private $settings;
    private $api_client;
    private $delta_queue = [];
    private $batch_size;
    private $feed_dir;
    private $feed_base_url;

    public function __construct() {
        $t0 = microtime(true);
        $m0 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        // If kill-switch is on, make this a no-op
        if (defined('WK_SEARCH_SYSTEM_DISABLED') && WK_SEARCH_SYSTEM_DISABLED) {
            if (class_exists('WKSearchSystem\\Logger')) {
                \WKSearchSystem\Logger::debug('FeedEmitter::__construct skipped (kill-switch)');
            }
            return;
        }
        $this->settings = Plugin::getInstance()->getSettings();
        // ApiClient not needed for local file generation in simplified mode
        $this->api_client = null;
        $this->batch_size = 200;
        // Note: no hooks, no scheduling, no storage initialization here
        if (class_exists('WKSearchSystem\\Logger')) {
            $elapsedMs = round((microtime(true) - $t0) * 1000, 2);
            $m1 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            $memDelta = $m1 - $m0;
            \WKSearchSystem\Logger::debug('FeedEmitter::__construct completed in ' . $elapsedMs . 'ms, mem +' . $memDelta . ' bytes');
        }
    }

    public function onProductSave($product_id) {
        // delta emits disabled in simplified mode
        if (true) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $this->queueProductDelta($product);
    }

    public function onProductDelete($product_id) {
        // delta emits disabled in simplified mode
        if (true) {
            return;
        }

        $this->queueProductDelta(['id' => $product_id, 'deleted' => true]);
    }

    public function onVariationSave($variation) {
        // delta emits disabled in simplified mode
        if (true) {
            return;
        }

        $parent_product = wc_get_product($variation->get_parent_id());
        if ($parent_product) {
            $this->queueProductDelta($parent_product);
        }
    }

    private function queueProductDelta($product) {
        // Disabled
        return;
    }

    public function processDeltaQueue() { /* removed: no cron queue */ }
    private function scheduleNextDelta() { /* removed: no cron queue */ }

    public function scheduleFullFeeds() { /* deprecated in favor of daily 3am wp_schedule_event */ }

    public function runFullFeed() {
        try {
            // Enforce safe context for large catalogs
            $this->assertSafeContextForFullFeed();
            $sent = $this->runProductsJson();
            \WKSearchSystem\Logger::info('products.json generated with ' . $sent . ' products');
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Full feed failed: ' . $e->getMessage());
        }

        // Let daily schedule handle next run
    }

    private function runFullFeedStreamed() {
        $total_written = 0;
        $batch_size = max(10, intval($this->settings->getOption('feed_full_batch_size', 200)));
        // Default to no HTML for full feeds; can be explicitly enabled by setting if absolutely needed
        $include_html = (bool)$this->settings->getOption('feed_include_html', false);

        $paths = $this->getFeedPaths();
        $tmp_path = $paths['full'] . '.tmp';

        // Suspend cache additions to avoid unbounded memory growth during long loops
        $prev_cache_suspend = null;
        if (function_exists('wp_suspend_cache_addition')) {
            $prev_cache_suspend = wp_suspend_cache_addition(true);
        }

        $fh = fopen($tmp_path, 'w');
        if (!$fh) { throw new \Exception('Unable to open full feed temp file for writing'); }

        $meta = [
            'tenant_id' => $this->settings->getOption('tenant_id'),
            'site_url' => get_site_url(),
            'generated_at' => date('c'),
            'version' => defined('WK_SEARCH_SYSTEM_VERSION') ? WK_SEARCH_SYSTEM_VERSION : 'dev'
        ];

        fwrite($fh, '{');
        fwrite($fh, '"tenant_id":' . json_encode($meta['tenant_id']) . ',');
        fwrite($fh, '"site_url":' . json_encode($meta['site_url']) . ',');
        fwrite($fh, '"generated_at":' . json_encode($meta['generated_at']) . ',');
        fwrite($fh, '"version":' . json_encode($meta['version']) . ',');
        fwrite($fh, '"products":[');

        $page = 1;
        $per_page = $batch_size;
        $first = true;
        try {
            do {
                $product_ids = wc_get_products([
                    'limit' => $per_page,
                    'page' => $page,
                    'status' => 'publish',
                    'return' => 'ids'
                ]);
                if (empty($product_ids)) { break; }

                foreach ($product_ids as $product_id) {
                    $product = wc_get_product($product_id);
                    if (!$product) { continue; }
                    $item = $this->prepareProductData($product, $include_html);
                    if ($item === null) { 
                        // Product was skipped due to invalid price
                        continue; 
                    }
                    $json = json_encode($item, JSON_UNESCAPED_UNICODE);
                    if ($json === false) { continue; }
                    if (!$first) { fwrite($fh, ','); }
                    fwrite($fh, $json);
                    $first = false;
                    $total_written++;
                }

                // Log batch progress and current memory usage
                if (class_exists('WKSearchSystem\\Logger') && function_exists('memory_get_usage')) {
                    \WKSearchSystem\Logger::debug('Full feed progress: page=' . $page . ', written=' . $total_written . ', mem=' . memory_get_usage(true));
                }

                // Encourage GC between batches
                if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }

                $page++;
                usleep(200000); // 200ms
            } while (count($product_ids) === $per_page);
        } finally {
            // Restore cache addition suspension state
            if (function_exists('wp_suspend_cache_addition')) {
                wp_suspend_cache_addition((bool)$prev_cache_suspend);
            }
        }

        fwrite($fh, ']}');
        fclose($fh);

        // Atomic replace
        rename($tmp_path, $paths['full']);
        return $total_written;
    }

    private function appendDeltaFeed($records) {
        if (empty($records)) { return 0; }
        $paths = $this->getFeedPaths();
        $fh = fopen($paths['delta'], 'a');
        if (!$fh) { return 0; }
        if (function_exists('flock')) { flock($fh, LOCK_EX); }
        $written = 0;
        foreach ($records as $rec) {
            $json = json_encode($rec, JSON_UNESCAPED_UNICODE);
            if ($json === false) { continue; }
            fwrite($fh, $json . "\n");
            $written++;
        }
        if (function_exists('flock')) { flock($fh, LOCK_UN); }
        fclose($fh);
        return $written;
    }

    private function initializeFeedStorage() {
        $uploads = wp_upload_dir();
        $tenant = $this->settings->getOption('tenant_id');
        if (empty($tenant)) { $tenant = 'default'; }
        $dir = trailingslashit($uploads['basedir']) . 'wk-search/' . $tenant;
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $this->feed_dir = $dir;
        $this->feed_base_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant;
        // Update site option so Laravel can auto-discover without manual input
        $manifest_url = trailingslashit($this->feed_base_url) . 'manifest.json';
        $this->updateSiteManifestUrlOption($manifest_url);
    }

    private function updateSiteManifestUrlOption($manifest_url) {
        // Store a public URL in a WP option for external systems to discover
        update_option('wk_search_manifest_url', $manifest_url);
    }

    private function getFeedPaths() {
        if (empty($this->feed_dir)) {
            $this->initializeFeedStorage();
        }
        $full = $this->feed_dir . '/full.json';
        $delta = $this->feed_dir . '/delta.jsonl';
        $manifest = $this->feed_dir . '/manifest.json';
        $products = $this->feed_dir . '/products.json';
        return [ 'full' => $full, 'delta' => $delta, 'manifest' => $manifest, 'products' => $products ];
    }

    // Next-run calculator moved to admin settings scheduler

    private function updateManifest($full_updated) {
        $paths = $this->getFeedPaths();
        $data = [
            'tenant_id' => $this->settings->getOption('tenant_id'),
            'site_url' => get_site_url(),
            'full_feed_url' => trailingslashit($this->feed_base_url) . 'full.json',
            'delta_feed_url' => trailingslashit($this->feed_base_url) . 'delta.jsonl',
            'products_url' => trailingslashit($this->feed_base_url) . 'products.json',
            'full_updated' => $full_updated ? date('c') : (file_exists($paths['full']) ? date('c', filemtime($paths['full'])) : null),
            'delta_updated' => file_exists($paths['delta']) ? date('c', filemtime($paths['delta'])) : null,
            'version' => defined('WK_SEARCH_SYSTEM_VERSION') ? WK_SEARCH_SYSTEM_VERSION : 'dev'
        ];

        $tmp = $paths['manifest'] . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $paths['manifest']);
    }

    private function getAllProducts() {
        $products = [];
        $page = 1;
        $per_page = 100;
        
        // Get excluded product IDs
        $excluded_ids_raw = $this->settings->getOption('excluded_product_ids', '');
        $excluded_ids = array_filter(array_map('intval', explode(',', $excluded_ids_raw)));

        do {
            // Use WP_Query instead of wc_get_products to get ALL published products
            $args = [
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => false
            ];
            
            // Exclude specified product IDs
            if (!empty($excluded_ids)) {
                $args['post__not_in'] = $excluded_ids;
            }
            
            $query = new WP_Query($args);
            $product_ids = $query->posts;

            if (empty($product_ids)) {
                break;
            }

            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) {
                    continue;
                }
                
                // Check if product should be searchable (exclude catalog-only and hidden products)
                if (!wk_fast_search_is_product_searchable($product)) {
                    continue;
                }
                
                $item = $this->prepareProductData($product);
                if ($item !== null) {
                    $products[] = $item;
                }
            }

            $page++;
        } while (count($product_ids) === $per_page);

        return $products;
    }

    private function prepareProductData($product, $include_html = false) {
        if (is_array($product) && isset($product['deleted'])) {
            return $product;
        }

        // Handle variable products specially - use WooCommerce built-in functions
        if ($product->is_type('variable')) {
            // Get minimum variation prices using WooCommerce built-in methods
            $price = $product->get_variation_price('min', false); // false = unformatted price
            $price_old = $product->get_variation_regular_price('min', false);
            
            // Sanitize the prices
            $price = $this->sanitizePrice($price);
            $price_old = $this->sanitizePrice($price_old);
        } else {
            // Regular products - use standard price handling
            $price = $this->getProductPrice($product);
            $price_old = $this->getProductRegularPrice($product);
        }
        
        // Skip product if either price is invalid (null)
        if ($price === null || $price_old === null) {
            return null; // This will cause the product to be skipped
        }

        $product_data = [
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'title' => $product->get_name(),
            'description' => $this->getProductDescription($product),
            'slug' => $product->get_slug(),
            'url' => get_permalink($product->get_id()),
            'brand' => $this->getProductBrand($product),
            'hierarchies' => $this->getProductHierarchies($product),
            'attributes' => $this->getProductAttributes($product),
            'price' => $price,
            'price_old' => $price_old,
            'currency' => get_woocommerce_currency(),
            'in_stock' => $product->is_in_stock(),
            'rating' => $product->get_average_rating(),
            'image' => $this->getProductImage($product),
            'html' => $include_html ? $this->renderProductHtml($product) : null,
            'popularity' => $this->getProductPopularity($product),
            'purchase_count' => (int) get_post_meta($product->get_id(), 'total_sales', true),
            'created_at' => $product->get_date_created()->format('c'),
            'updated_at' => $product->get_date_modified()->format('c'),
        ];

        // Handle variations
        // Do not expand or summarize variations; use parent product price only

        return $product_data;
    }

    private function runProductsJson() {
        $total_written = 0;
        $paths = $this->getFeedPaths();
        $tmp_path = $paths['products'] . '.tmp';

        $fh = fopen($tmp_path, 'w');
        if (!$fh) { throw new \Exception('Unable to open products.json temp file for writing'); }
        fwrite($fh, '[');
        $page = 1; $per_page = 200; $first = true;
        do {
            // Use WP_Query instead of wc_get_products to get ALL published products
            $query = new WP_Query([
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => false
            ]);
            $product_ids = $query->posts;
            if (empty($product_ids)) { break; }
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) { continue; }
                
                // Check if product should be searchable (exclude catalog-only and hidden products)
                if (!wk_fast_search_is_product_searchable($product)) {
                    continue;
                }
                
                $item = $this->prepareProductData($product, true); // Enable HTML rendering
                if ($item === null) { 
                    // Product was skipped due to invalid price
                    continue; 
                }
                $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json === false) { continue; }
                if (!$first) { fwrite($fh, ','); }
                fwrite($fh, $json);
                $first = false; $total_written++;
            }
            if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
            $page++;
        } while (count($product_ids) === $per_page);
        fwrite($fh, ']');
        fclose($fh);
        rename($tmp_path, $paths['products']);
        $this->updateManifest(false);
        return $total_written;
    }

    private function prepareDeltaRecord($product) {
        if (is_array($product) && isset($product['deleted'])) {
            return [
                'id' => intval($product['id']),
                'deleted' => true,
                'timestamp' => time()
            ];
        }

        return [
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'price' => $this->getProductPrice($product),
            'price_old' => $this->getProductRegularPrice($product),
            'in_stock' => $product->is_in_stock(),
            'updated_at' => $product->get_date_modified() ? $product->get_date_modified()->format('c') : date('c'),
            'action' => 'upsert'
        ];
    }

    private function renderProductHtmlLite($product) {
        $permalink = get_permalink($product->get_id());
        $title = esc_html($product->get_name());
        $img = esc_url($this->getProductImage($product));
        $price_html = function_exists('wc_price') ? wc_price($product->get_price()) : esc_html($product->get_price());
        return '<a class="wk-card" href="' . $permalink . '"><img loading="lazy" src="' . $img . '" alt="' . $title . '"><h3>' . $title . '</h3><div class="price">' . $price_html . '</div></a>';
    }
    
    private function renderProductHtml($product) {
        // Get render mode setting
        $render_mode = get_option('wk_fast_search_render_mode', 'woocommerce');
        
        $html = '';
        if ($render_mode === 'elementor') {
            // Use Elementor loop template
            $loop_id = get_option('wk_fast_search_elementor_loop_id', '');
            if ($loop_id && function_exists('wkfs_render_elementor_loop_item_for_product')) {
                $html = wkfs_render_elementor_loop_item_for_product($product->get_id(), $loop_id);
            }
        }
        
        // Fallback to WooCommerce template
        if (empty($html) && function_exists('wkfs_render_woo_product_card')) {
            $html = wkfs_render_woo_product_card($product->get_id());
        }
        
        // Final fallback to lite template
        if (empty($html)) {
            $html = $this->renderProductHtmlLite($product);
        }
        
        // Post-process: Add outofstock class if product is not in stock
        if (!$product->is_in_stock() && !empty($html)) {
            // Find the root element's class attribute and append outofstock
            // Elementor structure: <div data-elementor-type="loop-item" ... class="elementor elementor-100928 ..."
            $html = preg_replace(
                '/(class=["\'][^"\']*)(["\']\s)/i',
                '$1 outofstock$2',
                $html,
                1
            );
        }
        
        // Post-process: Fill empty title-wrapper and price-wrapper if present
        if (!empty($html) && (strpos($html, 'title-wrapper') !== false || strpos($html, 'price-wrapper') !== false)) {
            $title = $product->get_name();
            $price_html = $product->get_price_html();
            
            // Inject title if wrapper is empty (match with or without whitespace/newlines)
            if (preg_match('/<div class=["\']title-wrapper["\']>\s*<\/div>/', $html)) {
                $title_content = '<div class="title-wrapper"><h2 class="woocommerce-loop-product__title">' . esc_html($title) . '</h2></div>';
                $html = preg_replace('/<div class=["\']title-wrapper["\']>\s*<\/div>/', $title_content, $html, 1);
            }
            
            // Inject price if wrapper is empty (match with or without whitespace/newlines)
            if (preg_match('/<div class=["\']price-wrapper["\']>\s*<\/div>/', $html)) {
                $price_content = '<div class="price-wrapper"><span class="price">' . $price_html . '</span></div>';
                $html = preg_replace('/<div class=["\']price-wrapper["\']>\s*<\/div>/', $price_content, $html, 1);
            }
        }
        
        return $html;
    }

    private function getProductBrand($product) {
        // Try to get brand from product attributes
        $brand_attributes = ['brand', 'manufacturer', 'make'];
        
        foreach ($brand_attributes as $attr) {
            $brand = $product->get_attribute($attr);
            if (!empty($brand)) {
                return $brand;
            }
        }

        // Try to get from custom fields
        $brand = get_post_meta($product->get_id(), '_brand', true);
        if (!empty($brand)) {
            return $brand;
        }

        return '';
    }

    private function getProductDescription($product) {
        // Only use short description (excerpt) - more concise and curated
        // Don't fallback to long description to keep data clean and search relevant
        $short_desc = $product->get_short_description();
        
        if (empty($short_desc)) {
            return '';
        }
        
        // Strip HTML tags and clean up
        $description = wp_strip_all_tags($short_desc);
        
        // Remove excessive whitespace
        $description = preg_replace('/\s+/', ' ', $description);
        
        // Trim to reasonable length (300 chars max) to keep index lean
        if (strlen($description) > 300) {
            $description = substr($description, 0, 300);
        }
        
        return trim($description);
    }

    private function getProductHierarchies($product) {
        $hierarchies = [];
        
        // Get categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach ($categories as $category) {
            $hierarchies[] = [
                'type' => 'category',
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => get_term_link($category),
                'level' => $this->getCategoryLevel($category),
                'parent_id' => $category->parent > 0 ? $category->parent : null, // ADDED for hierarchy optimization
            ];
        }

        // Get tags
        $tags = wp_get_post_terms($product->get_id(), 'product_tag');
        foreach ($tags as $tag) {
            $hierarchies[] = [
                'type' => 'tag',
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'url' => get_term_link($tag),
                'level' => 0
            ];
        }

        return $hierarchies;
    }

    private function getCategoryLevel($category) {
        $level = 0;
        $parent = $category->parent;
        
        while ($parent > 0) {
            $level++;
            $parent_category = get_term($parent, 'product_cat');
            $parent = $parent_category ? $parent_category->parent : 0;
        }
        
        return $level;
    }

    private function getProductAttributes($product) {
        $attributes = [];
        
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                $values = array_map(function($term) {
                    return $term->name;
                }, $terms);
            } else {
                $values = explode('|', $attribute->get_options());
            }
            
            $attributes[$attribute->get_name()] = $values;
        }

        return $attributes;
    }

    private function getProductImage($product) {
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image = wp_get_attachment_image_src($image_id, 'woocommerce_thumbnail');
            return $image ? $image[0] : '';
        }
        return '';
    }

    private function getProductPopularity($product) {
        // Simple popularity calculation based on sales and views
        $sales = get_post_meta($product->get_id(), 'total_sales', true);
        $views = get_post_meta($product->get_id(), '_product_views', true);
        
        return intval($sales) + (intval($views) * 0.1);
    }

    public function exportFullFeed() {
        throw new \RuntimeException('exportFullFeed() is disabled. Use runFullFeed() which streams to disk.');
    }

    private function assertSafeContextForFullFeed() {
        if (!defined('WP_CLI') && !wp_doing_cron()) {
            $count = (int) wp_count_posts('product')->publish;
            if ($count > 1000) {
                throw new \RuntimeException('Full feed disabled in web context for large catalogs; run via WP-CLI or cron.');
            }
        }
    }

    public function getDeltaQueueSize() {
        return count($this->delta_queue);
    }

    public function clearDeltaQueue() {
        $this->delta_queue = [];
    }

    /**
     * Get product price safely, avoiding WooCommerce filters that might cause issues
     */
    private function getProductPrice($product) {
        // Get raw price without any filters
        $price = $product->get_meta('_price');
        if (empty($price)) {
            $price = $product->get_meta('_regular_price');
        }
        if (empty($price)) {
            $price = $product->get_meta('_sale_price');
        }
        
        // Fallback to WooCommerce method if meta is empty
        if (empty($price)) {
            $price = $product->get_price();
        }
        
        return $this->sanitizePrice($price);
    }

    /**
     * Get product regular price safely
     */
    private function getProductRegularPrice($product) {
        // Get raw regular price without any filters
        $price = $product->get_meta('_regular_price');
        
        // Fallback to WooCommerce method if meta is empty
        if (empty($price)) {
            $price = $product->get_regular_price();
        }
        
        return $this->sanitizePrice($price);
    }

    /**
     * Sanitize price to prevent database overflow
     * Returns null for invalid prices to skip the product entirely
     */
    private function sanitizePrice($price) {
        // Allow 0 and empty prices - they are valid
        if ($price === '' || $price === null) {
            return 0; // Convert empty/null to 0
        }
        
        if (!is_numeric($price)) {
            return null; // Skip only non-numeric prices
        }
        
        $price = floatval($price);
        
        // Skip products with suspicious prices (likely data corruption)
        // Check for scientific notation or extremely large values
        if ($price > 1000000 || is_infinite($price) || is_nan($price)) {
            error_log("WK Search: Skipping product with suspicious price: {$price} (original: " . var_export($price, true) . ")");
            return null; // Skip product entirely
        }
        
        // Allow 0 and negative prices - they might be valid (free products, returns, etc.)
        return round($price, 2);
    }
}
