<?php

namespace WKSearchSystem;

class Plugin {
    private static $instance = null;
    private $admin;
    private $feed_emitter;
    private $tracking;
    private $overlay_loader;
    private $search_overlay;
    private $settings;
    private $renderer;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        $t0 = microtime(true);
        $m0 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        // Determine kill-switch state
        $disabled = (defined('WK_SEARCH_SYSTEM_DISABLED') && WK_SEARCH_SYSTEM_DISABLED);

        // Initialize admin UI ALWAYS (even if disabled). Settings are lazy-loaded.
        $this->settings = null;
        $this->admin = new Admin\Admin();

        // Hook into WordPress (lightweight)
        add_action('init', [$this, 'initHooks']);
        add_action('admin_enqueue_scripts', [$this->admin, 'enqueueAdminScripts']);
        add_action('wp_ajax_wk_search_admin_action', [$this->admin, 'handleAjaxRequest']);

        // Only initialize heavy components on the frontend (not in wp-admin)
        if (!$disabled && $this->getSettings()->getOption('enabled') && !is_admin()) {
            $this->feed_emitter = new FeedEmitter();
            $this->tracking = class_exists('WKSearchSystem\\Tracking') ? new Tracking() : null;
            $this->overlay_loader = new OverlayLoader();
            $this->search_overlay = new SearchOverlay();
            $this->renderer = new RenderController();

            add_action('wp_enqueue_scripts', [$this->overlay_loader, 'enqueueScripts']);
            $this->renderer->registerAjax();

            // WooCommerce hooks
            add_action('woocommerce_new_product', [$this->feed_emitter, 'onProductSave']);
            add_action('woocommerce_update_product', [$this->feed_emitter, 'onProductSave']);
            add_action('woocommerce_delete_product', [$this->feed_emitter, 'onProductDelete']);
            add_action('woocommerce_variation_object_save', [$this->feed_emitter, 'onVariationSave']);
            if ($this->tracking) {
                add_action('woocommerce_thankyou', [$this->tracking, 'onOrderComplete']);
                add_action('wp_ajax_nopriv_wk_search_track', [$this->tracking, 'handleTrackingRequest']);
            }

            // Product template hooks
            add_action('init', [$this, 'initProductTemplate']);
        }

        if (class_exists('WKSearchSystem\\Logger')) {
            $elapsedMs = round((microtime(true) - $t0) * 1000, 2);
            $m1 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            $memDelta = $m1 - $m0;
            \WKSearchSystem\Logger::debug('Plugin::init completed in ' . $elapsedMs . 'ms, mem +' . $memDelta . ' bytes, is_admin=' . (is_admin() ? '1' : '0'));
        }
    }

    public function initHooks() {
        // Load text domain
        load_plugin_textdomain('woo-fast-search', false, dirname(plugin_basename(WK_SEARCH_SYSTEM_PLUGIN_FILE)) . '/languages');
    }

    public function getSettings() {
        if ($this->settings === null) {
            $this->settings = new Settings();
        }
        return $this->settings;
    }

    public function getFeedEmitter() {
        // Lazy instantiate so admin actions (like Generate Full Feed) can use it on-demand
        if ($this->feed_emitter === null) {
            $this->feed_emitter = new FeedEmitter();
        }
        return $this->feed_emitter;
    }

    public function initProductTemplate() {
        $product_template = $this->getSettings()->getOption('product_template', 'woocommerce_default');
        
        if ($product_template === 'woocommerce_default') {
            // Ensure WooCommerce default templates are used
            add_filter('woocommerce_locate_template', [$this, 'useWooCommerceDefaultTemplate'], 10, 3);
        }
    }

    public function useWooCommerceDefaultTemplate($template, $template_name, $template_path) {
        // Only override if it's a product-related template and we want WooCommerce default
        if (strpos($template_name, 'single-product') !== false || 
            strpos($template_name, 'content-product') !== false ||
            strpos($template_name, 'loop') !== false) {
            
            // Use WooCommerce's default template location
            $default_path = WC()->plugin_path() . '/templates/';
            $default_template = $default_path . $template_name;
            
            if (file_exists($default_template)) {
                return $default_template;
            }
        }
        
        return $template;
    }

    public function getTracking() {
        return $this->tracking;
    }

    public function getOverlayLoader() {
        return $this->overlay_loader;
    }
}
