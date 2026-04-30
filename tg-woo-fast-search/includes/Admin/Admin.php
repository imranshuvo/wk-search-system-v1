<?php

namespace WKSearchSystem\Admin;

use WKSearchSystem\Plugin;
use WKSearchSystem\ApiClient;

class Admin {
    private $settings;
    private $api_client;

    public function __construct() {
        $t0 = microtime(true);
        $m0 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        $this->settings = null; // lazy
        $this->api_client = null; // lazy
        
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'initAdmin']);
        add_action('wp_ajax_wk_search_admin_action', [$this, 'handleAjaxRequest']);
        if (class_exists('WKSearchSystem\\Logger')) {
            $elapsedMs = round((microtime(true) - $t0) * 1000, 2);
            $m1 = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            $memDelta = $m1 - $m0;
            \WKSearchSystem\Logger::debug('Admin::__construct completed in ' . $elapsedMs . 'ms, mem +' . $memDelta . ' bytes');
        }
    }

    public function addAdminMenu() {
        // Menu removed - settings are now in woo-fast-search.php main settings page
        // This prevents duplicate/conflicting settings pages
    }

    public function initAdmin() {
        // Register admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        // Register settings only when on our settings page to avoid heavy admin_init globally
        if ($this->isOnSettingsPage()) {
            Plugin::getInstance()->getSettings()->registerSettings();
        }
    }

    public function enqueueAdminScripts($hook) {
        if (strpos($hook, 'wk-search') === false) {
            return;
        }

        wp_enqueue_script(
            'wk-search-admin',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            WK_SEARCH_SYSTEM_VERSION,
            true
        );

        wp_enqueue_style(
            'wk-search-admin',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WK_SEARCH_SYSTEM_VERSION
        );

        wp_localize_script('wk-search-admin', 'wkSearchAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wk_search_admin_nonce'),
            'strings' => [
                'saving' => __('Saving...', 'wk-search-system'),
                'saved' => __('Saved!', 'wk-search-system'),
                'error' => __('Error saving settings', 'wk-search-system'),
                'confirmDelete' => __('Are you sure you want to delete this item?', 'wk-search-system'),
            ]
        ]);
    }

    public function renderMainPage() {
        $this->ensureDeps();
        if (isset($_POST['submit'])) {
            $this->handleSettingsSave();
        }

        $options = $this->settings->getOptions();
        include WK_SEARCH_SYSTEM_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    public function renderSynonymsPage() {
        $this->renderConfigPage('synonyms', __('Synonyms', 'wk-search-system'));
    }

    public function renderRedirectsPage() {
        $this->renderConfigPage('redirects', __('Redirects', 'wk-search-system'));
    }

    public function renderPinsBansPage() {
        $this->renderConfigPage('pins_bans', __('Pins & Bans', 'wk-search-system'));
    }

    public function renderStrategiesPage() {
        $this->renderConfigPage('strategies', __('Strategy Composer', 'wk-search-system'));
    }

    public function renderAnalyticsPage() {
        $analytics = $this->getAnalyticsData();
        include WK_SEARCH_SYSTEM_PLUGIN_DIR . 'templates/admin/analytics.php';
    }

    private function renderConfigPage($type, $title) {
        $this->ensureDeps();
        if (isset($_POST['submit'])) {
            $this->handleConfigSave($type);
        }

        $config = $this->getConfigData($type);
        include WK_SEARCH_SYSTEM_PLUGIN_DIR . 'templates/admin/config.php';
    }

    private function handleSettingsSave() {
        $this->ensureDeps();
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wk_search_settings')) {
            wp_die(__('Security check failed', 'wk-search-system'));
        }

        $options = $this->settings->sanitizeOptions($_POST['wk_search_system_options']);
        update_option(Settings::OPTION_NAME, $options);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'wk-search-system') . '</p></div>';
        });
    }

    private function handleConfigSave($type) {
        $this->ensureDeps();
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wk_search_config_' . $type)) {
            wp_die(__('Security check failed', 'wk-search-system'));
        }

        // Approve/Reject suggestion actions
        if (!empty($_POST['wk_action']) && !empty($_POST['suggestion_id'])) {
            $id = intval($_POST['suggestion_id']);
            try {
                $client = new ApiClient();
                if ($_POST['wk_action'] === 'approve_suggestion') {
                    $client->approveSuggestion($id);
                } elseif ($_POST['wk_action'] === 'reject_suggestion') {
                    $client->rejectSuggestion($id);
                }
            } catch (\Exception $e) {
                // ignore for now
            }
        }

        $config_data = sanitize_textarea_field($_POST['config_data']);
        $this->settings->updateOption('admin_' . $type, $config_data);

        // Push config to hosted service
        $this->pushConfigToHosted($type, $config_data);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>' . __('Configuration saved successfully!', 'wk-search-system') . '</p></div>';
        });
    }

    private function pushConfigToHosted($type, $config_data) {
        $options = $this->settings->getOptions();
        
        if (!$options['enabled'] || !$options['edge_url'] || !$options['api_key']) {
            return;
        }

        try {
            $this->api_client->pushConfig($type, $config_data);
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Failed to push config to hosted service: ' . $e->getMessage());
        }
    }

    private function getConfigData($type) {
        $this->ensureDeps();
        $config_key = 'admin_' . $type;
        $raw_data = $this->settings->getOption($config_key, '');
        
        if (empty($raw_data)) {
            return $this->getDefaultConfig($type);
        }

        return json_decode($raw_data, true) ?: [];
    }

    private function getDefaultConfig($type) {
        switch ($type) {
            case 'synonyms':
                return [
                    ['from' => 'running shoes', 'to' => 'sneakers'],
                    ['from' => 'mobile phone', 'to' => 'smartphone'],
                ];
            case 'redirects':
                return [
                    ['query' => 'nike shoes', 'url' => '/category/nike-shoes'],
                    ['query' => 'adidas', 'url' => '/brand/adidas'],
                ];
            case 'pins_bans':
                return [
                    'pins' => [
                        ['query' => 'sale', 'product_id' => 123],
                        ['query' => 'clearance', 'product_id' => 456],
                    ],
                    'bans' => [
                        ['query' => 'test', 'product_id' => 789],
                    ]
                ];
            case 'strategies':
                return [
                    'bestsellers' => [
                        'enabled' => true,
                        'filters' => ['in_stock' => true],
                        'sort' => 'popularity desc',
                        'count' => 12
                    ],
                    'trending' => [
                        'enabled' => true,
                        'filters' => ['in_stock' => true],
                        'time_window' => '7d',
                        'count' => 8
                    ],
                    'similar' => [
                        'enabled' => true,
                        'filters' => ['in_stock' => true],
                        'count' => 6
                    ]
                ];
            default:
                return [];
        }
    }

    private function getAnalyticsData() {
        // This would typically fetch from the hosted service
        // For now, return mock data
        return [
            'total_searches' => 15420,
            'unique_searches' => 8930,
            'top_queries' => [
                ['query' => 'running shoes', 'count' => 1250],
                ['query' => 'nike', 'count' => 980],
                ['query' => 'adidas', 'count' => 750],
            ],
            'conversion_rate' => 3.2,
            'avg_response_time' => 145, // ms
        ];
    }

    public function handleAjaxRequest() {
        check_ajax_referer('wk_search_admin_nonce', 'nonce');

        $action = sanitize_text_field($_POST['action_type']);
        
        $this->ensureDeps();
        switch ($action) {
            case 'test_connection':
                $this->handleTestConnection();
                break;
            case 'trigger_feed':
                $this->handleTriggerFeed();
                break;
            case 'warm_cache':
                $this->handleWarmCache();
                break;
            case 'generate_full_feed':
                $this->handleGenerateFullFeed();
                break;
            default:
                wp_send_json_error(['message' => 'Invalid action']);
        }
    }

    private function handleTestConnection() {
        $this->ensureDeps();
        try {
            $result = $this->api_client->testConnection();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function handleTriggerFeed() {
        $this->ensureDeps();
        try {
            $type = sanitize_text_field($_POST['feed_type']); // full or delta
            $result = $this->api_client->triggerFeedRun($type);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function handleWarmCache() {
        $this->ensureDeps();
        try {
            $queries = json_decode(stripslashes($_POST['queries']), true);
            $result = $this->api_client->warmCache($queries);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function handleGenerateFullFeed() {
        $this->ensureDeps();
        try {
            if ( ! wp_next_scheduled('wk_search_full_feed_once') ) {
                wp_schedule_single_event( time() + 60, 'wk_search_full_feed_once' );
             }
            wp_send_json_success(['message' => 'Full feed scheduled. Check logs in a few minutes.']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function ensureDeps() {
        if ($this->settings === null) {
            $this->settings = Plugin::getInstance()->getSettings();
        }
        if ($this->api_client === null) {
            $this->api_client = new ApiClient();
        }
    }

    private function isOnSettingsPage() {
        if (!is_admin()) return false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        return $page === 'wk-fast-search' || strpos($page, 'wk-search-') === 0 || strpos($page, 'wk-fast-search') === 0;
    }
}
