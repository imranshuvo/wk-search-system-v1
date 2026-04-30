<?php

namespace WKSearchSystem;

class ApiClient {
    private $settings;
    private $edge_url;
    private $api_key;
    private $tenant_id;

    public function __construct() {
        $this->settings = Plugin::getInstance()->getSettings();
        if (defined('WK_SEARCH_EDGE_URL') && WK_SEARCH_EDGE_URL) {
            $this->edge_url = rtrim(WK_SEARCH_EDGE_URL, '/');
        } else {
            $this->edge_url = rtrim($this->settings->getOption('edge_url'), '/');
        }
        $this->api_key = $this->settings->getOption('api_key');
        $this->tenant_id = $this->settings->getOption('tenant_id');
    }

    public function testConnection() {
        $response = $this->makeRequest('GET', '/healthz');
        return $response;
    }

    public function pushConfig($type, $config_data) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'type' => $type,
            'config' => $config_data
        ];

        return $this->makeRequest('POST', '/admin/config', $data);
    }

    public function triggerFeedRun($type = 'delta') {
        $data = [
            'tenant_id' => $this->tenant_id,
            'type' => $type,
            'full' => ($type === 'full')
        ];

        return $this->makeRequest('POST', '/productfeedruns', $data);
    }

    public function warmCache($queries) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'queries' => $queries
        ];

        return $this->makeRequest('POST', '/admin/warm-cache', $data);
    }

    public function ingestProducts($products, $is_delta = false, $fire_and_forget = false) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'products' => $products,
            'is_delta' => $is_delta,
            'timestamp' => time()
        ];

        return $this->makeRequest('POST', '/api/ingest/products', $data, true, $fire_and_forget);
    }

    public function ingestOrders($orders) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'orders' => $orders,
            'timestamp' => time()
        ];

        return $this->makeRequest('POST', '/api/ingest/orders', $data, true);
    }

    public function trackEvents($events) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'events' => $events,
            'timestamp' => time()
        ];

        return $this->makeRequest('POST', '/api/track', $data, true);
    }

    /**
     * Search products using the hosted service
     */
    public function search($search_data) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'query' => $search_data['query'],
            'options' => $search_data['options'] ?? []
        ];

        return $this->makeRequest('POST', '/api/serve/search', $data);
    }

    /**
     * Get recommendations from the hosted service
     */
    public function getRecommendations($strategy, $options = []) {
        $data = [
            'tenant_id' => $this->tenant_id,
            'strategy' => $strategy,
            'options' => $options
        ];

        return $this->makeRequest('POST', '/api/serve/reco', $data);
    }

    

    // Analytics for WP-admin
    public function fetchTopQueries($start = null, $end = null) {
        $query = [];
        if ($start && $end) { $query['start']=$start; $query['end']=$end; }
        return $this->makeRequest('GET', '/api/admin/analytics/top' . $this->qs($query));
    }
    public function fetchZeroResults($start = null, $end = null) {
        $query = [];
        if ($start && $end) { $query['start']=$start; $query['end']=$end; }
        return $this->makeRequest('GET', '/api/admin/analytics/zero' . $this->qs($query));
    }
    public function fetchPerformance($start = null, $end = null) {
        $query = [];
        if ($start && $end) { $query['start']=$start; $query['end']=$end; }
        return $this->makeRequest('GET', '/api/admin/analytics/perf' . $this->qs($query));
    }

    private function qs($params){
        if (empty($params)) return '';
        return '?' . http_build_query($params);
    }

    // Suggestions API for WP-admin
    public function fetchSuggestions() {
        return $this->makeRequest('GET', '/api/admin/suggestions');
    }
    public function approveSuggestion($id) {
        return $this->makeRequest('PUT', '/api/admin/suggestions/' . intval($id) . '/approve');
    }
    public function rejectSuggestion($id) {
        return $this->makeRequest('PUT', '/api/admin/suggestions/' . intval($id) . '/reject');
    }

    private function makeRequest($method, $endpoint, $data = null, $use_hmac = false, $fire_and_forget = false) {
        if (empty($this->edge_url) || empty($this->api_key) || empty($this->tenant_id)) {
            throw new \Exception('API configuration incomplete');
        }

        $url = $this->edge_url . $endpoint;
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'X-Tenant-Id' => $this->tenant_id,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WK-Search-System-WP/' . WK_SEARCH_SYSTEM_VERSION
        ];

        if ($use_hmac) {
            $nonce = wp_generate_password(16, false);
            $timestamp = time();
            $payload = $data ? json_encode($data) : '';
            $signature = $this->generateHmac($method, $endpoint, $payload, $nonce, $timestamp);
            
            $headers['X-Nonce'] = $nonce;
            $headers['X-Timestamp'] = $timestamp;
            $headers['X-Signature'] = $signature;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ];

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }

        if ($fire_and_forget) {
            // Asynchronous, no-blocking: short timeout and ignore result
            $args['timeout'] = 0.01;
            $args['blocking'] = false;
            wp_remote_request($url, $args);
            return ['status' => 'queued'];
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('Request failed: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code >= 400) {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']) ? $error_data['error'] : 'HTTP ' . $status_code;
            throw new \Exception($error_message);
        }

        return json_decode($body, true) ?: [];
    }

    private function generateHmac($method, $endpoint, $payload, $nonce, $timestamp) {
        $message = $method . "\n" . $endpoint . "\n" . $payload . "\n" . $nonce . "\n" . $timestamp;
        return hash_hmac('sha256', $message, $this->api_key);
    }

    public function getSearchConfig() {
        $search_key = $this->settings->getOption('search_key');
        if (empty($search_key)) {
            return null;
        }

        try {
            $data = [
                'tenant_id' => $this->tenant_id,
                'key' => $search_key
            ];
            return $this->makeRequest('POST', '/api/admin/search-config', $data);
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Failed to get search config: ' . $e->getMessage());
            return null;
        }
    }

    public function getClientShards() {
        $options = $this->settings->getOptions();
        if (!$options['client_shards_enabled']) {
            return null;
        }

        try {
            $data = [
                'tenant_id' => $this->tenant_id,
                'max_size' => $options['client_shards_max_size'] * 1024 * 1024, // Convert MB to bytes
                'chunk_size' => $options['client_shards_chunk_size'] * 1024 // Convert KB to bytes
            ];
            return $this->makeRequest('POST', '/api/admin/client-shards', $data);
        } catch (\Exception $e) {
            \WKSearchSystem\Logger::error('Failed to get client shards: ' . $e->getMessage());
            return null;
        }
    }
}
