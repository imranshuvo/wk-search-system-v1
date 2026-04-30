<?php
/**
 * Settings - Wrapper around wk_fast_search_get_all_settings()
 * Legacy compatibility layer for old Plugin/Admin classes
 */

namespace WKSearchSystem;

class Settings {
    const OPTION_NAME = 'wk_search_system_options';
    
    public function getOptions() {
        return wk_fast_search_get_all_settings();
    }

    public function getOption($key, $default = null) {
        $options = $this->getOptions();
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function updateOption($key, $value) {
        update_option('wk_fast_search_' . $key, $value);
        return true;
    }

    public function registerSettings() {
        // Settings registration handled in woo-fast-search.php
        // This method kept for compatibility only
    }
}
