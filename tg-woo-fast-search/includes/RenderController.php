<?php

namespace WKSearchSystem;

class RenderController {
    public function registerAjax() {
        add_action('wp_ajax_wkss_render_products', [$this, 'handleRenderProducts']);
        add_action('wp_ajax_nopriv_wkss_render_products', [$this, 'handleRenderProducts']);
    }

    public function handleRenderProducts() {
        // Expect ids as JSON array via POST
        $ids = isset($_POST['ids']) ? json_decode(stripslashes($_POST['ids']), true) : [];
        if (!is_array($ids)) {
            $ids = [];
        }

        // Basic sanitization
        $ids = array_values(array_filter(array_map('intval', $ids), function($id){ return $id > 0; }));

        $htmlMap = [];

        if (!function_exists('wc_get_product')) {
            wp_send_json_error(['message' => 'WooCommerce not active'], 400);
        }

        // Prepare WooCommerce loop context
        global $woocommerce_loop;
        $woocommerce_loop = is_array($woocommerce_loop) ? $woocommerce_loop : [];
        if (empty($woocommerce_loop['columns'])) {
            $woocommerce_loop['columns'] = 4;
        }

        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) { continue; }

            global $post;
            $post = get_post($product_id);
            if (!$post) { continue; }
            setup_postdata($post);

            ob_start();
            // content-product.php typically outputs an <li class="product"> wrapper
            wc_get_template('content-product.php');
            $html = ob_get_clean();

            $htmlMap[$product_id] = $html;
        }

        // Reset postdata
        wp_reset_postdata();

        wp_send_json_success(['html' => $htmlMap]);
    }
}


