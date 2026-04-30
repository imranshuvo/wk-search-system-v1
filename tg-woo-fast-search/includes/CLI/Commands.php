<?php

namespace WKSearchSystem\CLI;

use WP_CLI;
use WP_CLI_Command;
use WKSearchSystem\Plugin;
use WKSearchSystem\ApiClient;

class Commands extends WP_CLI_Command {
    private $api_client;

    public function __construct() {
        $this->api_client = new ApiClient();
    }

    /**
     * Export and push full product feed to hosted service
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force a full feed run even if recent run exists
     *
     * [--dry-run]
     * : Show what would be exported without actually sending
     *
     * ## EXAMPLES
     *
     *     wp wkss feed:full
     *     wp wkss feed:full --force
     *     wp wkss feed:full --dry-run
     *
     * @when after_wp_load
     */
    public function feed_full($args, $assoc_args) {
        $force = isset($assoc_args['force']);
        $dry_run = isset($assoc_args['dry-run']);

        WP_CLI::log('Starting full product feed export...');

        try {
            $feed_emitter = Plugin::getInstance()->getFeedEmitter();
            if ($dry_run) {
                // Estimate counts without materializing products
                $count = (int) wp_count_posts('product')->publish;
                WP_CLI::log('Dry run - would stream ~' . $count . ' products to full.json');
                return;
            }

            WP_CLI::log('Streaming full feed to disk (uploads/wk-search/<tenant>/full.json)...');
            $feed_emitter->runFullFeed();
            WP_CLI::success('Full feed streaming completed');

        } catch (\Exception $e) {
            WP_CLI::error('Full feed failed: ' . $e->getMessage());
        }
    }

    /**
     * Push delta changes to hosted service
     *
     * ## OPTIONS
     *
     * [--batch-size=<size>]
     * : Number of products to process in each batch (default: 100)
     *
     * ## EXAMPLES
     *
     *     wp wkss feed:delta
     *     wp wkss feed:delta --batch-size=50
     *
     * @when after_wp_load
     */
    public function feed_delta($args, $assoc_args) {
        $batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 100;

        WP_CLI::log('Starting delta feed...');

        try {
            $feed_emitter = Plugin::getInstance()->getFeedEmitter();
            $queue_size = $feed_emitter->getDeltaQueueSize();

            if ($queue_size === 0) {
                WP_CLI::log('No delta changes to process');
                return;
            }

            WP_CLI::log("Processing {$queue_size} delta changes...");

            // Process delta queue
            $feed_emitter->processDeltaQueue();

            WP_CLI::success('Delta feed completed successfully');

        } catch (\Exception $e) {
            WP_CLI::error('Delta feed failed: ' . $e->getMessage());
        }
    }

    /**
     * Trigger a feed run on the hosted service
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of feed run (full|delta) (default: delta)
     *
     * ## EXAMPLES
     *
     *     wp wkss feed:trigger
     *     wp wkss feed:trigger --type=full
     *
     * @when after_wp_load
     */
    public function feed_trigger($args, $assoc_args) {
        $type = isset($assoc_args['type']) ? $assoc_args['type'] : 'delta';

        if (!in_array($type, ['full', 'delta'])) {
            WP_CLI::error('Invalid type. Must be "full" or "delta"');
        }

        WP_CLI::log("Triggering {$type} feed run...");

        try {
            $result = $this->api_client->triggerFeedRun($type);

            WP_CLI::success("Feed run triggered successfully");
            WP_CLI::log("Run ID: " . $result['run_id']);
            WP_CLI::log("Status: " . $result['status']);

        } catch (\Exception $e) {
            WP_CLI::error('Failed to trigger feed run: ' . $e->getMessage());
        }
    }

    /**
     * Warm search cache with popular queries
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : JSON file containing queries to warm (default: uses top queries from analytics)
     *
     * [--limit=<number>]
     * : Maximum number of queries to warm (default: 50)
     *
     * ## EXAMPLES
     *
     *     wp wkss warm:queries
     *     wp wkss warm:queries --file=queries.json
     *     wp wkss warm:queries --limit=100
     *
     * @when after_wp_load
     */
    public function warm_queries($args, $assoc_args) {
        $file = isset($assoc_args['file']) ? $assoc_args['file'] : null;
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 50;

        WP_CLI::log('Warming search cache...');

        try {
            $queries = [];

            if ($file && file_exists($file)) {
                $queries = json_decode(file_get_contents($file), true);
                if (!is_array($queries)) {
                    WP_CLI::error('Invalid queries file format');
                }
            } else {
                // Get top queries from analytics (mock data for now)
                $queries = $this->getTopQueries($limit);
            }

            if (empty($queries)) {
                WP_CLI::log('No queries to warm');
                return;
            }

            WP_CLI::log('Warming ' . count($queries) . ' queries...');

            $result = $this->api_client->warmCache($queries);

            WP_CLI::success('Cache warming completed');
            WP_CLI::log('Warmed: ' . $result['warmed'] . ' queries');

            if (!empty($result['errors'])) {
                WP_CLI::warning('Errors encountered: ' . count($result['errors']));
                foreach ($result['errors'] as $error) {
                    WP_CLI::log('  - ' . $error);
                }
            }

        } catch (\Exception $e) {
            WP_CLI::error('Cache warming failed: ' . $e->getMessage());
        }
    }

