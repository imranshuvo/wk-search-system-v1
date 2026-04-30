<?php
/**
 * Plugin Name: Woo Fast Search
 * Plugin URI: https://webkonsulenterne.dk
 * Description: Advanced search system with intelligent suggestions, filters, and analytics for WooCommerce stores
 * Version: 2.0.5
 * Author: Imran Khan
 * License: GPL v2 or later
 * Text Domain: woo-fast-search
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Define plugin constants
define('WK_SEARCH_SYSTEM_VERSION', '2.0.5');
define('WK_SEARCH_SYSTEM_PLUGIN_FILE', __FILE__);
define('WK_SEARCH_SYSTEM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WK_SEARCH_SYSTEM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WK_SEARCH_SYSTEM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Minimal mode: no diagnostics to avoid any extra overhead

/**
 * Hard host-gate for sync.
 *
 * Single-client plugin: only the production hostnames may push to the search API
 * (delta sync, full-feed file generation, popular/top-categories exports).
 * Any other host — staging.qliving.com, dev.qliving.com, localhost, anything else —
 * is read-only against the search API: search overlay still works, no writes happen.
 *
 * Override for ad-hoc testing: `define('WK_FAST_SEARCH_FORCE_SYNC', true);` in wp-config.php.
 */
function wk_fast_search_sync_allowed() {
    if (defined('WK_FAST_SEARCH_FORCE_SYNC') && WK_FAST_SEARCH_FORCE_SYNC) {
        return true;
    }
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    return $host === 'qliving.com' || $host === 'www.qliving.com';
}

// Include Delta Sync module
require_once WK_SEARCH_SYSTEM_PLUGIN_DIR . 'includes/DeltaSync.php';

// Add custom cron schedule
add_filter('cron_schedules', function($schedules) {
    $schedules['wkfs_5min'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'woo-fast-search')
    ];
    return $schedules;
});

// Minimal settings-only mode to reduce memory usage
add_action('admin_init', function() {
    register_setting('wk_fast_search_settings', 'wk_fast_search_tenant_id');
    register_setting('wk_fast_search_settings', 'wk_fast_search_api_key');
    register_setting('wk_fast_search_settings', 'wk_fast_search_feed_url');
    register_setting('wk_fast_search_settings', 'wk_fast_search_brand_taxonomy');
    register_setting('wk_fast_search_settings', 'wk_fast_search_taxonomy_facets');
    register_setting('wk_fast_search_settings', 'wk_fast_search_attribute_facets');
    register_setting('wk_fast_search_settings', 'wk_fast_search_render_mode'); // 'woocommerce' or 'elementor'
    register_setting('wk_fast_search_settings', 'wk_fast_search_elementor_loop_id');
    register_setting('wk_fast_search_settings', 'wk_fast_search_products_per_page', [
        'type' => 'integer',
        'default' => 40,
        'sanitize_callback' => function($value) {
            return max(10, min(100, (int)$value)); // Between 10 and 100
        }
    ]);
    // Strings settings - Individual fields for each text
    $string_fields = [
        'searchPlaceholder' => __('Search products...', 'woo-fast-search'),
        'filters' => __('Filters', 'woo-fast-search'),
        'price' => __('Price', 'woo-fast-search'),
        'brand' => __('Brand', 'woo-fast-search'),
        'category' => __('Category', 'woo-fast-search'),
        'inStockOnly' => __('In stock only', 'woo-fast-search'),
        'inStock' => __('In stock', 'woo-fast-search'),
        'onSale' => __('On sale', 'woo-fast-search'),
        'results' => __('results', 'woo-fast-search'),
        'relevance' => __('Relevance', 'woo-fast-search'),
        'priceLowHigh' => __('Price: Low to High', 'woo-fast-search'),
        'priceHighLow' => __('Price: High to Low', 'woo-fast-search'),
        'popularity' => __('Popularity', 'woo-fast-search'),
        'rating' => __('Rating', 'woo-fast-search'),
        'newest' => __('Newest', 'woo-fast-search'),
        'loadMore' => __('Load More', 'woo-fast-search'),
        'loading' => __('Loading...', 'woo-fast-search'),
        'noResults' => __('No products found', 'woo-fast-search'),
        'popularSearches' => __('Popular Searches', 'woo-fast-search'),
        'recentSearches' => __('Recent Searches', 'woo-fast-search'),
        'tryDifferentKeywords' => __('Try different keywords or check your spelling', 'woo-fast-search'),
        'error' => __('Search temporarily unavailable', 'woo-fast-search'),
        'outOfStock' => __('Out of stock', 'woo-fast-search'),
        'clear' => __('Clear', 'woo-fast-search'),
        'close' => __('Close', 'woo-fast-search'),
        'didYouMean' => __('Did you mean one of these?', 'woo-fast-search'),
        'showingTopPopular' => __('Showing top {count} popular products', 'woo-fast-search'),
        'showingResultsFor' => __('Showing {count} items for "{query}"', 'woo-fast-search'),
        'showingResults' => __('Showing {count} items', 'woo-fast-search'),
        'tags' => __('Tags', 'woo-fast-search'),
        'productStatus' => __('Product Status', 'woo-fast-search'),
        'anyRating' => __('Any Rating', 'woo-fast-search'),
        'stars' => __('Stars', 'woo-fast-search'),
        'min' => __('Min', 'woo-fast-search'),
        'max' => __('Max', 'woo-fast-search'),
        'classicMode' => __('Classic', 'woo-fast-search'),
        'advancedMode' => __('Advanced', 'woo-fast-search'),
        'searchMode' => __('Search Mode', 'woo-fast-search'),
        'switchToClassic' => __('Switch to Classic Mode', 'woo-fast-search'),
        'switchToAdvanced' => __('Switch to Advanced Mode', 'woo-fast-search'),
    ];

    // Register settings for each string field
    foreach ($string_fields as $key => $default) {
        register_setting('wk_fast_search_strings', 'wk_fast_search_string_' . $key);
    }
    register_setting('wk_fast_search_settings', 'wk_fast_search_selectors');
    register_setting('wk_fast_search_settings', 'wk_fast_search_edge_url');
    register_setting('wk_fast_search_settings', 'wk_fast_search_primary_color');
    register_setting('wk_fast_search_settings', 'wk_fast_search_text_color');
    register_setting('wk_fast_search_settings', 'wk_fast_search_enabled_filters', [
        'sanitize_callback' => function($value) {
            // Ensure all filter keys exist with '0' if not checked
            $all_filters = ['price', 'brand', 'category', 'tags', 'status', 'rating'];
            $sanitized = [];
            foreach ($all_filters as $filter) {
                $sanitized[$filter] = isset($value[$filter]) && $value[$filter] ? '1' : '0';
            }
            return $sanitized;
        }
    ]);
    
    // Search mode settings
    register_setting('wk_fast_search_settings', 'wk_fast_search_search_mode'); // 'classic' or 'advanced'
    register_setting('wk_fast_search_settings', 'wk_fast_search_allow_mode_toggle'); // boolean
    register_setting('wk_fast_search_settings', 'wk_fast_search_sidebar_position'); // 'left' or 'right'
    register_setting('wk_fast_search_settings', 'wk_fast_search_hide_out_of_stock'); // boolean - hide out-of-stock products
    register_setting('wk_fast_search_settings', 'wk_fast_search_search_description'); // boolean - search in product descriptions
    register_setting('wk_fast_search_settings', 'wk_fast_search_show_popular_searches'); // boolean - show popular searches in empty state
    register_setting('wk_fast_search_settings', 'wk_fast_search_show_recent_searches'); // boolean - show recent searches in empty state
    register_setting('wk_fast_search_settings', 'wk_fast_search_debug_mode'); // boolean - enable debug console logging
    register_setting('wk_fast_search_settings', 'wk_fast_search_debug_delta_sync'); // boolean - enable delta sync logging
    register_setting('wk_fast_search_settings', 'wk_fast_search_excluded_product_ids'); // comma-separated product IDs to exclude from search
    register_setting('wk_fast_search_settings', 'wk_fast_search_index_variations'); // boolean - index each variation as its own searchable product
    register_setting('wk_fast_search_settings', 'wk_fast_search_demoted_ids', [
        'type' => 'array',
        'default' => [],
        'sanitize_callback' => function($value) {
            if (!is_array($value)) { return []; }
            $clean = array_map('intval', $value);
            $clean = array_values(array_unique(array_filter($clean, function($id){ return $id > 0; })));
            return $clean;
        }
    ]);

    // Cleanup: ensure any legacy scheduled events are cleared
    if (function_exists('wp_next_scheduled')) {
        foreach (['wk_search_products_json', 'wk_search_full_feed_once'] as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts) { wp_unschedule_event($ts, $hook); }
            if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook($hook); }
        }
    }
});

add_action('admin_menu', function() {
    // Main menu
    add_menu_page(
        __('FAST Search', 'woo-fast-search'),
        __('FAST Search', 'woo-fast-search'),
        'manage_options',
        'wk-fast-search',
        'wk_fast_search_render_settings_page',
        'dashicons-search',
        58
    );

    // Subpage: General settings (points to same renderer for now)
    add_submenu_page(
        'wk-fast-search',
        __('General', 'woo-fast-search'),
        __('General', 'woo-fast-search'),
        'manage_options',
        'wk-fast-search',
        'wk_fast_search_render_settings_page'
    );

    // Subpage: Strings overrides
    add_submenu_page(
        'wk-fast-search',
        __('Strings', 'woo-fast-search'),
        __('Strings', 'woo-fast-search'),
        'manage_options',
        'wk-fast-search-strings',
        'wk_fast_search_render_strings_page'
    );

    // Remove separate submenu; button will be in settings page
});

// Enqueue Select2 (via WooCommerce's selectWoo) + admin.css on the FAST Search settings page only.
add_action('admin_enqueue_scripts', function($hook) {
    // Hook for top-level menu page is "toplevel_page_<slug>".
    if ($hook !== 'toplevel_page_wk-fast-search') {
        return;
    }
    // WooCommerce registers these in admin context. Enqueue both for safety; if Woo isn't active the page degrades gracefully.
    if (wp_script_is('selectWoo', 'registered')) {
        wp_enqueue_script('selectWoo');
    } elseif (wp_script_is('select2', 'registered')) {
        wp_enqueue_script('select2');
    }
    if (wp_style_is('select2', 'registered')) {
        wp_enqueue_style('select2');
    }
    wp_enqueue_style(
        'wk-fast-search-admin',
        plugins_url('assets/css/admin.css', __FILE__),
        [],
        defined('WK_SEARCH_SYSTEM_VERSION') ? WK_SEARCH_SYSTEM_VERSION : '1.0'
    );
});

