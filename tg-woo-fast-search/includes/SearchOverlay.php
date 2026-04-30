<?php

namespace WKSearchSystem;

class SearchOverlay {
    private $settings;
    private $api_client;
    private $search_cache = [];
    private $debounce_timer = null;

    public function __construct() {
        $this->settings = Plugin::getInstance()->getSettings();
        $this->api_client = new ApiClient();
        
        add_action('wp_enqueue_scripts', [$this, 'enqueueOverlayAssets']);
        add_action('wp_footer', [$this, 'renderOverlayHTML']);
        // No WordPress AJAX handlers - all calls go directly to hosted service
    }

    public function enqueueOverlayAssets() {
        if (!$this->settings->getOption('enabled')) {
            return;
        }

        // Enqueue overlay styles
        wp_enqueue_style(
            'wk-search-overlay',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/css/overlay.css',
            [],
            WK_SEARCH_SYSTEM_VERSION
        );

        // Enqueue overlay script
        wp_enqueue_script(
            'wk-search-overlay',
            WK_SEARCH_SYSTEM_PLUGIN_URL . 'assets/js/overlay.js',
            ['jquery'],
            WK_SEARCH_SYSTEM_VERSION,
            true
        );

        // Localize script
        wp_localize_script('wk-search-overlay', 'wkSearchOverlay', [
            'config' => $this->getOverlayConfig(),
            'strings' => $this->getOverlayStrings()
        ]);
    }

    private function getOverlayConfig() {
        return [
            'enabled' => $this->settings->getOption('enabled'),
            'tenantId' => $this->settings->getOption('tenant_id'),
            'apiKey' => $this->settings->getOption('api_key'),
            'edgeUrl' => rtrim($this->settings->getOption('edge_url'), '/'),
            'searchKey' => $this->settings->getOption('search_key'),
            'version' => WK_SEARCH_SYSTEM_VERSION,
            'instantSearch' => true,
            'minQueryLength' => 2,
            'maxResults' => 8,
            'debounceDelay' => 300,
            'showImages' => true,
            'showPrices' => true,
            'showRatings' => true,
            'showCategories' => true,
            'enableKeyboardNav' => true,
            'enableVoiceSearch' => false, // Future feature
            'enableFilters' => true,
            'enableSorting' => true,
            'enableSuggestions' => true,
            'enableZeroResultsHandling' => true,
            'enableAnalytics' => $this->settings->getOption('tracking_enabled'),
            'consentRequired' => $this->settings->getOption('tracking_consent_required'),
            'consentIntegration' => $this->settings->getOption('consent_integration')
        ];
    }

    private function getOverlayStrings() {
        return [
            'searchPlaceholder' => __('Search products...', 'woo-fast-search'),
            'noResults' => __('No products found', 'woo-fast-search'),
            'loading' => __('Searching...', 'woo-fast-search'),
            'error' => __('Search temporarily unavailable', 'woo-fast-search'),
            'viewAll' => __('View all results', 'woo-fast-search'),
            'addToCart' => __('Add to cart', 'woo-fast-search'),
            'outOfStock' => __('Out of stock', 'woo-fast-search'),
            'inStock' => __('In stock', 'woo-fast-search'),
            'clearSearch' => __('Clear search', 'woo-fast-search'),
            'searchSuggestions' => __('Search suggestions', 'woo-fast-search'),
            'popularSearches' => __('Popular searches', 'woo-fast-search'),
            'recentSearches' => __('Recent searches', 'woo-fast-search'),
            'filters' => __('Filters', 'woo-fast-search'),
            'sortBy' => __('Sort by', 'woo-fast-search'),
            'priceRange' => __('Price range', 'woo-fast-search'),
            'categories' => __('Categories', 'woo-fast-search'),
            'brands' => __('Brands', 'woo-fast-search'),
            'ratings' => __('Ratings', 'woo-fast-search'),
            'availability' => __('Availability', 'woo-fast-search')
        ];
    }

