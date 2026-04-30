<?php
/**
 * Admin Settings Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$options = $this->settings->getOptions();
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <!-- DEBUG: Template loaded on <?php echo date('Y-m-d H:i:s'); ?> -->
    
    <form method="post" action="">
        <?php wp_nonce_field('wk_search_settings'); ?>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="api_key"><?php _e('API Key', 'woo-fast-search'); ?></label>
                    </th>
                    <td>
                        <input type="password" id="api_key" name="wk_search_system_options[api_key]" value="<?php echo esc_attr($options['api_key']); ?>" class="regular-text" />
                        <p class="description"><?php _e('API key for authenticating with the hosted service', 'woo-fast-search'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="tenant_id"><?php _e('Tenant ID', 'woo-fast-search'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="tenant_id" name="wk_search_system_options[tenant_id]" value="<?php echo esc_attr($options['tenant_id']); ?>" class="regular-text" />
                        <p class="description"><?php _e('Unique identifier for your site/tenant', 'woo-fast-search'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="debug_mode"><?php _e('Debug Mode', 'woo-fast-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="debug_mode" name="wk_search_system_options[debug_mode]" value="1" <?php checked(1, $options['debug_mode']); ?> />
                        <p class="description"><?php _e('Enable console logging for debugging (disable in production)', 'woo-fast-search'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div class="wk-search-actions">
            <button type="button" id="generate-full-feed" class="button button-primary"><?php _e('Generate Full Feed File', 'woo-fast-search'); ?></button>
        </div>
        
        <?php submit_button(); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Generate full feed file (writes products.json locally)
    $('#generate-full-feed').click(function() {
        var button = $(this);
        button.prop('disabled', true).text('<?php _e('Generating...', 'woo-fast-search'); ?>');
        $.post(ajaxurl, {
            action: 'wk_search_admin_action',
            action_type: 'generate_full_feed',
            nonce: wkSearchAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert('<?php _e('Full feed generated!', 'woo-fast-search'); ?>');
            } else {
                alert('<?php _e('Failed to generate feed: ', 'woo-fast-search'); ?>' + response.data.message);
            }
        }).always(function() {
            button.prop('disabled', false).text('<?php _e('Generate Full Feed File', 'woo-fast-search'); ?>');
        });
    });
});
</script>