function wk_fast_search_render_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    $settings = wk_fast_search_get_all_settings();
    $tenant_id = $settings['tenant_id'];
    $api_key = $settings['api_key'];
    $feed_url = $settings['feed_url'];
    $brand_taxonomy = $settings['brand_taxonomy'];
    $taxonomy_facets = $settings['taxonomy_facets'];
    $attribute_facets = $settings['attribute_facets'];
    $custom_selectors = $settings['selectors'];
    $edge_url = $settings['edge_url'];
    if (!is_array($taxonomy_facets)) { $taxonomy_facets = []; }
    if (!is_array($attribute_facets)) { $attribute_facets = []; }

    // Discover product taxonomies and global attributes
    $tax_objects = function_exists('get_object_taxonomies') ? get_object_taxonomies('product', 'objects') : [];
    $all_taxonomies = [];
    if (is_array($tax_objects)) {
        foreach ($tax_objects as $slug => $obj) {
            if (!is_object($obj)) { continue; }
            if (!empty($obj->public)) {
                $all_taxonomies[$slug] = $obj->label ?: $slug;
            }
        }
    }
    // Suggest common brand taxonomies first
    $brand_suggestions = ['product_brand','berocket_brand','pwb-brand'];
    $brand_options = [];
    foreach ($brand_suggestions as $bt) { if (isset($all_taxonomies[$bt])) { $brand_options[$bt] = $all_taxonomies[$bt]; } }
    // Merge with all others
    foreach ($all_taxonomies as $slug => $label) { if (!isset($brand_options[$slug])) { $brand_options[$slug] = $label; } }

    // Global attribute taxonomies
    $global_attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
    $attr_options = [];
    if (is_array($global_attrs)) {
        foreach ($global_attrs as $ga) {
            if (isset($ga->attribute_name)) {
                $slug = 'pa_' . $ga->attribute_name;
                $label = isset($ga->attribute_label) && $ga->attribute_label ? $ga->attribute_label : strtoupper($slug);
                $attr_options[$slug] = $label;
            }
        }
    }
    echo '<div class="wrap wk-settings-wrap"><h1>FAST Search Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('wk_fast_search_settings');
    echo '<div class="wk-card"><h2>' . esc_html__('Connection', 'woo-fast-search') . '</h2>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="wk_fast_search_tenant_id">Tenant ID</label></th><td><input name="wk_fast_search_tenant_id" id="wk_fast_search_tenant_id" type="text" class="regular-text" value="' . esc_attr($tenant_id) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="wk_fast_search_api_key">API Key</label></th><td><input name="wk_fast_search_api_key" id="wk_fast_search_api_key" type="text" class="regular-text" value="' . esc_attr($api_key) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="wk_fast_search_feed_url">Feed File URL</label></th><td><input name="wk_fast_search_feed_url" id="wk_fast_search_feed_url" type="url" class="regular-text code" value="' . esc_attr($feed_url) . '" placeholder="https://example.com/wp-content/uploads/wk-search/{tenant}/products.json" /></td></tr>';
    // Edge URL
    echo '<tr><th scope="row"><label for="wk_fast_search_edge_url">Search API Base URL</label></th><td>';
    echo '<input name="wk_fast_search_edge_url" id="wk_fast_search_edge_url" type="url" class="regular-text code" value="' . esc_attr($edge_url) . '" placeholder="https://api.example.com" />';
    echo '<p class="description">Base URL of the hosted search API (e.g., https://search.example.com). Used for /api/serve/search and suggestions.</p>';
    echo '</td></tr>';
    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Indexing & Facets', 'woo-fast-search') . '</h2>';
    echo '<table class="form-table" role="presentation">';
    // Brand taxonomy selector
    echo '<tr><th scope="row"><label for="wk_fast_search_brand_taxonomy">Brand taxonomy</label></th><td>';
    echo '<select name="wk_fast_search_brand_taxonomy" id="wk_fast_search_brand_taxonomy">';
    echo '<option value="">Auto-detect</option>';
    foreach ($brand_options as $slug => $label) {
        $sel = selected($brand_taxonomy, $slug, false);
        echo '<option value="' . esc_attr($slug) . '" ' . $sel . '>' . esc_html($label . ' (' . $slug . ')') . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Optional. Choose the taxonomy to use for brand.</p>';
    echo '</td></tr>';

    // Extra taxonomy facets selector (multi)
    echo '<tr><th scope="row"><label for="wk_fast_search_taxonomy_facets">Taxonomy facets</label></th><td>';
    echo '<select multiple name="wk_fast_search_taxonomy_facets[]" id="wk_fast_search_taxonomy_facets" style="min-width:280px; height: 120px;">';
    foreach ($all_taxonomies as $slug => $label) {
        if (in_array($slug, ['product_cat','product_tag'], true)) { continue; }
        $sel = in_array($slug, $taxonomy_facets, true) ? 'selected' : '';
        echo '<option value="' . esc_attr($slug) . '" ' . $sel . '>' . esc_html($label . ' (' . $slug . ')') . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Selected taxonomies will be exported under attributes for faceting.</p>';
    echo '</td></tr>';

    // Attribute facets selector (multi)
    echo '<tr><th scope="row"><label for="wk_fast_search_attribute_facets">Attribute facets</label></th><td>';
    echo '<select multiple name="wk_fast_search_attribute_facets[]" id="wk_fast_search_attribute_facets" style="min-width:280px; height: 120px;">';
    foreach ($attr_options as $slug => $label) {
        $sel = in_array($slug, $attribute_facets, true) ? 'selected' : '';
        echo '<option value="' . esc_attr($slug) . '" ' . $sel . '>' . esc_html($label . ' (' . $slug . ')') . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Select which global attributes (pa_*) to include as facets. Leave empty to include all.</p>';
    echo '</td></tr>';

    // Index variations as individual products
    $index_variations = $settings['index_variations'] ?? '1';
    echo '<tr><th scope="row"><label for="wk_fast_search_index_variations">' . esc_html__('Index variations as individual products', 'woo-fast-search') . '</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_index_variations" id="wk_fast_search_index_variations" value="1" ' . checked($index_variations, '1', false) . '> ';
    echo esc_html__('Each enabled variation appears as its own search result; the parent variable product is hidden from the index', 'woo-fast-search') . '</label>';
    echo '<p class="description">' . esc_html__('When ON, a "T-Shirt – Black, M" variation can be matched and ranked individually. When OFF, only the parent product is indexed (with a price range). After changing this, run the full sync to rebuild the index.', 'woo-fast-search') . '</p>';
    echo '</td></tr>';

    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Display', 'woo-fast-search') . '</h2>';
    echo '<table class="form-table" role="presentation">';
    // Renderer mode
    $render_mode = $settings['render_mode'];
    echo '<tr><th scope="row"><label for="wk_fast_search_render_mode">' . esc_html__('Product renderer', 'woo-fast-search') . '</label></th><td>';
    echo '<select name="wk_fast_search_render_mode" id="wk_fast_search_render_mode">';
    echo '<option value="woocommerce"' . selected($render_mode, 'woocommerce', false) . '>' . esc_html__('WooCommerce template', 'woo-fast-search') . '</option>';
    echo '<option value="elementor"' . selected($render_mode, 'elementor', false) . '>' . esc_html__('Elementor Loop Item', 'woo-fast-search') . '</option>';
    echo '</select>';
    echo '</td></tr>';

    // Elementor Loop template id
    $loop_id = intval($settings['elementor_loop_id']);
    echo '<tr><th scope="row"><label for="wk_fast_search_elementor_loop_id">' . esc_html__('Elementor Loop Item ID', 'woo-fast-search') . '</label></th><td>';
    echo '<input type="number" name="wk_fast_search_elementor_loop_id" id="wk_fast_search_elementor_loop_id" value="' . esc_attr($loop_id) . '" min="0" style="width: 180px;" />';
    echo '<p class="description">' . esc_html__('Used when Product renderer = Elementor Loop Item', 'woo-fast-search') . '</p>';
    echo '</td></tr>';

    // Products Per Page
    $products_per_page = intval($settings['products_per_page']);
    echo '<tr><th scope="row"><label for="wk_fast_search_products_per_page">' . esc_html__('Products Per Page', 'woo-fast-search') . '</label></th><td>';
    echo '<input type="number" name="wk_fast_search_products_per_page" id="wk_fast_search_products_per_page" value="' . esc_attr($products_per_page) . '" min="10" max="100" step="10" style="width: 180px;" />';
    echo '<p class="description">' . esc_html__('Number of products to show per page in search results (10-100, increments of 10). Default: 40', 'woo-fast-search') . '</p>';
    echo '</td></tr>';

    // Custom search form selectors (comma-separated)
    echo '<tr><th scope="row"><label for="wk_fast_search_selectors">Search form selectors</label></th><td>';
    echo '<input name="wk_fast_search_selectors" id="wk_fast_search_selectors" type="text" class="regular-text code" value="' . esc_attr($custom_selectors) . '" placeholder="#search, .header-search input[name=\'s\']" />';
    echo '<p class="description">Comma-separated CSS selectors. Clicking or focusing these will open the FAST Search overlay.</p>';
    echo '</td></tr>';
    
    // Color settings
    $primary_color = $settings['primary_color'];
    $text_color = $settings['text_color'];
    
    echo '<tr><th scope="row"><label for="wk_fast_search_primary_color">Primary Color</label></th><td>';
    echo '<div style="display: flex; align-items: center; gap: 10px;">';
    echo '<input name="wk_fast_search_primary_color" id="wk_fast_search_primary_color" type="text" class="regular-text" value="' . esc_attr($primary_color) . '" placeholder="#0071ce" pattern="^#[0-9A-Fa-f]{6}$" />';
    echo '<input type="color" id="wk_fast_search_primary_color_picker" value="' . esc_attr($primary_color) . '" style="width: 50px; height: 38px; border: 1px solid #ddd; border-radius: 4px;" />';
    echo '</div>';
    echo '<p class="description">Primary color for the search overlay header and focus states. Enter hex code (e.g., #0071ce) or use color picker.</p>';
    echo '</td></tr>';
    
    echo '<tr><th scope="row"><label for="wk_fast_search_text_color">Text Color</label></th><td>';
    echo '<div style="display: flex; align-items: center; gap: 10px;">';
    echo '<input name="wk_fast_search_text_color" id="wk_fast_search_text_color" type="text" class="regular-text" value="' . esc_attr($text_color) . '" placeholder="#ffffff" pattern="^#[0-9A-Fa-f]{6}$" />';
    echo '<input type="color" id="wk_fast_search_text_color_picker" value="' . esc_attr($text_color) . '" style="width: 50px; height: 38px; border: 1px solid #ddd; border-radius: 4px;" />';
    echo '</div>';
    echo '<p class="description">Text color for the search overlay header (should contrast with primary color). Enter hex code (e.g., #ffffff) or use color picker.</p>';
    echo '</td></tr>';
    
    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Search Behavior', 'woo-fast-search') . '</h2>';
    echo '<table class="form-table" role="presentation">';
    // Filter settings
    $enabled_filters = $settings['enabled_filters'];

    echo '<tr><th scope="row">Enabled Filters</th><td>';
    echo '<fieldset>';
    echo '<legend class="screen-reader-text"><span>Enabled Filters</span></legend>';
    
    $filter_options = array(
        'price' => 'Price Range',
        'brand' => 'Brand',
        'category' => 'Category', 
        'tags' => 'Tags',
        'status' => 'Product Status (In Stock, On Sale, Featured)',
        'rating' => 'Rating'
    );
    
    foreach ($filter_options as $key => $label) {
        $checked = isset($enabled_filters[$key]) && $enabled_filters[$key] ? 'checked' : '';
        echo '<label><input type="checkbox" name="wk_fast_search_enabled_filters[' . $key . ']" value="1" ' . $checked . '> ' . $label . '</label><br>';
    }
    
    echo '</fieldset>';
    echo '<p class="description">Select which filters to show in the search overlay sidebar. Unchecked filters will be hidden.</p>';
    echo '</td></tr>';
    
    // Search Mode settings
    $search_mode = $settings['search_mode'];
    echo '<tr><th scope="row"><label for="wk_fast_search_search_mode">Search Mode</label></th><td>';
    echo '<select name="wk_fast_search_search_mode" id="wk_fast_search_search_mode">';
    echo '<option value="classic"' . selected($search_mode, 'classic', false) . '>Classic (Strict matching - exact products prioritized)</option>';
    echo '<option value="advanced"' . selected($search_mode, 'advanced', false) . '>Advanced (Smart relevance - AI-powered ranking)</option>';
    echo '</select>';
    echo '<p class="description"><strong>Classic Mode:</strong> Prioritizes exact matches and shows ALL matching products. Best for finding specific items.<br>';
    echo '<strong>Advanced Mode:</strong> Uses intelligent ranking with relevance scoring. Best for discovery.</p>';
    echo '</td></tr>';
    
    // Allow mode toggle
    $allow_toggle = $settings['allow_mode_toggle'];
    echo '<tr><th scope="row"><label for="wk_fast_search_allow_mode_toggle">Allow User Mode Toggle</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_allow_mode_toggle" id="wk_fast_search_allow_mode_toggle" value="1" ' . checked($allow_toggle, '1', false) . '> ';
    echo 'Show toggle button in search overlay to let users switch between Classic and Advanced modes</label>';
    echo '<p class="description">When enabled, users can toggle between search modes while searching.</p>';
    echo '</td></tr>';
    
    // Sidebar position
    $sidebar_position = $settings['sidebar_position'];
    echo '<tr><th scope="row"><label for="wk_fast_search_sidebar_position">Sidebar Position</label></th><td>';
    echo '<select name="wk_fast_search_sidebar_position" id="wk_fast_search_sidebar_position">';
    echo '<option value="left"' . selected($sidebar_position, 'left', false) . '>Left</option>';
    echo '<option value="right"' . selected($sidebar_position, 'right', false) . '>Right</option>';
    echo '</select>';
    echo '<p class="description">Position of the filter sidebar in the search overlay.</p>';
    echo '</td></tr>';
    
    // Hide out-of-stock products
    $hide_out_of_stock = $settings['hide_out_of_stock'];
    echo '<tr><th scope="row"><label for="wk_fast_search_hide_out_of_stock">Hide Out-of-Stock Products</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_hide_out_of_stock" id="wk_fast_search_hide_out_of_stock" value="1" ' . checked($hide_out_of_stock, '1', false) . '> ';
    echo 'Automatically exclude out-of-stock products from search results</label>';
    echo '<p class="description">When enabled, products with no stock will be hidden from search results. This setting can also be controlled via the API tenant settings.</p>';
    echo '</td></tr>';
    
    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Diagnostics', 'woo-fast-search') . '</h2>';
    echo '<table class="form-table" role="presentation">';
    // Debug Mode
    $debug_mode = $settings['debug_mode'];
    echo '<tr><th scope="row"><label for="wk_fast_search_debug_mode">Debug Mode</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_debug_mode" id="wk_fast_search_debug_mode" value="1" ' . checked($debug_mode, '1', false) . '> ';
    echo 'Enable console logging for debugging</label>';
    echo '<p class="description">When enabled, detailed search logs will appear in the browser console. <strong>Disable in production.</strong></p>';
    echo '</td></tr>';
    
    // Debug Delta Sync
    $debug_delta = $settings['debug_delta_sync'] ?? '0';
    echo '<tr><th scope="row"><label for="wk_fast_search_debug_delta_sync">Debug Delta Sync</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_debug_delta_sync" id="wk_fast_search_debug_delta_sync" value="1" ' . checked($debug_delta, '1', false) . '> ';
    echo 'Enable delta sync logging</label>';
    echo '<p class="description">When enabled, delta sync operations will be logged to <code>wp-content/uploads/wk-search/logs/delta-sync.log</code>. Useful for troubleshooting real-time product sync issues.</p>';
    echo '</td></tr>';
    
    // Search in description
    $search_description = $settings['search_description'];
    echo '<tr><th scope="row"><label for="wk_fast_search_search_description">Search in Product Descriptions</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_search_description" id="wk_fast_search_search_description" value="1" ' . checked($search_description, '1', false) . '> ';
    echo 'Include product descriptions in search queries</label>';
    echo '<p class="description">When enabled, searches will also match keywords in product descriptions. <strong>Note:</strong> This may reduce search performance and cause less relevant results. Disabled by default.</p>';
    echo '</td></tr>';
    
    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Empty State', 'woo-fast-search') . '</h2>';
    echo '<p class="wk-card-desc">' . esc_html__('What to show in the search overlay before the user types anything.', 'woo-fast-search') . '</p>';
    echo '<table class="form-table" role="presentation">';
    // Show Popular Searches
    $show_popular = $settings['show_popular_searches'] ?? 'no';
    echo '<tr><th scope="row"><label for="wk_fast_search_show_popular_searches">Show Popular Searches</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_show_popular_searches" id="wk_fast_search_show_popular_searches" value="yes" ' . checked($show_popular, 'yes', false) . '> ';
    echo 'Display trending/popular search terms in empty state</label>';
    echo '<p class="description">When enabled, users will see popular searches before they start typing. Disabled by default.</p>';
    echo '</td></tr>';
    
    // Show Recent Searches
    $show_recent = $settings['show_recent_searches'] ?? 'no';
    echo '<tr><th scope="row"><label for="wk_fast_search_show_recent_searches">Show Recent Searches</label></th><td>';
    echo '<label><input type="checkbox" name="wk_fast_search_show_recent_searches" id="wk_fast_search_show_recent_searches" value="yes" ' . checked($show_recent, 'yes', false) . '> ';
    echo 'Display user\'s recent search history in empty state</label>';
    echo '<p class="description">When enabled, users will see their own recent searches before they start typing. Disabled by default.</p>';
    echo '</td></tr>';
    
    echo '</table></div>';
    echo '<div class="wk-card"><h2>' . esc_html__('Service Products', 'woo-fast-search') . '</h2>';
    echo '<p class="wk-card-desc">' . esc_html__('Special-handling products such as shipping fees, mileage charges, or miscellaneous service items. They stay searchable by name but are kept out of the way on default surfaces.', 'woo-fast-search') . '</p>';
    echo '<table class="form-table" role="presentation">';

    // Excluded Product IDs (hidden everywhere)
    $excluded_ids = $settings['excluded_product_ids'] ?? '';
    echo '<tr><th scope="row"><label for="wk_fast_search_excluded_product_ids">' . esc_html__('Excluded Product IDs', 'woo-fast-search') . '</label></th><td>';
    echo '<input type="text" name="wk_fast_search_excluded_product_ids" id="wk_fast_search_excluded_product_ids" class="regular-text" value="' . esc_attr($excluded_ids) . '" placeholder="444, 123, 789" />';
    echo '<p class="description">' . esc_html__('Comma-separated product IDs to hide from search results entirely. Use sparingly — these products will never appear in search.', 'woo-fast-search') . '</p>';
    echo '</td></tr>';

    // Push-to-bottom product IDs (push to bottom in search; hide on popular/empty-state)
    $demoted_ids = isset($settings['demoted_ids']) && is_array($settings['demoted_ids']) ? array_map('intval', $settings['demoted_ids']) : [];
    echo '<tr><th scope="row"><label for="wk_fast_search_demoted_ids">' . esc_html__('Push to bottom', 'woo-fast-search') . '</label></th><td>';
    // Sentinel hidden input ensures the option saves as empty array when the picker is cleared
    // (an empty multi-select posts no key at all, which would leave the old value unchanged).
    echo '<input type="hidden" name="wk_fast_search_demoted_ids[]" value="" />';
    echo '<select multiple class="wk-product-picker" name="wk_fast_search_demoted_ids[]" id="wk_fast_search_demoted_ids" data-placeholder="' . esc_attr__('Type a product name…', 'woo-fast-search') . '" style="width: 100%; max-width: 600px;">';
    if (!empty($demoted_ids) && function_exists('wc_get_product')) {
        foreach ($demoted_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) { continue; }
            $label = sprintf('%s (#%d)', $product->get_formatted_name(), $pid);
            echo '<option value="' . esc_attr($pid) . '" selected>' . esc_html($label) . '</option>';
        }
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Selected products will be pushed to the bottom of search results and hidden from the empty-state popular products list. They remain findable when the customer searches for them by name.', 'woo-fast-search') . '</p>';
    echo '</td></tr>';

    echo '</table></div>';
    submit_button('Save Changes');
    echo '</form>';

    // Initialize Select2 product picker for demoted_ids using WooCommerce's built-in product search AJAX.
    $wc_search_nonce = function_exists('wp_create_nonce') ? wp_create_nonce('search-products') : '';
    echo '<script>
    (function(){
        function initPicker(){
            if (typeof jQuery === "undefined") { return; }
            var $sel = jQuery("#wk_fast_search_demoted_ids");
            if (!$sel.length) { return; }
            var s2 = (typeof jQuery.fn.selectWoo === "function") ? "selectWoo" : ((typeof jQuery.fn.select2 === "function") ? "select2" : null);
            if (!s2) { return; }
            $sel[s2]({
                multiple: true,
                placeholder: $sel.data("placeholder") || "",
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: ' . wp_json_encode(admin_url('admin-ajax.php')) . ',
                    dataType: "json",
                    delay: 250,
                    data: function(params){
                        return {
                            action: "woocommerce_json_search_products",
                            security: ' . wp_json_encode($wc_search_nonce) . ',
                            term: params.term,
                            exclude_type: "variation"
                        };
                    },
                    processResults: function(data){
                        var items = [];
                        jQuery.each(data, function(id, text){
                            items.push({ id: id, text: text });
                        });
                        return { results: items };
                    },
                    cache: true
                }
            });
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initPicker);
        } else {
            initPicker();
        }
    })();
    </script>';
    
    // Add JavaScript to sync text inputs with color pickers
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Sync primary color
        const primaryText = document.getElementById("wk_fast_search_primary_color");
        const primaryPicker = document.getElementById("wk_fast_search_primary_color_picker");
        
        primaryText.addEventListener("input", function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                primaryPicker.value = this.value;
            }
        });
        
        primaryPicker.addEventListener("input", function() {
            primaryText.value = this.value;
        });
        
        // Sync text color
        const textText = document.getElementById("wk_fast_search_text_color");
        const textPicker = document.getElementById("wk_fast_search_text_color_picker");
        
        textText.addEventListener("input", function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                textPicker.value = this.value;
            }
        });
        
        textPicker.addEventListener("input", function() {
            textText.value = this.value;
        });
    });
    </script>';

    if (!empty($_GET['wk_generated'])) {
        $count = isset($_GET['wk_count']) ? intval($_GET['wk_count']) : 0;
        $tenant_slug = sanitize_title($settings['tenant_id'] ?: 'default');
        $uploads = wp_upload_dir();
        $products_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant_slug . '/products.json';
        echo '<div class="notice notice-success is-dismissible"><p>Generated products.json with ' . esc_html($count) . ' products.</p>';
        echo '<p><strong>Products JSON URL:</strong> <code>' . esc_html($products_url) . '</code></p></div>';
    }
    if (!empty($_GET['wk_sync_blocked'])) {
        $current_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Sync is disabled on this host (<code>' . esc_html($current_host) . '</code>).</strong> Only the production hostnames may push to the search API. Search overlay still works in read-only mode.</p></div>';
    }
    echo '<div class="wk-card"><h2>' . esc_html__('Manual Feed Generation', 'woo-fast-search') . '</h2>';
    echo '<p class="wk-card-desc">' . esc_html__('Generate products.json on-demand. Does not set up any background task.', 'woo-fast-search') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wk_fast_search_generate" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wk_fast_search_generate')) . '" />';
    submit_button('Generate products.json now', 'secondary');
    echo '</form></div>';

    // Command line documentation — same effect as the button above, useful for cron / scripted ops.
    echo '<div class="wk-card"><h2>' . esc_html__('Command Line (WP-CLI)', 'woo-fast-search') . '</h2>';
    echo '<p class="wk-card-desc">' . esc_html__('Trigger a full re-sync from the shell. Same effect as the "Generate products.json now" button — useful for cron jobs, scripted deploys, or remote terminals.', 'woo-fast-search') . '</p>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row">' . esc_html__('Generate the feed', 'woo-fast-search') . '</th><td>';
    echo '<p><code>wp wkfs feed:generate</code></p>';
    echo '<p class="description">' . esc_html__('Subject to the host-gate: runs only on the production hostnames (qliving.com / www.qliving.com). On staging, dev, or any other host it exits with a warning. Add `define(\'WK_FAST_SEARCH_FORCE_SYNC\', true);` to wp-config.php only if you genuinely need to sync from a non-production host.', 'woo-fast-search') . '</p>';
    echo '</td></tr>';
    echo '</table></div>';

    // Manual sync popular queries (button under products.json section)
    echo '<div class="wk-card"><h2>' . esc_html__('Popular Searches', 'woo-fast-search') . '</h2>';
    echo '<p class="wk-card-desc">' . esc_html__('Export a popular_searches.json file alongside products.json. Configure the URL in Laravel and run sync from there.', 'woo-fast-search') . '</p>';
    if (!empty($_GET['wk_popular_exported'])) {
        echo '<div class="notice notice-success is-dismissible"><p>popular_searches.json exported.</p></div>';
    }
    // Show the expected URL for convenience
    $tenant_slug = sanitize_title($settings['tenant_id'] ?: 'default');
    $uploads = wp_upload_dir();
    $popular_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant_slug . '/popular_searches.json';
    echo '<p><code>'.esc_html($popular_url).'</code></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wk_fast_search_export_popular" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wk_fast_search_export_popular')) . '" />';
    submit_button('Export popular_searches.json', 'secondary');
    echo '</form></div>';

    // Top Categories export (by sales)
    echo '<div class="wk-card"><h2>' . esc_html__('Top Categories (by sales)', 'woo-fast-search') . '</h2>';
    if (!empty($_GET['wk_topcats_exported'])) {
        echo '<div class="notice notice-success is-dismissible"><p>top_categories.json exported.</p></div>';
    }
    $topcats_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant_slug . '/top_categories.json';
    echo '<p><code>'.esc_html($topcats_url).'</code></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wk_fast_search_export_top_categories" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wk_fast_search_export_top_categories')) . '" />';
    submit_button('Export top_categories.json', 'secondary');
    echo '</form></div>';

    echo '</div>';
}

