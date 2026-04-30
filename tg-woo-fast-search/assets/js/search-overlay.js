/**
 * WK Search System - Advanced Search Overlay
 * Production-ready search overlay with autocomplete, filters, and analytics
 */

class WKSearchOverlay {
    constructor(config) {
        this.config = config;
        this.debugMode = config.debugMode || false;
        this.isInitialized = false;
        this.isOverlayOpen = false;
        this.currentQuery = '';
        this.currentFilters = {};
        this.currentSorting = 'relevance';
        this.currentPage = 1;
        this.isLoading = false;
        this.selectedIndex = -1;
        this.searchHistory = this.loadSearchHistory();
        this.popularSearches = [];
        this.popularQueriesLoaded = false;
        this.suggestions = [];
        this.results = [];
        this.totalResults = 0;
        this.hasMoreResults = false;
        this.currentPage = 1;
        this.lastSearchResults = null;
        this.isDefaultView = false;
        // Track the query that established the current baseline result set
        this.baselineQuery = '';
        // Track current full price range for deciding if price filter is active
        this.priceRange = null;
        // Seed: track the baseline product IDs to scope filter-only searches
        this.seedProductIds = [];
        // Track last submitted query/filters to detect price-only changes
        this._lastQuery = '';
        this._lastFilters = null;
        this._skipRangeUpdateForThisResponse = false;
        this._hasInitializedPriceRange = false;
        
        // NEW: Search mode tracking
        this.currentMode = this.config.searchMode || 'advanced'; // 'classic' or 'advanced'
        
        // Try to hydrate price range from previous session for better first paint
        try {
            const cached = localStorage.getItem('wk_price_range');
            if (cached) {
                const pr = JSON.parse(cached);
                if (pr && Number.isFinite(pr.min) && Number.isFinite(pr.max)) {
                    this.priceRange = { min: Math.floor(pr.min), max: Math.ceil(pr.max), currency: pr.currency || (this.config.shopCurrency || 'USD') };
                }
            }
        } catch(e) {}
        
        this.init();
    }

    // Debug logging helper - only logs when debug mode is enabled
    debug(...args) {
        if (this.debugMode) {
            console.log('[WK Search Debug]', ...args);
        }
    }

    async init() {
        if (this.isInitialized || !this.config.enabled) {
            return;
        }

        try {
            // Set up search triggers
            this.setupSearchTriggers();
            
            // Set up keyboard navigation
            this.setupKeyboardNavigation();
            
            // Load initial data
            await this.loadInitialData();
            
            this.isInitialized = true;
        } catch (error) {
            console.error('WK Search Overlay initialization failed:', error);
        }
    }

