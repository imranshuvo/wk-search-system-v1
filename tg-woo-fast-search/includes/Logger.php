<?php

namespace WKSearchSystem;

class Logger {
    private static $bootstrapped = false;

    private static function ensureDir($dir) {
        if (!file_exists($dir)) {
            // Suppress PHP warnings but check return value to avoid fatals
            if (function_exists('wp_mkdir_p')) {
                @wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }
        return is_dir($dir) && is_writable($dir);
    }

    private static function getLogPath() {
        // Preferred: WordPress uploads dir
        $paths = [];
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && !empty($uploads['basedir'])) {
                $paths[] = trailingslashit($uploads['basedir']) . 'wk-search';
            }
        }
        // Fallback: wp-content/uploads
        if (defined('WP_CONTENT_DIR')) {
            $paths[] = rtrim(WP_CONTENT_DIR, '/').'/uploads/wk-search';
        }
        // Last resort: system temp dir
        $paths[] = rtrim(function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp', '/').'/wk-search';

        foreach ($paths as $dir) {
            if (self::ensureDir($dir)) {
                return $dir . '/wk.log';
            }
        }

        // If all else fails, return php://stderr to still emit something
        return 'php://stderr';
    }

    private static function writeLine($line) {
        $path = self::getLogPath();
        $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            // As a final fallback, use PHP error_log
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log(rtrim($line));
        }
    }

    public static function log($level, $message) {
        $line = '['.date('Y-m-d H:i:s').'] ['.strtoupper($level).'] ' . $message . "\n";
        self::writeLine($line);
    }

    public static function info($message) { self::log('info', $message); }
    public static function warning($message) { self::log('warning', $message); }
    public static function error($message) { self::log('error', $message); }
    public static function debug($message) { self::log('debug', $message); }

    public static function bootstrap() {
        if (self::$bootstrapped) { return; }
        self::$bootstrapped = true;
        // Pre-flight write to ensure directory exists and catch permission issues early
        self::debug('Logger bootstrap');
        // Capture fatal errors that might occur before normal logging
        register_shutdown_function(function () {
            $err = function_exists('error_get_last') ? error_get_last() : null;
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::error('Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            }
        });
    }
}