// ==== Popular Searches: DB Table, REST Route, Cron, Manual Sync ====
register_activation_hook(__FILE__, function(){
    global $wpdb;
    $table = $wpdb->prefix . 'wk_search_popular';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `tenant_id` varchar(191) NOT NULL,
        `query` varchar(191) NOT NULL,
        `count` int(11) NOT NULL DEFAULT 0,
        `last_searched` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tenant_query` (`tenant_id`,`query`)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// Ensure table exists helper (call before any usage to avoid SQL errors if activation did not run)
function wk_fs_ensure_popular_table_exists() {
    global $wpdb;
    $table = $wpdb->prefix . 'wk_search_popular';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists === $table) { return; }
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `tenant_id` varchar(191) NOT NULL,
        `query` varchar(191) NOT NULL,
        `count` int(11) NOT NULL DEFAULT 0,
        `last_searched` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tenant_query` (`tenant_id`,`query`)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

add_action('rest_api_init', function(){
    register_rest_route('wk-search/v1', '/track', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function($request){
            global $wpdb;
            wk_fs_ensure_popular_table_exists();
            $table = $wpdb->prefix . 'wk_search_popular';
            $settings = wk_fast_search_get_all_settings();
            $tenant = $settings['tenant_id'] ?: 'default';
            $query = trim(strtolower((string) ($request->get_param('query') ?? ($request->get_param('q') ?? ''))));
            if (strlen($query) < 2) { return new WP_REST_Response(['ok'=>true,'skipped'=>true], 200); }
            $now = current_time('mysql');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `$table` (tenant_id, `query`, `count`, last_searched) VALUES (%s,%s,1,%s)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1, last_searched = VALUES(last_searched)",
                $tenant, $query, $now
            ));
            return new WP_REST_Response(['ok'=>true], 200);
        }
    ]);
});

