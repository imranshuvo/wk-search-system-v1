/**
 * WK Search System - Search Worker
 * Background search processing for performance optimization
 */

class WKSearchWorker {
    constructor() {
        this.isRunning = false;
        this.debugMode = false;
        this.searchQueue = [];;
        this.cache = new Map();
        this.maxCacheSize = 100;
        this.searchTimeout = 5000; // 5 seconds
    }

    // Debug logging helper - only logs when debug mode is enabled
    debug(...args) {
        if (this.debugMode) {
            console.log('[WK Search Worker Debug]', ...args);
        }
    }

    /**
     * Initialize the search worker
     */
    init() {
        if (this.isRunning) {
            return;
        }

        this.isRunning = true;
        this.startProcessing();
        this.debug('Search Worker initialized');
    }

    /**
     * Add search request to queue
     */
    queueSearch(query, options = {}) {
        const searchId = this.generateSearchId();
        const searchRequest = {
            id: searchId,
            query: query,
            options: options,
            timestamp: Date.now(),
            priority: options.priority || 'normal'
        };

        // Add to queue based on priority
        if (searchRequest.priority === 'high') {
            this.searchQueue.unshift(searchRequest);
        } else {
            this.searchQueue.push(searchRequest);
        }

        return searchId;
    }

    /**
     * Process search queue
     */
    async startProcessing() {
        while (this.isRunning) {
            if (this.searchQueue.length > 0) {
                const searchRequest = this.searchQueue.shift();
                await this.processSearch(searchRequest);
            } else {
                // Wait for new requests
                await this.sleep(100);
            }
        }
    }

    /**
     * Process individual search request
     */
    async processSearch(searchRequest) {
        try {
            const { query, options } = searchRequest;
            
            // Check cache first
            const cacheKey = this.getCacheKey(query, options);
            if (this.cache.has(cacheKey)) {
                this.notifySearchComplete(searchRequest.id, this.cache.get(cacheKey));
                return;
            }

            // Perform search
            const results = await this.performSearch(query, options);
            
            // Cache results
            this.cacheResults(cacheKey, results);
            
            // Notify completion
            this.notifySearchComplete(searchRequest.id, results);

        } catch (error) {
            console.error('Search worker error:', error);
            this.notifySearchError(searchRequest.id, error);
        }
    }

    /**
     * Perform actual search
     */
    async performSearch(query, options) {
        const searchData = {
            action: 'wk_search_instant',
            nonce: wkSearchOverlay.nonce,
            query: query,
            filters: options.filters || {},
            sorting: options.sorting || 'relevance',
            page: options.page || 1,
            per_page: options.per_page || 8
        };

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.searchTimeout);

        try {
            const response = await fetch(wkSearchOverlay.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(searchData),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.data || 'Search failed');
            }

            return result.data;

        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }

    /**
     * Cache search results
     */
    cacheResults(key, results) {
        // Implement LRU cache
        if (this.cache.size >= this.maxCacheSize) {
            const firstKey = this.cache.keys().next().value;
            this.cache.delete(firstKey);
        }

        this.cache.set(key, {
            ...results,
            cached_at: Date.now()
        });
    }

    /**
     * Get cache key for search
     */
    getCacheKey(query, options) {
        return `${query}_${JSON.stringify(options)}`;
    }

    /**
     * Generate unique search ID
     */
    generateSearchId() {
        return `search_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    /**
     * Notify search completion
     */
    notifySearchComplete(searchId, results) {
        // Dispatch custom event
        const event = new CustomEvent('wkSearchComplete', {
            detail: {
                searchId: searchId,
                results: results
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * Notify search error
     */
    notifySearchError(searchId, error) {
        const event = new CustomEvent('wkSearchError', {
            detail: {
                searchId: searchId,
                error: error.message
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * Stop the search worker
     */
    stop() {
        this.isRunning = false;
        this.searchQueue = [];
        this.debug('Search Worker stopped');
    }

    /**
     * Clear cache
     */
    clearCache() {
        this.cache.clear();
        this.debug('Search Worker cache cleared');
    }

    /**
     * Get cache statistics
     */
    getCacheStats() {
        return {
            size: this.cache.size,
            maxSize: this.maxCacheSize,
            hitRate: this.calculateHitRate()
        };
    }

    /**
     * Calculate cache hit rate
     */
    calculateHitRate() {
        // This would need to track hits/misses in a real implementation
        return 0;
    }

    /**
     * Sleep utility
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Initialize search worker
window.wkSearchWorker = new WKSearchWorker();
window.wkSearchWorker.init();