<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CribOps WP Kit Manifest-based Plugin Installer
 *
 * Allows downloading individual plugins from a manifest instead of
 * downloading a large bundle file all at once.
 */
class CWPK_Manifest_Installer {

    /**
     * Get plugin manifest from API
     */
    public function get_plugin_manifest() {
        $user_data = get_transient('lk_user_data');

        if (!$user_data || empty($user_data['email'])) {
            return new WP_Error('not_logged_in', 'Please log in to access plugins');
        }

        // Call the API to get plugin manifest
        $api_url = class_exists('CWPKConfig') ? CWPKConfig::get_api_url() : 'https://cribops.com';
        $response = wp_remote_get(
            $api_url . '/api/wp-kit/plugins',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $user_data['email'], // Email is used as API token
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['plugins'])) {
            // Fallback to local manifest if API fails
            return $this->get_local_manifest();
        }

        // Process plugins to ensure consistent structure
        $plugins = array();
        foreach ($data['plugins'] as $plugin) {
            $plugins[] = $this->normalize_plugin_data($plugin);
        }

        return $plugins;
    }

    /**
     * Normalize plugin data structure
     */
    private function normalize_plugin_data($plugin) {
        $slug = isset($plugin['slug']) ? $plugin['slug'] : '';

        // Check if file is already downloaded
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit';
        $file_path = $target_dir . '/' . $slug . '.zip';
        $is_downloaded = file_exists($file_path);

        // Get plugin status
        $status = $this->get_plugin_status($slug);

        // If not installed but file exists, mark as downloaded
        if ($status === 'not_installed' && $is_downloaded) {
            $status = 'downloaded';
        }

        return array(
            'slug' => $slug,
            'name' => isset($plugin['name']) ? $plugin['name'] : '',
            'author' => isset($plugin['author']) ? $plugin['author'] : '',
            'description' => isset($plugin['description']) ? $plugin['description'] : '',
            'type' => isset($plugin['type']) ? $plugin['type'] : 'plugin',
            'version' => isset($plugin['version']) ? $plugin['version'] : '',
            'file_size' => isset($plugin['file_size']) ? $this->format_file_size($plugin['file_size']) : '',
            'php_required' => isset($plugin['requires_php']) ? $plugin['requires_php'] : (isset($plugin['php_version']) ? $plugin['php_version'] : (isset($plugin['php_required']) ? $plugin['php_required'] : '')),
            'tested_up_to' => isset($plugin['tested_up_to']) ? $plugin['tested_up_to'] : (isset($plugin['tested']) ? $plugin['tested'] : (isset($plugin['wp_version']) ? $plugin['wp_version'] : '')),
            's3_url' => isset($plugin['s3_url']) ? $plugin['s3_url'] : '',
            'cdn_url' => isset($plugin['cdn_url']) ? $plugin['cdn_url'] : '',
            'download_url' => isset($plugin['download_url']) ? $plugin['download_url'] : '',
            'status' => $status,
            'local' => $is_downloaded
        );
    }

    /**
     * Format file size for display
     */
    private function format_file_size($bytes) {
        if (!$bytes) return '';

        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get plugin installation status
     */
    private function get_plugin_status($slug) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Clear the plugin cache to ensure we get fresh data
        wp_cache_delete('plugins', 'plugins');

        $installed_plugins = get_plugins();

        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            // Get the plugin directory name
            $plugin_slug = dirname($plugin_file);

            // Handle single-file plugins (where dirname returns '.')
            if ($plugin_slug === '.') {
                // For single-file plugins, use the filename without extension as slug
                $plugin_slug = basename($plugin_file, '.php');
            }

            // Check various possible matches
            if ($plugin_slug === $slug ||
                strpos($plugin_file, $slug . '/') === 0 ||
                strpos($plugin_file, $slug . '.php') === 0) {

                if (is_plugin_active($plugin_file)) {
                    return 'active';
                } else {
                    return 'inactive';
                }
            }
        }

        return 'not_installed';
    }

    /**
     * Get local manifest from extracted bundle (fallback)
     */
    private function get_local_manifest() {
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit';

        $plugins = array();

        if (file_exists($target_dir)) {
            $files = glob($target_dir . '/*.zip');

            foreach ($files as $file) {
                $plugin_name = basename($file, '.zip');
                $plugins[] = array(
                    'slug' => sanitize_title($plugin_name),
                    'name' => $plugin_name,
                    'author' => '',
                    'description' => '',
                    'type' => 'plugin',
                    'version' => '',
                    'file' => basename($file),
                    'file_size' => $this->format_file_size(filesize($file)),
                    'php_required' => '',
                    'tested_up_to' => '',
                    's3_url' => '',
                    'cdn_url' => '',
                    'download_url' => trailingslashit($upload_dir['baseurl']) . 'cribops-wp-kit/' . basename($file),
                    'status' => $this->get_plugin_status(sanitize_title($plugin_name)),
                    'local' => true
                );
            }
        }

        return $plugins;
    }

    /**
     * Download individual plugin
     */
    public function download_plugin($plugin_data) {
        $user_data = get_transient('lk_user_data');

        if (!$user_data) {
            return new WP_Error('not_logged_in', 'Please log in to download plugins');
        }

        // Determine download URL - prefer S3 over CDN
        $download_url = '';

        if (!empty($plugin_data['s3_url'])) {
            $download_url = $plugin_data['s3_url'];
        } elseif (!empty($plugin_data['cdn_url'])) {
            $download_url = $plugin_data['cdn_url'];
        } elseif (!empty($plugin_data['download_url'])) {
            $download_url = $plugin_data['download_url'];
        }

        // If no direct URL, try API endpoint
        if (empty($download_url) && !empty($plugin_data['slug'])) {
            // Get download URL from API
            $api_url = class_exists('CWPKConfig') ? CWPKConfig::get_api_url() : 'https://cribops.com';
            $response = wp_remote_get(
                $api_url . '/api/wp-kit/plugins/' . $plugin_data['slug'] . '/download',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $user_data['email'] // Email is used as API token
                    ),
                    'timeout' => 60,
                    'redirection' => 0 // Don't follow redirects automatically
                )
            );

            if (is_wp_error($response)) {
                return $response;
            }

            // Check if it's a redirect
            $status = wp_remote_retrieve_response_code($response);

            if ($status == 302 || $status == 301) {
                $headers = wp_remote_retrieve_headers($response);
                $download_url = isset($headers['location']) ? $headers['location'] : '';
            } else {
                // If direct response, save the body to temp file first for validation
                $body = wp_remote_retrieve_body($response);

                if ($body) {
                    // Save to temp file first
                    $tmp_file = wp_tempnam();
                    if (!$tmp_file) {
                        return new WP_Error('temp_file_failed', 'Could not create temporary file');
                    }

                    if (!file_put_contents($tmp_file, $body)) {
                        @unlink($tmp_file);
                        return new WP_Error('temp_write_failed', 'Could not write to temporary file');
                    }

                    error_log('CribOps WP-Kit: API returned direct response for ' . $plugin_data['slug'] . ' (size: ' . strlen($body) . ' bytes)');

                    // Validate the downloaded content
                    $validation_result = $this->validate_zip_file($tmp_file);
                    if (is_wp_error($validation_result)) {
                        error_log('CribOps WP-Kit: Validation failed for API response: ' . $validation_result->get_error_message());
                        @unlink($tmp_file);
                        return $validation_result;
                    }

                    // Validation passed, move to final location
                    $upload_dir = wp_upload_dir();
                    $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit';

                    if (!file_exists($target_dir)) {
                        wp_mkdir_p($target_dir);
                        // Ensure proper permissions and ownership for web server
                        @chmod($target_dir, 0755);
                        @chown($target_dir, 'www-data');
                        @chgrp($target_dir, 'www-data');
                    }

                    $file_path = $target_dir . '/' . $plugin_data['slug'] . '.zip';

                    if (rename($tmp_file, $file_path)) {
                        error_log('CribOps WP-Kit: Successfully saved validated file to: ' . $file_path);
                        return array('success' => true, 'file' => $file_path);
                    } else {
                        @unlink($tmp_file);
                        return new WP_Error('move_failed', 'Failed to move validated file to final location');
                    }
                }

                return new WP_Error('download_failed', 'Failed to download plugin - empty response body');
            }
        }

        if (empty($download_url)) {
            return new WP_Error('no_download_url', 'No download URL available for this plugin');
        }

        return $this->download_from_url($download_url, $plugin_data['slug']);
    }

    /**
     * Download from URL (S3 presigned URL)
     */
    private function download_from_url($url, $plugin_slug) {
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit';

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
            // Ensure proper permissions and ownership for web server
            @chmod($target_dir, 0755);
            @chown($target_dir, 'www-data');
            @chgrp($target_dir, 'www-data');
        }

        $file_path = $target_dir . '/' . $plugin_slug . '.zip';

        // If file already exists, back it up before overwriting
        if (file_exists($file_path)) {
            @unlink($file_path . '.backup');
            @rename($file_path, $file_path . '.backup');
        }

        // Log the download attempt
        error_log('CribOps WP-Kit: Attempting to download from URL: ' . $url);

        // Use WordPress download function with increased timeout
        add_filter('http_request_timeout', array($this, 'extend_timeout'));
        $tmp_file = download_url($url, 300); // 5 minute timeout
        remove_filter('http_request_timeout', array($this, 'extend_timeout'));

        if (is_wp_error($tmp_file)) {
            error_log('CribOps WP-Kit: download_url failed: ' . $tmp_file->get_error_message());
            return $tmp_file;
        }

        error_log('CribOps WP-Kit: Downloaded to temp file: ' . $tmp_file . ' (size: ' . filesize($tmp_file) . ' bytes)');

        // Validate that the downloaded file is actually a ZIP file
        $validation_result = $this->validate_zip_file($tmp_file);
        if (is_wp_error($validation_result)) {
            error_log('CribOps WP-Kit: Validation failed: ' . $validation_result->get_error_message());
            @unlink($tmp_file);
            return $validation_result;
        }

        error_log('CribOps WP-Kit: Validation passed for: ' . $plugin_slug);

        // Inspect the ZIP to determine actual slug
        $actual_slug = $this->get_plugin_slug_from_zip($tmp_file);

        // Check for slug mismatch
        if ($actual_slug && $actual_slug !== $plugin_slug) {
            // Log the mismatch for debugging
            error_log(sprintf(
                'CribOps WP-Kit: Slug mismatch detected! Expected: %s, Actual: %s, URL: %s',
                $plugin_slug,
                $actual_slug,
                $url
            ));

            // Store mismatch info for reporting
            $mismatches = get_option('cwpk_slug_mismatches', array());
            $mismatches[$plugin_slug] = array(
                'expected' => $plugin_slug,
                'actual' => $actual_slug,
                'url' => $url,
                'detected' => current_time('mysql')
            );
            update_option('cwpk_slug_mismatches', $mismatches);

            // Use the actual slug for the filename to ensure proper installation
            $file_path = $target_dir . '/' . $actual_slug . '.zip';
        }

        // Remove existing file if it exists
        if (file_exists($file_path)) {
            @unlink($file_path);
        }

        // Move to target directory
        if (rename($tmp_file, $file_path)) {
            return array(
                'success' => true,
                'file' => $file_path,
                'actual_slug' => $actual_slug,
                'mismatch' => ($actual_slug && $actual_slug !== $plugin_slug)
            );
        }

        // If rename fails, try copy + delete as fallback
        if (copy($tmp_file, $file_path)) {
            @unlink($tmp_file);
            return array(
                'success' => true,
                'file' => $file_path,
                'actual_slug' => $actual_slug,
                'mismatch' => ($actual_slug && $actual_slug !== $plugin_slug)
            );
        }

        @unlink($tmp_file);
        return new WP_Error('move_failed', 'Failed to move downloaded file from ' . $tmp_file . ' to ' . $file_path);
    }

    /**
     * Validate that a file is actually a ZIP file
     */
    private function validate_zip_file($file_path) {
        // Check if file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'Downloaded file does not exist');
        }

        // Check file size (should be larger than just an error message)
        $file_size = filesize($file_path);
        if ($file_size < 100) {
            // File is suspiciously small, likely an error message
            $content = file_get_contents($file_path);

            // Check if it's JSON
            $json_data = @json_decode($content, true);
            if ($json_data !== null) {
                $error_message = 'Download failed: Server returned JSON error';
                if (isset($json_data['error'])) {
                    $error_message .= ' - ' . $json_data['error'];
                } elseif (isset($json_data['message'])) {
                    $error_message .= ' - ' . $json_data['message'];
                }
                return new WP_Error('invalid_response', $error_message);
            }

            // Check if it's HTML error page
            if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
                return new WP_Error('invalid_response', 'Download failed: Server returned HTML error page');
            }

            return new WP_Error('file_too_small', 'Downloaded file is too small to be a valid plugin (only ' . $file_size . ' bytes)');
        }

        // Check ZIP file signature (magic bytes)
        $file_handle = fopen($file_path, 'rb');
        if ($file_handle === false) {
            return new WP_Error('file_read_error', 'Cannot read downloaded file');
        }

        $magic_bytes = fread($file_handle, 4);
        fclose($file_handle);

        // ZIP files start with PK\x03\x04 or PK\x05\x06 (empty archive) or PK\x07\x08 (spanned archive)
        $valid_signatures = array(
            "\x50\x4b\x03\x04", // Standard ZIP
            "\x50\x4b\x05\x06", // Empty ZIP
            "\x50\x4b\x07\x08"  // Spanned ZIP
        );

        $is_valid = false;
        foreach ($valid_signatures as $signature) {
            if (strpos($magic_bytes, $signature) === 0) {
                $is_valid = true;
                break;
            }
        }

        if (!$is_valid) {
            // Try to get a preview of file content for better error message
            $preview = file_get_contents($file_path, false, null, 0, 200);

            // Check if it looks like JSON
            $json_data = @json_decode($preview, true);
            if ($json_data !== null) {
                $error_message = 'Download failed: Server returned JSON instead of ZIP file';
                if (isset($json_data['error'])) {
                    $error_message .= ' - ' . $json_data['error'];
                } elseif (isset($json_data['message'])) {
                    $error_message .= ' - ' . $json_data['message'];
                }
                error_log('CribOps WP-Kit: Invalid file content: ' . $preview);
                return new WP_Error('invalid_zip', $error_message);
            }

            // Check if it looks like HTML
            if (stripos($preview, '<!DOCTYPE') !== false || stripos($preview, '<html') !== false) {
                error_log('CribOps WP-Kit: Invalid file content (HTML): ' . substr($preview, 0, 100));
                return new WP_Error('invalid_zip', 'Download failed: Server returned HTML page instead of ZIP file');
            }

            error_log('CribOps WP-Kit: Invalid ZIP signature. First 4 bytes: ' . bin2hex($magic_bytes));
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive. File may be corrupted or server returned an error.');
        }

        // If ZipArchive is available, try to open it for additional validation
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $result = $zip->open($file_path, ZipArchive::CHECKCONS);

            if ($result !== true) {
                $error_messages = array(
                    ZipArchive::ER_NOZIP => 'Not a valid ZIP archive',
                    ZipArchive::ER_INCONS => 'ZIP archive is inconsistent',
                    ZipArchive::ER_CRC => 'CRC error in ZIP',
                    ZipArchive::ER_READ => 'Cannot read ZIP file',
                );

                $error_message = isset($error_messages[$result]) ? $error_messages[$result] : 'ZIP validation failed (error code: ' . $result . ')';
                return new WP_Error('zip_validation_failed', $error_message);
            }

            $zip->close();
        }

        return true;
    }

    /**
     * Get the actual plugin slug from a ZIP file
     */
    private function get_plugin_slug_from_zip($zip_file) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== TRUE) {
            return false;
        }

        // Get the first entry to determine the root folder
        $first_entry = $zip->statIndex(0);
        if (!$first_entry) {
            $zip->close();
            return false;
        }

        $name = $first_entry['name'];

        // Extract the root folder name
        $parts = explode('/', $name);
        $root_folder = $parts[0];

        // Clean up any trailing slashes
        $root_folder = rtrim($root_folder, '/');

        $zip->close();

        // Return the root folder as the actual slug
        return $root_folder;
    }

    /**
     * Extend timeout for large downloads
     */
    public function extend_timeout($timeout) {
        return 300; // 5 minutes
    }

    /**
     * AJAX handler to clear a mismatch entry
     */
    public function ajax_clear_mismatch() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';

        if ($slug) {
            $mismatches = get_option('cwpk_slug_mismatches', array());
            unset($mismatches[$slug]);
            update_option('cwpk_slug_mismatches', $mismatches);
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler for manifest display
     */
    public function ajax_get_manifest() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        // Clear plugin cache before getting manifest to ensure fresh data
        wp_cache_delete('plugins', 'plugins');

        $manifest = $this->get_plugin_manifest();

        if (is_wp_error($manifest)) {
            wp_send_json_error($manifest->get_error_message());
        }

        wp_send_json_success($manifest);
    }

    /**
     * AJAX handler for individual plugin download
     */
    public function ajax_download_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_data = isset($_POST['plugin_data']) ? $_POST['plugin_data'] : array();

        if (empty($plugin_data) || !isset($plugin_data['slug'])) {
            wp_send_json_error('No plugin specified');
        }

        $result = $this->download_plugin($plugin_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Add mismatch warning to response
        if (isset($result['mismatch']) && $result['mismatch']) {
            $result['warning'] = sprintf(
                'Slug mismatch detected! Repository expects "%s" but plugin uses "%s". Please update the repository.',
                $plugin_data['slug'],
                $result['actual_slug']
            );
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for plugin installation
     */
    public function ajax_install_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_slug = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        $actual_slug = isset($_POST['actual_slug']) ? sanitize_text_field($_POST['actual_slug']) : $plugin_slug;

        if (!$plugin_slug) {
            wp_send_json_error('No plugin specified');
        }

        // Check if file exists in download directory
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit';

        // Try the actual slug first, then the expected slug
        $file_path = $target_dir . '/' . $actual_slug . '.zip';
        if (!file_exists($file_path)) {
            $file_path = $target_dir . '/' . $plugin_slug . '.zip';
        }

        // If file exists, validate it's a valid ZIP before attempting installation
        if (file_exists($file_path)) {
            $validation_result = $this->validate_zip_file($file_path);
            if (is_wp_error($validation_result)) {
                // File is corrupted, delete it and force re-download
                error_log('CribOps WP-Kit: Deleting corrupted ZIP file: ' . $file_path . ' - ' . $validation_result->get_error_message());
                @unlink($file_path);

                // Force download of fresh copy
                $manifest = $this->get_plugin_manifest();
                if (is_wp_error($manifest)) {
                    wp_send_json_error('Failed to get plugin list: ' . $manifest->get_error_message());
                }

                $plugin_data = null;
                foreach ($manifest as $plugin) {
                    if ($plugin['slug'] === $plugin_slug) {
                        $plugin_data = $plugin;
                        break;
                    }
                }

                if (!$plugin_data) {
                    wp_send_json_error('Plugin not found in repository. Previous file was corrupted: ' . $validation_result->get_error_message());
                }

                // Download the plugin
                $download_result = $this->download_plugin($plugin_data);
                if (is_wp_error($download_result)) {
                    wp_send_json_error('Failed to download plugin after detecting corruption: ' . $download_result->get_error_message());
                }

                // Update file path after download
                $file_path = isset($download_result['file']) ? $download_result['file'] : $target_dir . '/' . $plugin_slug . '.zip';
            }
        }

        // Special handling for Prime Mover free version - install from WordPress.org
        if ($plugin_slug === 'prime-mover') {
            // Install directly from WordPress.org repository
            if (!function_exists('plugins_api')) {
                include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            if (!class_exists('WP_Ajax_Upgrader_Skin')) {
                include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                include_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
            }

            // Get plugin information from WordPress.org
            $api = plugins_api('plugin_information', array(
                'slug' => 'prime-mover',
                'fields' => array(
                    'short_description' => false,
                    'sections' => false,
                    'requires' => false,
                    'rating' => false,
                    'ratings' => false,
                    'downloaded' => false,
                    'last_updated' => false,
                    'added' => false,
                    'tags' => false,
                    'homepage' => false,
                    'donate_link' => false,
                )
            ));

            if (is_wp_error($api)) {
                wp_send_json_error('Failed to get Prime Mover from WordPress.org: ' . $api->get_error_message());
            }

            // Initialize WP_Filesystem
            WP_Filesystem();

            // Clean up any existing Prime Mover installations
            $possible_dirs = array(
                WP_PLUGIN_DIR . '/prime-mover',
                WP_PLUGIN_DIR . '/prime-mover-pro'
            );

            foreach ($possible_dirs as $plugin_dir) {
                if (is_dir($plugin_dir)) {
                    $this->delete_directory($plugin_dir);
                }
            }

            // Install from WordPress.org
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result   = $upgrader->install($api->download_link);

            if (is_wp_error($result)) {
                wp_send_json_error('Installation failed: ' . $result->get_error_message());
            } elseif (is_wp_error($skin->result)) {
                wp_send_json_error('Installation process error: ' . $skin->result->get_error_message());
            } elseif ($skin->get_errors()->has_errors()) {
                wp_send_json_error('Installation errors: ' . implode(', ', $skin->get_error_messages()));
            }

            wp_send_json_success('Prime Mover installed successfully from WordPress.org.');
            return;
        }

        // If file doesn't exist, try to download it first
        if (!file_exists($file_path)) {
            // Get plugin data from manifest
            $manifest = $this->get_plugin_manifest();
            if (is_wp_error($manifest)) {
                wp_send_json_error('Failed to get plugin list: ' . $manifest->get_error_message());
            }

            $plugin_data = null;
            foreach ($manifest as $plugin) {
                if ($plugin['slug'] === $plugin_slug) {
                    $plugin_data = $plugin;
                    break;
                }
            }

            if (!$plugin_data) {
                wp_send_json_error('Plugin not found in repository.');
            }

            // Download the plugin
            $download_result = $this->download_plugin($plugin_data);
            if (is_wp_error($download_result)) {
                wp_send_json_error('Failed to download plugin: ' . $download_result->get_error_message());
            }

            // Update file path after download
            $file_path = isset($download_result['file']) ? $download_result['file'] : $target_dir . '/' . $plugin_slug . '.zip';

            if (!file_exists($file_path)) {
                wp_send_json_error('Plugin downloaded but file not found.');
            }
        }

        // Install the plugin
        WP_Filesystem();

        // First, detect the actual folder name from the ZIP
        $actual_plugin_slug = $this->get_plugin_slug_from_zip($file_path);
        if (!$actual_plugin_slug) {
            $actual_plugin_slug = $plugin_slug; // Fallback to expected slug
        }

        // Check both possible plugin directories and remove if they exist
        $possible_dirs = array(
            WP_PLUGIN_DIR . '/' . $actual_plugin_slug,
            WP_PLUGIN_DIR . '/' . $plugin_slug,
            WP_PLUGIN_DIR . '/' . $actual_slug
        );

        foreach (array_unique($possible_dirs) as $plugin_dir) {
            if (is_dir($plugin_dir)) {
                $this->delete_directory($plugin_dir);
            }
        }

        // Validate the ZIP one more time before unzipping
        $final_validation = $this->validate_zip_file($file_path);
        if (is_wp_error($final_validation)) {
            error_log('CribOps WP-Kit: Final validation failed before unzip: ' . $final_validation->get_error_message());
            wp_send_json_error('Installation failed: Invalid ZIP file - ' . $final_validation->get_error_message());
        }

        // Now unzip the file
        error_log('CribOps WP-Kit: Attempting to unzip: ' . $file_path . ' to ' . WP_PLUGIN_DIR);
        $result = unzip_file($file_path, WP_PLUGIN_DIR);

        if (is_wp_error($result)) {
            error_log('CribOps WP-Kit: Unzip failed: ' . $result->get_error_message() . ' (Code: ' . $result->get_error_code() . ')');

            // Provide more detailed error message
            $error_msg = 'Installation failed: ' . $result->get_error_message();

            // Check if file still exists and is readable
            if (!file_exists($file_path)) {
                $error_msg .= ' - File disappeared during installation.';
            } elseif (!is_readable($file_path)) {
                $error_msg .= ' - File is not readable.';
            } else {
                $error_msg .= ' - File exists and is ' . filesize($file_path) . ' bytes.';
            }

            wp_send_json_error($error_msg);
        }

        error_log('CribOps WP-Kit: Successfully unzipped plugin: ' . $plugin_slug);
        wp_send_json_success('Plugin installed successfully.');
    }

    /**
     * AJAX handler for plugin activation
     */
    public function ajax_activate_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_slug = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (!$plugin_slug) {
            wp_send_json_error('No plugin specified');
        }

        // Find the main plugin file
        $plugin_file = $this->find_plugin_file($plugin_slug);

        if (!$plugin_file) {
            wp_send_json_error('Plugin not found');
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Plugin activated successfully.');
    }

    /**
     * AJAX handler for plugin deactivation
     */
    public function ajax_deactivate_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_slug = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (!$plugin_slug) {
            wp_send_json_error('No plugin specified');
        }

        // Find the main plugin file
        $plugin_file = $this->find_plugin_file($plugin_slug);

        if (!$plugin_file) {
            wp_send_json_error('Plugin not found');
        }

        deactivate_plugins($plugin_file);

        wp_send_json_success('Plugin deactivated successfully.');
    }

    /**
     * AJAX handler for plugin deletion
     */
    public function ajax_delete_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_slug = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (!$plugin_slug) {
            wp_send_json_error('No plugin specified');
        }

        // Find the main plugin file
        $plugin_file = $this->find_plugin_file($plugin_slug);

        if (!$plugin_file) {
            wp_send_json_error('Plugin not found');
        }

        // Deactivate first if active
        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }

        // Delete the plugin
        $result = delete_plugins(array($plugin_file));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Plugin deleted successfully.');
    }

    /**
     * Find plugin main file
     */
    private function find_plugin_file($plugin_slug) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Clear the plugin cache to ensure we get fresh data
        wp_cache_delete('plugins', 'plugins');

        $installed_plugins = get_plugins();

        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            // Get the plugin directory name
            $plugin_dir = dirname($plugin_file);

            // Check various possible matches
            if (strpos($plugin_file, $plugin_slug . '/') === 0 ||
                $plugin_file === $plugin_slug . '.php' ||
                $plugin_dir === $plugin_slug ||
                ($plugin_dir === '.' && basename($plugin_file, '.php') === $plugin_slug)) {
                return $plugin_file;
            }
        }

        return false;
    }

    /**
     * Delete directory recursively using WP Filesystem
     */
    private function delete_directory($dir) {
        global $wp_filesystem;

        if (!is_dir($dir)) {
            return true;
        }

        // Initialize WP_Filesystem if needed
        if (!$wp_filesystem) {
            WP_Filesystem();
        }

        // Use WP Filesystem API if available
        if ($wp_filesystem && method_exists($wp_filesystem, 'delete')) {
            return $wp_filesystem->delete($dir, true);
        }

        // Fallback to manual deletion
        $objects = @scandir($dir);
        if ($objects === false) {
            return false;
        }

        foreach ($objects as $object) {
            if ($object !== '.' && $object !== '..') {
                $file = $dir . '/' . $object;
                if (is_dir($file)) {
                    $this->delete_directory($file);
                } else {
                    @unlink($file);
                }
            }
        }
        return @rmdir($dir);
    }

    /**
     * Display slug mismatches for admin attention
     */
    private function display_slug_mismatches() {
        $mismatches = get_option('cwpk_slug_mismatches', array());

        if (empty($mismatches)) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <h3>⚠️ Plugin Slug Mismatches Detected</h3>
            <p>The following plugins have mismatched slugs that need to be updated in the repository:</p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Expected Slug</th>
                        <th>Actual Slug</th>
                        <th>Detected</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mismatches as $slug => $data) : ?>
                    <tr>
                        <td><code><?php echo esc_html($data['expected']); ?></code></td>
                        <td><code><?php echo esc_html($data['actual']); ?></code></td>
                        <td><?php echo esc_html($data['detected']); ?></td>
                        <td>
                            <button class="button button-small cwpk-clear-mismatch" data-slug="<?php echo esc_attr($slug); ?>">Clear</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong>Action Required:</strong> Please update the repository to use the actual slugs shown above.</p>
        </div>
        <script>
        jQuery('.cwpk-clear-mismatch').click(function() {
            var slug = jQuery(this).data('slug');
            jQuery.post(ajaxurl, {
                action: 'cwpk_clear_mismatch',
                slug: slug,
                security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
            }, function() {
                location.reload();
            });
        });
        </script>
        <?php
    }

    /**
     * Display manifest-based installer UI
     */
    public function display_manifest_installer() {
        $user_data = get_transient('lk_user_data');

        if (!$user_data) {
            echo '<p>Please log in to view available plugins.</p>';
            return;
        }

        // Clear plugin cache on page load to ensure fresh data
        wp_cache_delete('plugins', 'plugins');

        // Display any slug mismatches
        $this->display_slug_mismatches();

        ?>
        <div id="cwpk-manifest-installer">
            <h3>Available Plugins</h3>
            <div style="margin-bottom: 15px;">
                <input type="text" id="cwpk-plugin-search" placeholder="Search plugins by name..." style="width: 300px; padding: 5px;">
                <span id="cwpk-search-results" style="margin-left: 10px; color: #666;"></span>
            </div>
            <p>
                <button type="button" class="button" id="cwpk-refresh-manifest">Refresh List</button>
                <button type="button" class="button button-primary" id="cwpk-download-selected">Download Selected</button>
                <button type="button" class="button" id="cwpk-install-downloaded">Install Downloaded Plugins</button>
                <span id="cwpk-manifest-status"></span>
            </p>

            <div class="cwpk-table-wrapper">
                <table class="wp-list-table widefat fixed striped cwpk-plugins-table">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="cwpk-select-all" /></th>
                            <th>Plugin</th>
                            <th>Author</th>
                            <th>Version</th>
                            <th>Type</th>
                            <th>File Size</th>
                            <th>PHP Required</th>
                            <th>Tested Up To</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cwpk-manifest-list">
                        <tr><td colspan="10">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(function($) {
            var manifestInstaller = {
                plugins: [],

                init: function() {
                    this.loadManifest();
                    this.bindEvents();
                },

                bindEvents: function() {
                    $('#cwpk-refresh-manifest').on('click', this.loadManifest.bind(this));
                    $('#cwpk-download-selected').on('click', this.downloadSelected.bind(this));
                    $('#cwpk-install-downloaded').on('click', this.installDownloaded.bind(this));
                    $('#cwpk-select-all').on('change', this.toggleSelectAll.bind(this));
                    $(document).on('click', '.cwpk-download-single', this.downloadSingle.bind(this));
                    $(document).on('click', '.cwpk-redownload', this.redownloadPlugin.bind(this));
                    $(document).on('click', '.cwpk-install', this.installPlugin.bind(this));
                    $(document).on('click', '.cwpk-activate', this.activatePlugin.bind(this));
                    $(document).on('click', '.cwpk-deactivate', this.deactivatePlugin.bind(this));
                    $(document).on('click', '.cwpk-delete', this.deletePlugin.bind(this));

                    // Search functionality
                    $('#cwpk-plugin-search').on('keyup', this.filterPlugins.bind(this));
                    $('#cwpk-plugin-search').on('search', this.filterPlugins.bind(this)); // Handles clicking the X in search field
                },

                installPlugin: function(e) {
                    e.preventDefault();
                    var self = this;
                    var button = $(e.target);
                    var plugin = button.data('plugin');
                    var row = button.closest('tr');

                    button.text('Installing...').prop('disabled', true);
                    var statusCell = row.find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Installing...');

                    var actualSlug = button.data('actual-slug') || plugin;

                    $.post(ajaxurl, {
                        action: 'cwpk_install_plugin',
                        plugin: plugin,
                        actual_slug: actualSlug,
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Update status and action
                            statusCell.html('<span class="dashicons dashicons-minus"></span> Inactive');
                            statusCell.removeClass('cwpk-status-downloaded').addClass('cwpk-status-inactive');
                            button.closest('td').html('<button class="button button-small cwpk-activate" data-plugin="' + plugin + '">Activate</button>');

                            // Update the plugin status in our data
                            self.plugins.forEach(function(p) {
                                if (p.slug === plugin) {
                                    p.status = 'inactive';
                                }
                            });
                        } else {
                            button.text('Install').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Installation failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Install').prop('disabled', false);
                        statusCell.html('<span class="dashicons dashicons-warning"></span> Failed');
                        alert('Installation failed. Please try again.');
                    });
                },

                activatePlugin: function(e) {
                    e.preventDefault();
                    var self = this;
                    var button = $(e.target);
                    var plugin = button.data('plugin');
                    var row = button.closest('tr');

                    button.text('Activating...').prop('disabled', true);
                    var statusCell = row.find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Activating...');

                    $.post(ajaxurl, {
                        action: 'cwpk_activate_plugin',
                        plugin: plugin,
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Update status and action
                            statusCell.html('<span class="dashicons dashicons-yes-alt"></span> Active');
                            statusCell.removeClass('cwpk-status-inactive').addClass('cwpk-status-active');
                            button.closest('td').html('<button class="button button-small cwpk-deactivate" data-plugin="' + plugin + '">Deactivate</button>' +
                                ' <button class="button button-small cwpk-delete" data-plugin="' + plugin + '">Delete</button>');

                            // Update checkbox to disabled
                            row.find('.cwpk-plugin-check').prop('disabled', true);

                            // Update the plugin status in our data
                            self.plugins.forEach(function(p) {
                                if (p.slug === plugin) {
                                    p.status = 'active';
                                }
                            });
                        } else {
                            button.text('Activate').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Activation failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Activate').prop('disabled', false);
                        statusCell.html('<span class="dashicons dashicons-warning"></span> Failed');
                        alert('Activation failed. Please try again.');
                    });
                },

                deactivatePlugin: function(e) {
                    e.preventDefault();
                    var self = this;
                    var button = $(e.target);
                    var plugin = button.data('plugin');
                    var row = button.closest('tr');

                    button.text('Deactivating...').prop('disabled', true);
                    var statusCell = row.find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Deactivating...');

                    $.post(ajaxurl, {
                        action: 'cwpk_deactivate_plugin',
                        plugin: plugin,
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Update status and action
                            statusCell.html('<span class="dashicons dashicons-minus"></span> Inactive');
                            statusCell.removeClass('cwpk-status-active').addClass('cwpk-status-inactive');
                            button.closest('td').html('<button class="button button-small cwpk-activate" data-plugin="' + plugin + '">Activate</button>' +
                                ' <button class="button button-small cwpk-delete" data-plugin="' + plugin + '">Delete</button>');

                            // Update checkbox to enabled
                            row.find('.cwpk-plugin-check').prop('disabled', false);

                            // Update the plugin status in our data
                            self.plugins.forEach(function(p) {
                                if (p.slug === plugin) {
                                    p.status = 'inactive';
                                }
                            });
                        } else {
                            button.text('Deactivate').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Deactivation failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Deactivate').prop('disabled', false);
                        statusCell.html('<span class="dashicons dashicons-warning"></span> Failed');
                        alert('Deactivation failed. Please try again.');
                    });
                },

                deletePlugin: function(e) {
                    e.preventDefault();

                    if (!confirm('Are you sure you want to delete this plugin? This action cannot be undone.')) {
                        return;
                    }

                    var self = this;
                    var button = $(e.target);
                    var plugin = button.data('plugin');
                    var row = button.closest('tr');

                    button.text('Deleting...').prop('disabled', true);
                    var statusCell = row.find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Deleting...');

                    $.post(ajaxurl, {
                        action: 'cwpk_delete_plugin',
                        plugin: plugin,
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Refresh the manifest to get accurate status
                            setTimeout(function() {
                                self.loadManifest();
                            }, 500); // Small delay to ensure WordPress cache is updated
                        } else {
                            button.text('Delete').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Deletion failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Delete').prop('disabled', false);
                        statusCell.html('<span class="dashicons dashicons-warning"></span> Failed');
                        alert('Deletion failed. Please try again.');
                    });
                },

                redownloadPlugin: function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to re-download this plugin? This will overwrite the existing file.')) {
                        this.downloadSingle(e);
                    }
                },

                loadManifest: function() {
                    $('#cwpk-manifest-status').text('Loading plugin list...');

                    // Add a cache buster to force fresh data
                    $.post(ajaxurl, {
                        action: 'cwpk_get_manifest',
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>',
                        _nocache: Date.now() // Cache buster
                    }, function(response) {
                        if (response.success) {
                            manifestInstaller.plugins = response.data;
                            manifestInstaller.renderPlugins();
                            $('#cwpk-manifest-status').text('');
                        } else {
                            $('#cwpk-manifest-status').text('Error: ' + response.data);
                        }
                    }).fail(function() {
                        $('#cwpk-manifest-status').text('Failed to load plugin list. Please refresh the page.');
                    });
                },

                renderPlugins: function() {
                    var html = '';

                    if (this.plugins.length === 0) {
                        html = '<tr><td colspan="10">No plugins available</td></tr>';
                    } else {
                        $.each(this.plugins, function(i, plugin) {
                            var statusClass = '';
                            var statusText = '';
                            var actions = '';

                            if (plugin.status === 'active') {
                                statusClass = 'cwpk-status-active';
                                statusText = '<span class="dashicons dashicons-yes-alt"></span> Active';
                                actions = '<button class="button button-small cwpk-deactivate" data-plugin="' + plugin.slug + '">Deactivate</button>' +
                                        ' <button class="button button-small cwpk-delete" data-plugin="' + plugin.slug + '">Delete</button>';
                            } else if (plugin.status === 'inactive') {
                                statusClass = 'cwpk-status-inactive';
                                statusText = '<span class="dashicons dashicons-minus"></span> Inactive';
                                actions = '<button class="button button-small cwpk-activate" data-plugin="' + plugin.slug + '">Activate</button>' +
                                        ' <button class="button button-small cwpk-delete" data-plugin="' + plugin.slug + '">Delete</button>';
                            } else if (plugin.status === 'downloaded' || plugin.local) {
                                statusClass = 'cwpk-status-downloaded';
                                statusText = '<span class="dashicons dashicons-download"></span> Downloaded';
                                actions = '<button class="button button-small cwpk-install" data-plugin="' + plugin.slug + '">Install</button>' +
                                        ' <button class="button button-small cwpk-redownload" data-plugin-index="' + i + '">Re-download</button>';
                            } else {
                                statusClass = 'cwpk-status-available';
                                statusText = '<span class="dashicons dashicons-cloud"></span> Available';
                                actions = '<button class="button button-small cwpk-download-single" data-plugin-index="' + i + '">Download</button>';
                            }

                            var description = plugin.description ? '<div class="cwpk-plugin-description">' + plugin.description + '</div>' : '';

                            html += '<tr class="cwpk-plugin-row" data-plugin-index="' + i + '">';
                            html += '<td><input type="checkbox" class="cwpk-plugin-check" value="' + i + '" ' + (plugin.status === 'active' ? 'disabled' : '') + '/></td>';
                            html += '<td class="cwpk-plugin-name"><strong>' + (plugin.name || plugin.slug) + '</strong>' + description + '</td>';
                            html += '<td>' + (plugin.author || '-') + '</td>';
                            html += '<td>' + (plugin.version || '-') + '</td>';
                            html += '<td>' + (plugin.type || 'Plugin') + '</td>';
                            html += '<td>' + (plugin.file_size || '-') + '</td>';
                            html += '<td>' + (plugin.php_required || '-') + '</td>';
                            html += '<td>' + (plugin.tested_up_to || '-') + '</td>';
                            html += '<td class="cwpk-status ' + statusClass + '">' + statusText + '</td>';
                            html += '<td class="cwpk-action-' + plugin.slug + '">' + actions + '</td>';
                            html += '</tr>';
                        });
                    }

                    $('#cwpk-manifest-list').html(html);
                },

                downloadSingle: function(e) {
                    e.preventDefault();
                    var button = $(e.target);
                    var pluginIndex = button.data('plugin-index');
                    var plugin = this.plugins[pluginIndex];

                    if (!plugin) {
                        alert('Plugin data not found');
                        return;
                    }

                    button.text('Downloading...').prop('disabled', true);
                    var statusCell = button.closest('tr').find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Downloading...');

                    $.post(ajaxurl, {
                        action: 'cwpk_download_plugin',
                        plugin_data: plugin,
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            plugin.local = true;
                            plugin.status = 'downloaded';

                            // Store actual slug if different
                            if (response.data.actual_slug) {
                                plugin.actual_slug = response.data.actual_slug;
                            }

                            // Show warning if there's a mismatch
                            if (response.data.warning) {
                                alert('WARNING: ' + response.data.warning);
                                console.error('CribOps WP-Kit:', response.data.warning);
                            }

                            statusCell.html('<span class="dashicons dashicons-download"></span> Downloaded');

                            var actualSlug = response.data.actual_slug || plugin.slug;
                            $('.cwpk-action-' + plugin.slug).html('<button class="button button-small cwpk-install" data-plugin="' + plugin.slug + '" data-actual-slug="' + actualSlug + '">Install</button>' +
                                ' <button class="button button-small cwpk-redownload" data-plugin-index="' + pluginIndex + '">Re-download</button>');
                        } else {
                            button.text('Download').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Download failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Download').prop('disabled', false);
                        statusCell.html('<span class="dashicons dashicons-warning"></span> Failed');
                        alert('Download failed. Please try again.');
                    });
                },

                downloadSelected: function() {
                    var self = this;
                    var selected = $('.cwpk-plugin-check:checked:not(:disabled)');

                    if (selected.length === 0) {
                        alert('Please select plugins to download');
                        return;
                    }

                    selected.each(function() {
                        var pluginIndex = $(this).val();
                        var button = $('.cwpk-plugin-row[data-plugin-index="' + pluginIndex + '"] .cwpk-download-single');
                        if (button.length) {
                            button.click();
                        }
                    });
                },

                installDownloaded: function() {
                    $('.cwpk-install').each(function() {
                        $(this).click();
                    });
                },

                toggleSelectAll: function(e) {
                    $('.cwpk-plugin-check:not(:disabled)').prop('checked', e.target.checked);
                },

                filterPlugins: function() {
                    var searchTerm = $('#cwpk-plugin-search').val().toLowerCase();
                    var visibleCount = 0;
                    var totalCount = 0;

                    $('.cwpk-plugin-row').each(function() {
                        var row = $(this);
                        var pluginName = row.find('.cwpk-plugin-name').text().toLowerCase();

                        totalCount++;

                        if (searchTerm === '' || pluginName.indexOf(searchTerm) !== -1) {
                            row.show();
                            visibleCount++;
                        } else {
                            row.hide();
                        }
                    });

                    // Update results count
                    if (searchTerm === '') {
                        $('#cwpk-search-results').text('');
                    } else {
                        $('#cwpk-search-results').text('Showing ' + visibleCount + ' of ' + totalCount + ' plugins');
                    }

                    // Handle "no results" message
                    if (visibleCount === 0 && totalCount > 0) {
                        if ($('#cwpk-no-results').length === 0) {
                            $('#cwpk-manifest-list').append('<tr id="cwpk-no-results"><td colspan="10" style="text-align: center; padding: 20px;">No plugins found matching "' + searchTerm + '"</td></tr>');
                        }
                    } else {
                        $('#cwpk-no-results').remove();
                    }
                }
            };

            manifestInstaller.init();
        });
        </script>
        <style>
            .cwpk-table-wrapper {
                overflow-x: auto;
                margin-top: 20px;
            }
            .cwpk-plugins-table {
                min-width: 1000px;
            }
            .cwpk-plugins-table th,
            .cwpk-plugins-table td {
                padding: 8px 10px;
            }
            .cwpk-plugin-description {
                font-size: 12px;
                color: #666;
                margin-top: 4px;
                line-height: 1.4;
                max-width: 400px;
            }
            .cwpk-status {
                white-space: nowrap;
            }
            .cwpk-status .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                vertical-align: text-bottom;
            }
            .cwpk-status-active .dashicons-yes-alt {
                color: #46b450;
            }
            .cwpk-status-inactive .dashicons-minus {
                color: #ffb900;
            }
            .cwpk-status-downloaded .dashicons-download {
                color: #00a0d2;
            }
            .cwpk-status-available .dashicons-cloud {
                color: #82878c;
            }
            .cwpk-status .spinner {
                float: none;
                margin: 0;
                vertical-align: middle;
            }
            .cwpk-redownload {
                margin-left: 5px !important;
            }
            .cwpk-action-cell .button + .button {
                margin-left: 5px;
            }
            .cwpk-delete {
                color: #a00;
            }
            .cwpk-delete:hover {
                color: #dc3232;
                border-color: #dc3232;
            }
            @media screen and (max-width: 1200px) {
                .cwpk-plugin-description {
                    max-width: 300px;
                }
            }
        </style>
        <?php
    }
}

// Initialize AJAX handlers when this class is loaded
if (is_admin()) {
    $cwpk_manifest_installer = new CWPK_Manifest_Installer();

    // Register AJAX handlers
    add_action('wp_ajax_cwpk_get_manifest', array($cwpk_manifest_installer, 'ajax_get_manifest'));
    add_action('wp_ajax_cwpk_download_plugin', array($cwpk_manifest_installer, 'ajax_download_plugin'));
    add_action('wp_ajax_cwpk_install_plugin', array($cwpk_manifest_installer, 'ajax_install_plugin'));
    add_action('wp_ajax_cwpk_activate_plugin', array($cwpk_manifest_installer, 'ajax_activate_plugin'));
    add_action('wp_ajax_cwpk_deactivate_plugin', array($cwpk_manifest_installer, 'ajax_deactivate_plugin'));
    add_action('wp_ajax_cwpk_delete_plugin', array($cwpk_manifest_installer, 'ajax_delete_plugin'));
    add_action('wp_ajax_cwpk_clear_mismatch', array($cwpk_manifest_installer, 'ajax_clear_mismatch'));
}