// Manual push popular searches to Laravel (URL-based ingest)
add_action('wk_fast_search_push_popular', function(){
    // Host-gate: only production may push to the search API.
    if (!wk_fast_search_sync_allowed()) { return; }
    // Push aggregated queries to Laravel and reset counts upon success
    global $wpdb;
    wk_fs_ensure_popular_table_exists();
    $table = $wpdb->prefix . 'wk_search_popular';
    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'];
    $key = $settings['api_key'];
    $edge = $settings['edge_url'];
    if (empty($tenant) || empty($key) || empty($edge)) { return; }
    $uploads = wp_upload_dir();
    $tenant_slug = sanitize_title($tenant);
    $popular_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant_slug . '/popular_searches.json';
    $payload = [ 'popular_url' => $popular_url ];
    $resp = wp_remote_post( rtrim($edge,'/').'/api/serve/ingest/popular-queries-url', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $key,
            'X-Tenant-Id' => $tenant,
            'X-User' => 'default'
        ],
        'timeout' => 5,
        'body' => wp_json_encode($payload)
    ]);
    if (is_wp_error($resp)) { set_transient('wk_popular_sync_error', $resp->get_error_message(), 60); return; }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 200 && $code < 300) {
        set_transient('wk_popular_sync_ok', 1, 60);
    } else {
        set_transient('wk_popular_sync_error', 'HTTP '.$code, 60);
    }
});

