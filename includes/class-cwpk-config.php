<?php
/**
 * CribOps WP Kit Configuration
 *
 * Centralized configuration for API endpoints and environment settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class CWPKConfig {
    /**
     * Get the API base URL
     *
     * @return string The API base URL
     */
    public static function get_api_url() {
        // Check for environment-specific configuration
        if (defined('CWPK_API_URL')) {
            return CWPK_API_URL;
        }

        // Check environment (can be set in wp-config.php)
        if (defined('WP_ENVIRONMENT_TYPE')) {
            switch (WP_ENVIRONMENT_TYPE) {
                case 'local':
                case 'development':
                    return 'https://cribops.cloudbedrock.com';
                case 'staging':
                    return 'https://staging.cribops.com';
                case 'production':
                    return 'https://cribops.com';
            }
        }

        // Default to production
        return 'https://cribops.com';
    }

    /**
     * Get the legacy WPLaunchify compatibility URL
     *
     * @return string
     */
    public static function get_legacy_api_url() {
        return self::get_api_url();
    }

    /**
     * Get the software bundle update path
     *
     * @return string
     */
    public static function get_update_path() {
        return self::get_api_url() . '/wp-content/uploads/software-bundle';
    }

    /**
     * Get the WP Kit API endpoint
     *
     * @return string
     */
    public static function get_wp_kit_api_endpoint() {
        return self::get_api_url() . '/api/wp-kit';
    }

    /**
     * Get the legacy WPLaunchify API endpoint
     *
     * @return string
     */
    public static function get_wplaunchify_api_endpoint() {
        return self::get_api_url() . '/wp-json/wplaunchify/v1';
    }

    /**
     * Get CDN URL for static assets (if different from API)
     *
     * @return string
     */
    public static function get_cdn_url() {
        if (defined('CWPK_CDN_URL')) {
            return CWPK_CDN_URL;
        }

        // Default to API URL
        return self::get_api_url();
    }

    /**
     * Check if we're in development mode
     *
     * @return bool
     */
    public static function is_development() {
        return defined('WP_DEBUG') && WP_DEBUG === true;
    }

    /**
     * Get the API timeout in seconds
     *
     * @return int
     */
    public static function get_api_timeout() {
        return defined('CWPK_API_TIMEOUT') ? CWPK_API_TIMEOUT : 30;
    }
}