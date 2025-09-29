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
        $target_dir = trailingslashit($upload_dir['basedir']) . 'launchkit-updates';
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
            'php_required' => isset($plugin['requires_php']) ? $plugin['requires_php'] : '',
            'tested_up_to' => isset($plugin['tested_up_to']) ? $plugin['tested_up_to'] : '',
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

        $installed_plugins = get_plugins();

        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            $plugin_slug = dirname($plugin_file);
            if ($plugin_slug === '.' || $plugin_slug === $slug) {
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
        $target_dir = trailingslashit($upload_dir['basedir']) . 'launchkit-updates';

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
                    'download_url' => trailingslashit($upload_dir['baseurl']) . 'launchkit-updates/' . basename($file),
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
                // If direct response, save the body
                $body = wp_remote_retrieve_body($response);

                if ($body) {
                    $upload_dir = wp_upload_dir();
                    $target_dir = trailingslashit($upload_dir['basedir']) . 'launchkit-updates';

                    if (!file_exists($target_dir)) {
                        wp_mkdir_p($target_dir);
                    }

                    $file_path = $target_dir . '/' . $plugin_data['slug'] . '.zip';

                    if (file_put_contents($file_path, $body)) {
                        return array('success' => true, 'file' => $file_path);
                    }
                }

                return new WP_Error('download_failed', 'Failed to download plugin');
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
        $target_dir = trailingslashit($upload_dir['basedir']) . 'launchkit-updates';

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $file_path = $target_dir . '/' . $plugin_slug . '.zip';

        // If file already exists, back it up before overwriting
        if (file_exists($file_path)) {
            @unlink($file_path . '.backup');
            @rename($file_path, $file_path . '.backup');
        }

        // Use WordPress download function with increased timeout
        add_filter('http_request_timeout', array($this, 'extend_timeout'));
        $tmp_file = download_url($url, 300); // 5 minute timeout
        remove_filter('http_request_timeout', array($this, 'extend_timeout'));

        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        // Move to target directory
        if (rename($tmp_file, $file_path)) {
            return array('success' => true, 'file' => $file_path);
        }

        @unlink($tmp_file);
        return new WP_Error('move_failed', 'Failed to move downloaded file');
    }

    /**
     * Extend timeout for large downloads
     */
    public function extend_timeout($timeout) {
        return 300; // 5 minutes
    }

    /**
     * AJAX handler for manifest display
     */
    public function ajax_get_manifest() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

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

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for plugin installation
     */
    public function ajax_install_plugin() {
        check_ajax_referer('cwpk_manifest_nonce', 'security');

        $plugin_slug = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (!$plugin_slug) {
            wp_send_json_error('No plugin specified');
        }

        // Check if file exists in download directory
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'launchkit-updates';
        $file_path = $target_dir . '/' . $plugin_slug . '.zip';

        if (!file_exists($file_path)) {
            wp_send_json_error('Plugin file not found. Please download it first.');
        }

        // Install the plugin
        WP_Filesystem();
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        // Remove existing folder if any
        if (is_dir($plugin_dir)) {
            $this->delete_directory($plugin_dir);
        }

        $result = unzip_file($file_path, WP_PLUGIN_DIR);

        if (is_wp_error($result)) {
            wp_send_json_error('Installation failed: ' . $result->get_error_message());
        }

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

        $installed_plugins = get_plugins();

        // First try exact match
        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            if (strpos($plugin_file, $plugin_slug . '/') === 0 || $plugin_file === $plugin_slug . '.php') {
                return $plugin_file;
            }
        }

        // Try with -pro suffix
        $pro_slug = $plugin_slug . '-pro';
        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            if (strpos($plugin_file, $pro_slug . '/') === 0 || $plugin_file === $pro_slug . '.php') {
                return $plugin_file;
            }
        }

        // Try removing -pro suffix if it exists
        if (substr($plugin_slug, -4) === '-pro') {
            $base_slug = substr($plugin_slug, 0, -4);
            foreach ($installed_plugins as $plugin_file => $plugin_info) {
                if (strpos($plugin_file, $base_slug . '/') === 0 || $plugin_file === $base_slug . '.php') {
                    return $plugin_file;
                }
            }
        }

        // Try partial match (contains the slug)
        foreach ($installed_plugins as $plugin_file => $plugin_info) {
            $plugin_dir = dirname($plugin_file);
            if (strpos($plugin_dir, $plugin_slug) !== false) {
                return $plugin_file;
            }
        }

        return false;
    }

    /**
     * Delete directory recursively
     */
    private function delete_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object !== '.' && $object !== '..') {
                $file = $dir . '/' . $object;
                is_dir($file) ? $this->delete_directory($file) : unlink($file);
            }
        }
        rmdir($dir);
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

        ?>
        <div id="cwpk-manifest-installer">
            <h3>Available Plugins</h3>
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

                    $.post(ajaxurl, {
                        action: 'cwpk_install_plugin',
                        plugin: plugin,
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
                            // Check if downloaded file still exists
                            var pluginIndex = -1;
                            self.plugins.forEach(function(p, index) {
                                if (p.slug === plugin) {
                                    pluginIndex = index;
                                    // Check if file is downloaded
                                    if (p.local) {
                                        // File exists, show as downloaded
                                        p.status = 'downloaded';
                                        statusCell.html('<span class="dashicons dashicons-download"></span> Downloaded');
                                        statusCell.removeClass('cwpk-status-active cwpk-status-inactive').addClass('cwpk-status-downloaded');
                                        button.closest('td').html('<button class="button button-small cwpk-install" data-plugin="' + plugin + '">Install</button>' +
                                            ' <button class="button button-small cwpk-redownload" data-plugin-index="' + pluginIndex + '">Re-download</button>');
                                    } else {
                                        // No file, show as available
                                        p.status = 'not_installed';
                                        statusCell.html('<span class="dashicons dashicons-cloud"></span> Available');
                                        statusCell.removeClass('cwpk-status-active cwpk-status-inactive').addClass('cwpk-status-available');
                                        button.closest('td').html('<button class="button button-small cwpk-download-single" data-plugin-index="' + pluginIndex + '">Download</button>');
                                    }

                                    // Re-enable checkbox
                                    row.find('.cwpk-plugin-check').prop('disabled', false);
                                }
                            });
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

                    $.post(ajaxurl, {
                        action: 'cwpk_get_manifest',
                        security: '<?php echo wp_create_nonce('cwpk_manifest_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            manifestInstaller.plugins = response.data;
                            manifestInstaller.renderPlugins();
                            $('#cwpk-manifest-status').text('');
                        } else {
                            $('#cwpk-manifest-status').text('Error: ' + response.data);
                        }
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
                            statusCell.html('<span class="dashicons dashicons-download"></span> Downloaded');
                            $('.cwpk-action-' + plugin.slug).html('<button class="button button-small cwpk-install" data-plugin="' + plugin.slug + '">Install</button>' +
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
}