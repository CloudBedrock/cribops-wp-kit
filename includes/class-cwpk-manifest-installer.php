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
        return array(
            'slug' => isset($plugin['slug']) ? $plugin['slug'] : '',
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
            'status' => $this->get_plugin_status($plugin['slug']),
            'local' => false
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
                    $(document).on('click', '.cwpk-install', this.installPlugin.bind(this));
                    $(document).on('click', '.cwpk-activate', this.activatePlugin.bind(this));
                },

                installPlugin: function(e) {
                    e.preventDefault();
                    var button = $(e.target);
                    var plugin = button.data('plugin');

                    button.text('Installing...').prop('disabled', true);

                    // Add installation logic here
                    alert('Installation functionality to be implemented for: ' + plugin);
                    button.text('Install').prop('disabled', false);
                },

                activatePlugin: function(e) {
                    e.preventDefault();
                    var button = $(e.target);
                    var plugin = button.data('plugin');

                    button.text('Activating...').prop('disabled', true);

                    // Add activation logic here
                    alert('Activation functionality to be implemented for: ' + plugin);
                    button.text('Activate').prop('disabled', false);
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
                                actions = '<button class="button button-small" disabled>Installed</button>';
                            } else if (plugin.status === 'inactive') {
                                statusClass = 'cwpk-status-inactive';
                                statusText = '<span class="dashicons dashicons-minus"></span> Inactive';
                                actions = '<button class="button button-small cwpk-activate" data-plugin="' + plugin.slug + '">Activate</button>';
                            } else if (plugin.local) {
                                statusClass = 'cwpk-status-downloaded';
                                statusText = '<span class="dashicons dashicons-download"></span> Downloaded';
                                actions = '<button class="button button-small cwpk-install" data-plugin="' + plugin.slug + '">Install</button>';
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
                            statusCell.html('<span class="dashicons dashicons-download"></span> Downloaded');
                            $('.cwpk-action-' + plugin.slug).html('<button class="button button-small cwpk-install" data-plugin="' + plugin.slug + '">Install</button>');
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
}