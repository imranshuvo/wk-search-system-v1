<?php
/**
 * Admin Config Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$config = $this->getConfigData($type);
?>

<div class="wrap">
    <h1><?php echo esc_html($title); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('wk_search_config_' . $type); ?>
        
        <div class="wk-search-config-editor">
            <div class="wk-search-config-help">
                <h3><?php _e('Configuration Format', 'woo-fast-search'); ?></h3>
                <p><?php echo $this->getConfigHelp($type); ?></p>
            </div>
            
            <div class="wk-search-config-json">
                <label for="config_data"><?php _e('Configuration (JSON)', 'woo-fast-search'); ?></label>
                <textarea id="config_data" name="config_data" rows="20" cols="80" class="large-text code"><?php echo esc_textarea(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                <p class="description"><?php _e('Enter your configuration in JSON format', 'woo-fast-search'); ?></p>
            </div>
            
            <div class="wk-search-config-actions">
                <button type="button" id="validate-json" class="button"><?php _e('Validate JSON', 'woo-fast-search'); ?></button>
                <button type="button" id="reset-config" class="button"><?php _e('Reset to Default', 'woo-fast-search'); ?></button>
            </div>
        </div>
        
        <?php submit_button(); ?>
    </form>
</div>

<?php if ($type === 'synonyms'): ?>
<div class="wrap">
  <hr/>
  <h2><?php _e('Synonym Suggestions', 'woo-fast-search'); ?></h2>
  <?php
    try {
      $client = new \WKSearchSystem\ApiClient();
      $resp = $client->fetchSuggestions();
      $rows = $resp['rows'] ?? [];
    } catch (\Exception $e) { $rows = []; }
  ?>
  <table class="widefat fixed striped">
    <thead><tr><th>From</th><th>To</th><th>Score</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo esc_html($r['from_term'] ?? ''); ?></td>
        <td><?php echo esc_html($r['to_term'] ?? ''); ?></td>
        <td><?php echo esc_html($r['score'] ?? ''); ?></td>
        <td>
          <form method="post" style="display:inline">
            <?php wp_nonce_field('wk_search_config_' . $type); ?>
            <input type="hidden" name="wk_action" value="approve_suggestion" />
            <input type="hidden" name="suggestion_id" value="<?php echo intval($r['id'] ?? 0); ?>" />
            <button class="button">Approve</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Reject?')">
            <?php wp_nonce_field('wk_search_config_' . $type); ?>
            <input type="hidden" name="wk_action" value="reject_suggestion" />
            <input type="hidden" name="suggestion_id" value="<?php echo intval($r['id'] ?? 0); ?>" />
            <button class="button">Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Validate JSON
    $('#validate-json').click(function() {
        var configText = $('#config_data').val();
        try {
            JSON.parse(configText);
            alert('<?php _e('JSON is valid!', 'woo-fast-search'); ?>');
        } catch (e) {
            alert('<?php _e('Invalid JSON: ', 'woo-fast-search'); ?>' + e.message);
        }
    });
    
    // Reset to default
    $('#reset-config').click(function() {
        if (confirm('<?php _e('Are you sure you want to reset to default configuration?', 'woo-fast-search'); ?>')) {
            var defaultConfig = <?php echo json_encode($this->getDefaultConfig($type)); ?>;
            $('#config_data').val(JSON.stringify(defaultConfig, null, 2));
        }
    });
});
</script>

<style>
.wk-search-config-editor {
    max-width: 100%;
}

.wk-search-config-help {
    background: #f9f9f9;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.wk-search-config-help h3 {
    margin-top: 0;
}

.wk-search-config-json textarea {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.4;
}

.wk-search-config-actions {
    margin: 15px 0;
}

.wk-search-config-actions .button {
    margin-right: 10px;
}
</style>
