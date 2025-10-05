<?php
/**
 * CribOps WP Kit Authentication Handler
 *
 * Handles both bearer token and username/password authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class CWPKAuth {

    /**
     * Check if bearer token is available in environment
     *
     * @return string|false Bearer token if available, false otherwise
     */
    public static function get_env_bearer_token() {
        // Check multiple possible environment variable names
        $possible_vars = [
            'CWPK_BEARER_TOKEN',
            'CRIBOPS_BEARER_TOKEN',
            'WP_KIT_BEARER_TOKEN'
        ];

        foreach ($possible_vars as $var) {
            $token = getenv($var);
            if (!empty($token)) {
                return $token;
            }
        }

        // Check if .env file exists in plugin directory
        $env_file = plugin_dir_path(dirname(__FILE__)) . '.env';
        if (file_exists($env_file)) {
            $env_contents = parse_ini_file($env_file, false, INI_SCANNER_RAW);
            foreach ($possible_vars as $var) {
                if (isset($env_contents[$var]) && !empty($env_contents[$var])) {
                    return $env_contents[$var];
                }
            }
        }

        return false;
    }

    /**
     * Authenticate using bearer token
     *
     * @param string $token Bearer token
     * @return array|WP_Error User data on success, WP_Error on failure
     */
    public static function authenticate_with_token($token, $site_url = null) {
        if (empty($site_url)) {
            $site_url = site_url();
        }

        $response = wp_remote_post(
            CWPKConfig::get_wp_kit_api_endpoint() . '/authenticate-token',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'site_url' => $site_url
                )),
                'timeout' => 30
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code === 200 && !empty($data)) {
            return $data;
        }

        return new WP_Error('auth_failed', 'Token authentication failed');
    }

    /**
     * Authenticate using username and password
     *
     * @param string $username Email/username
     * @param string $password Password
     * @param string $site_url Site URL
     * @return array|WP_Error User data on success, WP_Error on failure
     */
    public static function authenticate_with_credentials($username, $password, $site_url = null) {
        if (empty($site_url)) {
            $site_url = site_url();
        }

        $response = wp_remote_post(
            CWPKConfig::get_wplaunchify_api_endpoint() . '/user-meta',
            array(
                'body' => array(
                    'email' => $username,
                    'password' => $password,
                    'site_url' => $site_url
                ),
                'timeout' => 30
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code === 200 && !empty($data) && !empty($data['can_access_launchkit'])) {
            return $data;
        }

        return new WP_Error('auth_failed', 'Invalid credentials or no access');
    }

    /**
     * Check if currently authenticated
     *
     * @return bool True if authenticated, false otherwise
     */
    public static function is_authenticated() {
        // First check for bearer token authentication
        $token = self::get_env_bearer_token();
        if ($token) {
            // Token auth doesn't expire during session
            $token_auth = get_transient('cwpk_token_auth');
            if ($token_auth === false) {
                // Validate token and cache result
                $result = self::authenticate_with_token($token);
                if (!is_wp_error($result)) {
                    set_transient('cwpk_token_auth', true, HOUR_IN_SECONDS);
                    set_transient('lk_user_data', $result, HOUR_IN_SECONDS);
                    set_transient('lk_logged_in', true, HOUR_IN_SECONDS);
                    return true;
                }
            } else {
                return true;
            }
        }

        // Fall back to session-based authentication
        return get_transient('lk_logged_in') ? true : false;
    }

    /**
     * Get authentication type
     *
     * @return string 'token', 'credentials', or 'none'
     */
    public static function get_auth_type() {
        $token = self::get_env_bearer_token();
        if ($token) {
            $token_auth = get_transient('cwpk_token_auth');
            if ($token_auth !== false) {
                return 'token';
            }
            // Try to authenticate with token
            $result = self::authenticate_with_token($token);
            if (!is_wp_error($result)) {
                set_transient('cwpk_token_auth', true, HOUR_IN_SECONDS);
                set_transient('lk_user_data', $result, HOUR_IN_SECONDS);
                set_transient('lk_logged_in', true, HOUR_IN_SECONDS);
                return 'token';
            }
        }

        if (get_transient('lk_logged_in')) {
            return 'credentials';
        }

        return 'none';
    }

    /**
     * Clear authentication cache
     */
    public static function clear_auth_cache() {
        delete_transient('cwpk_token_auth');
        delete_transient('lk_logged_in');
        delete_transient('lk_user_data');
        delete_transient('lk_username');
    }

    /**
     * Perform authentication check on admin init
     */
    public static function check_authentication() {
        // Only check on our plugin pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'cwpk') !== 0) {
            return;
        }

        // If bearer token is available, use it
        $token = self::get_env_bearer_token();
        if ($token) {
            $token_auth = get_transient('cwpk_token_auth');
            if ($token_auth === false) {
                $result = self::authenticate_with_token($token);
                if (!is_wp_error($result)) {
                    set_transient('cwpk_token_auth', true, HOUR_IN_SECONDS);
                    set_transient('lk_user_data', $result, HOUR_IN_SECONDS);
                    set_transient('lk_logged_in', true, HOUR_IN_SECONDS);
                }
            }
        }
    }
}