// Strings page
function wk_fast_search_render_strings_page() {
    if (!current_user_can('manage_options')) { return; }
    
    $string_fields = [
        'searchPlaceholder' => __('Search products...', 'woo-fast-search'),
        'filters' => __('Filters', 'woo-fast-search'),
        'price' => __('Price', 'woo-fast-search'),
        'brand' => __('Brand', 'woo-fast-search'),
        'category' => __('Category', 'woo-fast-search'),
        'inStockOnly' => __('In stock only', 'woo-fast-search'),
        'inStock' => __('In stock', 'woo-fast-search'),
        'onSale' => __('On sale', 'woo-fast-search'),
        'results' => __('results', 'woo-fast-search'),
        'relevance' => __('Relevance', 'woo-fast-search'),
        'priceLowHigh' => __('Price: Low to High', 'woo-fast-search'),
        'priceHighLow' => __('Price: High to Low', 'woo-fast-search'),
        'popularity' => __('Popularity', 'woo-fast-search'),
        'rating' => __('Rating', 'woo-fast-search'),
        'newest' => __('Newest', 'woo-fast-search'),
        'loadMore' => __('Load More', 'woo-fast-search'),
        'loading' => __('Loading...', 'woo-fast-search'),
        'noResults' => __('No products found', 'woo-fast-search'),
        'popularSearches' => __('Popular Searches', 'woo-fast-search'),
        'recentSearches' => __('Recent Searches', 'woo-fast-search'),
        'tryDifferentKeywords' => __('Try different keywords or check your spelling', 'woo-fast-search'),
        'error' => __('Search temporarily unavailable', 'woo-fast-search'),
        'outOfStock' => __('Out of stock', 'woo-fast-search'),
        'clear' => __('Clear', 'woo-fast-search'),
        'close' => __('Close', 'woo-fast-search'),
        'didYouMean' => __('Did you mean one of these?', 'woo-fast-search'),
        'showingTopPopular' => __('Showing top {count} popular products', 'woo-fast-search'),
        'showingResultsFor' => __('Showing {count} items for "{query}"', 'woo-fast-search'),
        'showingResults' => __('Showing {count} items', 'woo-fast-search'),
        'tags' => __('Tags', 'woo-fast-search'),
        'productStatus' => __('Product Status', 'woo-fast-search'),
        'anyRating' => __('Any Rating', 'woo-fast-search'),
        'stars' => __('Stars', 'woo-fast-search'),
        'min' => __('Min', 'woo-fast-search'),
        'max' => __('Max', 'woo-fast-search'),
        'classicMode' => __('Classic', 'woo-fast-search'),
        'advancedMode' => __('Advanced', 'woo-fast-search'),
        'searchMode' => __('Search Mode', 'woo-fast-search'),
        'switchToClassic' => __('Switch to Classic Mode', 'woo-fast-search'),
        'switchToAdvanced' => __('Switch to Advanced Mode', 'woo-fast-search'),
    ];
    
    echo '<div class="wrap">';

    echo '<h1>' . esc_html__('FAST Search – Text Customization', 'woo-fast-search') . '</h1>';
    echo '<p>' . esc_html__('Customize all text strings used in the search overlay. Leave fields empty to use default translations.', 'woo-fast-search') . '</p>';
    echo '<form method="post" action="options.php">';
    settings_fields('wk_fast_search_strings');
    
    echo '<table class="form-table">';
    $settings = wk_fast_search_get_all_settings();
    foreach ($string_fields as $key => $default) {
        $value = isset($settings['strings'][$key]) ? $settings['strings'][$key] : '';
        echo '<tr>';
        echo '<th scope="row"><label for="wk_fast_search_string_' . esc_attr($key) . '">' . esc_html($default) . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="wk_fast_search_string_' . esc_attr($key) . '" name="wk_fast_search_string_' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Key: ', 'woo-fast-search') . '<code>' . esc_html($key) . '</code></p>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    submit_button();
    echo '</form>';
    echo '</div>';
}

// Centralized settings cache - ONE database query instead of 100+
function wk_fast_search_get_all_settings() {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    
    // Fetch all settings in ONE batch to avoid multiple DB queries
    global $wpdb;
    $option_names = array(
        'wk_fast_search_tenant_id',
        'wk_fast_search_api_key',
        'wk_fast_search_edge_url',
        'wk_fast_search_feed_url',
        'wk_fast_search_brand_taxonomy',
        'wk_fast_search_taxonomy_facets',
        'wk_fast_search_attribute_facets',
        'wk_fast_search_selectors',
        'wk_fast_search_render_mode',
        'wk_fast_search_elementor_loop_id',
        'wk_fast_search_products_per_page',
        'wk_fast_search_primary_color',
        'wk_fast_search_text_color',
        'wk_fast_search_enabled_filters',
        'wk_fast_search_search_mode',
        'wk_fast_search_allow_mode_toggle',
        'wk_fast_search_sidebar_position',
        'wk_fast_search_hide_out_of_stock',
        'wk_fast_search_show_popular_searches',
        'wk_fast_search_show_recent_searches',
        'wk_fast_search_debug_mode',
        'wk_fast_search_debug_delta_sync',
        'wk_fast_search_search_description',
        'wk_fast_search_excluded_product_ids',
        'wk_fast_search_demoted_ids',
        'wk_fast_search_index_variations',
        // String overrides
        'wk_fast_search_string_searchPlaceholder',
        'wk_fast_search_string_filters',
        'wk_fast_search_string_price',
        'wk_fast_search_string_brand',
        'wk_fast_search_string_category',
        'wk_fast_search_string_inStockOnly',
        'wk_fast_search_string_inStock',
        'wk_fast_search_string_onSale',
        'wk_fast_search_string_results',
        'wk_fast_search_string_relevance',
        'wk_fast_search_string_priceLowHigh',
        'wk_fast_search_string_priceHighLow',
        'wk_fast_search_string_popularity',
        'wk_fast_search_string_rating',
        'wk_fast_search_string_newest',
        'wk_fast_search_string_loadMore',
        'wk_fast_search_string_loading',
        'wk_fast_search_string_noResults',
        'wk_fast_search_string_popularSearches',
        'wk_fast_search_string_recentSearches',
        'wk_fast_search_string_tryDifferentKeywords',
        'wk_fast_search_string_error',
        'wk_fast_search_string_outOfStock',
        'wk_fast_search_string_clear',
        'wk_fast_search_string_close',
        'wk_fast_search_string_didYouMean',
        'wk_fast_search_string_showingTopPopular',
        'wk_fast_search_string_showingResultsFor',
        'wk_fast_search_string_showingResults',
        'wk_fast_search_string_tags',
        'wk_fast_search_string_productStatus',
        'wk_fast_search_string_anyRating',
        'wk_fast_search_string_stars',
        'wk_fast_search_string_min',
        'wk_fast_search_string_max',
        'wk_fast_search_string_classicMode',
        'wk_fast_search_string_advancedMode',
        'wk_fast_search_string_searchMode',
        'wk_fast_search_string_switchToClassic',
        'wk_fast_search_string_switchToAdvanced',
    );
    
    // ONE query to fetch all options at once
    $placeholders = implode(',', array_fill(0, count($option_names), '%s'));
    $query = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
    $results = $wpdb->get_results($wpdb->prepare($query, $option_names), OBJECT_K);
    
    // Build cache array with defaults
    $cache = array(
        'tenant_id' => '',
        'api_key' => '',
        'edge_url' => '',
        'feed_url' => '',
        'brand_taxonomy' => '',
        'taxonomy_facets' => array(),
        'attribute_facets' => array(),
        'selectors' => '',
        'render_mode' => 'woocommerce',
        'elementor_loop_id' => 0,
        'products_per_page' => 40,
        'primary_color' => '#0071ce',
        'text_color' => '#ffffff',
        'enabled_filters' => array('price' => '1', 'brand' => '1', 'category' => '1', 'tags' => '1', 'status' => '1', 'rating' => '1'),
        'search_mode' => 'advanced',
        'allow_mode_toggle' => '0',
        'sidebar_position' => 'left',
        'hide_out_of_stock' => '0',
        'show_popular_searches' => 'no',
        'show_recent_searches' => 'no',
        'debug_mode' => '0',
        'debug_delta_sync' => '0',
        'search_description' => '0',
        'excluded_product_ids' => '',
        'demoted_ids' => array(),
        'index_variations' => '1',
        'strings' => array(
            'searchPlaceholder' => __('Search products...', 'woo-fast-search'),
            'filters' => __('Filters', 'woo-fast-search'),
            'price' => __('Price', 'woo-fast-search'),
            'brand' => __('Brand', 'woo-fast-search'),
            'category' => __('Category', 'woo-fast-search'),
            'inStockOnly' => __('In stock only', 'woo-fast-search'),
            'inStock' => __('In stock', 'woo-fast-search'),
            'onSale' => __('On sale', 'woo-fast-search'),
            'results' => __('results', 'woo-fast-search'),
            'relevance' => __('Relevance', 'woo-fast-search'),
            'priceLowHigh' => __('Price: Low to High', 'woo-fast-search'),
            'priceHighLow' => __('Price: High to Low', 'woo-fast-search'),
            'popularity' => __('Popularity', 'woo-fast-search'),
            'rating' => __('Rating', 'woo-fast-search'),
            'newest' => __('Newest', 'woo-fast-search'),
            'loadMore' => __('Load More', 'woo-fast-search'),
            'loading' => __('Loading...', 'woo-fast-search'),
            'noResults' => __('No products found', 'woo-fast-search'),
            'popularSearches' => __('Popular Searches', 'woo-fast-search'),
            'recentSearches' => __('Recent Searches', 'woo-fast-search'),
            'tryDifferentKeywords' => __('Try different keywords or check your spelling', 'woo-fast-search'),
            'error' => __('Search temporarily unavailable', 'woo-fast-search'),
            'outOfStock' => __('Out of stock', 'woo-fast-search'),
            'clear' => __('Clear', 'woo-fast-search'),
            'close' => __('Close', 'woo-fast-search'),
            'didYouMean' => __('Did you mean one of these?', 'woo-fast-search'),
            'showingTopPopular' => __('Showing top {count} popular products', 'woo-fast-search'),
            'showingResultsFor' => __('Showing {count} items for "{query}"', 'woo-fast-search'),
            'showingResults' => __('Showing {count} items', 'woo-fast-search'),
            'tags' => __('Tags', 'woo-fast-search'),
            'productStatus' => __('Product Status', 'woo-fast-search'),
            'anyRating' => __('Any Rating', 'woo-fast-search'),
            'stars' => __('Stars', 'woo-fast-search'),
            'min' => __('Min', 'woo-fast-search'),
            'max' => __('Max', 'woo-fast-search'),
            'classicMode' => __('Classic', 'woo-fast-search'),
            'advancedMode' => __('Advanced', 'woo-fast-search'),
            'searchMode' => __('Search Mode', 'woo-fast-search'),
            'switchToClassic' => __('Switch to Classic Mode', 'woo-fast-search'),
            'switchToAdvanced' => __('Switch to Advanced Mode', 'woo-fast-search'),
            'noProductsFound' => __('Ingen produkter fundet', 'woo-fast-search'),
            'noSuggestionsAvailable' => __('Ingen forslag tilgængelige', 'woo-fast-search'),
            'suggestions' => __('Forslag', 'woo-fast-search'),
        ),
    );
    
    // Populate from database results
    foreach ($results as $option_name => $row) {
        $value = maybe_unserialize($row->option_value);
        
        if (strpos($option_name, 'wk_fast_search_string_') === 0) {
            $key = str_replace('wk_fast_search_string_', '', $option_name);
            if (!empty($value)) {
                $cache['strings'][$key] = $value;
            }
        } else {
            $key = str_replace('wk_fast_search_', '', $option_name);
            $cache[$key] = $value;
        }
    }
    
    return $cache;
}

// Helper function to get string with proper fallback
function getStringWithFallback($option_name, $default_value) {
    static $settings = null;
    if ($settings === null) {
        $settings = wk_fast_search_get_all_settings();
    }
    
    $key = str_replace('wk_fast_search_string_', '', $option_name);
    return isset($settings['strings'][$key]) && !empty($settings['strings'][$key]) 
        ? $settings['strings'][$key] 
        : $default_value;
}

// ====== REST: Product HTML rendering (no admin-ajax) ======
// Removed REST renderer per decision to pre-render HTML into products.json for speed.

// Minimal WP-CLI command: trigger a full feed regeneration.
// Usage: wp wkfs feed:generate
// Same host-gate applies — non-prod hosts return 0 silently.
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wkfs feed:generate', function() {
        if (!wk_fast_search_sync_allowed()) {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            WP_CLI::warning("Sync is disabled on this host ({$host}). Define WK_FAST_SEARCH_FORCE_SYNC for an override.");
            return;
        }
        WP_CLI::log('Generating products.json…');
        $count = wk_fast_search_generate_products_json();
        WP_CLI::success("Wrote {$count} rows to products.json");
    });
}

// Manual generation handler
add_action('admin_post_wk_fast_search_generate', 'wk_fast_search_handle_manual_generate');
function wk_fast_search_handle_manual_generate() {
    if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
    check_admin_referer('wk_fast_search_generate');
    if (!wk_fast_search_sync_allowed()) {
        wp_safe_redirect(add_query_arg(['page'=>'wk-fast-search','wk_sync_blocked'=>1], admin_url('admin.php')));
        exit;
    }
    $count = wk_fast_search_generate_products_json();
    $redirect = add_query_arg([
        'page' => 'wk-fast-search',
        'wk_generated' => 1,
        'wk_count' => $count,
    ], admin_url('admin.php'));
    wp_safe_redirect($redirect);
    exit;
}

// Export popular_searches.json under uploads/wk-search/{tenant}/popular_searches.json
add_action('admin_post_wk_fast_search_export_popular', function(){
    if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
    check_admin_referer('wk_fast_search_export_popular');
    if (!wk_fast_search_sync_allowed()) {
        wp_safe_redirect(add_query_arg(['page'=>'wk-fast-search','wk_sync_blocked'=>1], admin_url('admin.php')));
        exit;
    }
    global $wpdb;
    wk_fs_ensure_popular_table_exists();
    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'] ?: 'default';
    $tenant_slug = sanitize_title($tenant);
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'wk-search/' . $tenant_slug;
    if (!file_exists($dir)) { wp_mkdir_p($dir); }
    $table = $wpdb->prefix . 'wk_search_popular';
    $rows = $wpdb->get_results("SELECT `query`,`count`,`last_searched` FROM `$table` WHERE `count` > 0 ORDER BY `count` DESC LIMIT 1000", ARRAY_A);
    $json = wp_json_encode(array_map(function($r){ return [
        'query' => (string)$r['query'],
        'count' => (int)$r['count'],
        'last_searched' => $r['last_searched']
    ]; }, $rows), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    file_put_contents($dir . '/popular_searches.json', $json);
    wp_safe_redirect(add_query_arg(['page'=>'wk-fast-search','wk_popular_exported'=>1], admin_url('admin.php')));
    exit;
});

// Export top_categories.json (by sales) under uploads/wk-search/{tenant}/top_categories.json
add_action('admin_post_wk_fast_search_export_top_categories', function(){
    if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
    check_admin_referer('wk_fast_search_export_top_categories');
    if (!wk_fast_search_sync_allowed()) {
        wp_safe_redirect(add_query_arg(['page'=>'wk-fast-search','wk_sync_blocked'=>1], admin_url('admin.php')));
        exit;
    }
    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'] ?: 'default';
    $tenant_slug = sanitize_title($tenant);
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'wk-search/' . $tenant_slug;
    if (!file_exists($dir)) { wp_mkdir_p($dir); }
    // Compute top categories by total sales quickly via WooCommerce order items if available, fallback to popularity counts
    $top = [];
    if (function_exists('wc_get_orders')) {
        // Lightweight: recent 3 months orders, aggregate by product categories
        $orders = wc_get_orders(['limit' => 200, 'orderby'=>'date','order'=>'DESC']);
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = $item->get_product_id();
                $terms = wp_get_post_terms($pid, 'product_cat');
                foreach ($terms as $t) {
                    $slug = $t->slug;
                    if (!isset($top[$slug])) { $top[$slug] = ['name'=>$t->name, 'slug'=>$slug, 'count'=>0]; }
                    $top[$slug]['count'] += max(1, (int)$item->get_quantity());
                }
            }
        }
    }
    // Fallback: use term meta total_sales if present or product popularity aggregation
    if (empty($top)) {
        $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>true]);
        foreach ($cats as $t) {
            $top[$t->slug] = ['name'=>$t->name, 'slug'=>$t->slug, 'count'=> (int) get_term_meta($t->term_id, 'total_sales', true) ];
        }
    }
    // Sort and take top 100
    usort($top, function($a,$b){ return $b['count'] <=> $a['count']; });
    $top = array_slice(array_values($top), 0, 100);
    file_put_contents($dir . '/top_categories.json', wp_json_encode($top, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    wp_safe_redirect(add_query_arg(['page'=>'wk-fast-search','wk_topcats_exported'=>1], admin_url('admin.php')));
    exit;
});

// Manual sync popular searches (admin-post) - place above legacy return barrier
// (duplicate guard) Remove any duplicate registration below legacy code

