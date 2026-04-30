<?php
/**
 * Debug script to verify WK Search configuration
 * Access via: /wp-content/plugins/woo-fast-search/debug-config.php
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>WK Search Configuration Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #0071ce; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f8f8; font-weight: bold; }
        .value { color: #2563eb; }
        .good { color: #059669; font-weight: bold; }
        .bad { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <h1>WK Search System - Configuration Debug</h1>
    
    <div class="section">
        <h2>WordPress Settings (Database)</h2>
        <table>
            <tr>
                <th>Setting</th>
                <th>Value</th>
                <th>Type</th>
            </tr>
            <tr>
                <td>wk_fast_search_search_mode</td>
                <td class="value"><?php echo esc_html(get_option('wk_fast_search_search_mode', 'NOT SET')); ?></td>
                <td><?php echo gettype(get_option('wk_fast_search_search_mode')); ?></td>
            </tr>
            <tr>
                <td>wk_fast_search_allow_mode_toggle</td>
                <td class="value"><?php 
                    $toggle = get_option('wk_fast_search_allow_mode_toggle', 'NOT SET');
                    echo esc_html($toggle);
                    echo ' → ' . ($toggle === '1' ? '<span class="good">TRUE</span>' : '<span class="bad">FALSE</span>');
                ?></td>
                <td><?php echo gettype(get_option('wk_fast_search_allow_mode_toggle')); ?></td>
            </tr>
            <tr>
                <td>wk_fast_search_sidebar_position</td>
                <td class="value"><?php echo esc_html(get_option('wk_fast_search_sidebar_position', 'NOT SET')); ?></td>
                <td><?php echo gettype(get_option('wk_fast_search_sidebar_position')); ?></td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <h2>JavaScript Config (What Frontend Sees)</h2>
        <table>
            <tr>
                <th>Config Key</th>
                <th>Value</th>
                <th>Type</th>
            </tr>
            <tr>
                <td>searchMode</td>
                <td class="value"><?php echo esc_html(get_option('wk_fast_search_search_mode', 'advanced')); ?></td>
                <td>string</td>
            </tr>
            <tr>
                <td>allowModeToggle</td>
                <td class="value"><?php 
                    $bool = get_option('wk_fast_search_allow_mode_toggle', '0') === '1';
                    echo $bool ? '<span class="good">true</span>' : '<span class="bad">false</span>';
                ?></td>
                <td>boolean</td>
            </tr>
            <tr>
                <td>sidebarPosition</td>
                <td class="value"><?php echo esc_html(get_option('wk_fast_search_sidebar_position', 'left')); ?></td>
                <td>string</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <h2>Strings Configuration</h2>
        <table>
            <tr>
                <th>String Key</th>
                <th>Value</th>
            </tr>
            <?php
            $string_keys = ['classicMode', 'advancedMode', 'searchMode', 'switchToClassic', 'switchToAdvanced'];
            foreach ($string_keys as $key) {
                $value = get_option('wk_fast_search_string_' . $key, 'NOT SET');
                echo '<tr>';
                echo '<td>' . esc_html($key) . '</td>';
                echo '<td class="value">' . esc_html($value) . '</td>';
                echo '</tr>';
            }
            ?>
        </table>
    </div>
    
    <div class="section">
        <h2>Action Items</h2>
        <ol>
            <li>If settings show "NOT SET", go to WordPress Admin → FAST Search → General and save your settings</li>
            <li>Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)</li>
            <li>Clear WordPress object cache if using any caching plugin</li>
            <li>Check browser console for JavaScript errors</li>
            <li>Inspect Network tab to see if JS/CSS files are being loaded with correct version</li>
        </ol>
    </div>
    
    <div class="section">
        <h2>Browser Cache Buster</h2>
        <p>The plugin assets should have version query parameters. Check if these files load without errors:</p>
        <ul>
            <li><a href="<?php echo plugins_url('assets/js/search-overlay.js', __FILE__) . '?ver=' . time(); ?>" target="_blank">search-overlay.js</a></li>
            <li><a href="<?php echo plugins_url('assets/css/search-overlay.css', __FILE__) . '?ver=' . time(); ?>" target="_blank">search-overlay.css</a></li>
        </ul>
    </div>
</body>
</html>
