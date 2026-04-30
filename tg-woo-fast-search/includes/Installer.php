<?php

namespace WKSearchSystem;

class Installer {
    public static function activate() {
        // Minimalistic activation: do not create tables or schedule anything
        // Safety-first: just set defaults if missing, and flush rewrites
        self::setDefaultOptions();
        // Ensure uploads/wk-search exists so logging and feeds have a place to write
        $uploads = wp_upload_dir();
        if (!empty($uploads['basedir'])) {
            $base = trailingslashit($uploads['basedir']) . 'wk-search';
            if (!file_exists($base)) {
                wp_mkdir_p($base);
            }
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Clear scheduled events and flush
        self::clearCronEvents();
        flush_rewrite_rules();
    }

    public static function uninstall() {
        // Remove database tables
        self::dropTables();
        
        // Remove options
        delete_option(Settings::OPTION_NAME);
        
        // Clear scheduled events
        self::clearCronEvents();
    }

    private static function createTables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Create feed queue table
        $table_name = $wpdb->prefix . 'wk_search_feed_queue';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            action varchar(20) NOT NULL DEFAULT 'update',
            data longtext,
            processed tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            PRIMARY KEY (id),
            KEY idx_product (product_id),
            KEY idx_processed (processed),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // Disabled: no table creation on activation to avoid heavy work
        // require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        // dbDelta($sql);

        // Create tracking events table
        $table_name = $wpdb->prefix . 'wk_search_events';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            user_id varchar(64),
            session_id varchar(64),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_user_id (user_id),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // dbDelta($sql);
    }

    private static function dropTables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'wk_search_feed_queue',
            $wpdb->prefix . 'wk_search_events'
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }

    private static function setDefaultOptions() {
        $settings = new Settings();
        $options = $settings->getOptions();
        
        // Only set defaults if no options exist
        if (empty($options['tenant_id'])) {
            $options['tenant_id'] = 'site_' . get_current_blog_id();
        }
        
        if (empty($options['search_key'])) {
            $options['search_key'] = 'default';
        }

        update_option(Settings::OPTION_NAME, $options);
    }

    private static function scheduleCronEvents() {
        // Deprecated: scheduling is now managed by FeedEmitter with safe delays/guards
    }

    private static function clearCronEvents() {
        wp_clear_scheduled_hook('wk_search_full_feed');
        wp_clear_scheduled_hook('wk_search_process_delta_queue');
    }
}
