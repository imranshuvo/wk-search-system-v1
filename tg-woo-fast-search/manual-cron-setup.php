<?php
/**
 * Manual Cron Setup Script
 * Run this once to schedule the cron jobs without deactivating the plugin
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if we're in admin or can run this
if (!current_user_can('manage_options') && !defined('WP_CLI') && !defined('DOING_CRON')) {
    die('Access denied. Run this as admin, via WP-CLI, or during cron.');
}

echo "Setting up WK Search cron jobs...\n";

// Clear any existing schedules first
wp_clear_scheduled_hook('wk_fast_search_products_json');
wp_clear_scheduled_hook('wk_fast_search_popular_searches');

// Add custom cron schedules
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['wk_sixhourly'])) {
        $schedules['wk_sixhourly'] = [ 'interval' => 6*HOUR_IN_SECONDS, 'display' => 'WK Every 6 Hours' ];
    }
    if (!isset($schedules['wk_fortyfive_minutes'])) {
        $schedules['wk_fortyfive_minutes'] = [ 'interval' => 45*MINUTE_IN_SECONDS, 'display' => 'WK Every 45 Minutes' ];
    }
    return $schedules;
});

// Schedule products.json every 6 hours (starting in 5 minutes)
if (!wp_next_scheduled('wk_fast_search_products_json')) {
    $scheduled = wp_schedule_event(time() + 5*MINUTE_IN_SECONDS, 'wk_sixhourly', 'wk_fast_search_products_json');
    if ($scheduled) {
        echo "✓ Products JSON scheduled every 6 hours (starting in 5 minutes)\n";
    } else {
        echo "✗ Failed to schedule products JSON\n";
    }
} else {
    echo "✓ Products JSON already scheduled\n";
}

// Schedule popular_searches.json every 45 minutes (starting in 5 minutes)
if (!wp_next_scheduled('wk_fast_search_popular_searches')) {
    $scheduled = wp_schedule_event(time() + 5*MINUTE_IN_SECONDS, 'wk_fortyfive_minutes', 'wk_fast_search_popular_searches');
    if ($scheduled) {
        echo "✓ Popular searches scheduled every 45 minutes (starting in 5 minutes)\n";
    } else {
        echo "✗ Failed to schedule popular searches\n";
    }
} else {
    echo "✓ Popular searches already scheduled\n";
}

// Show next scheduled times
$next_products = wp_next_scheduled('wk_fast_search_products_json');
$next_popular = wp_next_scheduled('wk_fast_search_popular_searches');

echo "\nNext scheduled runs:\n";
echo "Products JSON: " . ($next_products ? date('Y-m-d H:i:s', $next_products) : 'Not scheduled') . "\n";
echo "Popular searches: " . ($next_popular ? date('Y-m-d H:i:s', $next_popular) : 'Not scheduled') . "\n";

echo "\nDone! You can delete this file now.\n";
?>