// Core generator (lightweight, no autoloader dependencies)
// Helpers to render product HTML via Woo template or Elementor Loop Item
if (!function_exists('wkfs_render_elementor_loop_item_for_product')) {
function wkfs_render_elementor_loop_item_for_product( $product_id, $loop_item_template_id ) {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return ''; }
    $plugin    = \Elementor\Plugin::instance();
    $frontend  = $plugin->frontend;
    global $post, $product;
    $original_post    = $post;
    $original_product = $product;
    $post    = get_post( $product_id );
    $product = wc_get_product( $product_id );
    if (!$post || !$product) { return ''; }
    setup_postdata( $post );
    ob_start();
    echo $frontend->get_builder_content_for_display( (int)$loop_item_template_id );
    $html = ob_get_clean();
    wp_reset_postdata();
    $post    = $original_post;
    $product = $original_product;
    return $html;
}}

if (!function_exists('wkfs_render_woo_product_card')) {
function wkfs_render_woo_product_card( $product_id ) {
    global $post, $product;
    $original_post = $post;
    $original_product = $product;
    
    $post = get_post( $product_id );
    $product = wc_get_product( $product_id );
    
    if (!$post || !$product) { 
        return ''; 
    }
    
    // Force product visibility for rendering (includes 'search' visibility products)
    $force_visible = function($visible, $id) use ($product_id) {
        if ($id === $product_id) {
            return true;
        }
        return $visible;
    };
    add_filter('woocommerce_product_is_visible', $force_visible, 10, 2);
    
    setup_postdata( $post );
    
    ob_start();
    wc_get_template_part('content', 'product');
    $html = ob_get_clean();
    
    wp_reset_postdata();
    
    // Remove filter
    remove_filter('woocommerce_product_is_visible', $force_visible, 10, 2);
    
    // Post-process: Fill empty title-wrapper and price-wrapper if present (for Flatsome theme)
    if (!empty($html) && (strpos($html, 'title-wrapper') !== false || strpos($html, 'price-wrapper') !== false)) {
        $title = $product->get_name();
        $permalink = get_permalink($product->get_id());
        $price_html = $product->get_price_html();
        
        // Inject title if wrapper is empty - match Flatsome's actual structure
        if (preg_match('/<div class=["\']title-wrapper["\']>\s*<\/div>/', $html)) {
            $title_content = '<div class="title-wrapper"><p class="name product-title woocommerce-loop-product__title"><a href="' . esc_url($permalink) . '" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">' . esc_html($title) . '</a></p></div>';
            $html = preg_replace('/<div class=["\']title-wrapper["\']>\s*<\/div>/', $title_content, $html, 1);
        }
        
        // Inject price if wrapper is empty
        if (preg_match('/<div class=["\']price-wrapper["\']>\s*<\/div>/', $html)) {
            $price_content = '<div class="price-wrapper"><span class="price">' . $price_html . '</span></div>';
            $html = preg_replace('/<div class=["\']price-wrapper["\']>\s*<\/div>/', $price_content, $html, 1);
        }
    }
    
    $post = $original_post;
    $product = $original_product;
    
    return $html;
}}

/**
 * Build a single feed item — works for both top-level products and variations.
 *
 * For variations, $context_parent is the parent variable product (used for inherited
 * fields like brand/categories/description; the variation provides its own price/sku/image/url/title).
 * For top-level products, $context_parent is null.
 *
 * Returns null when the product/variation should be skipped (invalid price, etc.).
 */
function wk_fast_search_build_feed_item($product, $context_parent, $settings) {
    // Determine which product object provides each field.
    $is_variation = $context_parent !== null;
    $brand_source = $is_variation ? $context_parent : $product;
    $tax_source   = $is_variation ? $context_parent : $product; // variations inherit categories/tags from parent

    // Price handling.
    if (!$is_variation && $product->is_type('variable')) {
        // Parent variable product (toggle OFF case): show min variation price as a range hint.
        $price = wk_fast_search_sanitize_price($product->get_variation_price('min', false));
        $price_old = wk_fast_search_sanitize_price($product->get_variation_regular_price('min', false));
    } else {
        $price = wk_fast_search_sanitize_price($product->get_price());
        $price_old = wk_fast_search_sanitize_price($product->get_regular_price());
    }
    if ($price === null || $price_old === null) {
        return null;
    }

    // Pre-rendered HTML: render top-level products as before.
    // Variations skip the pre-render — RenderController handles them on-demand at search time
    // (variation post + content-product.php template can produce theme-specific oddities; on-demand
    // rendering keeps the feed clean and gives the storefront fresh HTML each request).
    $rendered_html = '';
    if (!$is_variation) {
        $render_mode = $settings['render_mode'];
        $loop_id = (int) $settings['elementor_loop_id'];
        if ($render_mode === 'elementor' && $loop_id > 0 && class_exists('Elementor\\Plugin')) {
            $rendered_html = wkfs_render_elementor_loop_item_for_product($product->get_id(), $loop_id);
        } else {
            $rendered_html = wkfs_render_woo_product_card($product->get_id());
        }
    }

    // Image: variations may have their own image; fall back to parent.
    if ($is_variation) {
        $image_id = $product->get_image_id();
        if (!$image_id) {
            $image_id = $context_parent->get_image_id();
        }
        $image = $image_id ? wp_get_attachment_image_src($image_id, 'woocommerce_thumbnail') : null;
        $image_url = $image ? $image[0] : '';
    } else {
        $image_url = wk_fast_search_get_image($product);
    }

    // SKU: variations sometimes have empty SKU; fall back to parent's.
    $sku = (string) $product->get_sku();
    if ($sku === '' && $is_variation) {
        $sku = (string) $context_parent->get_sku();
    }

    $item = [
        'id' => $product->get_id(),
        'sku' => $sku,
        // $product->get_name() on a WC_Product_Variation already returns
        // "Parent Name – Attribute, Attribute" via Woo core.
        'title' => $product->get_name(),
        // Variations don't carry their own short_description; pull from parent.
        'description' => wk_fast_search_get_description($brand_source),
        'slug' => $is_variation ? $context_parent->get_slug() : $product->get_slug(),
        // Variation permalink includes ?attribute_*=… so add-to-cart pre-selects the variant.
        'url' => $is_variation ? $product->get_permalink() : get_permalink($product->get_id()),
        'brand' => wk_fast_search_get_brand($brand_source),
        'hierarchies' => wk_fast_search_get_hierarchies($tax_source),
        'attributes' => wk_fast_search_get_attributes($tax_source),
        'price' => $price,
        'price_old' => $price_old,
        'currency' => get_woocommerce_currency(),
        // Per-variation stock — the existing hide_out_of_stock display filter then handles visibility.
        'in_stock' => $product->is_in_stock(),
        'rating' => $is_variation ? $context_parent->get_average_rating() : $product->get_average_rating(),
        'image' => $image_url,
        'html' => $rendered_html ? do_shortcode($rendered_html) : null,
        // Popularity inherited from parent — variations don't accumulate sales/views in the parent's meta.
        'popularity' => wk_fast_search_get_popularity($brand_source),
        'purchase_count' => (int) get_post_meta(($is_variation ? $context_parent->get_id() : $product->get_id()), 'total_sales', true),
        'created_at' => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
        'updated_at' => $product->get_date_modified() ? $product->get_date_modified()->format('c') : null,
    ];

    // Optionally augment attributes with selected taxonomy facets (from parent for variations).
    $extra_tax_facets = $settings['taxonomy_facets'];
    if (is_array($extra_tax_facets) && !empty($extra_tax_facets)) {
        foreach ($extra_tax_facets as $tax) {
            $terms = wp_get_post_terms($tax_source->get_id(), $tax);
            if (is_array($terms) && !empty($terms)) {
                $item['attributes'][$tax] = array_values(array_unique(array_map(function($t){ return is_object($t)? $t->name : (string)$t; }, $terms)));
            }
        }
    }

    return $item;
}

function wk_fast_search_generate_products_json() {
    // Host-gate: silently skip on non-production hosts (e.g. staging.qliving.com, dev.qliving.com).
    // Prevents the hourly wk_fast_search_products_json cron from regenerating the feed on staging clones.
    if (!wk_fast_search_sync_allowed()) {
        return 0;
    }
    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'] ?: 'default';
    if (empty($tenant)) { $tenant = 'default'; }
    $tenant_slug = sanitize_title($tenant);
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'wk-search/' . $tenant_slug;
    if (!file_exists($dir)) { wp_mkdir_p($dir); }
    $base_url = trailingslashit($uploads['baseurl']) . 'wk-search/' . $tenant_slug;

    $products_path = $dir . '/products.json';
    $tmp_path = $products_path . '.tmp';
    $fh = fopen($tmp_path, 'w');
    if (!$fh) { return 0; }
    fwrite($fh, '[');
    $page = 1; $per_page = 200; $first = true; $total = 0; $product_ids = [];
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
        $settings = wk_fast_search_get_all_settings();
        $index_variations = ($settings['index_variations'] ?? '1') === '1';

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) { continue; }

            // Check if product should be searchable (excludes catalog-only and hidden products via WC visibility).
            // Variations inherit visibility from the parent, so this single check covers them too.
            if (!wk_fast_search_is_product_searchable($product)) {
                continue;
            }

            // Variations branch: expand variable parents into per-variation rows when the toggle is ON,
            // otherwise emit the parent as before.
            if ($index_variations && $product->is_type('variable')) {
                // Use get_children() not get_visible_children(): the latter honors WC's core "Hide OOS from catalog"
                // option which would silently double-filter against this plugin's own hide_out_of_stock setting.
                // Parent products at the WP_Query above are also pulled regardless of stock — keep variations consistent.
                // We then filter explicitly: only enabled variations are emitted.
                $children = $product->get_children();
                $emitted_any_variation = false;
                foreach ($children as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if (!$variation || !$variation->variation_is_active()) { continue; }
                    $item = wk_fast_search_build_feed_item($variation, $product, $settings);
                    if ($item === null) { continue; }
                    if (!$first) { fwrite($fh, ','); }
                    fwrite($fh, wp_json_encode($item));
                    $first = false; $total++;
                    $emitted_any_variation = true;
                }
                if (!$emitted_any_variation) {
                    // No enabled variations made it through — fall back to emitting the parent so we don't lose the product.
                    $item = wk_fast_search_build_feed_item($product, null, $settings);
                    if ($item !== null) {
                        if (!$first) { fwrite($fh, ','); }
                        fwrite($fh, wp_json_encode($item));
                        $first = false; $total++;
                    }
                }
            } else {
                $item = wk_fast_search_build_feed_item($product, null, $settings);
                if ($item === null) { continue; }
                if (!$first) { fwrite($fh, ','); }
                fwrite($fh, wp_json_encode($item));
                $first = false; $total++;
            }
        }
        if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
        $page++;
    } while (count($product_ids) === $per_page);
    fwrite($fh, ']');
    fclose($fh);
    rename($tmp_path, $products_path);

    // Minimal manifest
    $manifest = [
        'tenant_id' => $tenant,
        'site_url' => get_site_url(),
        'products_url' => trailingslashit($base_url) . 'products.json',
        'generated_at' => date('c'),
        'version' => '1.0.0',
    ];
    file_put_contents($dir . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    update_option('wk_search_manifest_url', trailingslashit($base_url) . 'manifest.json');

    return $total;
}

