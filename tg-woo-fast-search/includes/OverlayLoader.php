<?php

namespace WKSearchSystem;

class OverlayLoader {
    private $settings;

    public function __construct() {
        $this->settings = Plugin::getInstance()->getSettings();
    }

    public function enqueueScripts() {
        if (!$this->settings->getOption('enabled')) {
            return;
        }

        // Enqueue new search overlay script
        wp_enqueue_script(
            'wk-search-overlay',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/js/search-overlay.js',
            ['jquery'],
            WK_SEARCH_SYSTEM_VERSION,
            true
        );

        // Enqueue search overlay styles
        wp_enqueue_style(
            'wk-search-overlay',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/css/search-overlay.css',
            [],
            WK_SEARCH_SYSTEM_VERSION
        );

        // Localize script with configuration
        $config = $this->getOverlayConfig();
        wp_localize_script('wk-search-overlay', 'wkSearchConfig', $config);

        // Add tracking script
        $this->addTrackingScript();
    }

    private function getOverlayConfig() {
        $options = $this->settings->getOptions();

        // Excluded IDs are stored as a comma-separated string; demoted IDs as an array.
        // Normalize both to arrays of positive ints for the front-end.
        $excludedIds = [];
        if (!empty($options['excluded_product_ids']) && is_string($options['excluded_product_ids'])) {
            foreach (explode(',', $options['excluded_product_ids']) as $piece) {
                $id = (int) trim($piece);
                if ($id > 0) { $excludedIds[] = $id; }
            }
            $excludedIds = array_values(array_unique($excludedIds));
        }
        $demotedIds = [];
        if (!empty($options['demoted_ids']) && is_array($options['demoted_ids'])) {
            foreach ($options['demoted_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) { $demotedIds[] = $id; }
            }
            $demotedIds = array_values(array_unique($demotedIds));
        }

        return [
            'enabled' => $options['enabled'],
            'mode' => $options['mode'],
            'debugMode' => $options['debug_mode'],
            'edgeUrl' => rtrim($options['edge_url'], '/'),
            'tenantId' => $options['tenant_id'],
            'searchKey' => $options['search_key'],
            'apiKey' => $options['api_key'],
            'hideOutOfStock' => $options['hide_out_of_stock'],
            'excludedProductIds' => $excludedIds,
            'demotedProductIds' => $demotedIds,
            'clientShardsEnabled' => $options['client_shards_enabled'],
            'clientShardsMaxSize' => $options['client_shards_max_size'] * 1024 * 1024, // Convert to bytes
            'clientShardsChunkSize' => $options['client_shards_chunk_size'] * 1024, // Convert to bytes
            'consentIntegration' => $options['consent_integration'],
            'consentCustomFunction' => $options['consent_custom_function'],
            'trackingEnabled' => $options['tracking_enabled'],
            'trackingConsentRequired' => $options['tracking_consent_required'],
            'trackingUrl' => rtrim($options['edge_url'], '/') . '/api/track',
            'strings' => [
                'searchPlaceholder' => __('Search products...', 'woo-fast-search'),
                'noResults' => __('No products found', 'woo-fast-search'),
                'loading' => __('Loading...', 'woo-fast-search'),
                'error' => __('Search temporarily unavailable', 'woo-fast-search'),
                'viewAll' => __('View all results', 'woo-fast-search'),
                'addToCart' => __('Add to cart', 'woo-fast-search'),
                'outOfStock' => __('Out of stock', 'woo-fast-search'),
                'filters' => __('Filters', 'woo-fast-search'),
                'price' => __('Price', 'woo-fast-search'),
                'brand' => __('Brand', 'woo-fast-search'),
                'category' => __('Category', 'woo-fast-search'),
                'inStockOnly' => __('In stock only', 'woo-fast-search'),
                'results' => __('results', 'woo-fast-search'),
                'relevance' => __('Relevance', 'woo-fast-search'),
                'priceLowHigh' => __('Price: Low to High', 'woo-fast-search'),
                'priceHighLow' => __('Price: High to Low', 'woo-fast-search'),
                'popularity' => __('Popularity', 'woo-fast-search'),
                'rating' => __('Rating', 'woo-fast-search'),
                'newest' => __('Newest', 'woo-fast-search'),
                'loadMore' => __('Load More', 'woo-fast-search'),
                'popularSearches' => __('Popular Searches', 'woo-fast-search'),
                'recentSearches' => __('Recent Searches', 'woo-fast-search'),
                'suggestions' => __('Suggestions', 'woo-fast-search'),
                'tryDifferentKeywords' => __('Try different keywords or check your spelling', 'woo-fast-search'),
            ]
            ,
            // weight overrides for preview
            'weightRelevance' => floatval($options['weight_relevance']),
            'weightBrand' => floatval($options['weight_brand']),
            'weightCategory' => floatval($options['weight_category']),
            'weightPrice' => floatval($options['weight_price'])
        ];
    }

    private function addTrackingScript() {
        $options = $this->settings->getOptions();
        
        if (!$options['tracking_enabled']) {
            return;
        }

        $trackingScript = $this->generateTrackingScript($options);
        wp_add_inline_script('wk-search-overlay', $trackingScript, 'before');
    }

    private function generateTrackingScript($options) {
        $consentCheck = $this->getConsentCheckScript($options);
        
        return "
        window.wkTrack = function(event, payload) {
            {$consentCheck}
            
            if (!window.wkSearchConfig.trackingEnabled) {
                return;
            }
            
            // Queue event for batching
            if (!window.wkEventQueue) {
                window.wkEventQueue = [];
            }
            
            window.wkEventQueue.push({
                event: event,
                payload: payload || {},
                timestamp: Date.now()
            });
            
            // Process queue if it reaches batch size or after delay
            if (window.wkEventQueue.length >= 10) {
                window.wkProcessEventQueue();
            } else if (!window.wkEventQueueTimer) {
                window.wkEventQueueTimer = setTimeout(window.wkProcessEventQueue, 5000);
            }
        };
        
        window.wkProcessEventQueue = function() {
            if (!window.wkEventQueue || window.wkEventQueue.length === 0) {
                return;
            }
            
            var events = window.wkEventQueue.splice(0);
            clearTimeout(window.wkEventQueueTimer);
            window.wkEventQueueTimer = null;
            
            fetch(window.wkSearchConfig.trackingUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + window.wkSearchConfig.apiKey,
                    'X-Tenant-Id': window.wkSearchConfig.tenantId
                },
                body: JSON.stringify({
                    events: events,
                    consent: true
                })
            }).catch(function(error) {
                console.warn('WK Search: Failed to send tracking events', error);
            });
        };
        ";
    }

    private function getConsentCheckScript($options) {
        $consentIntegration = $options['consent_integration'];
        
        switch ($consentIntegration) {
            case 'cookiebot':
                return "
                if (typeof Cookiebot !== 'undefined' && !Cookiebot.consent.statistics) {
                    return;
                }
                ";
            case 'onetrust':
                return "
                if (typeof OnetrustActiveGroups !== 'undefined' && !OnetrustActiveGroups.includes('C0004')) {
                    return;
                }
                ";
            case 'custom':
                $customFunction = $options['consent_custom_function'];
                if (!empty($customFunction)) {
                    return "
                    if (typeof {$customFunction} === 'function' && !{$customFunction}()) {
                        return;
                    }
                    ";
                }
                break;
        }
        
        return '';
    }

    public function getClientShards() {
        $options = $this->settings->getOptions();
        
        if (!$options['client_shards_enabled']) {
            return null;
        }

        try {
            $apiClient = new ApiClient();
            return $apiClient->getClientShards();
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Failed to get client shards: ' . $e->getMessage());
            return null;
        }
    }

    public function getSearchConfig() {
        try {
            $apiClient = new ApiClient();
            return $apiClient->getSearchConfig();
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Failed to get search config: ' . $e->getMessage());
            return null;
        }
    }
}