    public function renderOverlayHTML() {
        if (!$this->settings->getOption('enabled')) {
            return;
        }

        $search_field_id = $this->settings->getOption('search_field_id', 'woocommerce-product-search-field-0');
        ?>
        <div id="wk-search-overlay" class="wk-search-overlay" style="display: none;">
            <div class="wk-search-overlay-backdrop"></div>
            <div class="wk-search-overlay-content">
                <div class="wk-search-overlay-header">
                    <div class="wk-search-input-container">
                        <input type="text" 
                               id="wk-search-input" 
                               class="wk-search-input" 
                               placeholder="<?php esc_attr_e('Search products...', 'woo-fast-search'); ?>"
                               autocomplete="off"
                               spellcheck="false">
                        <button type="button" class="wk-search-clear" style="display: none;">
                            <span class="wk-search-clear-icon">×</span>
                        </button>
                        <div class="wk-search-loading" style="display: none;">
                            <div class="wk-search-spinner"></div>
                        </div>
                    </div>
                    <button type="button" class="wk-search-close">
                        <span class="wk-search-close-icon">×</span>
                    </button>
                </div>
                
                <div class="wk-search-overlay-body">
                    <div class="wk-search-suggestions" style="display: none;">
                        <div class="wk-search-suggestions-header">
                            <h3><?php _e('Search suggestions', 'woo-fast-search'); ?></h3>
                        </div>
                        <div class="wk-search-suggestions-content">
                            <div class="wk-search-popular">
                                <h4><?php _e('Popular searches', 'woo-fast-search'); ?></h4>
                                <div class="wk-search-popular-tags"></div>
                            </div>
                            <div class="wk-search-recent">
                                <h4><?php _e('Recent searches', 'woo-fast-search'); ?></h4>
                                <div class="wk-search-recent-list"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wk-search-results" style="display: none;">
                        <div class="wk-search-results-header">
                            <div class="wk-search-results-count">
                                <span class="wk-search-results-text"></span>
                            </div>
                            <div class="wk-search-results-actions">
                                <button type="button" class="wk-search-filter-toggle">
                                    <?php _e('Filters', 'woo-fast-search'); ?>
                                </button>
                                <div class="wk-search-sort-dropdown">
                                    <select class="wk-search-sort-select">
                                        <option value="relevance"><?php _e('Relevance', 'woo-fast-search'); ?></option>
                                        <option value="price_asc"><?php _e('Price: Low to High', 'woo-fast-search'); ?></option>
                                        <option value="price_desc"><?php _e('Price: High to Low', 'woo-fast-search'); ?></option>
                                        <option value="rating"><?php _e('Highest Rated', 'woo-fast-search'); ?></option>
                                        <option value="newest"><?php _e('Newest', 'woo-fast-search'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="wk-search-filters" style="display: none;">
                            <div class="wk-search-filter-section">
                                <h4><?php _e('Price range', 'woo-fast-search'); ?></h4>
                                <div class="wk-search-price-range">
                                    <input type="range" class="wk-search-price-min" min="0" max="1000" value="0">
                                    <input type="range" class="wk-search-price-max" min="0" max="1000" value="1000">
                                    <div class="wk-search-price-display">
                                        <span class="wk-search-price-min-display">$0</span> - 
                                        <span class="wk-search-price-max-display">$1000</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="wk-search-filter-section">
                                <h4><?php _e('Categories', 'woo-fast-search'); ?></h4>
                                <div class="wk-search-categories-list"></div>
                            </div>
                            
                            <div class="wk-search-filter-section">
                                <h4><?php _e('Brands', 'woo-fast-search'); ?></h4>
                                <div class="wk-search-brands-list"></div>
                            </div>
                            
                            <div class="wk-search-filter-section">
                                <h4><?php _e('Availability', 'woo-fast-search'); ?></h4>
                                <label class="wk-search-filter-checkbox">
                                    <input type="checkbox" class="wk-search-filter-in-stock" checked>
                                    <?php _e('In stock only', 'woo-fast-search'); ?>
                                </label>
                            </div>
                        </div>
                        
                        <div class="wk-search-results-list"></div>
                        
                        <div class="wk-search-results-footer">
                            <button type="button" class="wk-search-view-all">
                                <?php _e('View all results', 'woo-fast-search'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="wk-search-zero-results" style="display: none;">
                        <div class="wk-search-zero-results-content">
                            <h3><?php _e('No products found', 'woo-fast-search'); ?></h3>
                            <p><?php _e('Try adjusting your search or browse our categories', 'woo-fast-search'); ?></p>
                            <div class="wk-search-zero-suggestions"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize search overlay
            if (typeof WKSearchOverlay !== 'undefined') {
                new WKSearchOverlay(wkSearchOverlay.config);
            }
            
            // Add search trigger to existing search field
            $('#<?php echo esc_js($search_field_id); ?>').on('focus', function() {
                $('#wk-search-overlay').show();
                $('#wk-search-input').focus();
            });
        });
        </script>
        <?php
    }

    // Note: Direct API calls to hosted service - no WordPress AJAX handlers needed
    // This significantly improves performance by eliminating the WordPress proxy layer
    // All search logic, filtering, and tracking now happens client-side for maximum speed
}