// AJAX: Render WooCommerce loop for given product IDs using theme template
add_action('wp_ajax_wkfs_render_products', 'wkfs_render_products');
add_action('wp_ajax_nopriv_wkfs_render_products', 'wkfs_render_products');
function wkfs_render_products() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) { wp_send_json_error('invalid'); }
    $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) { wp_send_json_success(['html' => '']); }

    $q = new WP_Query([
        'post_type' => 'product',
        'post__in' => $ids,
        'orderby' => 'post__in',
        'posts_per_page' => count($ids)
    ]);

    ob_start();
    echo '<div class="products columns-4 wkfs-grid">';
    if ($q->have_posts()) {
        while ($q->have_posts()) { $q->the_post();
            wc_get_template_part('content', 'product');
        }
        wp_reset_postdata();
    }
    echo '</div>';
    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
}

function wk_fast_search_sanitize_price($price) {
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

/**
 * Check if product should be searchable
 * Excludes products with 'exclude-from-search' term (catalog-only and hidden products)
 * 
 * @param WC_Product $product
 * @return bool
 */
function wk_fast_search_is_product_searchable($product) {
    // Check for 'exclude-from-search' term in product_visibility taxonomy
    $terms = wp_get_post_terms($product->get_id(), 'product_visibility', ['fields' => 'names']);
    
    if (is_wp_error($terms)) {
        return true; // If error checking terms, include product by default
    }
    
    // Exclude if product has 'exclude-from-search' term
    // This catches both "catalog" visibility and "hidden" visibility
    return !in_array('exclude-from-search', $terms, true);
}

function wk_fast_search_get_brand($product) {
    // If admin selected a specific brand taxonomy, use it first
    $settings = wk_fast_search_get_all_settings();
    $selected_brand_tax = $settings['brand_taxonomy'];
    if (is_string($selected_brand_tax) && $selected_brand_tax !== '') {
        $terms = wp_get_post_terms($product->get_id(), $selected_brand_tax);
        if (is_array($terms) && !empty($terms)) {
            return $terms[0]->name ?? '';
        }
    }

    // 1) Direct attribute lookups commonly used by themes/plugins
    $brand_attributes = ['brand', 'manufacturer', 'make', 'pa_brand'];
    foreach ($brand_attributes as $attr) {
        $brand = $product->get_attribute($attr);
        if (is_string($brand) && $brand !== '') { return $brand; }
        if (is_array($brand) && !empty($brand)) { return implode(' / ', array_map('strval', $brand)); }
    }

    // 2) Scan all product attributes for anything that looks like brand
    foreach ($product->get_attributes() as $attribute) {
        $name = strtolower($attribute->get_name());
        if (strpos($name, 'brand') !== false) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                if (is_array($terms) && !empty($terms)) {
                    return $terms[0]->name ?? '';
                }
            } else {
                $opts = $attribute->get_options();
                if (is_array($opts) && !empty($opts)) { return (string)reset($opts); }
                if (is_string($opts) && $opts !== '') { return $opts; }
            }
        }
    }

    // 3) Common custom field
    $brand = get_post_meta($product->get_id(), '_brand', true);
    if (!empty($brand)) { return $brand; }

    // 4) Common brand taxonomies (extensible via filter)
    $brand_taxonomies = apply_filters('wk_fast_search_brand_taxonomies', ['product_brand','berocket_brand','pwb-brand']);
    foreach ($brand_taxonomies as $tax) {
        $terms = wp_get_post_terms($product->get_id(), $tax);
        if (is_array($terms) && !empty($terms)) { return $terms[0]->name ?? ''; }
    }

    return '';
}

function wk_fast_search_get_description($product) {
    // Only use short description (excerpt) - more concise and curated
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

function wk_fast_search_get_hierarchies($product) {
    $hierarchies = [];
    $categories = wp_get_post_terms($product->get_id(), 'product_cat');
    foreach ($categories as $category) {
        $hierarchies[] = [
            'type' => 'category',
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'url' => get_term_link($category),
            'level' => wk_fast_search_get_category_level($category)
        ];
    }
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

function wk_fast_search_get_category_level($category) {
    $level = 0; $parent = $category->parent;
    while ($parent > 0) {
        $level++;
        $parent_category = get_term($parent, 'product_cat');
        $parent = $parent_category ? $parent_category->parent : 0;
    }
    return $level;
}

function wk_fast_search_get_attributes($product) {
    $attributes = [];
    foreach ($product->get_attributes() as $attribute) {
        $name = $attribute->get_name();
        if ($attribute->is_taxonomy()) {
            $terms = wp_get_post_terms($product->get_id(), $name);
            $values = [];
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (is_object($term) && isset($term->name)) {
                        $values[] = $term->name;
                    }
                }
            }
        } else {
            $opts = $attribute->get_options();
            if (is_array($opts)) {
                $values = array_values(array_filter(array_map('strval', $opts), function($v){ return $v !== ''; }));
            } else {
                $values = array_values(array_filter(array_map('trim', explode('|', (string)$opts)), function($v){ return $v !== ''; }));
            }
        }
        $attributes[$name] = isset($values) && is_array($values) ? $values : [];
    }
    return $attributes;
}

function wk_fast_search_get_image($product) {
    $image_id = $product->get_image_id();
    if ($image_id) {
        $image = wp_get_attachment_image_src($image_id, 'woocommerce_thumbnail');
        return $image ? $image[0] : '';
    }
    return '';
}

function wk_fast_search_get_popularity($product) {
    $sales = get_post_meta($product->get_id(), 'total_sales', true);
    $views = get_post_meta($product->get_id(), '_product_views', true);
    return (int) $sales + (int) round(((int)$views) * 0.1);
}

// Front-end: enqueue overlay assets and config (lightweight)
add_action('wp_enqueue_scripts', function(){
    if (is_admin()) { return; }
    
    // ONE database query for all settings
    $settings = wk_fast_search_get_all_settings();
    
    wp_enqueue_style(
        'wkfs-search-overlay',
        plugins_url('assets/css/search-overlay.css', __FILE__),
        [],
        '2.0.1'
    );
    
    // Add custom colors as CSS variables
    $custom_css = ":root { --wk-primary-color: {$settings['primary_color']}; --wk-text-color: {$settings['text_color']}; }";
    wp_add_inline_style('wkfs-search-overlay', $custom_css);
    
    // Add tracking functionality - direct API calls for speed
    $tracking_init = "
    window.wkTrack = function(event_type, event_data) {
        if (!window.wkSearchConfig) { return; }
        var base = (window.wkSearchConfig.wpRestUrl || (window.location.origin + '/wp-json')).replace(/\/$/, '');
        var url = base + '/wk-search/v1/track';
        var payload = { query: (event_type==='search' && event_data && event_data.query) ? String(event_data.query||'').trim() : '' };
        if (!payload.query) { return; }
        if (navigator.sendBeacon && typeof navigator.sendBeacon === 'function') {
            try { navigator.sendBeacon(url, new Blob([JSON.stringify(payload)], { type: 'application/json' })); return; } catch(e) {}
        }
        fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload), keepalive: true }).catch(function(){});
    };
    ";
    
    wp_enqueue_script(
        'wkfs-search-overlay',
        plugins_url('assets/js/search-overlay.js', __FILE__),
        [],
        WK_SEARCH_SYSTEM_VERSION,
        true
    );
    
    wp_add_inline_script('wkfs-search-overlay', $tracking_init, 'after');
    
    $edge_url = !empty($settings['edge_url']) ? $settings['edge_url'] : home_url();
    
    wp_localize_script('wkfs-search-overlay', 'wkSearchConfig', [
        'enabled' => true,
        'edgeUrl' => rtrim($edge_url, '/'),
        'wpRestUrl' => esc_url_raw( rest_url() ),
        'tenantId' => $settings['tenant_id'],
        'apiKey' => $settings['api_key'],
        'shopCurrency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency', 'USD'),
        'renderUrl' => esc_url_raw( rest_url( 'wk-fast-search/v1/render' ) ),
        'searchSelectors' => $settings['selectors'],
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wkfs_overlay'),
        'minChars' => 3,
        'debounceMs' => 250,
        'weightRelevance' => 1.0,
        'attributeFilters' => $settings['attribute_facets'],
        'enabledFilters' => $settings['enabled_filters'],
        'trackingEnabled' => true,
        'weightBrand' => 0.3,
        'weightCategory' => 0.2,
        'weightPrice' => 0.1,
        'searchMode' => $settings['search_mode'],
        'allowModeToggle' => $settings['allow_mode_toggle'] === '1',
        'sidebarPosition' => $settings['sidebar_position'],
        'productsPerPage' => (int)($settings['products_per_page'] ?: 40),
        'hideOutOfStock' => $settings['hide_out_of_stock'] === '1',
        'searchDescription' => $settings['search_description'] === '1',
        'debugMode' => $settings['debug_mode'] === '1',
        'showPopularSearches' => isset($settings['show_popular_searches']) && $settings['show_popular_searches'] === 'yes',
        'showRecentSearches' => isset($settings['show_recent_searches']) && $settings['show_recent_searches'] === 'yes',
        'themeName' => sanitize_title(wp_get_theme()->get('Name')),
        'excludedProductIds' => array_values(array_filter(array_map('intval', explode(',', $settings['excluded_product_ids'] ?? '')), function($id){ return $id > 0; })),
        'demotedProductIds' => array_values(array_filter(array_map('intval', is_array($settings['demoted_ids'] ?? null) ? $settings['demoted_ids'] : []), function($id){ return $id > 0; })),
        'strings' => $settings['strings']
    ]);
});

// Server-rendered overlay (HTML) using WooCommerce loop and backend facets
add_action('wp_ajax_wkfs_overlay', 'wkfs_overlay_html');
add_action('wp_ajax_nopriv_wkfs_overlay', 'wkfs_overlay_html');
function wkfs_overlay_html() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) { wp_send_json_error('invalid'); }
    if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wkfs_overlay')) { wp_send_json_error('bad_nonce'); }

    $query = sanitize_text_field($_REQUEST['query'] ?? '');
    $sort = sanitize_text_field($_REQUEST['sort'] ?? 'relevance');
    $filters = json_decode(stripslashes($_REQUEST['filters'] ?? '{}'), true);
    $page = intval($_REQUEST['page'] ?? 1);
    $is_load_more = filter_var($_REQUEST['is_load_more'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'];
    $key = $settings['api_key'];
    $edge = $settings['edge_url'] ?: home_url();

    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $key,
            'X-Tenant-Id' => $tenant,
            'X-User' => 'anon_' . (isset($_COOKIE['wk_user_id']) ? $_COOKIE['wk_user_id'] : uniqid()),
        ],
        'body' => wp_json_encode([
            'query' => $query,
            'limit' => 24,
            'page' => $page,
            'sort' => $sort,
            'filters' => $filters,
        ]),
        'timeout' => 15,
    ];

    // Transient cache key
    $cache_key = 'wkfs_' . md5( $tenant . '|' . $query . '|' . wp_json_encode($filters) . '|' . $sort . '|' . $page );
    $cached = get_transient($cache_key);
    if ($cached) {
        $data = $cached;
    } else {
        $resp = wp_remote_post($edge . '/api/serve/search', $args);
        if (is_wp_error($resp)) {
            wp_send_json_error(['html' => '<div class="wk-search-error">Search temporarily unavailable</div>', 'total_results' => 0, 'has_more' => false, 'current_page' => $page]);
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) { wp_send_json_error(['html' => '<div class="wk-search-error">Invalid response</div>', 'total_results' => 0, 'has_more' => false, 'current_page' => $page]); }
        set_transient($cache_key, $data, 60); // 60s cache window
    }

    // Get facets for filters
    $facets = $data['facets'] ?? [];
    
    // Render filters HTML
    $filters_html = wk_fast_search_render_filters($facets, $filters);
    
    // Render category chips HTML
    $chips_html = wk_fast_search_render_category_chips($facets);

    if (empty($data['products']['results'])) {
        wp_send_json_success([
            'html' => '<div class="wk-search-no-results">No products found</div>', 
            'filters_html' => $filters_html,
            'chips_html' => $chips_html,
            'total_results' => 0, 
            'has_more' => false, 
            'current_page' => $page
        ]);
    }

    $ids = array_column($data['products']['results'], 'id');
    $args = [
        'post_type' => 'product',
        'post__in' => $ids,
        'orderby' => 'post__in',
        'posts_per_page' => -1,
    ];
    $q = new WP_Query($args);

    ob_start();
    if ($q->have_posts()) {
        woocommerce_product_loop_start();
        while ($q->have_posts()) : $q->the_post();
            wc_get_template_part('content', 'product');
        endwhile;
        woocommerce_product_loop_end();
    } else {
        echo '<div class="wk-search-no-results">No products found</div>';
    }
    wp_reset_postdata();
    $html = ob_get_clean();
    // Minify simple whitespace
    $html = preg_replace('/\s+/', ' ', $html);
    
    wp_send_json_success([
        'html' => $html,
        'filters_html' => $filters_html,
        'chips_html' => $chips_html,
        'total_results' => $data['products']['total'] ?? 0,
        'has_more' => ($data['products']['total'] ?? 0) > ($page * 24),
        'current_page' => $page
    ]);
}