    /**
     * Test connection to hosted service
     *
     * ## EXAMPLES
     *
     *     wp wkss test:connection
     *
     * @when after_wp_load
     */
    public function test_connection($args, $assoc_args) {
        WP_CLI::log('Testing connection to hosted service...');

        try {
            $result = $this->api_client->testConnection();

            WP_CLI::success('Connection successful');
            WP_CLI::log('Status: ' . $result['status']);
            WP_CLI::log('Timestamp: ' . date('Y-m-d H:i:s', $result['timestamp']));

        } catch (\Exception $e) {
            WP_CLI::error('Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get system status and statistics
     *
     * ## EXAMPLES
     *
     *     wp wkss status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        WP_CLI::log('WK Search System Status');
        WP_CLI::log('======================');

        try {
            $settings = Plugin::getInstance()->getSettings();
            $options = $settings->getOptions();

            // Plugin status
            WP_CLI::log('Plugin Status: ' . ($options['enabled'] ? 'Enabled' : 'Disabled'));
            WP_CLI::log('Mode: ' . $options['mode']);
            WP_CLI::log('Edge URL: ' . ($options['edge_url'] ?: 'Not configured'));
            WP_CLI::log('Tenant ID: ' . ($options['tenant_id'] ?: 'Not configured'));
            WP_CLI::log('Search Key: ' . ($options['search_key'] ?: 'Not configured'));

            // Feed status
            $feed_emitter = Plugin::getInstance()->getFeedEmitter();
            $delta_queue_size = $feed_emitter->getDeltaQueueSize();
            WP_CLI::log('Delta Queue Size: ' . $delta_queue_size);

            // Test connection
            try {
                $result = $this->api_client->testConnection();
                WP_CLI::log('Hosted Service: Connected');
            } catch (\Exception $e) {
                WP_CLI::log('Hosted Service: Connection failed - ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            WP_CLI::error('Failed to get status: ' . $e->getMessage());
        }
    }

    /**
     * Clear all caches and reset system
     *
     * ## OPTIONS
     *
     * [--confirm]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp wkss clear:cache
     *     wp wkss clear:cache --confirm
     *
     * @when after_wp_load
     */
    public function clear_cache($args, $assoc_args) {
        $confirm = isset($assoc_args['confirm']);

        if (!$confirm) {
            WP_CLI::confirm('Are you sure you want to clear all caches?');
        }

        WP_CLI::log('Clearing caches...');

        try {
            // Clear WordPress object cache
            wp_cache_flush();

            // Clear delta queue
            $feed_emitter = Plugin::getInstance()->getFeedEmitter();
            $feed_emitter->clearDeltaQueue();

            // Clear any plugin-specific caches
            delete_transient('wk_search_config');
            delete_transient('wk_search_shards');

            WP_CLI::success('Caches cleared successfully');

        } catch (\Exception $e) {
            WP_CLI::error('Failed to clear caches: ' . $e->getMessage());
        }
    }

    /**
     * Export search configuration
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Output file path (default: stdout)
     *
     * ## EXAMPLES
     *
     *     wp wkss config:export
     *     wp wkss config:export --output=config.json
     *
     * @when after_wp_load
     */
    public function config_export($args, $assoc_args) {
        $output = isset($assoc_args['output']) ? $assoc_args['output'] : null;

        WP_CLI::log('Exporting search configuration...');

        try {
            $settings = Plugin::getInstance()->getSettings();
            $options = $settings->getOptions();

            $config = [
                'settings' => $options,
                'synonyms' => json_decode($options['admin_synonyms'] ?? '[]', true),
                'redirects' => json_decode($options['admin_redirects'] ?? '[]', true),
                'pins' => json_decode($options['admin_pins'] ?? '[]', true),
                'bans' => json_decode($options['admin_bans'] ?? '[]', true),
                'strategies' => json_decode($options['admin_strategies'] ?? '[]', true),
                'exported_at' => date('c'),
                'version' => WK_SEARCH_SYSTEM_VERSION
            ];

            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($output) {
                file_put_contents($output, $json);
                WP_CLI::success("Configuration exported to {$output}");
            } else {
                WP_CLI::log($json);
            }

        } catch (\Exception $e) {
            WP_CLI::error('Failed to export configuration: ' . $e->getMessage());
        }
    }

    private function getTopQueries($limit) {
        // This would typically query the hosted service for top search queries
        // For now, return some common queries
        return [
            'running shoes',
            'nike',
            'adidas',
            'sneakers',
            'basketball shoes',
            'workout clothes',
            'gym equipment',
            'sports apparel'
        ];
    }

    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