    setupSearchTriggers() {
        // Find all search input elements
        let selector = 'input[type="search"], input[name*="search"], .search-input, #search, .woocommerce-product-search-field';
        if (this.config && this.config.searchSelectors) {
            selector += ', ' + this.config.searchSelectors;
        }
        const searchInputs = document.querySelectorAll(selector);
        
        searchInputs.forEach(input => {
            if (input.dataset.wkSearchInitialized) {
                return;
            }

            input.dataset.wkSearchInitialized = 'true';

            // Trigger inputs are click-to-open targets only — never edited directly.
            // readOnly prevents text entry while still allowing focus + click events to fire,
            // and it sidesteps caret/IME state machines that some browsers got wedged on
            // (the cause of the "first click doesn't open" symptom on Safari/Firefox).
            if (input.tagName === 'INPUT') {
                input.readOnly = true;
                input.setAttribute('aria-haspopup', 'dialog');
                input.style.cursor = 'pointer';
            }

            // Add search icon click handler
            const searchIcon = input.parentNode && input.parentNode.querySelector
                ? input.parentNode.querySelector('.search-submit, .search-icon, .search-button')
                : null;
            if (searchIcon) {
                searchIcon.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.openOverlay();
                });

                // Store reference to search icon for show/hide functionality
                this.originalSearchIcon = searchIcon;
            }

            // mousedown fires earlier than click and is the most reliable open trigger;
            // click and focus stay as fallbacks for keyboard users (Tab + Enter, screen readers).
            input.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.openOverlay();
            });
            input.addEventListener('click', (e) => {
                e.preventDefault();
                this.openOverlay();
            });
            input.addEventListener('focus', () => {
                // Don't preventDefault on focus — there is no default action and some browsers
                // treat the call as a hint to revoke focus. Just open the overlay.
                this.openOverlay();
            });
        });

        // Add global search trigger (Ctrl+K or Cmd+K)
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.openOverlay();
            }
        });
    }

    setupKeyboardNavigation() {
        document.addEventListener('keydown', (e) => {
            if (!this.isOverlayOpen) return;

            switch (e.key) {
                case 'Escape':
                    this.closeOverlay();
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.navigateResults(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.navigateResults(-1);
                    break;
                case 'Enter':
                    e.preventDefault();
                    this.selectResult();
                    break;
            }
        });
    }

    async loadInitialData() {
        try {
            // Load popular searches
            this.popularSearches = await this.getPopularSearches();
            
            // Load search suggestions
            this.suggestions = await this.getSearchSuggestions();
        } catch (error) {
            console.warn('Failed to load initial data:', error);
        }
    }

    openOverlay() {
        if (this.isOverlayOpen) {
            return;
        }
        // Reset per-session tracked queries set
        this._trackedQueries = new Set();
        this.isOverlayOpen = true;
        this.createOverlay();
        this.showOverlay();
        this.focusSearchInput();
        // Note: setupInfiniteScroll() is invoked from setupOverlayEvents() (called by createOverlay).

        // Track overlay open
        this.trackEvent('search_overlay_opened');

        // Render bars (will hide if empty)
        this.renderTopQueriesBar();
        this.renderRecentQueriesBar();
    }

    closeOverlay() {
        if (!this.isOverlayOpen) return;

        // Track final query once on close (commit)
        try {
            const q = (this.currentQuery || '').trim();
            const minChars = (this.config && this.config.minChars) ? this.config.minChars : 3;
            if (q.length >= minChars) { this.trackSearchOnce(q); }
        } catch(e){}

        // Tear down the infinite-scroll observer (set up by setupInfiniteScroll on createOverlay);
        // the overlay DOM is about to be removed, but disconnecting explicitly avoids any chance
        // of a stale observer firing.
        if (this._io) {
            try { this._io.disconnect(); } catch (e) {}
            this._io = null;
        }

        this.isOverlayOpen = false;
        this.hideOverlay();
        this.selectedIndex = -1;

        // Track overlay close
        this.trackEvent('search_overlay_closed');
    }

    hideOverlay() {
        const overlay = document.getElementById('wk-search-overlay');
        if (!overlay) return;

        // Remove visible class
        overlay.classList.remove('wk-search-overlay-visible');
        
        // Restore body scroll and scroll position
        document.body.classList.remove('wk-search-overlay-open');
        document.body.style.top = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.overflow = '';
        
        // Restore scroll position
        if (this.scrollPosition !== undefined) {
            window.scrollTo(0, this.scrollPosition);
        }
        
        // Hide the overlay completely after animation
        setTimeout(() => {
            // Remove the overlay from DOM completely to ensure no interference
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            
            // Remove focus from any focused elements
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            // Focus back to body to ensure proper focus management
            document.body.focus();
            
            // Reset any state that might interfere with normal page interaction
            this.selectedIndex = -1;
            this.isLoading = false;
            
            // Clear any pending timeouts
            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = null;
            }
            
            // Abort any pending requests
            if (this._abortController) {
                try { 
                    this._abortController.abort(); 
                } catch(e) {}
                this._abortController = null;
            }
        }, 300);
    }

    createOverlay() {
        
        // Remove any existing overlay first
        const existingOverlay = document.getElementById('wk-search-overlay');
        if (existingOverlay && existingOverlay.parentNode) {
            existingOverlay.parentNode.removeChild(existingOverlay);
        }

        const overlay = document.createElement('div');
        overlay.id = 'wk-search-overlay';
        
        // Add sidebar position class
        const sidebarPosition = this.config.sidebarPosition || 'left';
        overlay.className = `wk-search-overlay wk-sidebar-${sidebarPosition}`;
        
        overlay.innerHTML = this.getOverlayHTML();
        
        document.body.appendChild(overlay);
        this.setupOverlayEvents();
        // Reset init flag so first result after open sets range from dataset
        this._hasInitializedPriceRange = false;
    }

    getOverlayHTML() {
        const baseMin = (this.priceRange && Number.isFinite(this.priceRange.min)) ? Math.floor(this.priceRange.min) : 0;
        const baseMax = (this.priceRange && Number.isFinite(this.priceRange.max)) ? Math.ceil(this.priceRange.max) : 1000;
        const currency = (this.priceRange && this.priceRange.currency) ? this.priceRange.currency : (this.config.shopCurrency || 'USD');
        
        // Get enabled filters configuration
        const enabledFilters = this.config.enabledFilters || {};
        const showPrice = enabledFilters.price === '1';
        const showBrand = enabledFilters.brand === '1';
        const showCategory = enabledFilters.category === '1';
        const showStatus = enabledFilters.status === '1';
        
        return `
            <div class="wk-search-overlay-backdrop"></div>
            <div class="wk-search-overlay-content" role="dialog" aria-modal="true" aria-labelledby="wk-search-title">
                <!-- Close button outside of everything -->
                <button type="button" class="wk-search-close" aria-label="${this.config.strings.close}">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 8.586L2.929 1.515 1.515 2.929 8.586 10l-7.071 7.071 1.414 1.414L10 11.414l7.071 7.071 1.414-1.414L11.414 10l7.071-7.071L17.071 1.515 10 8.586z"/>
                    </svg>
                </button>
                
                <div class="wk-search-header">
                    <div class="wk-search-input-container">
                        <input type="text" 
                               id="wk-search-input" 
                               class="wk-search-input" 
                               placeholder="${this.config.strings.searchPlaceholder}"
                               autocomplete="off"
                               spellcheck="false">
                        <div class="wk-search-input-actions">
                            ${this.config.allowModeToggle ? `
                            <button type="button" class="wk-search-mode-toggle" title="${this.currentMode === 'classic' ? (this.config.strings?.switchToAdvanced || 'Switch to Advanced') : (this.config.strings?.switchToClassic || 'Switch to Classic')}">
                                <span class="wk-mode-label">${this.currentMode === 'classic' ? (this.config.strings?.classicMode || 'Classic') : (this.config.strings?.advancedMode || 'Advanced')}</span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M12 6v6l4 2"></path>
                                </svg>
                            </button>
                            ` : ''}
                            <button type="button" class="wk-search-clear" style="display: none;">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 8.586L2.929 1.515 1.515 2.929 8.586 10l-7.071 7.071 1.414 1.414L10 11.414l7.071 7.071 1.414-1.414L11.414 10l7.071-7.071L17.071 1.515 10 8.586z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div id="wk-recent-queries" class="wk-recent-queries" style="display:none;"></div>
                    <div id="wk-top-queries" class="wk-top-queries" style="display:none;"></div>
                </div>
                
                <div class="wk-search-body">
                    <div class="wk-search-sidebar">
                        <button type="button" class="wk-filters-close" aria-label="${this.config.strings.close}" title="${this.config.strings.close}" style="display:none;">
                            ✕ ${this.config.strings.close}
                        </button>
                        <div class="wk-search-filters">
                            <h3 id="wk-filters-title">${this.config.strings.filters}</h3>
                            ${showPrice ? `
                            <div class="wk-search-filter-group wk-collapsible wk-open">
                                <h4 tabindex="0">${this.config.strings.price}<span class="wk-accordion-caret">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6,9 12,15 18,9"></polyline>
                                    </svg>
                                </span></h4>
                                <div class="wk-search-price-range">
                                    <div class="wk-search-price-slider-container">
                                        <div class="wk-search-price-display">
                                            <span class="wk-search-price-min">${this.formatPrice(baseMin, currency)}</span>
                                            <span class="wk-search-price-sep">–</span>
                                            <span class="wk-search-price-max">${this.formatPrice(baseMax, currency)}</span>
                                        </div>
                                        <div class="wk-search-price-slider-wrapper">
                                            <div class="wk-search-price-slider">
                                                <div class="wk-search-price-track"></div>
                                                <input type="range" id="price-min" min="${baseMin}" max="${baseMax}" value="${baseMin}" class="wk-search-price-min-slider">
                                                <input type="range" id="price-max" min="${baseMin}" max="${baseMax}" value="${baseMax}" class="wk-search-price-max-slider">
                                            </div>
                                        </div>
                                        <div class="wk-search-price-inputs">
                                            <input type="number" id="price-min-input" placeholder="${this.config.strings.min}" value="${baseMin}">
                                            <input type="number" id="price-max-input" placeholder="${this.config.strings.max}" value="${baseMax}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                            ${showBrand ? `
                            <div class="wk-search-filter-group wk-collapsible wk-open">
                                <h4 tabindex="0">${this.config.strings.brand}<span class="wk-accordion-caret">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6,9 12,15 18,9"></polyline>
                                    </svg>
                                </span></h4>
                                <div class="wk-search-filter-options" id="brand-filters">
                                    <!-- Dynamic brand filters -->
                                </div>
                            </div>
                            ` : ''}
                            ${showCategory ? `
                            <div class="wk-search-filter-group wk-collapsible wk-open">
                                <h4 tabindex="0">${this.config.strings.category}<span class="wk-accordion-caret">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6,9 12,15 18,9"></polyline>
                                    </svg>
                                </span></h4>
                                <div class="wk-search-filter-options" id="category-filters">
                                    <!-- Dynamic category filters -->
                                </div>
                            </div>
                            ` : ''}
                            ${showStatus ? `
                            <div class="wk-search-filter-group wk-collapsible wk-open">
                                <h4 tabindex="0">${this.config.strings.productStatus}<span class="wk-accordion-caret">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6,9 12,15 18,9"></polyline>
                                    </svg>
                                </span></h4>
                                <div class="wk-search-filter-options">
                                    <label class="wk-search-checkbox">
                                        <input type="checkbox" id="in-stock-filter">
                                        <span class="wk-search-checkmark"></span>
                                        ${this.config.strings.inStockOnly}
                                    </label>
                                    <label class="wk-search-checkbox">
                                        <input type="checkbox" id="on-sale-filter">
                                        <span class="wk-search-checkmark"></span>
                                        ${this.config.strings.onSale}
                                    </label>
                                    
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    <div class="wk-search-main">
                        <div class="wk-search-suggestions" id="wk-search-suggestions">
                            <!-- Suggestions will be populated here -->
                        </div>
                        
                        <!-- Did you mean suggestions (shown when fallback is used) -->
                        <div class="wk-search-did-you-mean" id="wk-search-did-you-mean" style="display: none;">
                            <h4>${this.config.strings.didYouMean}</h4>
                            <div class="wk-search-suggestions" id="did-you-mean-suggestions">
                                <!-- Suggestions will be populated here -->
                            </div>
                        </div>
                        
                        <div class="wk-search-results" id="wk-search-results" style="display: none;">
                            <!-- Filter toggle button -->
                            <button type="button" class="wk-filter-toggle" aria-label="${this.config.strings.filters}" title="${this.config.strings.filters}">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M3 5h14v2H3V5zm3 4h8v2H6V9zm2 4h4v2H8v-2z"/>
                                </svg>
                            </button>
                            <div class="wk-search-results-header">
                                <div class="wk-search-results-info">
                                    <span id="results-count">0 ${this.config.strings.results}</span>
                                </div>
                                <div class="wk-search-sort">
                                    <select id="wk-search-sort">
                                        <option value="relevance">${this.config.strings.relevance}</option>
                                        <option value="price_asc">${this.config.strings.priceLowHigh}</option>
                                        <option value="price_desc">${this.config.strings.priceHighLow}</option>
                                        <option value="popularity_desc">${this.config.strings.popularity}</option>
                                        <option value="rating_desc">${this.config.strings.rating}</option>
                                        <option value="newest">${this.config.strings.newest}</option>
                                    </select>
                                </div>
                            </div>
                            <div id="wk-selected-chips" aria-live="polite"></div>
                            
                            <div class="wk-search-products wk-theme-${this.config.themeName || 'default'}" id="wk-search-products">
                                <!-- Products will be populated here -->
                            </div>
                            </div>
                            
                            <div class="wk-search-load-more" id="wk-search-load-more" style="display: none;">
                                <button type="button" class="wk-search-load-more-btn">
                                    ${this.config.strings.loadMore}
                                </button>
                        </div>
                        
                        <div class="wk-search-loading" id="wk-search-loading" style="display: none;">
                            <div class="wk-search-spinner"></div>
                            <span>${this.config.strings.loading}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    setupOverlayEvents() {
        const overlay = document.getElementById('wk-search-overlay');
        const searchInput = document.getElementById('wk-search-input');
        const closeBtn = overlay.querySelector('.wk-search-close');
        const clearBtn = overlay.querySelector('.wk-search-clear');
        const backdrop = overlay.querySelector('.wk-search-overlay-backdrop');
        const loadMoreBtn = overlay.querySelector('.wk-search-load-more-btn');

        // Close overlay
        closeBtn.addEventListener('click', () => this.closeOverlay());
        backdrop.addEventListener('click', () => this.closeOverlay());

        // Search input events
        searchInput.addEventListener('input', (e) => this.handleSearchInput(e));
        searchInput.addEventListener('keydown', (e) => this.handleSearchKeydown(e));

        // Clear search
        clearBtn.addEventListener('click', () => this.clearSearch());
        
        // Mode toggle (if enabled)
        const modeToggleBtn = overlay.querySelector('.wk-search-mode-toggle');
        if (modeToggleBtn) {
            modeToggleBtn.addEventListener('click', () => this.toggleSearchMode());
        }

        // Sort change
        overlay.querySelector('#wk-search-sort').addEventListener('change', (e) => {
            this.currentSorting = e.target.value;
            const query = this.currentQuery || '';
            this.performSearch(query);
        });

        // Filter events
        this.setupFilterEvents();

        // Collapsible facet groups
        this.setupCollapsibles();

        // Load more
        loadMoreBtn.addEventListener('click', () => this.loadMoreResults());

        // Infinite scroll
        this.setupInfiniteScroll();

        // Mobile filter modal behavior
        const filterToggle = overlay.querySelector('.wk-filter-toggle');
        const filtersClose = overlay.querySelector('.wk-filters-close');
        const sidebar = overlay.querySelector('.wk-search-sidebar');
        this._onFilterKeydown = (e) => {
            if (!this._filterOpen) return;
            if (e.key === 'Escape') { e.preventDefault(); this.closeFiltersModal(); }
            if (e.key === 'Tab') {
                const f = this._filterFocusables || [];
                if (f.length === 0) return;
                const i = f.indexOf(document.activeElement);
                if (e.shiftKey) {
                    if (i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
                } else {
                    if (i === f.length - 1) { e.preventDefault(); f[0].focus(); }
                }
            }
        };
        this.openFiltersModal = () => {
            if (!window.matchMedia('(max-width: 768px)').matches) return;
            this._filterOpen = true;
            this._filterPrevFocus = document.activeElement;
            sidebar.setAttribute('role','dialog');
            sidebar.setAttribute('aria-modal','true');
            sidebar.setAttribute('aria-labelledby','wk-filters-title');
            sidebar.style.position = 'fixed';
            sidebar.style.top = '0';
            sidebar.style.right = '0';
            sidebar.style.bottom = '0';
            sidebar.style.width = '100%';
            sidebar.style.maxWidth = '420px';
            sidebar.style.background = '#fff';
            sidebar.style.zIndex = '1000000';
            sidebar.style.transform = 'translateX(100%)';
            sidebar.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
            sidebar.style.display = 'flex';
            sidebar.style.flexDirection = 'column';
            sidebar.style.visibility = 'hidden';
            filtersClose.style.display = 'block';
            
            // Use double requestAnimationFrame to prevent jitter
            requestAnimationFrame(() => {
                sidebar.style.visibility = 'visible';
                requestAnimationFrame(() => { 
                    sidebar.style.transform = 'translateX(0)'; 
                });
            });
            
            const focusables = sidebar.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            this._filterFocusables = Array.from(focusables).filter(el => !el.hasAttribute('disabled'));
            (this._filterFocusables[0] || filtersClose).focus();
            document.addEventListener('keydown', this._onFilterKeydown, true);
        };
        this.closeFiltersModal = () => {
            this._filterOpen = false;
            sidebar.style.transform = 'translateX(100%)';
            setTimeout(() => {
                filtersClose.style.display = 'none';
                sidebar.removeAttribute('style');
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
                sidebar.removeAttribute('aria-labelledby');
                document.removeEventListener('keydown', this._onFilterKeydown, true);
                if (this._filterPrevFocus && this._filterPrevFocus.focus) this._filterPrevFocus.focus();
            }, 300);
        };
        filterToggle && filterToggle.addEventListener('click', this.openFiltersModal);
        filtersClose && filtersClose.addEventListener('click', this.closeFiltersModal);
    }

    setupFilterEvents() {
        const overlay = document.getElementById('wk-search-overlay');
        
        // Price range: if dual-range slider is present, its handler will manage events; otherwise fallback to simple range inputs
        const hasDualRange = overlay.querySelector('.wk-search-price-min-slider');
        if (!hasDualRange) {
        const priceMin = overlay.querySelector('#price-min');
        const priceMax = overlay.querySelector('#price-max');
        const priceMinDisplay = overlay.querySelector('#price-min-display');
        const priceMaxDisplay = overlay.querySelector('#price-max-display');
        // Price slider event listeners are now handled in setupDualRangeSlider()
        // Removed duplicate listeners to prevent double requests
        }

        // In stock filter
        const inStockFilter = overlay.querySelector('#in-stock-filter');
        if (inStockFilter) {
            inStockFilter.addEventListener('change', () => {
                this.debounceFilterChange();
            });
        }

        // On sale filter
        const onSale = overlay.querySelector('#on-sale-filter');
        if (onSale) {
            onSale.addEventListener('change', () => {
                this.debounceFilterChange();
            });
        }

        // Brand and category filters
        overlay.addEventListener('change', (e) => {
            if (e.target.matches('.wk-search-filter-checkbox')) {
                this.debounceFilterChange();
                // Auto-close filter modal on mobile when filter is selected
                if (window.matchMedia('(max-width: 768px)').matches && this._filterOpen) {
                    setTimeout(() => this.closeFiltersModal(), 100);
                }
            }
        });
    }

    showOverlay() {
        const overlay = document.getElementById('wk-search-overlay');
        if (!overlay) {
            console.error('WK Search: Overlay element not found!');
            return;
        }
        
        overlay.style.display = 'block';
        
        // Store current scroll position and lock body scroll
        this.scrollPosition = window.pageYOffset;
        document.body.classList.add('wk-search-overlay-open');
        document.body.style.top = `-${this.scrollPosition}px`;
        
        // Animate in
        requestAnimationFrame(() => {
            overlay.classList.add('wk-search-overlay-visible');
            const content = overlay.querySelector('.wk-search-overlay-content');
            content && content.classList.add('wk-bottomsheet-open');
        });
        
        // Test tracking immediately (goes to WP REST, then batched to Laravel)
        this.trackEvent('overlay_opened', { route: 'wp_rest' });
        
        // Load default popular products when overlay opens
        this.loadDefaultProducts();
    }

    renderTopQueriesBar() {
        try {
            const bar = document.getElementById('wk-top-queries');
            if (!bar) return;
            // Check if popular searches are enabled in settings
            if (!this.config || !this.config.showPopularSearches) {
                bar.style.display = 'none';
                return;
            }
            const items = this.normalizeTopQueries((Array.isArray(this.popularSearches) ? this.popularSearches : [])).slice(0, 10);
            if (items.length === 0) { bar.style.display = 'none'; return; }
            const label = (this.config && this.config.strings && (this.config.strings.topSearches || this.config.strings.popularSearches)) || 'Top searches';
            bar.innerHTML = `<span class=\"label\">${label}</span>` + items.map(q => `<button type=\"button\" data-query=\"${q}\">${q}</button>`).join('');
            bar.style.display = 'flex';
            bar.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    const q = btn.getAttribute('data-query') || '';
                    const input = document.getElementById('wk-search-input');
                    if (input) input.value = q;
                    this.currentQuery = q;
                    // Show clear button and hide original icon
                    try {
                        const clearBtn = document.querySelector('.wk-search-clear');
                        if (clearBtn) clearBtn.style.display = 'block';
                        if (this.originalSearchIcon) { this.originalSearchIcon.style.display = 'none'; }
                    } catch(e){}
                    this.trackSearchOnce(q);
                    this.performSearch(q);
                    const tq = document.getElementById('wk-top-queries'); if (tq) tq.style.display = 'none';
                    const rq = document.getElementById('wk-recent-queries'); if (rq) rq.style.display = 'none';
                });
            });
        } catch(e){}
    }

    renderRecentQueriesBar() {
        try {
            const bar = document.getElementById('wk-recent-queries');
            if (!bar) return;
            // Check if recent searches are enabled in settings
            if (!this.config || !this.config.showRecentSearches) {
                bar.style.display = 'none';
                return;
            }
            const list = (this.searchHistory || []).slice(0, 5);
            if (list.length === 0) { bar.style.display = 'none'; return; }
            const label = (this.config && this.config.strings && (this.config.strings.recent || this.config.strings.recentSearches)) || 'Recent';
            bar.innerHTML = `<span class=\"label\">${label}</span>` + list.map(q => `<button type=\"button\" data-query=\"${q}\">${q}</button>`).join('');
            bar.style.display = 'flex';
            bar.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    const q = btn.getAttribute('data-query') || '';
                    const input = document.getElementById('wk-search-input');
                    if (input) input.value = q;
                    this.currentQuery = q;
                    // Show clear button and hide original icon
                    try {
                        const clearBtn = document.querySelector('.wk-search-clear');
                        if (clearBtn) clearBtn.style.display = 'block';
                        if (this.originalSearchIcon) { this.originalSearchIcon.style.display = 'none'; }
                    } catch(e){}
                    this.trackSearchOnce(q);
                    this.performSearch(q);
                    const tq = document.getElementById('wk-top-queries'); if (tq) tq.style.display = 'none';
                    const rq = document.getElementById('wk-recent-queries'); if (rq) rq.style.display = 'none';
                });
            });
        } catch(e){}
    }

    async loadDefaultProducts() {
        // Show loading state
        this.showLoading();
        this.hideSuggestions();
        
        try {
            // Load both popular products and popular queries in parallel
            const [productsResponse, queriesResponse] = await Promise.all([
                // Load most popular/sold products
                fetch(`${this.config.edgeUrl}/api/serve/popular-searches`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.config.apiKey}`,
                        'X-Tenant-Id': this.config.tenantId
                    },
                    body: JSON.stringify({
                        limit: this.config.productsPerPage || 40
                    })
                }),
                // Load popular search queries
                fetch(`${this.config.edgeUrl}/api/serve/popular-queries`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.config.apiKey}`,
                        'X-Tenant-Id': this.config.tenantId
                    },
                    body: JSON.stringify({
                        limit: 10
                    })
                })
            ]);

            
            if (productsResponse.ok) {
                const productsData = await productsResponse.json();
                
                if (productsData.products && productsData.products.length > 0) {
                    // Format data to match displayResults expected structure
                    const formattedData = {
                        products: {
                            results: productsData.products,
                            total: productsData.totalResults || productsData.products.length
                        },
                        facets: productsData.facets || {}
                    };
                    this.isDefaultView = true;
                    this.displayResults(formattedData);
                    // In background, fetch accurate price range from search endpoint for initial slider init
                    try {
                        const rangeData = await this.searchProducts('', 1);
                        if (rangeData && rangeData.facets_meta && rangeData.facets_meta.price_range && !this._hasInitializedPriceRange) {
                            this.updatePriceSliderFromRange(rangeData.facets_meta.price_range);
                            this._hasInitializedPriceRange = true;
                        }
                    } catch(e) { /* ignore */ }
                    
                    // Popular products loaded successfully, handle queries and return
                    if (queriesResponse.ok) {
                        const queriesData = await queriesResponse.json();
                        
                        if (queriesData.queries && queriesData.queries.length > 0) {
                            this.popularSearches = queriesData.queries;
                            this.renderTopQueriesBar();
                        }
                    } else {
                        console.error('Failed to load popular queries:', queriesResponse.status, queriesResponse.statusText);
                    }
                    return; // Exit early - popular products loaded successfully
                }
            }
            
            // Only run fallback if popular products failed
            const fallbackResponse = await fetch(`${this.config.edgeUrl}/api/serve/search`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.config.apiKey}`,
                    'X-Tenant-Id': this.config.tenantId,
                    'X-User': 'default'
                },
                body: JSON.stringify({
                    query: '',
                    limit: this.config.productsPerPage || 40,
                    offset: 0,
                    facets: {},
                    sort: 'popularity_desc'
                })
            });

            if (fallbackResponse.ok) {
                const fallbackData = await fallbackResponse.json();
                
                if (fallbackData.products && fallbackData.products.results && fallbackData.products.results.length > 0) {
					this.isDefaultView = true;
                    this.displayResults(fallbackData);
                    return;
                }
            }

            // If all fails, show suggestions
            this.showSuggestions();
            
        } catch (error) {
            console.error('Error loading default products:', error);
            this.showSuggestions();
        }
    }

    hideResults() {
        const overlay = document.getElementById('wk-search-overlay');
        overlay.classList.remove('wk-search-overlay-visible');
        
        // Restore body scroll and scroll position
        document.body.classList.remove('wk-search-overlay-open');
        document.body.style.top = '';
        
        // Restore scroll position
        if (this.scrollPosition !== undefined) {
            window.scrollTo(0, this.scrollPosition);
        }
        
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 300);
    }

    focusSearchInput() {
        const searchInput = document.getElementById('wk-search-input');
        if (!searchInput) { return; }
        // Defer past the next paint: showOverlay() may be in the middle of a CSS transition,
        // and Safari/Firefox silently drop focus() requests on elements that aren't fully
        // visible yet. Two rAFs guarantee the element has been laid out and painted before
        // we try to focus it — this is what makes the cursor blink reliably across browsers.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                try {
                    searchInput.focus({ preventScroll: true });
                    // Ensure the caret is positioned (some Safari builds focus without a caret).
                    const len = searchInput.value ? searchInput.value.length : 0;
                    if (typeof searchInput.setSelectionRange === 'function') {
                        searchInput.setSelectionRange(len, len);
                    }
                } catch (e) {
                    searchInput.focus();
                }
            });
        });
    }

    handleSearchInput(e) {
        const query = e.target.value.trim();
        this.currentQuery = query;
        
        // Show/hide clear button and original search icon
        const clearBtn = document.querySelector('.wk-search-clear');
        
        if (query) {
            clearBtn.style.display = 'block';
            // Hide original search icon when there's a query
            if (this.originalSearchIcon) {
                this.originalSearchIcon.style.display = 'none';
            }
        } else {
            clearBtn.style.display = 'none';
            // Show original search icon when no query
            if (this.originalSearchIcon) {
                this.originalSearchIcon.style.display = 'block';
            }
        }
        
        // Backspaced/cut all the way to empty: restore the popular-products view rather than leaving
        // the previous search results sitting there (matches the behaviour of clicking the clear icon).
        if (query.length === 0) {
            this.hideNoResults();
            if (!this.isDefaultView) {
                this.loadDefaultProducts();
            } else {
                this.showSuggestions();
            }
            return;
        }
        const minChars = (this.config && this.config.minChars) ? this.config.minChars : 3;
        if (query.length < minChars) {
            this.hideNoResults();
            this.showSuggestions();
            return;
        }
        // Live results as user types (no suggestions view)
        this.debounceSearch();
        // Debounced tracking after user pauses typing
        this.debounceTrack();
    }

    handleSearchKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.selectedIndex >= 0) {
                this.selectResult();
            } else {
                const q = (this.currentQuery || '').trim();
                this.trackSearchOnce(q);
                this.performSearch();
            }
        }
    }

    debounceSearch() {
        clearTimeout(this.searchTimeout);
        const delay = (this.config && this.config.debounceMs) ? this.config.debounceMs : 250;
        this.searchTimeout = setTimeout(() => {
            this.performSearch();
        }, delay);
    }

    debounceFilterChange() {
        clearTimeout(this.filterTimeout);
        const delay = (this.config && this.config.debounceMs) ? this.config.debounceMs : 250;
        this.filterTimeout = setTimeout(() => {
            // Always use server-side filtering for consistency
            // Use current query if available, otherwise use empty string for filter-only search
            const query = this.currentQuery || '';
            this.performSearch(query);
        }, delay);
    }

    debounceTrack() {
        clearTimeout(this._trackTimeout);
        const delay = (this.config && this.config.trackingDebounceMs) ? this.config.trackingDebounceMs : 350;
        this._trackTimeout = setTimeout(() => {
            const q = (this.currentQuery || '').trim();
            this.trackSearchOnce(q);
        }, delay);
    }

    async performSearch(query = null) {
        const searchQuery = query || this.currentQuery;
        const minChars = (this.config && this.config.minChars) ? this.config.minChars : 3;
        
        // If no query provided and current query is too short, use empty string for filter-only searches
        const effectiveQuery = searchQuery || '';
        
        // Only check minChars if we have a non-empty query
        if (effectiveQuery.length > 0 && effectiveQuery.length < minChars) {
            return;
        }

        this.showLoading();
        this.hideNoResults();

        try {
            const results = await this.searchProducts(effectiveQuery, 1);
            this.displayResults(results);
        } catch (error) {
            // Ignore aborted requests during fast typing
            if (error && (error.name === 'AbortError' || (String(error).toLowerCase().includes('abort')))) {
                return;
            }
            console.error('Search failed:', error);
            this.showError();
        }
    }

    async searchProducts(query, page = 1) {
        
        // Reset price sliders BEFORE reading filters if query or non-price filters changed
        try {
            const overlay = document.getElementById('wk-search-overlay');
            if (overlay && this.priceRange) {
                const omitPrice = (obj) => {
                    const o = { ...(obj || {}) };
                    delete o.price_min; delete o.price_max;
                    return o;
                };
                const sameQuery = (this._lastQuery || '') === (query || '');
                const queryChanged = !sameQuery;
                
                // Read filters to check if non-price changed
                const filtersPreCheck = this.getCurrentFilters();
                const prevNoPrice = omitPrice(this._lastFilters || {});
                const nowNoPrice = omitPrice(filtersPreCheck || {});
                const sameNonPrice = JSON.stringify(prevNoPrice) === JSON.stringify(nowNoPrice);
                const nonPriceChanged = !sameNonPrice;
                
                // If query or non-price filters changed, reset price sliders IMMEDIATELY
                if (queryChanged || nonPriceChanged) {
                    const minSlider = overlay.querySelector('#price-min');
                    const maxSlider = overlay.querySelector('#price-max');
                    const minInput = overlay.querySelector('#price-min-input');
                    const maxInput = overlay.querySelector('#price-max-input');
                    const minDisplay = overlay.querySelector('.wk-search-price-min');
                    const maxDisplay = overlay.querySelector('.wk-search-price-max');
                    
                    if (minSlider && maxSlider) {
                        minSlider.value = String(this.priceRange.min);
                        maxSlider.value = String(this.priceRange.max);
                        if (minInput) minInput.value = String(this.priceRange.min);
                        if (maxInput) maxInput.value = String(this.priceRange.max);
                        if (minDisplay) minDisplay.textContent = this.formatPrice(this.priceRange.min, this.priceRange.currency);
                        if (maxDisplay) maxDisplay.textContent = this.formatPrice(this.priceRange.max, this.priceRange.currency);
                        
                        // Update visual track
                        const slider = overlay.querySelector('.wk-search-price-slider');
                        if (slider) {
                            slider.style.setProperty('--min-percent', '0%');
                            slider.style.setProperty('--max-percent', '100%');
                        }
                    }
                }
            }
        } catch(e) { /* ignore */ }
        
        // NOW read filters (with price sliders already reset if needed)
        const filters = this.getCurrentFilters();
        
        // Decide if this request is a price-only change (same query, same non-price filters)
        try {
            const omitPrice = (obj) => {
                const o = { ...(obj || {}) };
                delete o.price_min; delete o.price_max;
                return o;
            };
            const sameQuery = (this._lastQuery || '') === (query || '');
            const prevNoPrice = omitPrice(this._lastFilters || {});
            const nowNoPrice = omitPrice(filters || {});
            const sameNonPrice = JSON.stringify(prevNoPrice) === JSON.stringify(nowNoPrice);
            const priceChanged = (
                (this._lastFilters?.price_min ?? undefined) !== (filters.price_min ?? undefined)
                || (this._lastFilters?.price_max ?? undefined) !== (filters.price_max ?? undefined)
            );
            const queryChanged = !sameQuery;
            const nonPriceChanged = !sameNonPrice;
            
            // ALWAYS update price range and facets from results to reflect current dataset
            // This ensures filters/slider always show what's actually in the current results
            this._skipRangeUpdateForThisResponse = false;
            
            // Reset selection to full new range when query/non-price filters change
            this._resetSelectionForThisResponse = queryChanged || nonPriceChanged;
            
            // If query changed, don't send stale price filters in this request
            if (queryChanged) {
                delete filters.price_min;
                delete filters.price_max;
            }
        } catch(e) { this._skipRangeUpdateForThisResponse = false; this._resetSelectionForThisResponse = false; }
        
        // Abort previous request
        if (this._abortController) {
            try { this._abortController.abort(); } catch(e){}
        }
        this._abortController = new AbortController();

        const requestPayload = {
                query: query,
                limit: this.config.productsPerPage || 40,
                page: page,
                mode: this.currentMode, // NEW: Add search mode parameter
                sort: this.mapSort(this.currentSorting),
                ...this.mapFilters(filters),
                attributes_meta: this.config.attributeFilters || [],
                hide_out_of_stock: this.config.hideOutOfStock || false,
                search_description: this.config.searchDescription || false,
                weights: {
                    relevance: this.config.weightRelevance || 1.0,
                    brand: this.config.weightBrand || 0.3,
                    category: this.config.weightCategory || 0.2,
                    price: this.config.weightPrice || 0.1
                }
        };
        // Scope only when there is no query (default set). For queries, always search full matching set.
        if (this.seedProductIds && this.seedProductIds.length > 0 && (!query || query.length === 0)) {
            requestPayload.restrict_ids = this.seedProductIds.slice(0, 200);
        }
        
        
        const response = await fetch(`${this.config.edgeUrl}/api/serve/search`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${this.getApiKey()}`,
                'X-Tenant-Id': this.config.tenantId,
                'X-User': this.getAnonId()
            },
            signal: this._abortController.signal,
            body: JSON.stringify(requestPayload)
        });

        if (!response.ok) {
            throw new Error(`Search request failed: ${response.status}`);
        }

        const data = await response.json();
        
        this.debug('Search response:', {
            query: query,
            page: page,
            totalResults: data.products?.total,
            currentResults: data.products?.results?.length,
            limit: data.products?.limit,
            hasMore: data.products?.results?.length < data.products?.total
        });
        
        // Save last sent state
        this._lastQuery = query || '';
        this._lastFilters = { ...(filters || {}) };
        return data;
    }

    getAnonId() {
        try {
            let id = localStorage.getItem('wk_user_id');
            if (!id) { id = 'u_' + Math.random().toString(36).slice(2) + Date.now(); localStorage.setItem('wk_user_id', id); }
            return id;
        } catch(e){ return ''; }
    }

    getCurrentFilters() {
        const filters = { brand: [], category: [], tag: [], attributes: {} };
        const overlay = document.getElementById('wk-search-overlay');
        
        // Price range - compare against BASE range (original query), not current filtered range
        const priceMinEl = overlay.querySelector('#price-min');
        const priceMaxEl = overlay.querySelector('#price-max');
        const baseRange = this._basePriceRange || this.priceRange;
        
        if (priceMinEl && priceMaxEl && baseRange) {
            const priceMin = parseInt(priceMinEl.value);
            const priceMax = parseInt(priceMaxEl.value);
            
            // Only send if user has actually moved the sliders from base range
            // Allow small tolerance for rounding errors
            const TOLERANCE = 1;
            const minChanged = priceMin > baseRange.min + TOLERANCE;
            const maxChanged = priceMax < baseRange.max - TOLERANCE;
            
            // If EITHER slider moved, send BOTH values
            if (minChanged || maxChanged) {
                filters.price_min = priceMin;
                filters.price_max = priceMax;
            }
        }
        
        // In stock
        const inStockFilter = overlay.querySelector('#in-stock-filter');
        if (inStockFilter?.checked) filters.in_stock = 1;
        
        // On sale
        const onSaleFilter = overlay.querySelector('#on-sale-filter');
        if (onSaleFilter?.checked) {
            filters.on_sale = 1;
        }
        
        // Brand filters
        const brandCheckboxes = overlay.querySelectorAll('#brand-filters .wk-search-filter-checkbox:checked');
        brandCheckboxes.forEach(checkbox => { filters.brand.push(checkbox.value); });
        
        // Category filters
        const categoryCheckboxes = overlay.querySelectorAll('#category-filters .wk-search-filter-checkbox:checked');
        categoryCheckboxes.forEach(checkbox => { filters.category.push(checkbox.value); });
        return filters;
    }

    // Removed applySortingAndFilters - now using server-side filtering

    // Removed sortProducts and filterProducts - now using server-side filtering and sorting

    // Removed updateFiltersFromCurrentResults - now using server-side filtering

    // Removed generateFacetsFromCurrentResults - now using server-side filtering

    mapSort(sort) {
        switch (sort) {
            case 'price_asc': return 'price_asc';
            case 'price_desc': return 'price_desc';
            case 'newest': return 'newest';
            case 'relevance':
            default: return 'relevance';
        }
    }

    mapFilters(filters) {
        const payload = {};
        if (filters.price_min !== undefined) payload.price_min = filters.price_min;
        if (filters.price_max !== undefined) payload.price_max = filters.price_max;
        if (filters.in_stock !== undefined) payload.in_stock = filters.in_stock;
        if (filters.on_sale !== undefined) payload.on_sale = filters.on_sale;
        if (filters.brand && filters.brand.length) payload.brand = filters.brand;
        if (filters.category && filters.category.length) payload.category = filters.category;
        return payload;
    }

    displayResults(results, append = false) {
        this.hideLoading();
        if (results && results.redirect && results.redirect.url) {
            window.location.href = results.redirect.url;
            return;
        }

        this.debug('Displaying results:', {
            hasProducts: !!(results.products && results.products.results),
            productsLength: results.products ? results.products.results.length : 0,
            fallback_used: results.fallback_used,
            currentQuery: this.currentQuery
        });

        // Filter out excluded products if configured
        if (this.config.excludedProductIds && this.config.excludedProductIds.length > 0) {
            if (results.products && results.products.results) {
                const excludedSet = new Set(this.config.excludedProductIds.map(Number));
                results.products.results = results.products.results.filter(p => !excludedSet.has(parseInt(p.id)));
                // Update total count
                if (results.products.total) {
                    results.products.total = results.products.results.length;
                }
            }
        }

        // Service products: hide on the empty-state/popular surface, demote (push to bottom) inside actual searches.
        // The product remains findable when the user types its name — it just stops crowding default views and
        // gets out of the way when the customer is searching for something else.
        if (this.config.demotedProductIds && this.config.demotedProductIds.length > 0
            && results.products && Array.isArray(results.products.results)) {
            const demotedSet = new Set(this.config.demotedProductIds.map(Number));
            const hasQuery = !!(this.currentQuery && this.currentQuery.trim().length > 0);
            if (!hasQuery) {
                // Empty-state / popular view → hide entirely.
                results.products.results = results.products.results.filter(p => !demotedSet.has(parseInt(p.id)));
                if (results.products.total) {
                    results.products.total = results.products.results.length;
                }
            } else {
                // Active search → push demoted products to the bottom, preserving original order within each group.
                const normal = [];
                const demoted = [];
                for (const p of results.products.results) {
                    if (demotedSet.has(parseInt(p.id))) { demoted.push(p); } else { normal.push(p); }
                }
                results.products.results = normal.concat(demoted);
                // Total count stays the same — we re-ordered, not removed.
            }
        }

        // Show "Did you mean" if fallback was used OR if no results
        if (results.fallback_used || !results.products || results.products.results.length === 0) {
            this.showNoResults(results.fallback_used, this.currentQuery);
            return;
        }
        
        // Show results section for actual results
        const resultsElement = document.getElementById('wk-search-results');
        if (resultsElement) {
            resultsElement.style.display = 'block';
        }

        if (append) {
            // Append results for load more
            this.results = [...this.results, ...results.products.results];
            // Accumulate seed IDs to keep narrowing within displayed set
            try {
                const filtersNow = this.getCurrentFilters();
                const hasFilters = (
                    (filtersNow.brand && filtersNow.brand.length) ||
                    (filtersNow.category && filtersNow.category.length) ||
                    filtersNow.in_stock !== undefined ||
                    filtersNow.on_sale !== undefined ||
                    filtersNow.price_min !== undefined ||
                    filtersNow.price_max !== undefined
                );
                const isDefaultMode = !this.currentQuery || this.currentQuery.length === 0;
                if (isDefaultMode && !hasFilters) {
                    const ids = results.products.results.map(p => p.id).filter(Boolean);
                    this.seedProductIds = Array.from(new Set([...(this.seedProductIds||[]), ...ids]));
                }
            } catch(e) {}
        } else {
            // Replace results for new search
        this.results = results.products.results;
            this.currentPage = 1;
            if (this.currentQuery && this.currentQuery.trim().length > 0) {
                this.isDefaultView = false; // Reset default view flag only for actual searches
            }
            // Store last search results for when query is cleared
            this.lastSearchResults = results;
            // If we're in default/empty-query mode, capture seed IDs to scope facet updates
            try {
                const filtersNow = this.getCurrentFilters();
                const hasFilters = (
                    (filtersNow.brand && filtersNow.brand.length) ||
                    (filtersNow.category && filtersNow.category.length) ||
                    filtersNow.in_stock !== undefined ||
                    filtersNow.on_sale !== undefined ||
                    filtersNow.price_min !== undefined ||
                    filtersNow.price_max !== undefined
                );
                const isDefaultMode = !this.currentQuery || this.currentQuery.length === 0;
                if (isDefaultMode && !hasFilters) {
                    this.seedProductIds = this.results.map(p => p.id).filter(Boolean);
                }
            } catch(e) { /* leave seedProductIds as-is */ }
            // Update baselineQuery to current query so follow-up filters narrow within this set
            this.baselineQuery = (this.currentQuery && this.currentQuery.length > 0) ? this.currentQuery : '';
        }
        
        this.totalResults = results.products.total;
        this.hasMoreResults = this.results.length < this.totalResults;
        
        this.debug('Pagination state:', {
            currentResults: this.results.length,
            totalResults: this.totalResults,
            hasMoreResults: this.hasMoreResults,
            currentPage: this.currentPage,
            append: append
        });
        
        // All filtering and sorting is now handled server-side
        
        this.updateResultsInfo();
        this.renderProducts();
        
        // Handle different API response formats
        let facets = {};
        if (results.facets) {
            // Popular products format
            facets = results.facets;
        } else if (results.products && results.products.filters) {
            // Search results format
            facets = results.products.filters;
        } else if (results.facets_meta) {
            // Alternative format
            facets = results.facets_meta;
        }
        
        this.updateFilters(facets);
        this.showResults();
        
        // ALWAYS update price slider range from current results
        // This ensures the slider reflects what's actually available in the current dataset
        if (results.facets_meta && results.facets_meta.price_range) {
            this.updatePriceSliderFromRange(results.facets_meta.price_range, this._resetSelectionForThisResponse);
        } else {
            // Fallback: derive range from current results
            try {
                const prices = (this.results || []).map(p => parseFloat(p.price)).filter(v => !isNaN(v));
                if (prices.length > 0) {
                    const min = Math.floor(Math.min(...prices));
                    const max = Math.ceil(Math.max(...prices));
                    const currency = (this.results && this.results[0] && this.results[0].currency) ? this.results[0].currency : (this.config.shopCurrency || 'USD');
                    this.updatePriceSliderFromRange({ min, max, currency }, this._resetSelectionForThisResponse);
                }
            } catch(e) { /* ignore */ }
        }
        
        this._hasInitializedPriceRange = true;

        // All filtering and sorting is now handled server-side
    }

    updatePriceSliderFromRange(range, isNewQueryOrFilter = false) {
        try {
            const overlay = document.getElementById('wk-search-overlay');
            if (!overlay || !range) return;
            const minSlider = overlay.querySelector('#price-min');
            const maxSlider = overlay.querySelector('#price-max');
            const minDisplay = overlay.querySelector('.wk-search-price-min');
            const maxDisplay = overlay.querySelector('.wk-search-price-max');
            const minField = overlay.querySelector('#price-min-input');
            const maxField = overlay.querySelector('#price-max-input');
            const sliderBar = overlay.querySelector('.wk-search-price-slider');
            if (!minSlider || !maxSlider) return;
            
            const rangeMin = Math.max(0, Math.floor(range.min || 0));
            const rangeMax = Math.ceil(range.max || 0);
            
            // Save as current default range for filter comparisons
            this.priceRange = { min: rangeMin, max: rangeMax, currency: range.currency || 'USD' };
            try { localStorage.setItem('wk_price_range', JSON.stringify(this.priceRange)); } catch(e) {}
            
            // On new query/filter change, update the BASE range (slider bounds)
            // This allows user to expand back to full range of the query
            if (isNewQueryOrFilter || !this._basePriceRange) {
                this._basePriceRange = { min: rangeMin, max: rangeMax, currency: this.priceRange.currency };
                // Reset slider values to full base range
                minSlider.min = String(rangeMin);
                minSlider.max = String(rangeMax);
                maxSlider.min = String(rangeMin);
                maxSlider.max = String(rangeMax);
                minSlider.value = String(rangeMin);
                maxSlider.value = String(rangeMax);
                if (minField) minField.value = String(rangeMin);
                if (maxField) maxField.value = String(rangeMax);
                if (minDisplay) minDisplay.textContent = this.formatPrice(rangeMin, this.priceRange.currency);
                if (maxDisplay) maxDisplay.textContent = this.formatPrice(rangeMax, this.priceRange.currency);
            } else {
                // Price filter applied - keep base range as bounds, preserve user selection
                // This lets user expand back to see full query results
                const curMin = parseInt(minSlider.value, 10);
                const curMax = parseInt(maxSlider.value, 10);
                
                // Keep the base range as slider bounds (don't shrink)
                minSlider.min = String(this._basePriceRange.min);
                minSlider.max = String(this._basePriceRange.max);
                maxSlider.min = String(this._basePriceRange.min);
                maxSlider.max = String(this._basePriceRange.max);
                
                // Preserve current slider position (user's selection)
                minSlider.value = String(curMin);
                maxSlider.value = String(curMax);
                if (minField) minField.value = String(curMin);
                if (maxField) maxField.value = String(curMax);
                if (minDisplay) minDisplay.textContent = this.formatPrice(curMin, this.priceRange.currency);
                if (maxDisplay) maxDisplay.textContent = this.formatPrice(curMax, this.priceRange.currency);
            }
            
            // Update active track
            if (sliderBar) {
                const boundMin = parseInt(minSlider.min);
                const boundMax = parseInt(minSlider.max);
                const curMin = parseInt(minSlider.value);
                const curMax = parseInt(maxSlider.value);
                
                // Ensure valid values
                if (!isNaN(boundMin) && !isNaN(boundMax) && !isNaN(curMin) && !isNaN(curMax) && boundMax > boundMin) {
                    const percent = (v) => ((v - boundMin) / (boundMax - boundMin)) * 100;
                    const minPercent = Math.max(0, Math.min(100, percent(curMin)));
                    const maxPercent = Math.max(0, Math.min(100, percent(curMax)));
                    sliderBar.style.setProperty('--min-percent', minPercent + '%');
                    sliderBar.style.setProperty('--max-percent', maxPercent + '%');
                }
            }
            
            // Mark slider as initialized (makes it visible)
            const sliderWrapper = overlay.querySelector('.wk-search-price-slider-wrapper');
            if (sliderWrapper) {
                sliderWrapper.classList.add('initialized');
            }
        } catch(e) { /* noop */ }
    }
    
    displayPopularQueries(queries) {
        
        const suggestionsContainer = document.getElementById('wk-search-suggestions');
        if (!suggestionsContainer) return;
        
        // Create popular queries section
        let html = '<div class="wk-search-suggestions-section">';
        html += '<h3 class="wk-search-suggestions-title">Popular Searches</h3>';
        html += '<div class="wk-search-suggestions-list">';
        
        queries.forEach(query => {
            html += `<div class="wk-search-suggestion-item" data-query="${query.query}">`;
            html += `<span class="wk-search-suggestion-text">${query.query}</span>`;
            html += `<span class="wk-search-suggestion-count">${query.count} searches</span>`;
            html += '</div>';
        });
        
        html += '</div></div>';
        
        suggestionsContainer.innerHTML = html;
        this.showSuggestions();
        
        // Add click handlers for popular queries
        suggestionsContainer.querySelectorAll('.wk-search-suggestion-item').forEach(item => {
            item.addEventListener('click', () => {
                const query = item.dataset.query;
                this.searchInput.value = query;
                this.performSearch(query);
            });
        });
    }

    updateResultsInfo() {
        const resultsCount = document.getElementById('results-count');
        
        // Determine the appropriate title based on context
        let title = '';
        if (this.currentQuery && this.currentQuery.trim().length > 0) {
            // Search query - show "Showing X items for 'query'"
            title = this.config.strings.showingResultsFor
                .replace('{count}', this.totalResults)
                .replace('{query}', this.currentQuery);
        } else {
            // Default view - show "Showing X items" or "Showing top X popular products"
            if (this.isDefaultView) {
                title = this.config.strings.showingTopPopular
                    .replace('{count}', this.totalResults);
            } else {
                title = this.config.strings.showingResults
                    .replace('{count}', this.totalResults);
            }
        }
        
        resultsCount.textContent = title;
        this.renderSelectedChips();
    }

    renderSelectedChips() {
        let bar = document.getElementById('wk-selected-chips');
        if (!bar) {
            const header = document.querySelector('.wk-search-results-header');
            bar = document.createElement('div');
            bar.id = 'wk-selected-chips';
            bar.style.marginTop = '6px';
            header.appendChild(bar);
        }
        const filters = this.getCurrentFilters();
        const chips = [];
        const labelFor = (type, value) => {
            const overlay = document.getElementById('wk-search-overlay');
            const input = overlay.querySelector(`[id$='${type}-filters'] .wk-search-filter-checkbox[value="${CSS.escape(value)}"]`);
            return input ? (input.getAttribute('data-label') || value) : value;
        };
        (filters.brand||[]).forEach(v => chips.push({type:'brand',value:labelFor('brand',v), raw:v}));
        (filters.category||[]).forEach(v => chips.push({type:'category',value:labelFor('category',v), raw:v}));
        (filters.tag||[]).forEach(v => chips.push({type:'tag',value:labelFor('tag',v), raw:v}));
        Object.keys(filters.attributes||{}).forEach(a => {
            (filters.attributes[a]||[]).forEach(v => chips.push({type:a,value:v,attr:true}));
        });
        if (filters.in_stock) chips.push({type:'in_stock',value:'In stock'});
        if (filters.on_sale) chips.push({type:'on_sale',value:'On sale'});
        // Show single price chip if either min or max is active
        if (filters.price_min || filters.price_max) {
            const baseRange = this._basePriceRange || this.priceRange;
            const minVal = filters.price_min || (baseRange ? baseRange.min : 0);
            const maxVal = filters.price_max || (baseRange ? baseRange.max : 0);
            const currency = this.priceRange?.currency;
            chips.push({
                type:'price_range',
                value:`Price: ${this.formatPrice(minVal, currency)} - ${this.formatPrice(maxVal, currency)}`
            });
        }
        bar.innerHTML = chips.map(c => `<button class="wk-chip" data-type="${c.type}" data-value="${c.raw ?? c.value}" style="margin-right:6px;padding:4px 8px;border:1px solid #e5e7eb;border-radius:9999px;background:#f9fafb">${c.value} ✕</button>`).join('');
        bar.querySelectorAll('.wk-chip').forEach(btn => btn.addEventListener('click', () => {
            this.removeChip(btn.dataset.type, btn.dataset.value);
        }));
    }

    removeChip(type, value){
        const overlay = document.getElementById('wk-search-overlay');
        const inStockFilter = overlay.querySelector('#in-stock-filter');
        if (type==='in_stock' && inStockFilter) inStockFilter.checked = false;
        else if (type==='price_range') {
            // Reset BOTH min and max to base range
            const minSlider = overlay.querySelector('#price-min');
            const maxSlider = overlay.querySelector('#price-max');
            if (minSlider && maxSlider) {
                const baseRange = this._basePriceRange || this.priceRange;
                const baseMin = baseRange ? baseRange.min : parseInt(minSlider.min, 10) || 0;
                const baseMax = baseRange ? baseRange.max : parseInt(maxSlider.max, 10) || 1000;
                this.setPriceSliderValues(baseMin, baseMax);
            }
        }
        else if (type==='on_sale') overlay.querySelector('#on-sale-filter').checked = false;
        else if (['brand','category'].includes(type)) {
            const el = overlay.querySelector(`[id$='${type}-filters'] .wk-search-filter-checkbox[value="${CSS.escape(value)}"]`);
            if (el) el.checked = false;
        } else {
            const group = Array.from(overlay.querySelectorAll('.wk-search-attribute-filter input'))
              .find(input => input.value===value);
            if (group) group.checked = false;
        }
        
        // Always use server-side filtering for consistency
        const query = this.currentQuery || '';
        this.performSearch(query);
    }

    setPriceSliderValues(minVal, maxVal) {
        try {
            const overlay = document.getElementById('wk-search-overlay');
            const minSlider = overlay.querySelector('#price-min');
            const maxSlider = overlay.querySelector('#price-max');
            const minField = overlay.querySelector('#price-min-input');
            const maxField = overlay.querySelector('#price-max-input');
            const minDisplay = overlay.querySelector('.wk-search-price-min');
            const maxDisplay = overlay.querySelector('.wk-search-price-max');
            const sliderBar = overlay.querySelector('.wk-search-price-slider');
            if (!minSlider || !maxSlider) return;
            // Clamp to slider bounds
            const boundMin = parseInt(minSlider.min, 10) || 0;
            const boundMax = parseInt(maxSlider.max, 10) || 1000;
            let a = Math.max(boundMin, Math.min(minVal, boundMax));
            let b = Math.max(boundMin, Math.min(maxVal, boundMax));
            if (a >= b) { a = boundMin; b = boundMax; }
            minSlider.value = String(a);
            maxSlider.value = String(b);
            if (minField) minField.value = String(a);
            if (maxField) maxField.value = String(b);
            if (minDisplay) minDisplay.textContent = this.formatPrice(a, this.priceRange?.currency);
            if (maxDisplay) maxDisplay.textContent = this.formatPrice(b, this.priceRange?.currency);
            if (sliderBar) {
                const percent = (v) => ((v - boundMin) / Math.max(1, (boundMax - boundMin))) * 100;
                sliderBar.style.setProperty('--min-percent', percent(a) + '%');
                sliderBar.style.setProperty('--max-percent', percent(b) + '%');
            }
        } catch(e) { /* noop */ }
    }

    async renderProducts() {
        const container = document.getElementById('wk-search-products');
        container.innerHTML = '';

        // Prefer HTML if already present in the API payload (pre-rendered)
        const hasAnyHtml = this.results.length && this.results.some(p => p.html !== undefined);
        if (hasAnyHtml) {
            this.results.forEach((product, index) => {
                const html = product.html;
                if (html) {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    const node = wrapper.firstElementChild;
                    if (node) {
                        node.dataset.index = index;
                        // Add stock status class if out of stock (check for 0, false, or "0")
                        const inStock = product.inStock ?? product.in_stock;
                        if (inStock === 0 || inStock === false || inStock === '0') {
                            node.classList.add('outofstock');
                        }
                        container.appendChild(node);
                    } else {
                        container.appendChild(this.createProductElement(product, index));
                    }
                } else {
                    container.appendChild(this.createProductElement(product, index));
                }
            });
        } else {
            this.results.forEach((product, index) => {
                container.appendChild(this.createProductElement(product, index));
            });
        }

        // Load More button removed — using infinite scroll (setupInfiniteScroll) for cleaner UX.
        // The container element stays in the DOM (existing JS hooks reference it) but is never shown.
        const loadMore = document.getElementById('wk-search-load-more');
        if (loadMore) { loadMore.style.display = 'none'; }
    }

    createProductElement(product, index) {
        const div = document.createElement('div');
        div.className = 'wk-search-product product';
        div.dataset.index = index;
        
        const price = this.formatPrice(product.price, product.currency);
        const rating = product.rating ? this.renderRating(product.rating) : '';
        const inStock = product.inStock ? '' : `<span class="wk-search-out-of-stock">${this.config.strings.outOfStock}</span>`;
        const productUrl = product.url || product.permalink || '#';
        
        // Debug missing URL
        if (!product.url && !product.permalink) {
            console.warn('Product missing URL:', product.id, product);
        }
        
        div.innerHTML = `
            <a href="${productUrl}" class="wk-search-product-link woocommerce-LoopProduct-link" data-product-id="${product.id}">
                <div class="wk-search-product-image">
                    ${product.image ? `<img class="attachment-woocommerce_thumbnail" src="${product.image}" alt="${product.title}" loading="lazy">` : '<div class="wk-search-no-image"></div>'}
                </div>
                <div class="wk-search-product-details">
                    <h3 class="wk-search-product-title woocommerce-loop-product__title">${product.title}</h3>
                    <div class="wk-search-product-brand">${product.brand || ''}</div>
                    <div class="wk-search-product-price price">${price} ${inStock}</div>
                    ${rating}
                </div>
            </a>
        `;

        // Add click tracking
        div.addEventListener('click', (e) => {
            this.trackEvent('search_result_clicked', {
                product_id: product.id,
                position: index + 1,
                query: this.currentQuery
            });
        });

        return div;
    }

    renderRating(rating) {
        const stars = Math.round(rating);
        const starsHTML = '★'.repeat(stars) + '☆'.repeat(5 - stars);
        return `<div class="wk-search-product-rating" title="${rating}/5">${starsHTML}</div>`;
    }

    formatPrice(price, currency = null) {
        // Get currency from first product if available
        if (!currency && this.results && this.results.length > 0) {
            currency = this.results[0].currency || 'USD';
        }
        if (!currency) currency = 'USD';
        
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(price);
    }

    updateFilters(facets) {
        if (!facets || typeof facets !== 'object') {
            return;
        }
        
        // Check enabled filters from config
        const enabledFilters = { ...(this.config.enabledFilters || {}) };
        // Keep sidebar focused but include Price again
        enabledFilters.tags = '0';
        enabledFilters.rating = '0';
        enabledFilters.attributes = '0';
        
        // Update brand filters
        if (enabledFilters.brand === '1') {
            this.updateFilterGroup('brand-filters', facets.brand || []);
        } else {
            this.hideFilterGroup('brand-filters');
        }
        
        // Update category filters
        if (enabledFilters.category === '1') {
            this.updateFilterGroup('category-filters', facets.category || []);
        } else {
            this.hideFilterGroup('category-filters');
        }
        
        // Hide price filter if disabled
        if (enabledFilters.price !== '1') {
            const pr = document.querySelector('.wk-search-price-range');
            if (pr) {
                const grp = pr.closest('.wk-search-filter-group');
                if (grp) grp.style.display = 'none';
            }
        }
        
        // Hide product status filter if disabled
        if (enabledFilters.status !== '1') {
            const stockFilter = document.querySelector('#in-stock-filter');
            if (stockFilter) {
                const grp = stockFilter.closest('.wk-search-filter-group');
                if (grp) grp.style.display = 'none';
            }
        }
    }
    
    hideFilterGroup(containerId) {
        const container = document.getElementById(containerId);
        if (container) {
            const filterGroup = container.closest('.wk-search-filter-group');
            if (filterGroup) {
                filterGroup.style.display = 'none';
            }
        }
    }
    

    updateFilterGroup(containerId, options) {
        const container = document.getElementById(containerId);
        // Preserve previously selected values to avoid losing multi-select state
        const previouslySelected = new Set(Array.from(container.querySelectorAll('.wk-search-filter-checkbox:checked')).map(i => i.value));
        container.innerHTML = '';

        // Normalize "options" into an array of { name, id, count, level }
        const normalized = (() => {
            if (!options) return [];
            if (Array.isArray(options)) {
                return options.map(o => {
                    if (typeof o === 'string') return { name: o };
                    if (typeof o === 'number') return { name: String(o) };
                    // Handle different API response formats
                    if (o && typeof o === 'object') {
                        return {
                            name: o.name || o.label || o.value || '',
                            id: o.id || o.name || o.label || o.value || '',
                            count: o.count || o.c || 0
                        };
                    }
                    return o || {};
                });
            }
            // Object map: { label: count }
            if (typeof options === 'object') {
                return Object.keys(options).map(k => ({ name: k, count: options[k] }));
            }
            return [];
        })();

        // Filter out undefined/null values and sort by count
        const validOptions = normalized
            .filter(option => option && option.name && option.name !== 'undefined' && option.name !== 'null')
            .sort((a, b) => (b.count || 0) - (a.count || 0));
            
        validOptions.forEach(option => {
            const label = document.createElement('label');
            label.className = 'wk-search-filter-option';
            
            label.innerHTML = `
                <input type="checkbox" class="wk-search-filter-checkbox" value="${option.id || option.name}" data-label="${option.name || option.id}">
                <span class="wk-search-checkmark"></span>
                <span class="wk-search-filter-label" style="padding-left:${(option.level||0)*8}px">${option.name || option.id}</span>
            `;
            
            const input = label.querySelector('input');
            if (previouslySelected.has(input.value)) {
                input.checked = true;
            }
            container.appendChild(label);
        });
    }


    async loadMoreResults() {
        this.debug('loadMoreResults called', {
            hasMoreResults: this.hasMoreResults,
            isLoading: this.isLoading,
            currentPage: this.currentPage,
            currentResults: this.results.length,
            totalResults: this.totalResults
        });
        
        if (!this.hasMoreResults || this.isLoading) return;

        this.isLoading = true;
        const loadMoreBtn = document.querySelector('.wk-search-load-more-btn');
        loadMoreBtn.textContent = this.config.strings.loading;

        try {
            this.currentPage++;
            const results = await this.searchProducts(this.currentQuery, this.currentPage);
            
            // Use displayResults with append=true to properly handle the results
            this.displayResults(results, true);
            
            this.trackEvent('search_load_more', { page: this.currentPage });
        } catch (error) {
            console.error('Load more failed:', error);
            // Revert page on error
            this.currentPage--;
        } finally {
            this.isLoading = false;
            // Update button text with current count
            if (this.hasMoreResults && this.results.length > 0) {
                loadMoreBtn.textContent = `${this.config.strings.loadMore} (${this.results.length}/${this.totalResults})`;
            } else {
            loadMoreBtn.textContent = this.config.strings.loadMore;
            }
        }
    }

    showSuggestions() {
        const suggestions = document.getElementById('wk-search-suggestions');
        const results = document.getElementById('wk-search-results');
        const loadMore = document.getElementById('wk-search-load-more');
        
        // If we have last search results, show them instead of suggestions
        if (this.lastSearchResults && this.lastSearchResults.products && this.lastSearchResults.products.results.length > 0) {
            suggestions.style.display = 'none';
            results.style.display = 'block';
            loadMore.style.display = 'none';
            
            // Restore the last search results
            this.results = this.lastSearchResults.products.results;
            this.totalResults = this.lastSearchResults.products.total;
            this.hasMoreResults = this.results.length < this.totalResults;
            
            // Re-render the products (sorting and filtering handled server-side)
            this.renderProducts();
            this.updateResultsInfo();
        } else {
            // Show default products or suggestions
        suggestions.style.display = 'block';
        results.style.display = 'none';
            loadMore.style.display = 'none';
            
            // Load default products if not already loaded
            if (this.results.length === 0) {
                this.loadDefaultProducts();
            }
        }
    }

    hideSuggestions() {
        const suggestions = document.getElementById('wk-search-suggestions');
        suggestions.style.display = 'none';
    }

    showResults() {
        const suggestions = document.getElementById('wk-search-suggestions');
        const results = document.getElementById('wk-search-results');
        
        suggestions.style.display = 'none';
        results.style.display = 'block';
    }

    setupCollapsibles(){
        const overlay = document.getElementById('wk-search-overlay');
        const collapsibleGroups = overlay.querySelectorAll('.wk-collapsible');
        
        // Set default states: all filter groups open by default
        collapsibleGroups.forEach((group, index) => {
            const panel = group.querySelector('.wk-search-filter-options, .wk-search-price-range');
            if (!panel) return;
            
            // Keep all filter groups open by default
            group.classList.add('wk-open');
            panel.style.display = '';
        });
        
        // Add click handlers
        overlay.querySelectorAll('.wk-collapsible > h4').forEach(h => {
            h.addEventListener('click', () => {
                const group = h.parentElement;
                const panel = group.querySelector('.wk-search-filter-options, .wk-search-price-range');
                if (!panel) return;
                
                const isOpen = group.classList.contains('wk-open');
                
                if (isOpen) {
                    // Close it
                    group.classList.remove('wk-open');
                    panel.style.display = 'none';
                } else {
                    // Open it
                    group.classList.add('wk-open');
                    panel.style.display = '';
                }
            });
            
            h.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { 
                    e.preventDefault(); 
                    h.click(); 
                }
            });
        });
        
        // Setup dual-range slider
        this.setupDualRangeSlider();
    }
    
    setupDualRangeSlider() {
        const overlay = document.getElementById('wk-search-overlay');
        if (!overlay) return;
        
        const minSlider = overlay.querySelector('#price-min');
        const maxSlider = overlay.querySelector('#price-max');
        const minInput = overlay.querySelector('#price-min-input');
        const maxInput = overlay.querySelector('#price-max-input');
        const minDisplay = overlay.querySelector('.wk-search-price-min');
        const maxDisplay = overlay.querySelector('.wk-search-price-max');
        const slider = overlay.querySelector('.wk-search-price-slider');
        
        if (!minSlider || !maxSlider || !minInput || !maxInput || !slider) return;
        
        // Set initial values
        const minValue = parseInt(minSlider.value);
        const maxValue = parseInt(maxSlider.value);
        minInput.value = minValue;
        maxInput.value = maxValue;
        minDisplay.textContent = this.formatPrice(minValue, this.priceRange?.currency);
        maxDisplay.textContent = this.formatPrice(maxValue, this.priceRange?.currency);
        
        // Calculate minimum gap (5% of total range or 1, whichever is greater)
        const getMinGap = () => {
            const rangeMin = parseInt(minSlider.min);
            const rangeMax = parseInt(maxSlider.max);
            const totalRange = rangeMax - rangeMin;
            return Math.max(1, Math.ceil(totalRange * 0.05));
        };
        
        // Update the active range between thumbs
        const updateActiveRange = () => {
            const minVal = parseInt(minSlider.value);
            const maxVal = parseInt(maxSlider.value);
            const rangeMin = parseInt(minSlider.min);
            const rangeMax = parseInt(maxSlider.max);
            const percent = (v) => ((v - rangeMin) / Math.max(1, (rangeMax - rangeMin))) * 100;
            const minPercent = percent(minVal);
            const maxPercent = percent(maxVal);
            slider.style.setProperty('--min-percent', minPercent + '%');
            slider.style.setProperty('--max-percent', maxPercent + '%');
        };

        // Ensure the active thumb stays on top so it can move across the other slider's area
        const bringToFront = (active) => {
            if (!active) return;
            if (active === minSlider) {
                minSlider.style.zIndex = '5';
                maxSlider.style.zIndex = '4';
            } else {
                maxSlider.style.zIndex = '5';
                minSlider.style.zIndex = '4';
            }
        };

        const attachActivationHandlers = (el) => {
            const fn = () => bringToFront(el);
            el.addEventListener('pointerdown', fn);
            el.addEventListener('mousedown', fn);
            el.addEventListener('touchstart', fn, { passive: true });
            el.addEventListener('focus', fn);
        };
        attachActivationHandlers(minSlider);
        attachActivationHandlers(maxSlider);
        bringToFront(minSlider);
        
        // Double-click to reset to full range
        const resetToFullRange = () => {
            if (!this.priceRange) return;
            minSlider.value = this.priceRange.min;
            maxSlider.value = this.priceRange.max;
            minInput.value = this.priceRange.min;
            maxInput.value = this.priceRange.max;
            minDisplay.textContent = this.formatPrice(this.priceRange.min, this.priceRange.currency);
            maxDisplay.textContent = this.formatPrice(this.priceRange.max, this.priceRange.currency);
            updateActiveRange();
            scheduleCommit();
        };
        
        minSlider.addEventListener('dblclick', resetToFullRange);
        maxSlider.addEventListener('dblclick', resetToFullRange);
        slider.addEventListener('dblclick', resetToFullRange);

        const scheduleCommit = () => {
            clearTimeout(this._priceCommitTimer);
            this._priceCommitTimer = setTimeout(() => {
                this.debounceFilterChange();
            }, 300);
        };
        
        let isProgrammaticChange = false;
        
        // Update min slider
        minSlider.addEventListener('input', () => {
            let minVal = parseInt(minSlider.value);
            const maxVal = parseInt(maxSlider.value);
            const minGap = getMinGap();
            
            // Enforce minimum gap but allow smooth movement
            if (minVal > maxVal - minGap) {
                minVal = maxVal - minGap;
                isProgrammaticChange = true;
                minSlider.value = minVal;
                isProgrammaticChange = false;
            }
            
            minInput.value = minVal;
            minDisplay.textContent = this.formatPrice(minVal, this.priceRange?.currency);
            updateActiveRange();
        });
        minSlider.addEventListener('change', () => {
            if (!isProgrammaticChange) scheduleCommit();
        });
        
        // Update max slider
        maxSlider.addEventListener('input', () => {
            const minVal = parseInt(minSlider.value);
            let maxVal = parseInt(maxSlider.value);
            const minGap = getMinGap();
            
            // Enforce minimum gap but allow smooth movement
            if (maxVal < minVal + minGap) {
                maxVal = minVal + minGap;
                isProgrammaticChange = true;
                maxSlider.value = maxVal;
                isProgrammaticChange = false;
            }
            
            maxInput.value = maxVal;
            maxDisplay.textContent = this.formatPrice(maxVal, this.priceRange?.currency);
            updateActiveRange();
        });
        maxSlider.addEventListener('change', () => {
            if (!isProgrammaticChange) scheduleCommit();
        });
        
        // Update from input fields
        minInput.addEventListener('input', () => {
            let minVal = parseInt(minInput.value) || 0;
            const maxVal = parseInt(maxSlider.value);
            const boundMin = parseInt(minSlider.min);
            const boundMax = parseInt(maxSlider.max);
            const minGap = getMinGap();
            
            // Clamp to bounds
            minVal = Math.max(boundMin, Math.min(minVal, boundMax));
            
            // Enforce minimum gap
            if (minVal > maxVal - minGap) {
                minVal = maxVal - minGap;
            }
            
            isProgrammaticChange = true;
            minInput.value = minVal;
            minSlider.value = minVal;
            minDisplay.textContent = this.formatPrice(minVal, this.priceRange?.currency);
            updateActiveRange();
            isProgrammaticChange = false;
        });
        minInput.addEventListener('change', () => {
            if (!isProgrammaticChange) scheduleCommit();
        });
        
        maxInput.addEventListener('input', () => {
            const minVal = parseInt(minSlider.value);
            let maxVal = parseInt(maxInput.value) || 1000;
            const boundMin = parseInt(minSlider.min);
            const boundMax = parseInt(maxSlider.max);
            const minGap = getMinGap();
            
            // Clamp to bounds
            maxVal = Math.max(boundMin, Math.min(maxVal, boundMax));
            
            // Enforce minimum gap
            if (maxVal < minVal + minGap) {
                maxVal = minVal + minGap;
            }
            
            isProgrammaticChange = true;
            maxInput.value = maxVal;
            maxSlider.value = maxVal;
            maxDisplay.textContent = this.formatPrice(maxVal, this.priceRange?.currency);
            updateActiveRange();
            isProgrammaticChange = false;
        });
        maxInput.addEventListener('change', () => {
            if (!isProgrammaticChange) scheduleCommit();
        });
        
        // Make price display values clickable to reset
        if (minDisplay) {
            minDisplay.style.cursor = 'pointer';
            minDisplay.title = 'Click to reset minimum price';
            minDisplay.addEventListener('click', () => {
                const baseMin = this._basePriceRange ? this._basePriceRange.min : this.priceRange?.min || 0;
                isProgrammaticChange = true;
                minSlider.value = baseMin;
                minInput.value = baseMin;
                minDisplay.textContent = this.formatPrice(baseMin, this.priceRange?.currency);
                updateActiveRange();
                isProgrammaticChange = false;
                scheduleCommit();
            });
        }
        
        if (maxDisplay) {
            maxDisplay.style.cursor = 'pointer';
            maxDisplay.title = 'Click to reset maximum price';
            maxDisplay.addEventListener('click', () => {
                const baseMax = this._basePriceRange ? this._basePriceRange.max : this.priceRange?.max || 1000;
                isProgrammaticChange = true;
                maxSlider.value = baseMax;
                maxInput.value = baseMax;
                maxDisplay.textContent = this.formatPrice(baseMax, this.priceRange?.currency);
                updateActiveRange();
                isProgrammaticChange = false;
                scheduleCommit();
            });
        }
        
        // Initialize the active range
        updateActiveRange();

        // Reset price to base range
        this.resetPriceToBase = () => {
            const baseMin = this.priceRange ? this.priceRange.min : parseInt(minSlider.min, 10) || 0;
            const baseMax = this.priceRange ? this.priceRange.max : parseInt(maxSlider.max, 10) || 1000;
            this.setPriceSliderValues(baseMin, baseMax);
        };
    }
    
    updatePriceFilter() { /* no-op: handled via getCurrentFilters + debounceFilterChange on commit */ }

    setupInfiniteScroll(){
        const sentinelId = 'wk-scroll-sentinel';
        let sentinel = document.getElementById(sentinelId);
        if (!sentinel) {
            sentinel = document.createElement('div');
            sentinel.id = sentinelId;
            sentinel.style.height = '1px';
            const container = document.getElementById('wk-search-results');
            container && container.appendChild(sentinel);
        }
        if (this._io) { try { this._io.disconnect(); } catch(e){} }
        this._io = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting && this.hasMoreResults && !this.isLoading) {
                    this.loadMoreResults();
                }
            });
        }, { root: document.querySelector('#wk-search-results'), rootMargin: '200px', threshold: 0 });
        this._io.observe(sentinel);
    }

    hideResults() {
        const results = document.getElementById('wk-search-results');
        results.style.display = 'none';
    }

    showLoading() {
        const loading = document.getElementById('wk-search-loading');
        loading.style.display = 'flex';
        // Render skeletons in grid
        const grid = document.getElementById('wk-search-products');
        if (grid) {
            grid.innerHTML = '';
            for (let i=0;i<8;i++) {
                const div = document.createElement('div');
                div.className = 'wk-skel-card';
                div.innerHTML = `
                  <div class="wk-skel-img wk-skel-anim"></div>
                  <div class="wk-skel-body">
                    <div class="wk-skel-line wk-skel-anim" style="width:80%"></div>
                    <div class="wk-skel-line wk-skel-anim" style="width:60%"></div>
                  </div>`;
                grid.appendChild(div);
            }
        }
    }

    hideLoading() {
        const loading = document.getElementById('wk-search-loading');
        loading.style.display = 'none';
    }

    showNoResults(fallbackUsed = false, query = '') {
        const results = document.getElementById('wk-search-results');
        const loadMore = document.getElementById('wk-search-load-more');
        const didYouMean = document.getElementById('wk-search-did-you-mean');
        
        this.debug('showNoResults elements check', {
            results: !!results,
            loadMore: !!loadMore,
            didYouMean: !!didYouMean,
            mode: this.currentMode
        });
        
        // Hide load more
        loadMore.style.display = 'none';
        
        // Only show "Did you mean" suggestions in advanced mode
        if (query && query.trim().length > 0) {
            if (didYouMean) {
                if (this.currentMode === 'advanced') {
                    // Advanced mode: Show suggestions
                    didYouMean.style.display = 'block';
                    this.renderDidYouMeanSuggestions(query);
                } else {
                    // Classic mode: Show "no products found" message without suggestions
                    didYouMean.style.display = 'block';
                    this.renderNoProductsMessage(query);
                }
            } else {
                console.error('❌ Did you mean element not found!');
            }
            
            // Try to show popular products underneath, but don't fail if no products
            this.showPopularProductsUnderDidYouMean();
        } else {
            if (didYouMean) {
                didYouMean.style.display = 'none';
            }
        }
    }
    
    async showPopularProductsUnderDidYouMean() {
        try {
            // Load popular products using the same endpoint as default view
            const response = await fetch(`${this.config.edgeUrl}/api/serve/popular-searches`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.config.apiKey}`,
                    'X-Tenant-Id': this.config.tenantId,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    limit: this.config.productsPerPage || 40
                })
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.products && data.products.length > 0) {
                    // Format data to match displayResults expected structure
                    const formattedData = {
                        products: {
                            results: data.products,
                            total: data.totalResults || data.products.length
                        },
                        facets: data.facets || {}
                    };
                    
                    // Set default view flag and clear current query to show popular products title
                    this.isDefaultView = true;
                    this.currentQuery = ''; // Clear query to show popular products title
                    this.results = formattedData.products.results;
                    this.totalResults = formattedData.products.total;
                    this.currentPage = 1;
                    
                    // Show results section
                    const results = document.getElementById('wk-search-results');
                    if (results) {
                        results.style.display = 'block';
                        this.renderProducts();
                        this.updateResultsInfo();
                    }
                } else {
                }
            } else {
            }
        } catch (error) {
        }
    }

    renderNoProductsMessage(query) {
        const didYouMeanContainer = document.getElementById('wk-search-did-you-mean');
        const suggestionsContainer = document.getElementById('did-you-mean-suggestions');
        
        if (!didYouMeanContainer || !suggestionsContainer) {
            return;
        }
        
        // Replace the title with "no products found" message
        const titleElement = didYouMeanContainer.querySelector('h4');
        if (titleElement) {
            titleElement.textContent = this.config.strings?.noProductsFound || 'Ingen produkter fundet';
        }
        
        // Clear the suggestions container (no suggestions in classic mode)
        suggestionsContainer.innerHTML = '';
    }

    hideNoResults() {
        const didYouMean = document.getElementById('wk-search-did-you-mean');
        const results = document.getElementById('wk-search-results');
        if (didYouMean) {
            didYouMean.style.display = 'none';
        }
        if (results) {
            results.style.display = 'none';
        }
    }

    showError() {
        const results = document.getElementById('wk-search-results');
        results.innerHTML = `<div class="wk-search-error">${this.config.strings.error}</div>`;
        results.style.display = 'block';
    }

    async renderSuggestions() {
        const container = document.getElementById('wk-search-suggestions');
        
        if (this.currentQuery.length === 0) {
            await this.loadPopularQueries();
            this.renderPopularSearches();
        } else {
            this.renderSearchSuggestions();
        }
    }

    async loadPopularQueries() {
        if (this.popularQueriesLoaded) return;
        
        try {
            const response = await fetch(`${this.config.edgeUrl}/api/serve/popular-queries`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.config.apiKey}`,
                    'X-Tenant-Id': this.config.tenantId
                },
                body: JSON.stringify({
                    limit: 10
                })
            });

            if (response.ok) {
                const data = await response.json();
                
                if (data.queries && data.queries.length > 0) {
                    this.popularSearches = data.queries;
                } else {
                    // Fallback to default popular searches
                    this.popularSearches = ['shoes', 'jacket', 'shirt', 'pants', 'accessories'];
                }
                
                this.popularQueriesLoaded = true;
            }
        } catch (error) {
            console.error('Error loading popular queries:', error);
            // Use fallback popular searches
            this.popularSearches = ['shoes', 'jacket', 'shirt', 'pants', 'accessories'];
            this.popularQueriesLoaded = true;
        }
    }

    renderPopularSearches() {
        const container = document.getElementById('wk-search-suggestions');
        
        container.innerHTML = `
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.popularSearches}</h3>
                <div class="wk-search-popular-tags">
                    ${this.popularSearches.map(term => 
                        `<button type="button" class="wk-search-popular-tag" data-query="${term}">${term}</button>`
                    ).join('')}
                </div>
            </div>
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.recentSearches}</h3>
                <div class="wk-search-recent-searches">
                    ${this.searchHistory.map(term => 
                        `<button type="button" class="wk-search-recent-item" data-query="${term}">${term}</button>`
                    ).join('')}
                </div>
            </div>
        `;
        
        // Add click handlers
        container.querySelectorAll('[data-query]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const query = e.target.dataset.query;
                this.setSearchQuery(query);
                this.trackSearchOnce(query);
                this.performSearch();
            });
        });
    }

    renderSearchSuggestions() {
        const container = document.getElementById('wk-search-suggestions');
        
        // Suggestions shape may be rich: {queries, categories, brands, products}
        const s = this.suggestions;
        const queries = Array.isArray(s.queries) ? s.queries : (Array.isArray(s) ? s : []);
        const categories = Array.isArray(s.categories) ? s.categories : [];
        const brands = Array.isArray(s.brands) ? s.brands : [];
        const products = Array.isArray(s.products) ? s.products : [];

        container.innerHTML = `
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.suggestions}</h3>
                <div class="wk-search-suggestion-list">
                    ${queries.slice(0, 8).map(q => 
                        `<button type="button" class="wk-search-suggestion-item" data-query="${q}">${q}</button>`
                    ).join('')}
                </div>
            </div>
            ${categories.length ? `
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.category}</h3>
                <div class="wk-search-category-list">
                    ${categories.slice(0, 6).map(c => 
                        `<a class="wk-search-category-item" href="${c.url || '#'}">${c.name || ''}</a>`
                    ).join('')}
                </div>
            </div>` : ''}
            ${brands.length ? `
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.brand}</h3>
                <div class="wk-search-brand-list">
                    ${brands.slice(0, 6).map(b => 
                        `<button type="button" class="wk-search-brand-item" data-query="${b.name}">${b.name} (${b.count})</button>`
                    ).join('')}
                </div>
            </div>` : ''}
            ${products.length ? `
            <div class="wk-search-suggestions-section">
                <h3>${this.config.strings.results}</h3>
                <div class="wk-search-product-suggestions">
                    ${products.slice(0, 5).map(p => `
                        <a class="wk-search-product-suggestion" href="${p.url}">
                            ${p.image ? `<img src="${p.image}" alt="${p.title}" loading="lazy"/>` : ''}
                            <span>${p.title}</span>
                        </a>
                    `).join('')}
                </div>
            </div>` : ''}
        `;
        
        // Add click handlers
        container.querySelectorAll('[data-query]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const query = e.target.dataset.query;
                this.setSearchQuery(query);
                this.trackSearchOnce(query);
                this.performSearch();
            });
        });
    }

    setSearchQuery(query) {
        const searchInput = document.getElementById('wk-search-input');
        searchInput.value = query;
        this.currentQuery = query;
    }

    clearSearch() {
        const searchInput = document.getElementById('wk-search-input');
        searchInput.value = '';
        this.currentQuery = '';

        const clearBtn = document.querySelector('.wk-search-clear');
        clearBtn.style.display = 'none';

        // Show original search icon when cleared
        if (this.originalSearchIcon) {
            this.originalSearchIcon.style.display = 'block';
        }

        // Clear all filters when clearing search
        this.clearAllFilters();

        // Recent-queries bar isn't refreshed by loadDefaultProducts (which only renders the top-queries bar),
        // so render it explicitly. loadDefaultProducts handles the top-queries bar, popular products fetch, and display.
        try { this.renderRecentQueriesBar(); } catch(e){}
        // Restore the empty-state popular-products view — replaces stale search results.
        this.loadDefaultProducts();
    }
    
    clearAllFilters() {
        const overlay = document.getElementById('wk-search-overlay');
        if (!overlay) return;
        
        // Clear all checkboxes
        overlay.querySelectorAll('.wk-search-filter-checkbox').forEach(cb => cb.checked = false);
        
        const inStockFilter = overlay.querySelector('#in-stock-filter');
        if (inStockFilter) inStockFilter.checked = false;
        
        const onSale = overlay.querySelector('#on-sale-filter');
        if (onSale) onSale.checked = false;
        
        // Reset price sliders to full range
        const minSlider = overlay.querySelector('#price-min');
        const maxSlider = overlay.querySelector('#price-max');
        if (minSlider && maxSlider && this.priceRange) {
            this.setPriceSliderValues(this.priceRange.min, this.priceRange.max);
        }
        
        // Clear filter chips
        const chipsBar = document.getElementById('wk-selected-chips');
        if (chipsBar) chipsBar.innerHTML = '';
    }
    
    toggleSearchMode() {
        // Toggle between classic and advanced modes
        this.currentMode = this.currentMode === 'classic' ? 'advanced' : 'classic';
        
        // Update button label and title
        const modeToggleBtn = document.querySelector('.wk-search-mode-toggle');
        if (modeToggleBtn) {
            const label = modeToggleBtn.querySelector('.wk-mode-label');
            if (label) {
                label.textContent = this.currentMode === 'classic' 
                    ? (this.config.strings?.classicMode || 'Classic')
                    : (this.config.strings?.advancedMode || 'Advanced');
            }
            modeToggleBtn.title = this.currentMode === 'classic' 
                ? (this.config.strings?.switchToAdvanced || 'Switch to Advanced')
                : (this.config.strings?.switchToClassic || 'Switch to Classic');
            
            // Add visual feedback
            modeToggleBtn.classList.add('wk-mode-switching');
            setTimeout(() => {
                modeToggleBtn.classList.remove('wk-mode-switching');
            }, 300);
        }
        
        // Re-run search with new mode
        const query = this.currentQuery || '';
        this.performSearch(query);
        
        // Track mode switch
        if (typeof window.wkTrack === 'function') {
            window.wkTrack('mode_switch', { mode: this.currentMode, query: query });
        }
    }

    navigateResults(direction) {
        const products = document.querySelectorAll('.wk-search-product');
        if (products.length === 0) return;

        // Remove current selection
        products.forEach(p => p.classList.remove('wk-search-product-selected'));
        
        // Update index
        this.selectedIndex += direction;
        this.selectedIndex = Math.max(0, Math.min(this.selectedIndex, products.length - 1));
        
        // Add selection
        if (products[this.selectedIndex]) {
            products[this.selectedIndex].classList.add('wk-search-product-selected');
            products[this.selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    selectResult() {
        const selectedProduct = document.querySelector('.wk-search-product-selected');
        if (selectedProduct) {
            const link = selectedProduct.querySelector('.wk-search-product-link');
            if (link) {
                link.click();
            }
        }
    }

    async getPopularSearches() {
        try {
            const response = await fetch(`${this.config.edgeUrl}/api/serve/popular-searches`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getApiKey()}`,
                    'X-Tenant-Id': this.config.tenantId
                },
                body: JSON.stringify({
                    tenant_id: this.config.tenantId,
                    limit: 10
                })
            });

            if (response.ok) {
                const data = await response.json();
                return data.searches || [];
            }
        } catch (error) {
            console.warn('Failed to load popular searches:', error);
        }

        // Fallback to default popular searches
        return ['shoes', 'clothing', 'electronics', 'accessories', 'home'];
    }

    async getSearchSuggestions() {
        try {
            const response = await fetch(`${this.config.edgeUrl}/api/serve/suggestions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getApiKey()}`,
                    'X-Tenant-Id': this.config.tenantId
                },
                body: JSON.stringify({
                    tenant_id: this.config.tenantId,
                    limit: 50,
                    query: this.currentQuery || ''
                })
            });

            if (response.ok) {
                const data = await response.json();
                return data.suggestions || [];
            }
        } catch (error) {
            console.warn('Failed to load search suggestions:', error);
        }

        return [];
    }

    loadSearchHistory() {
        try {
            const history = localStorage.getItem('wk_search_history');
            return history ? JSON.parse(history) : [];
        } catch (error) {
            return [];
        }
    }

    saveSearchHistory(query) {
        if (!query || query.length < 2) return;

        // Remove if already exists
        this.searchHistory = this.searchHistory.filter(term => term !== query);
        
        // Add to beginning
        this.searchHistory.unshift(query);
        
        // Keep only last 5
        this.searchHistory = this.searchHistory.slice(0, 5);
        
        // Save to localStorage
        try {
            localStorage.setItem('wk_search_history', JSON.stringify(this.searchHistory));
        } catch (error) {
            console.warn('Failed to save search history:', error);
        }
    }

    trackEvent(event, data = {}) {
        if (typeof window.wkTrack === 'function') {
            window.wkTrack(event, {
                ...data,
                timestamp: Date.now(),
                overlay_version: '2.0'
            });
        } else {
            console.warn('WK Search: wkTrack function not available');
        }
    }

    trackSearchOnce(query) {
        try {
            const q = String(query || '').trim();
            const minChars = (this.config && this.config.minChars) ? this.config.minChars : 3;
            if (!q || q.length < minChars) return;
            this._trackedQueries = this._trackedQueries || new Set();
            if (this._trackedQueries.has(q)) return;
            this._trackedQueries.add(q);
            this.trackEvent('search', { query: q });
            try { this.saveSearchHistory(q); } catch(e){}
            // Update recent bar reactively
            try { this.renderRecentQueriesBar(); } catch(e){}
        } catch(e){}
    }

    normalizeTopQueries(arr) {
        try {
            return arr.map(x => {
                if (typeof x === 'string') return x;
                if (x && typeof x === 'object') { return x.query || x.name || x.value || ''; }
                return '';
            }).filter(Boolean);
        } catch(e){ return []; }
    }

    getApiKey() {
        return this.config.apiKey || '';
    }

    async renderDidYouMeanSuggestions(query) {
        const suggestionsContainer = document.getElementById('did-you-mean-suggestions');
        if (!suggestionsContainer) {
            return;
        }
        
        // Show loading state
        suggestionsContainer.innerHTML = '<div class="wk-search-loading-suggestions">Finding suggestions...</div>';
        
        try {
            // Generate suggestions based on the query (only real matches, no fallback)
            const suggestions = await this.generateSuggestions(query);
            
            if (suggestions && suggestions.length > 0) {
                // Render suggestions
                suggestionsContainer.innerHTML = suggestions.map(suggestion => 
                    `<button type="button" class="wk-search-suggestion-item" data-query="${suggestion}">${suggestion}</button>`
                ).join('');
                
                // Add click handlers for suggestions
                suggestionsContainer.querySelectorAll('[data-query]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const suggestionQuery = e.target.dataset.query;
                        this.setSearchQuery(suggestionQuery);
                        this.trackSearchOnce(suggestionQuery);
                        this.performSearch();
                    });
                });
            } else {
                // No suggestions available - show simple message
                suggestionsContainer.innerHTML = `
                    <div class="wk-no-suggestions-message">
                        <p style="font-size: 14px; color: #999; margin: 0;">
                            ${this.config.strings?.noSuggestionsAvailable || 'Ingen forslag tilgængelige'}
                        </p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('❌ Error generating suggestions:', error);
            // Show simple message on error
            suggestionsContainer.innerHTML = `
                <div class="wk-no-suggestions-message">
                    <p style="font-size: 14px; color: #999; margin: 0;">
                        ${this.config.strings?.noSuggestionsAvailable || 'Ingen forslag tilgængelige'}
                    </p>
                </div>
            `;
        }
    }

    generateFallbackSuggestions(query) {
        // Don't generate meaningless fallback suggestions
        // Return empty array - let the caller handle "no suggestions" state
        return [];
    }

    async generateSuggestions(query) {
        if (!query || query.trim().length === 0) return [];
        
        const suggestions = [];
        const queryLower = query.toLowerCase().trim();
        
        // 1. Try fuzzy matching with popular searches
        if (this.popularSearches && this.popularSearches.length > 0) {
            const fuzzyMatches = this.findFuzzyMatches(queryLower, this.popularSearches);
            suggestions.push(...fuzzyMatches.slice(0, 3));
        } else {
        }
        
        // 2. Try to find similar words in the query
        const wordSuggestions = this.generateWordSuggestions(queryLower);
        suggestions.push(...wordSuggestions.slice(0, 2));
        
        // 3. Try to get suggestions from the backend
        try {
            const backendSuggestions = await this.getBackendSuggestions(query);
            suggestions.push(...backendSuggestions.slice(0, 2));
        } catch (error) {
        }
        
        // Remove duplicates and return top 5 suggestions
        const finalSuggestions = [...new Set(suggestions)].slice(0, 5);
        return finalSuggestions;
    }

    findFuzzyMatches(query, candidates) {
        const matches = [];
        const queryWords = query.split(/\s+/);
        
        for (const candidate of candidates) {
            const candidateLower = candidate.toLowerCase();
            let score = 0;
            
            // Check if any query words are contained in the candidate
            for (const word of queryWords) {
                if (candidateLower.includes(word)) {
                    score += 2;
                } else if (this.calculateSimilarity(word, candidateLower) > 0.6) {
                    score += 1;
                }
            }
            
            if (score > 0) {
                matches.push({ text: candidate, score });
            }
        }
        
        return matches.sort((a, b) => b.score - a.score).map(m => m.text);
    }

    calculateSimilarity(str1, str2) {
        const longer = str1.length > str2.length ? str1 : str2;
        const shorter = str1.length > str2.length ? str2 : str1;
        
        if (longer.length === 0) return 1.0;
        
        const editDistance = this.levenshteinDistance(longer, shorter);
        return (longer.length - editDistance) / longer.length;
    }

    levenshteinDistance(str1, str2) {
        const matrix = [];
        
        for (let i = 0; i <= str2.length; i++) {
            matrix[i] = [i];
        }
        
        for (let j = 0; j <= str1.length; j++) {
            matrix[0][j] = j;
        }
        
        for (let i = 1; i <= str2.length; i++) {
            for (let j = 1; j <= str1.length; j++) {
                if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
                    matrix[i][j] = matrix[i - 1][j - 1];
                } else {
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j - 1] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j] + 1
                    );
                }
            }
        }
        
        return matrix[str2.length][str1.length];
    }

    generateWordSuggestions(query) {
        const suggestions = [];
        const words = query.split(/\s+/);
        
        // Try to fix common typos
        const commonTypos = {
            'shoes': ['shoe', 'shos', 'shoes'],
            'jacket': ['jacket', 'jackets', 'jackit'],
            'shirt': ['shirt', 'shirts', 'shrt'],
            'pants': ['pant', 'pants', 'pnts'],
            'dress': ['dress', 'dresses', 'dres'],
            'shorts': ['short', 'shorts', 'shrt'],
            'sweater': ['sweater', 'sweaters', 'sweter'],
            'hoodie': ['hoodie', 'hoodies', 'hoody'],
            'jeans': ['jean', 'jeans', 'jens'],
            'boots': ['boot', 'boots', 'bts']
        };
        
        for (const word of words) {
            for (const [correct, variants] of Object.entries(commonTypos)) {
                if (variants.includes(word.toLowerCase())) {
                    suggestions.push(query.replace(word, correct));
                }
            }
        }
        
        return suggestions;
    }

    async getBackendSuggestions(query) {
        try {
            const response = await fetch(`${this.config.edgeUrl}/api/serve/suggestions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.config.apiKey}`,
                    'X-Tenant-Id': this.config.tenantId
                },
                body: JSON.stringify({
                    query: query,
                    limit: 5
                })
            });
            
            if (response.ok) {
                const data = await response.json();
                return data.suggestions || [];
            }
        } catch (error) {
        }
        
        return [];
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (window.wkSearchConfig) {
        window.wkSearchOverlay = new WKSearchOverlay(window.wkSearchConfig);
    } else {
        console.error('WK Search: wkSearchConfig not found!');
    }
});