function wk_fast_search_render_filters($facets, $current_filters = []) {
    $html = '';
    
    // Price filter
    $html .= '<div class="wk-search-filter-group">';
    $html .= '<div class="wk-search-filter-header">Price</div>';
    $html .= '<div class="wk-search-filter-content">';
    $html .= '<div class="wk-search-price-range">';
    $html .= '<div class="wk-search-price-slider-container">';
    $html .= '<div class="wk-search-price-slider-wrapper">';
    $html .= '<div class="wk-search-price-slider">';
    $html .= '<div class="wk-search-price-track"></div>';
    $html .= '<input type="range" id="price-min" min="0" max="1000" value="' . esc_attr($current_filters['price_min'] ?? '0') . '" class="wk-search-price-min-slider">';
    $html .= '<input type="range" id="price-max" min="0" max="1000" value="' . esc_attr($current_filters['price_max'] ?? '1000') . '" class="wk-search-price-max-slider">';
    $html .= '</div>';
    $html .= '</div>';
    $currency = get_woocommerce_currency_symbol();
    $html .= '<div class="wk-search-price-display">';
    $html .= '<span class="wk-search-price-min">' . esc_html($currency) . '0</span>';
    $html .= '<span class="wk-search-price-separator">-</span>';
    $html .= '<span class="wk-search-price-max">' . esc_html($currency) . '1000</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="wk-search-price-inputs">';
    $html .= '<input type="number" id="price-min-input" placeholder="Min" value="' . esc_attr($current_filters['price_min'] ?? '') . '">';
    $html .= '<input type="number" id="price-max-input" placeholder="Max" value="' . esc_attr($current_filters['price_max'] ?? '') . '">';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    // Brand filter
    if (!empty($facets['brands'])) {
        $html .= '<div class="wk-search-filter-group">';
        $html .= '<div class="wk-search-filter-header">Brand</div>';
        $html .= '<div class="wk-search-filter-content">';
        $html .= '<div class="wk-search-filter-search">';
        $html .= '<input type="text" placeholder="Search brands..." class="filter-search-input" data-filter="brand">';
        $html .= '</div>';
        $html .= '<div class="wk-search-filter-options">';
        foreach (array_slice($facets['brands'], 0, 20) as $brand) {
            $checked = in_array($brand['value'], $current_filters['brands'] ?? []) ? 'checked' : '';
            $html .= '<div class="wk-search-filter-option">';
            $html .= '<input type="checkbox" value="' . esc_attr($brand['value']) . '" ' . $checked . '>';
            $html .= '<span>' . esc_html($brand['label']) . ' (' . $brand['count'] . ')</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }

    // Category filter
    if (!empty($facets['categories'])) {
        $html .= '<div class="wk-search-filter-group">';
        $html .= '<div class="wk-search-filter-header">Category</div>';
        $html .= '<div class="wk-search-filter-content">';
        $html .= '<div class="wk-search-filter-search">';
        $html .= '<input type="text" placeholder="Search categories..." class="filter-search-input" data-filter="category">';
        $html .= '</div>';
        $html .= '<div class="wk-search-filter-options">';
        foreach (array_slice($facets['categories'], 0, 20) as $category) {
            $checked = in_array($category['value'], $current_filters['categories'] ?? []) ? 'checked' : '';
            $html .= '<div class="wk-search-filter-option">';
            $html .= '<input type="checkbox" value="' . esc_attr($category['value']) . '" ' . $checked . '>';
            $html .= '<span>' . esc_html($category['label']) . ' (' . $category['count'] . ')</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }

    // In stock filter
    $html .= '<div class="wk-search-filter-group">';
    $html .= '<div class="wk-search-filter-header">Availability</div>';
    $html .= '<div class="wk-search-filter-content">';
    $html .= '<div class="wk-search-filter-options">';
    $checked = !empty($current_filters['in_stock']) ? 'checked' : '';
    $html .= '<div class="wk-search-filter-option">';
    $html .= '<input type="checkbox" value="1" ' . $checked . '>';
    $html .= '<span>In Stock Only</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function wk_fast_search_render_category_chips($facets) {
    $html = '';
    
    if (!empty($facets['categories'])) {
        foreach (array_slice($facets['categories'], 0, 10) as $category) {
            $html .= '<div class="wk-search-chip" data-category="' . esc_attr($category['value']) . '">';
            $html .= esc_html($category['label']);
            $html .= '</div>';
        }
    }
    
    return $html;
}

function wkfs_render_filters($facets) {
    // Helper to render search input + checkbox list
    $out = '';
    $groups = [
        'brand' => __('Brand','woo-fast-search'),
        'category' => __('Category','woo-fast-search'),
        'tag' => __('Tag','woo-fast-search'),
    ];
    foreach ($groups as $key => $label) {
        $opts = $facets[$key] ?? [];
        $items = [];
        if (is_array($opts)) {
            if (array_values($opts) === $opts) { $items = $opts; }
            else { foreach ($opts as $name=>$count){ $items[] = ['name'=>$name,'count'=>$count]; } }
        }
        $out .= '<div class="wk-search-filter-group wk-collapsible" data-filter="'.esc_attr($key).'">';
        $out .= '<h4 tabindex="0">'.esc_html($label).'</h4>';
        $out .= '<div class="wk-search-filter-options">';
        $out .= '<input type="text" class="wkfs-filter-search" placeholder="'.esc_attr__('Search…','woo-fast-search').'" />';
        foreach (array_slice($items,0,20) as $opt) {
            $name = is_array($opt) ? ($opt['name'] ?? $opt['id'] ?? '') : (string)$opt;
            $count = is_array($opt) ? ($opt['count'] ?? 0) : 0;
            $out .= '<label class="wk-search-filter-option">'
                 . '<input type="checkbox" class="wk-search-filter-checkbox" value="'.esc_attr($name).'">'
                 . '<span class="wk-search-checkmark"></span>'
                 . '<span class="wk-search-filter-label">'.esc_html($name).'</span>'
                 . '<span class="wk-search-filter-count">('.esc_html($count).')</span>'
                 . '</label>';
        }
        $out .= '</div></div>';
    }
    return $out;
}

// Custom cron schedules
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['wk_hourly'])) {
        $schedules['wk_hourly'] = [ 'interval' => HOUR_IN_SECONDS, 'display' => 'WK Every Hour' ];
    }
    return $schedules;
});

// Hourly cron scheduling for products.json
add_action('wk_fast_search_products_json', function(){ 
    try {
        $count = wk_fast_search_generate_products_json();
        error_log("WK Search: Generated products.json with {$count} products");
    } catch (\Throwable $e) {
        error_log("WK Search: Products JSON generation failed: " . $e->getMessage());
    }
});
register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('wk_fast_search_products_json')) {
        wp_schedule_event(time() + 5*MINUTE_IN_SECONDS, 'wk_hourly', 'wk_fast_search_products_json');
    }
});
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('wk_fast_search_products_json');
});

// Hourly cron scheduling for popular_searches.json
add_action('wk_fast_search_popular_searches', function(){ 
    try {
        global $wpdb;
        wk_fs_ensure_popular_table_exists();
        $settings = wk_fast_search_get_all_settings();
        $tenant = $settings['tenant_id'] ?: 'default';
        $tenant_slug = sanitize_title($tenant);
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'wk-search/' . $tenant_slug;
        if (!file_exists($dir)) { wp_mkdir_p($dir); }
        
        // Create logs directory
        $logs_dir = trailingslashit($uploads['basedir']) . 'wk-search/logs';
        if (!file_exists($logs_dir)) { wp_mkdir_p($logs_dir); }
        
        $table = $wpdb->prefix . 'wk_search_popular';
        $rows = $wpdb->get_results("SELECT `query`,`count`,`last_searched` FROM `$table` WHERE `count` > 0 ORDER BY `count` DESC LIMIT 1000", ARRAY_A);
        $json = wp_json_encode(array_map(function($r){ return [
            'query' => (string)$r['query'],
            'count' => (int)$r['count'],
            'last_searched' => $r['last_searched']
        ]; }, $rows), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        file_put_contents($dir . '/popular_searches.json', $json);
        error_log("WK Search: Generated popular_searches.json with " . count($rows) . " queries");
    } catch (\Throwable $e) {
        error_log("WK Search: Popular searches generation failed: " . $e->getMessage());
    }
});
register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('wk_fast_search_popular_searches')) {
        wp_schedule_event(time() + 5*MINUTE_IN_SECONDS, 'wk_hourly', 'wk_fast_search_popular_searches');
    }
});
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('wk_fast_search_popular_searches');
});


// Initialize search tracking for analytics
add_action('wp_ajax_wk_track_search', 'wk_fast_search_track_search');
add_action('wp_ajax_nopriv_wk_track_search', 'wk_fast_search_track_search');
function wk_fast_search_track_search() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_send_json_error('invalid');
    }

    // Host-gate: tracking events forwarded to the search API count as writes; block on non-prod.
    if (!wk_fast_search_sync_allowed()) {
        wp_send_json_success(['skipped' => 'sync_disabled_on_host']);
    }

    // Get tracking data
    $event_type = sanitize_text_field($_POST['event_type'] ?? '');
    $event_data = $_POST['event_data'] ?? [];

    if (empty($event_type)) {
        wp_send_json_error('missing_event_type');
    }

    // Forward to search API
    $settings = wk_fast_search_get_all_settings();
    $tenant = $settings['tenant_id'];
    $key = $settings['api_key'];
    $edge = $settings['edge_url'];
    
    if (empty($edge) || empty($tenant) || empty($key)) {
        wp_send_json_error('missing_config');
    }
    
    $args = [
        'method' => 'POST',
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $key,
            'X-Tenant-Id' => $tenant,
            'X-User' => 'default'
        ],
        'body' => wp_json_encode([
            'event_type' => $event_type,
            'event_data' => $event_data
        ]),
        'timeout' => 10,
    ];
    
    $response = wp_remote_post($edge . '/api/track', $args);
    
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        wp_send_json_success(['response' => json_decode($response_body, true)]);
    } else {
        wp_send_json_error(['code' => $response_code, 'response' => $response_body]);
    }
}
