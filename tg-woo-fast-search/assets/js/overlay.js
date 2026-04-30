/**
 * WK Search System - Advanced Search Overlay
 * Multi-tenant hosted search with instant results, filters, and analytics
 */

class WKSearchOverlay {
    constructor(config) {
        this.config = config;
        this.debugMode = config.debugMode || false;
        this.isInitialized = false;
        this.searchWorker = null;
        this.clientShards = null;
        this.searchCache = new Map();
        this.debounceTimer = null;
        this.currentRequest = null;
        this.currentQuery = '';
        this.currentFilters = {};
        this.currentSorting = 'relevance';
        this.currentPage = 1;
        this.isLoading = false;
        this.selectedIndex = -1;
        this.searchHistory = this.loadSearchHistory();
        this.popularSearches = [];
        this.suggestions = [];
        
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
            // Initialize client shards if enabled
            if (this.config.clientShardsEnabled) {
                await this.initializeClientShards();
            }

            // Initialize search worker
            await this.initializeSearchWorker();

            // Set up search triggers
            this.setupSearchTriggers();

            // Set up keyboard navigation
            this.setupKeyboardNavigation();

            // Set up overlay interactions
            this.setupOverlayInteractions();

            // Load initial data
            await this.loadInitialData();

            this.isInitialized = true;
        } catch (error) {
            console.error('WK Search System initialization failed:', error);
        }
    }

    async initializeClientShards() {
        try {
            // Check if shards are available in IndexedDB
            const shards = await this.loadShardsFromIndexedDB();
            
            if (shards && this.isShardsValid(shards)) {
                this.clientShards = shards;
            } else {
                // Fetch fresh shards from server
                const freshShards = await this.fetchClientShards();
                if (freshShards) {
                    this.clientShards = freshShards;
                    await this.saveShardsToIndexedDB(freshShards);
                }
            }
        } catch (error) {
            console.warn('WK Search: Failed to initialize client shards:', error);
            this.clientShards = null;
        }
    }

    async initializeSearchWorker() {
        if (!this.config.clientShardsEnabled || !this.clientShards) {
            return;
        }

        try {
            this.searchWorker = new Worker(this.getWorkerUrl());
            
            this.searchWorker.onmessage = (event) => {
                const { type, data } = event.data;
                
                switch (type) {
                    case 'search_result':
                        this.handleSearchResult(data);
                        break;
                    case 'error':
                        console.error('Search worker error:', data);
                        this.handleSearchError(data);
                        break;
                }
            };

            // Send shards to worker
            this.searchWorker.postMessage({
                type: 'init_shards',
                shards: this.clientShards,
                debugMode: this.debugMode
            });
        } catch (error) {
            console.warn('WK Search: Failed to initialize search worker:', error);
            this.searchWorker = null;
        }
    }

    setupSearchTriggers() {
        // Find search input elements
        const searchInputs = document.querySelectorAll('input[type="search"], input[name*="search"], .search-input, #search');
        
        searchInputs.forEach(input => {
            if (input.dataset.wkSearchInitialized) {
                return;
            }

            input.dataset.wkSearchInitialized = 'true';
            
            // Create search container
            const container = this.createSearchContainer(input);
            
            // Set up event listeners
            input.addEventListener('input', (e) => this.handleSearchInput(e, container));
            input.addEventListener('focus', (e) => this.showSearchResults(container));
            input.addEventListener('blur', (e) => this.hideSearchResults(container, e));
            input.addEventListener('keydown', (e) => this.handleKeydown(e, container));
        });
    }

    createSearchContainer(input) {
        const container = document.createElement('div');
        container.className = 'wk-search-container';
        container.style.position = 'relative';
        
        // Insert after the input
        input.parentNode.insertBefore(container, input.nextSibling);
        
        // Create results dropdown
        const results = document.createElement('div');
        results.className = 'wk-search-results';
        results.style.display = 'none';
        container.appendChild(results);
        
        return container;
    }

    async handleSearchInput(event, container) {
        const query = event.target.value.trim();
        
        // Clear previous debounce timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        // Cancel previous request
        if (this.currentRequest) {
            this.currentRequest.abort();
        }

        if (query.length < 2) {
            this.hideSearchResults(container);
            return;
        }

        // Debounce search
        this.debounceTimer = setTimeout(() => {
            this.performSearch(query, container);
        }, 150);
    }

    async performSearch(query, container) {
        const resultsContainer = container.querySelector('.wk-search-results');
        
        // Show loading state
        this.showLoadingState(resultsContainer);

        try {
            let results;
            
            // Try client-side search first if available
            if (this.searchWorker && this.clientShards) {
                results = await this.searchClientSide(query);
            }
            
            // Fallback to hosted search
            if (!results) {
                results = await this.searchHosted(query);
            }

            this.displaySearchResults(results, resultsContainer);
            this.showSearchResults(container);
            
        } catch (error) {
            console.error('Search failed:', error);
            this.showErrorState(resultsContainer);
        }
    }

    async searchClientSide(query) {
        return new Promise((resolve, reject) => {
            if (!this.searchWorker) {
                reject(new Error('Search worker not available'));
                return;
            }

            const timeout = setTimeout(() => {
                reject(new Error('Client search timeout'));
            }, 100);

            const handleMessage = (event) => {
                const { type, data } = event.data;
                if (type === 'search_result') {
                    clearTimeout(timeout);
                    this.searchWorker.removeEventListener('message', handleMessage);
                    resolve(data);
                } else if (type === 'error') {
                    clearTimeout(timeout);
                    this.searchWorker.removeEventListener('message', handleMessage);
                    reject(new Error(data.message));
                }
            };

            this.searchWorker.addEventListener('message', handleMessage);
            this.searchWorker.postMessage({
                type: 'search',
                query: query,
                options: {
                    limit: 12,
                    fields: ['title', 'price', 'url', 'image', 'inStock', 'brand']
                }
            });
        });
    }

    async searchHosted(query) {
        const response = await fetch(`${this.config.edgeUrl}/serve/search`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${this.getApiKey()}`,
                'X-Tenant-Id': this.config.tenantId
            },
            body: JSON.stringify({
                query: query,
                key: this.config.searchKey,
                products: {
                    start: 0,
                    count: 12,
                    fields: ['title', 'price', 'url', 'image', 'inStock', 'brand'],
                    returnInitialContent: false
                },
                format: 'json'
            })
        });

        if (!response.ok) {
            throw new Error(`Search request failed: ${response.status}`);
        }

        return await response.json();
    }

    displaySearchResults(results, container) {
        container.innerHTML = '';

        if (!results.products || results.products.results.length === 0) {
            this.showNoResults(container);
            return;
        }

        const products = results.products.results;
        
        // Create results list
        const list = document.createElement('ul');
        list.className = 'wk-search-results-list';

        products.forEach((product, index) => {
            const item = this.createProductItem(product, index);
            list.appendChild(item);
        });

        // Add "View all results" link
        if (results.products.total > products.length) {
            const viewAll = document.createElement('li');
            viewAll.className = 'wk-search-view-all';
            viewAll.innerHTML = `
                <a href="${this.getSearchUrl(results.query)}" class="wk-search-link">
                    ${this.config.strings.viewAll} (${results.products.total})
                </a>
            `;
            list.appendChild(viewAll);
        }

        container.appendChild(list);
    }

    createProductItem(product, index) {
        const item = document.createElement('li');
        item.className = 'wk-search-result-item';
        item.dataset.index = index;
        
        const price = this.formatPrice(product.price, product.currency);
        const inStock = product.inStock ? '' : ` (${this.config.strings.outOfStock})`;
        
        item.innerHTML = `
            <a href="${product.url}" class="wk-search-link" data-product-id="${product.id}">
                <div class="wk-search-product">
                    ${product.image ? `<img src="${product.image}" alt="${product.title}" class="wk-search-image">` : ''}
                    <div class="wk-search-details">
                        <div class="wk-search-title">${product.title}</div>
                        <div class="wk-search-price">${price}${inStock}</div>
                        ${product.brand ? `<div class="wk-search-brand">${product.brand}</div>` : ''}
                    </div>
                </div>
            </a>
        `;

        // Add click tracking
        item.addEventListener('click', () => {
            this.track('search_click', {
                query: this.getCurrentQuery(),
                product_id: product.id,
                position: index + 1
            });
        });

        return item;
    }

    showLoadingState(container) {
        container.innerHTML = `
            <div class="wk-search-loading">
                <div class="wk-search-spinner"></div>
                <span>${this.config.strings.loading}</span>
            </div>
        `;
    }

    showErrorState(container) {
        container.innerHTML = `
            <div class="wk-search-error">
                <span>${this.config.strings.error}</span>
            </div>
        `;
    }

    showNoResults(container) {
        container.innerHTML = `
            <div class="wk-search-no-results">
                <span>${this.config.strings.noResults}</span>
            </div>
        `;
    }

    showSearchResults(container) {
        const results = container.querySelector('.wk-search-results');
        if (results) {
            results.style.display = 'block';
        }
    }

    hideSearchResults(container, event) {
        // Delay hiding to allow clicks on results
        setTimeout(() => {
            const results = container.querySelector('.wk-search-results');
            if (results && !results.contains(document.activeElement)) {
                results.style.display = 'none';
            }
        }, 200);
    }

    setupKeyboardNavigation() {
        document.addEventListener('keydown', (event) => {
            const activeContainer = document.querySelector('.wk-search-container:focus-within');
            if (!activeContainer) return;

            const results = activeContainer.querySelector('.wk-search-results');
            if (!results || results.style.display === 'none') return;

            const items = results.querySelectorAll('.wk-search-result-item');
            const current = results.querySelector('.wk-search-result-item.active');
            let currentIndex = current ? parseInt(current.dataset.index) : -1;

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    currentIndex = Math.min(currentIndex + 1, items.length - 1);
                    this.updateActiveItem(items, currentIndex);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    currentIndex = Math.max(currentIndex - 1, -1);
                    this.updateActiveItem(items, currentIndex);
                    break;
                case 'Enter':
                    event.preventDefault();
                    if (currentIndex >= 0 && items[currentIndex]) {
                        items[currentIndex].querySelector('a').click();
                    }
                    break;
                case 'Escape':
                    this.hideSearchResults(activeContainer);
                    break;
            }
        });
    }

    updateActiveItem(items, index) {
        items.forEach((item, i) => {
            item.classList.toggle('active', i === index);
        });
    }

    getCurrentQuery() {
        const activeInput = document.querySelector('input[data-wk-search-initialized]:focus');
        return activeInput ? activeInput.value.trim() : '';
    }

    getSearchUrl(query) {
        // This would be customized based on your site's search page URL
        return `/search/?q=${encodeURIComponent(query)}`;
    }

    formatPrice(price, currency = 'USD') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(price);
    }

    getApiKey() {
        // In a real implementation, this would be securely retrieved
        return this.config.apiKey || '';
    }

    track(event, payload) {
        if (typeof window.wkTrack === 'function') {
            window.wkTrack(event, payload);
        }
    }

    getWorkerUrl() {
        return `${this.config.pluginUrl}/assets/js/search-worker.js`;
    }

    async loadShardsFromIndexedDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('wk-search-shards', 1);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const db = request.result;
                const transaction = db.transaction(['shards'], 'readonly');
                const store = transaction.objectStore('shards');
                const getRequest = store.get('shards');
                
                getRequest.onsuccess = () => resolve(getRequest.result);
                getRequest.onerror = () => reject(getRequest.error);
            };
            
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('shards')) {
                    db.createObjectStore('shards');
                }
            };
        });
    }

    async saveShardsToIndexedDB(shards) {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('wk-search-shards', 1);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const db = request.result;
                const transaction = db.transaction(['shards'], 'readwrite');
                const store = transaction.objectStore('shards');
                const putRequest = store.put(shards, 'shards');
                
                putRequest.onsuccess = () => resolve();
                putRequest.onerror = () => reject(putRequest.error);
            };
            
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('shards')) {
                    db.createObjectStore('shards');
                }
            };
        });
    }

    isShardsValid(shards) {
        if (!shards || !shards.version || !shards.products) {
            return false;
        }

        // Check if shards are not too old (e.g., 24 hours)
        const maxAge = 24 * 60 * 60 * 1000; // 24 hours in milliseconds
        return (Date.now() - shards.timestamp) < maxAge;
    }

    async fetchClientShards() {
        try {
            const response = await fetch(`${this.config.edgeUrl}/admin/client-shards`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getApiKey()}`,
                    'X-Tenant-Id': this.config.tenantId
                },
                body: JSON.stringify({
                    tenant_id: this.config.tenantId,
                    max_size: this.config.clientShardsMaxSize,
                    chunk_size: this.config.clientShardsChunkSize
                })
            });

            if (!response.ok) {
                throw new Error(`Failed to fetch shards: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.warn('Failed to fetch client shards:', error);
            return null;
        }
    }
}

    // Advanced search functionality
    setupOverlayInteractions() {
        const $overlay = $('#wk-search-overlay');
        const $input = $('#wk-search-input');
        const $close = $('.wk-search-close');
        const $clear = $('.wk-search-clear');
        const $backdrop = $('.wk-search-overlay-backdrop');

        // Close overlay
        $close.add($backdrop).on('click', () => this.closeOverlay());
        
        // Clear search
        $clear.on('click', () => this.clearSearch());
        
        // Input handling
        $input.on('input', (e) => this.handleInput(e));
        $input.on('keydown', (e) => this.handleKeydown(e));
        $input.on('focus', () => this.showSuggestions());
        
        // Filter toggle
        $('.wk-search-filter-toggle').on('click', () => this.toggleFilters());
        
        // Sort change
        $('.wk-search-sort-select').on('change', (e) => this.handleSortChange(e));
        
        // Filter changes
        $('.wk-search-filter-in-stock').on('change', () => this.applyFilters());
        $('.wk-search-price-min, .wk-search-price-max').on('input', () => this.updatePriceRange());
        
        // View all results
        $('.wk-search-view-all').on('click', () => this.viewAllResults());
    }

    async loadInitialData() {
        try {
            // Load popular searches
            this.popularSearches = await this.getPopularSearches();
            this.updatePopularSearches();
            
            // Load search suggestions
            this.suggestions = await this.getSearchSuggestions();
        } catch (error) {
            console.warn('Failed to load initial data:', error);
        }
    }

    handleInput(e) {
        const query = e.target.value.trim();
        this.currentQuery = query;
        
        // Clear previous timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
        
        // Show/hide clear button
        $('.wk-search-clear').toggle(query.length > 0);
        
        if (query.length < this.config.minQueryLength) {
            this.showSuggestions();
            return;
        }
        
        // Debounce search
        this.debounceTimer = setTimeout(() => {
            this.performSearch(query);
        }, this.config.debounceDelay);
    }

    async performSearch(query, page = 1) {
        if (this.isLoading) {
            return;
        }
        
        this.isLoading = true;
        this.currentPage = page;
        this.showLoading();
        
        try {
            // Check cache first
            const cacheKey = this.getCacheKey(query, this.currentFilters, this.currentSorting, page);
            if (this.searchCache.has(cacheKey)) {
                const cached = this.searchCache.get(cacheKey);
                this.displayResults(cached);
                this.isLoading = false;
                return;
            }
            
            // Perform search
            const results = await this.searchAPI(query, page);
            
            // Cache results
            this.searchCache.set(cacheKey, results);
            
            // Display results
            this.displayResults(results);
            
            // Track search
            this.trackSearch(query, results);
            
            // Add to search history
            this.addToSearchHistory(query);
            
        } catch (error) {
            console.error('Search failed:', error);
            this.showError();
        } finally {
            this.isLoading = false;
            this.hideLoading();
        }
    }

    async searchAPI(query, page = 1) {
        // Direct API call to hosted service - much faster than WordPress proxy
        const searchData = {
            tenant_id: this.config.tenantId,
            query: query,
            options: {
                start: (page - 1) * this.config.maxResults,
                count: this.config.maxResults,
                filters: this.sanitizeFilters(this.currentFilters),
                sorting: [this.currentSorting],
                returnInitialContent: false
            }
        };

        const response = await fetch(`${this.config.edgeUrl}/serve/search`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.config.apiKey}`,
                'X-Tenant-Id': this.config.tenantId,
                'Content-Type': 'application/json',
                'User-Agent': 'WK-Search-System-WP/' + this.config.version
            },
            body: JSON.stringify(searchData)
        });

        if (!response.ok) {
            throw new Error(`Search failed: ${response.status} ${response.statusText}`);
        }

        const result = await response.json();
        
        // Track search event for analytics
        this.trackSearchEvent(query, result);
        
        return result;
    }

    sanitizeFilters(filters) {
        const sanitized = [];
        
        if (filters.price_range && Array.isArray(filters.price_range)) {
            const priceRange = filters.price_range.map(Number);
            if (priceRange.length === 2) {
                sanitized.push('price:' + priceRange.join(','));
            }
        }
        
        if (filters.categories && Array.isArray(filters.categories)) {
            filters.categories.forEach(category => {
                sanitized.push('category:' + category);
            });
        }
        
        if (filters.brands && Array.isArray(filters.brands)) {
            filters.brands.forEach(brand => {
                sanitized.push('brand:' + brand);
            });
        }
        
        if (filters.in_stock) {
            sanitized.push('in_stock:true');
        }
        
        if (filters.rating && filters.rating > 0) {
            sanitized.push('rating:' + filters.rating);
        }
        
        return sanitized;
    }

    async trackSearchEvent(query, results) {
        if (!this.config.enableAnalytics) {
            return;
        }

        try {
            const eventData = {
                tenant_id: this.config.tenantId,
                events: [{
                    type: 'search',
                    query: query,
                    results_count: results.products ? results.products.results.length : 0,
                    total_results: results.products ? results.products.total : 0,
                    filters: this.currentFilters,
                    sorting: this.currentSorting,
                    page: this.currentPage,
                    timestamp: Date.now(),
                    user_agent: navigator.userAgent,
                    referrer: document.referrer
                }]
            };

            // Send tracking event directly to hosted service
            await fetch(`${this.config.edgeUrl}/track`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.config.apiKey}`,
                    'X-Tenant-Id': this.config.tenantId,
                    'Content-Type': 'application/json',
                    'User-Agent': 'WK-Search-System-WP/' + this.config.version
                },
                body: JSON.stringify(eventData)
            });
        } catch (error) {
            console.warn('Failed to track search event:', error);
        }
    }

    displayResults(results) {
        const $results = $('.wk-search-results');
        const $suggestions = $('.wk-search-suggestions');
        const $zeroResults = $('.wk-search-zero-results');
        
        // Hide suggestions
        $suggestions.hide();
        
        if (!results.products || results.products.results.length === 0) {
            $results.hide();
            $zeroResults.show();
            this.updateZeroResultsSuggestions();
            return;
        }
        
        $zeroResults.hide();
        $results.show();
        
        // Update results count
        $('.wk-search-results-text').text(
            `${results.products.results.length} of ${results.products.total} results`
        );
        
        // Render results
        this.renderResults(results.products.results);
        
        // Update filters
        this.updateFilters(results.products.filters);
    }

    renderResults(products) {
        const $list = $('.wk-search-results-list');
        $list.empty();
        
        products.forEach((product, index) => {
            const $item = this.createProductItem(product, index);
            $list.append($item);
        });
    }

    createProductItem(product, index) {
        const $item = $(`
            <div class="wk-search-result-item" data-index="${index}">
                <a href="${product.url}" class="wk-search-result-link">
                    <div class="wk-search-result-image">
                        <img src="${product.image || '/wp-content/plugins/woocommerce/assets/images/placeholder.png'}" 
                             alt="${product.title}" 
                             loading="lazy">
                    </div>
                    <div class="wk-search-result-content">
                        <h3 class="wk-search-result-title">${product.title}</h3>
                        <div class="wk-search-result-meta">
                            ${this.config.showPrices ? `<span class="wk-search-result-price">${this.formatPrice(product.price, product.currency)}</span>` : ''}
                            ${this.config.showRatings && product.rating ? `<span class="wk-search-result-rating">${this.renderRating(product.rating)}</span>` : ''}
                            ${product.brand ? `<span class="wk-search-result-brand">${product.brand}</span>` : ''}
                        </div>
                        ${product.categories ? `<div class="wk-search-result-categories">${product.categories.join(', ')}</div>` : ''}
                        <div class="wk-search-result-actions">
                            <button type="button" class="wk-search-add-to-cart" data-product-id="${product.id}">
                                ${product.in_stock ? wkSearchOverlay.strings.addToCart : wkSearchOverlay.strings.outOfStock}
                            </button>
                        </div>
                    </div>
                </a>
            </div>
        `);
        
        // Add to cart functionality
        $item.find('.wk-search-add-to-cart').on('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.addToCart(product.id);
        });
        
        return $item;
    }

    formatPrice(price, currency = 'USD') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(price);
    }

    renderRating(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        let stars = '';
        
        for (let i = 0; i < fullStars; i++) {
            stars += '<span class="wk-star wk-star-full">★</span>';
        }
        
        if (hasHalfStar) {
            stars += '<span class="wk-star wk-star-half">★</span>';
        }
        
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
        for (let i = 0; i < emptyStars; i++) {
            stars += '<span class="wk-star wk-star-empty">☆</span>';
        }
        
        return `<div class="wk-search-rating">${stars} <span class="wk-rating-value">${rating.toFixed(1)}</span></div>`;
    }

    // Additional utility methods
    clearSearch() {
        $('#wk-search-input').val('');
        this.currentQuery = '';
        $('.wk-search-clear').hide();
        this.showSuggestions();
    }

    closeOverlay() {
        $('#wk-search-overlay').hide();
        $('#wk-search-input').blur();
    }

    showLoading() {
        $('.wk-search-loading').show();
    }

    hideLoading() {
        $('.wk-search-loading').hide();
    }

    showError() {
        $('.wk-search-results').html('<div class="wk-search-error">' + wkSearchOverlay.strings.error + '</div>');
    }

    // Search history management
    loadSearchHistory() {
        try {
            return JSON.parse(localStorage.getItem('wk_search_history') || '[]');
        } catch {
            return [];
        }
    }

    addToSearchHistory(query) {
        if (!query || query.length < 2) return;
        
        // Remove if already exists
        this.searchHistory = this.searchHistory.filter(term => term !== query);
        
        // Add to beginning
        this.searchHistory.unshift(query);
        
        // Keep only last 10
        this.searchHistory = this.searchHistory.slice(0, 10);
        
        try {
            localStorage.setItem('wk_search_history', JSON.stringify(this.searchHistory));
        } catch {
            // Ignore localStorage errors
        }
    }

    // Analytics tracking
    trackSearch(query, results) {
        if (typeof wkTrack === 'function') {
            wkTrack('search', {
                query: query,
                result_count: results.products?.total || 0,
                has_results: (results.products?.results?.length || 0) > 0
            });
        }
    }

    // Utility methods
    getCacheKey(query, filters, sorting, page) {
        return `${query}_${JSON.stringify(filters)}_${sorting}_${page}`;
    }

    async getPopularSearches() {
        return [];
    }

    async getSearchSuggestions() {
        return [];
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (window.wkSearchConfig) {
        window.wkSearchOverlay = new WKSearchOverlay(window.wkSearchConfig);
    }
});
