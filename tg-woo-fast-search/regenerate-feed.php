<?php
/**
 * Regenerate Feed Script
 * Manually trigger products.json generation with descriptions
 */

// Include WordPress
require_once('../../../wp-load.php');

// Check if we're in admin or can run this
if (!current_user_can('manage_options') && !defined('WP_CLI') && !defined('DOING_CRON')) {
    die('Access denied. Run this as admin.');
}

echo "Starting products.json regeneration...\n";
echo "This will include product descriptions in the feed.\n\n";

try {
    // Get the plugin instance
    $plugin = \WKSearchSystem\Plugin::getInstance();
    $feed_emitter = $plugin->getFeedEmitter();
    
    // Run full feed
    $feed_emitter->runFullFeed();
    
    echo "\n✓ Feed regeneration completed successfully!\n";
    echo "Products.json has been updated with descriptions.\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nNext step: Sync to Laravel API\n";
echo "The feed will be automatically imported on the next scheduled sync.\n";
?>
