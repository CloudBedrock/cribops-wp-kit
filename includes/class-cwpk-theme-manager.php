<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CribOps WP Kit Theme Manager
 *
 * Manages WordPress theme download, installation, activation, and deletion
 * Mirrors plugin management functionality for themes
 */
class CWPK_Theme_Manager {

    /**
     * Get theme manifest from API
     */
    public function get_theme_manifest() {
        $user_data = get_transient('lk_user_data');

        if (!$user_data || empty($user_data['email'])) {
            return new WP_Error('not_logged_in', 'Please log in to access themes');
        }

        // Call the API to get theme manifest
        $api_url = class_exists('CWPKConfig') ? CWPKConfig::get_api_url() : 'https://cribops.com';
        $response = wp_remote_get(
            $api_url . '/api/wp-kit/themes',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $user_data['email'],
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

        if (!$data || !isset($data['themes'])) {
            // Fallback to local manifest if API fails
            return $this->get_local_manifest();
        }

        // Process themes to ensure consistent structure
        $themes = array();
        foreach ($data['themes'] as $theme) {
            $themes[] = $this->normalize_theme_data($theme);
        }

        return $themes;
    }

    /**
     * Normalize theme data structure
     */
    private function normalize_theme_data($theme) {
        $slug = isset($theme['slug']) ? $theme['slug'] : '';

        // Check if file is already downloaded
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/themes';
        $file_path = $target_dir . '/' . $slug . '.zip';
        $is_downloaded = file_exists($file_path);

        // Get theme status
        $status = $this->get_theme_status($slug);

        // If not installed but file exists, mark as downloaded
        if ($status === 'not_installed' && $is_downloaded) {
            $status = 'downloaded';
        }

        return array(
            'slug' => $slug,
            'name' => isset($theme['name']) ? $theme['name'] : '',
            'author' => isset($theme['author']) ? $theme['author'] : '',
            'description' => isset($theme['description']) ? $theme['description'] : '',
            'type' => 'theme',
            'version' => isset($theme['version']) ? $theme['version'] : '',
            'file_size' => isset($theme['file_size']) ? $this->format_file_size($theme['file_size']) : '',
            'php_required' => isset($theme['requires_php']) ? $theme['requires_php'] : (isset($theme['php_required']) ? $theme['php_required'] : ''),
            'tested_up_to' => isset($theme['tested_up_to']) ? $theme['tested_up_to'] : (isset($theme['wp_version']) ? $theme['wp_version'] : ''),
            's3_url' => isset($theme['s3_url']) ? $theme['s3_url'] : '',
            'cdn_url' => isset($theme['cdn_url']) ? $theme['cdn_url'] : '',
            'download_url' => isset($theme['download_url']) ? $theme['download_url'] : '',
            'thumbnail_url' => isset($theme['thumbnail_url']) ? $theme['thumbnail_url'] : '',
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
     * Get theme installation status
     */
    private function get_theme_status($slug) {
        // Get all installed themes
        $installed_themes = wp_get_themes();

        // Check if theme exists
        if (!isset($installed_themes[$slug])) {
            return 'not_installed';
        }

        // Check if it's the active theme
        $current_theme = wp_get_theme();
        if ($current_theme->get_stylesheet() === $slug) {
            return 'active';
        }

        return 'inactive';
    }

    /**
     * Get local manifest from downloaded files (fallback)
     */
    private function get_local_manifest() {
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/themes';

        $themes = array();

        if (file_exists($target_dir)) {
            $files = glob($target_dir . '/*.zip');

            foreach ($files as $file) {
                $theme_name = basename($file, '.zip');
                $themes[] = array(
                    'slug' => sanitize_title($theme_name),
                    'name' => $theme_name,
                    'author' => '',
                    'description' => '',
                    'type' => 'theme',
                    'version' => '',
                    'file' => basename($file),
                    'file_size' => $this->format_file_size(filesize($file)),
                    'php_required' => '',
                    'tested_up_to' => '',
                    's3_url' => '',
                    'cdn_url' => '',
                    'download_url' => trailingslashit($upload_dir['baseurl']) . 'cribops-wp-kit/themes/' . basename($file),
                    'thumbnail_url' => '',
                    'status' => $this->get_theme_status(sanitize_title($theme_name)),
                    'local' => true
                );
            }
        }

        return $themes;
    }

    /**
     * Download individual theme
     */
    public function download_theme($theme_data) {
        $user_data = get_transient('lk_user_data');

        if (!$user_data) {
            return new WP_Error('not_logged_in', 'Please log in to download themes');
        }

        // Determine download URL - prefer S3 over CDN
        $download_url = '';

        if (!empty($theme_data['s3_url'])) {
            $download_url = $theme_data['s3_url'];
        } elseif (!empty($theme_data['cdn_url'])) {
            $download_url = $theme_data['cdn_url'];
        } elseif (!empty($theme_data['download_url'])) {
            $download_url = $theme_data['download_url'];
        }

        // If no direct URL, try API endpoint
        if (empty($download_url) && !empty($theme_data['slug'])) {
            // Get download URL from API
            $api_url = class_exists('CWPKConfig') ? CWPKConfig::get_api_url() : 'https://cribops.com';
            $response = wp_remote_get(
                $api_url . '/api/wp-kit/themes/' . $theme_data['slug'] . '/download',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $user_data['email']
                    ),
                    'timeout' => 60,
                    'redirection' => 0
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
                    $tmp_file = wp_tempnam();
                    if (!$tmp_file) {
                        return new WP_Error('temp_file_failed', 'Could not create temporary file');
                    }

                    if (!file_put_contents($tmp_file, $body)) {
                        @unlink($tmp_file);
                        return new WP_Error('temp_write_failed', 'Could not write to temporary file');
                    }

                    error_log('CribOps WP-Kit: API returned direct response for theme ' . $theme_data['slug']);

                    // Validate the downloaded content
                    $validation_result = $this->validate_zip_file($tmp_file);
                    if (is_wp_error($validation_result)) {
                        error_log('CribOps WP-Kit: Theme validation failed: ' . $validation_result->get_error_message());
                        @unlink($tmp_file);
                        return $validation_result;
                    }

                    // Move to final location
                    $upload_dir = wp_upload_dir();
                    $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/themes';

                    if (!file_exists($target_dir)) {
                        wp_mkdir_p($target_dir);
                    }

                    $file_path = $target_dir . '/' . $theme_data['slug'] . '.zip';

                    if (rename($tmp_file, $file_path)) {
                        error_log('CribOps WP-Kit: Successfully saved theme to: ' . $file_path);
                        return array('success' => true, 'file' => $file_path);
                    } else {
                        @unlink($tmp_file);
                        return new WP_Error('move_failed', 'Failed to move theme file to final location');
                    }
                }

                return new WP_Error('download_failed', 'Failed to download theme - empty response');
            }
        }

        if (empty($download_url)) {
            return new WP_Error('no_download_url', 'No download URL available for this theme');
        }

        return $this->download_from_url($download_url, $theme_data['slug']);
    }

    /**
     * Download from URL (S3 presigned URL)
     */
    private function download_from_url($url, $theme_slug) {
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/themes';

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $file_path = $target_dir . '/' . $theme_slug . '.zip';

        // If file already exists, back it up before overwriting
        if (file_exists($file_path)) {
            @unlink($file_path . '.backup');
            @rename($file_path, $file_path . '.backup');
        }

        error_log('CribOps WP-Kit: Downloading theme from: ' . $url);

        // Use WordPress download function
        add_filter('http_request_timeout', array($this, 'extend_timeout'));
        $tmp_file = download_url($url, 300);
        remove_filter('http_request_timeout', array($this, 'extend_timeout'));

        if (is_wp_error($tmp_file)) {
            error_log('CribOps WP-Kit: Theme download failed: ' . $tmp_file->get_error_message());
            return $tmp_file;
        }

        // Validate ZIP file
        $validation_result = $this->validate_zip_file($tmp_file);
        if (is_wp_error($validation_result)) {
            error_log('CribOps WP-Kit: Theme validation failed: ' . $validation_result->get_error_message());
            @unlink($tmp_file);
            return $validation_result;
        }

        // Move to target directory
        if (rename($tmp_file, $file_path)) {
            return array(
                'success' => true,
                'file' => $file_path
            );
        }

        @unlink($tmp_file);
        return new WP_Error('move_failed', 'Failed to move downloaded theme file');
    }

    /**
     * Validate ZIP file
     */
    private function validate_zip_file($file_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'Downloaded file does not exist');
        }

        $file_size = filesize($file_path);
        if ($file_size < 100) {
            $content = file_get_contents($file_path);
            $json_data = @json_decode($content, true);
            if ($json_data !== null) {
                $error_message = 'Download failed: Server returned JSON error';
                if (isset($json_data['error'])) {
                    $error_message .= ' - ' . $json_data['error'];
                }
                return new WP_Error('invalid_response', $error_message);
            }
            return new WP_Error('file_too_small', 'Downloaded file is too small');
        }

        // Check ZIP signature
        $file_handle = fopen($file_path, 'rb');
        if ($file_handle === false) {
            return new WP_Error('file_read_error', 'Cannot read downloaded file');
        }

        $magic_bytes = fread($file_handle, 4);
        fclose($file_handle);

        $valid_signatures = array(
            "\x50\x4b\x03\x04",
            "\x50\x4b\x05\x06",
            "\x50\x4b\x07\x08"
        );

        $is_valid = false;
        foreach ($valid_signatures as $signature) {
            if (strpos($magic_bytes, $signature) === 0) {
                $is_valid = true;
                break;
            }
        }

        if (!$is_valid) {
            error_log('CribOps WP-Kit: Invalid ZIP signature for theme');
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive');
        }

        return true;
    }

    /**
     * Extend timeout for large downloads
     */
    public function extend_timeout($timeout) {
        return 300;
    }

    /**
     * AJAX handler for manifest display
     */
    public function ajax_get_manifest() {
        check_ajax_referer('cwpk_theme_nonce', 'security');

        $manifest = $this->get_theme_manifest();

        if (is_wp_error($manifest)) {
            wp_send_json_error($manifest->get_error_message());
        }

        wp_send_json_success($manifest);
    }

    /**
     * AJAX handler for theme download
     */
    public function ajax_download_theme() {
        check_ajax_referer('cwpk_theme_nonce', 'security');

        $theme_data = isset($_POST['theme_data']) ? $_POST['theme_data'] : array();

        if (empty($theme_data) || !isset($theme_data['slug'])) {
            wp_send_json_error('No theme specified');
        }

        $result = $this->download_theme($theme_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for theme installation
     */
    public function ajax_install_theme() {
        check_ajax_referer('cwpk_theme_nonce', 'security');

        $theme_slug = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';

        if (!$theme_slug) {
            wp_send_json_error('No theme specified');
        }

        // Check if file exists in download directory
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/themes';
        $file_path = $target_dir . '/' . $theme_slug . '.zip';

        // If file doesn't exist, try to download it first
        if (!file_exists($file_path)) {
            $manifest = $this->get_theme_manifest();
            if (is_wp_error($manifest)) {
                wp_send_json_error('Failed to get theme list: ' . $manifest->get_error_message());
            }

            $theme_data = null;
            foreach ($manifest as $theme) {
                if ($theme['slug'] === $theme_slug) {
                    $theme_data = $theme;
                    break;
                }
            }

            if (!$theme_data) {
                wp_send_json_error('Theme not found in repository');
            }

            $download_result = $this->download_theme($theme_data);
            if (is_wp_error($download_result)) {
                wp_send_json_error('Failed to download theme: ' . $download_result->get_error_message());
            }

            $file_path = isset($download_result['file']) ? $download_result['file'] : $target_dir . '/' . $theme_slug . '.zip';
        }

        // Validate ZIP before installation
        $validation_result = $this->validate_zip_file($file_path);
        if (is_wp_error($validation_result)) {
            wp_send_json_error('Invalid theme file: ' . $validation_result->get_error_message());
        }

        // Initialize WP_Filesystem
        WP_Filesystem();

        // Remove existing theme if present
        $theme_dir = get_theme_root() . '/' . $theme_slug;
        if (is_dir($theme_dir)) {
            $this->delete_directory($theme_dir);
        }

        // Unzip the theme
        error_log('CribOps WP-Kit: Installing theme from: ' . $file_path);
        $result = unzip_file($file_path, get_theme_root());

        if (is_wp_error($result)) {
            error_log('CribOps WP-Kit: Theme installation failed: ' . $result->get_error_message());
            wp_send_json_error('Installation failed: ' . $result->get_error_message());
        }

        error_log('CribOps WP-Kit: Successfully installed theme: ' . $theme_slug);
        wp_send_json_success('Theme installed successfully');
    }

    /**
     * AJAX handler for theme activation
     */
    public function ajax_activate_theme() {
        check_ajax_referer('cwpk_theme_nonce', 'security');

        $theme_slug = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';

        if (!$theme_slug) {
            wp_send_json_error('No theme specified');
        }

        // Verify theme exists
        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            wp_send_json_error('Theme not found');
        }

        // Activate the theme
        switch_theme($theme_slug);

        wp_send_json_success('Theme activated successfully');
    }

    /**
     * AJAX handler for theme deletion
     */
    public function ajax_delete_theme() {
        check_ajax_referer('cwpk_theme_nonce', 'security');

        $theme_slug = isset($_POST['theme']) ? sanitize_text_field($_POST['theme']) : '';

        if (!$theme_slug) {
            wp_send_json_error('No theme specified');
        }

        // Can't delete active theme
        $current_theme = wp_get_theme();
        if ($current_theme->get_stylesheet() === $theme_slug) {
            wp_send_json_error('Cannot delete active theme. Please activate a different theme first.');
        }

        // Delete the theme
        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            wp_send_json_error('Theme not found');
        }

        $result = delete_theme($theme_slug);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Theme deleted successfully');
    }

    /**
     * Delete directory recursively
     */
    private function delete_directory($dir) {
        global $wp_filesystem;

        if (!is_dir($dir)) {
            return true;
        }

        if (!$wp_filesystem) {
            WP_Filesystem();
        }

        if ($wp_filesystem && method_exists($wp_filesystem, 'delete')) {
            return $wp_filesystem->delete($dir, true);
        }

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
     * Display theme installer UI
     */
    public function display_theme_installer() {
        $user_data = get_transient('lk_user_data');

        if (!$user_data) {
            echo '<p>Please log in to view available themes.</p>';
            return;
        }

        ?>
        <div id="cwpk-theme-installer">
            <h3>Available Themes</h3>
            <p>
                <button type="button" class="button" id="cwpk-refresh-themes">Refresh List</button>
                <button type="button" class="button button-primary" id="cwpk-download-selected-themes">Download Selected</button>
                <button type="button" class="button" id="cwpk-install-downloaded-themes">Install Downloaded Themes</button>
                <span id="cwpk-theme-status"></span>
            </p>

            <div class="cwpk-table-wrapper">
                <table class="wp-list-table widefat fixed striped cwpk-themes-table">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="cwpk-select-all-themes" /></th>
                            <th>Theme</th>
                            <th>Author</th>
                            <th>Version</th>
                            <th>File Size</th>
                            <th>PHP Required</th>
                            <th>Tested Up To</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cwpk-theme-list">
                        <tr><td colspan="9">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(function($) {
            var themeInstaller = {
                themes: [],

                init: function() {
                    this.loadManifest();
                    this.bindEvents();
                },

                bindEvents: function() {
                    $('#cwpk-refresh-themes').on('click', this.loadManifest.bind(this));
                    $('#cwpk-download-selected-themes').on('click', this.downloadSelected.bind(this));
                    $('#cwpk-install-downloaded-themes').on('click', this.installDownloaded.bind(this));
                    $('#cwpk-select-all-themes').on('change', this.toggleSelectAll.bind(this));
                    $(document).on('click', '.cwpk-download-theme', this.downloadSingle.bind(this));
                    $(document).on('click', '.cwpk-install-theme', this.installTheme.bind(this));
                    $(document).on('click', '.cwpk-activate-theme', this.activateTheme.bind(this));
                    $(document).on('click', '.cwpk-delete-theme', this.deleteTheme.bind(this));
                },

                loadManifest: function() {
                    $('#cwpk-theme-status').text('Loading theme list...');

                    $.post(ajaxurl, {
                        action: 'cwpk_get_theme_manifest',
                        security: '<?php echo wp_create_nonce('cwpk_theme_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            themeInstaller.themes = response.data;
                            themeInstaller.renderThemes();
                            $('#cwpk-theme-status').text('');
                        } else {
                            $('#cwpk-theme-status').text('Error: ' + response.data);
                        }
                    });
                },

                renderThemes: function() {
                    var html = '';

                    if (this.themes.length === 0) {
                        html = '<tr><td colspan="9">No themes available</td></tr>';
                    } else {
                        $.each(this.themes, function(i, theme) {
                            var statusClass = '';
                            var statusText = '';
                            var actions = '';

                            if (theme.status === 'active') {
                                statusClass = 'cwpk-status-active';
                                statusText = '<span class="dashicons dashicons-yes-alt"></span> Active';
                                actions = '<button class="button button-small cwpk-delete-theme" data-theme="' + theme.slug + '">Delete</button>';
                            } else if (theme.status === 'inactive') {
                                statusClass = 'cwpk-status-inactive';
                                statusText = '<span class="dashicons dashicons-minus"></span> Inactive';
                                actions = '<button class="button button-small cwpk-activate-theme" data-theme="' + theme.slug + '">Activate</button>' +
                                        ' <button class="button button-small cwpk-delete-theme" data-theme="' + theme.slug + '">Delete</button>';
                            } else if (theme.status === 'downloaded' || theme.local) {
                                statusClass = 'cwpk-status-downloaded';
                                statusText = '<span class="dashicons dashicons-download"></span> Downloaded';
                                actions = '<button class="button button-small cwpk-install-theme" data-theme="' + theme.slug + '">Install</button>';
                            } else {
                                statusClass = 'cwpk-status-available';
                                statusText = '<span class="dashicons dashicons-cloud"></span> Available';
                                actions = '<button class="button button-small cwpk-download-theme" data-theme-index="' + i + '">Download</button>';
                            }

                            var description = theme.description ? '<div class="cwpk-theme-description">' + theme.description + '</div>' : '';
                            var thumbnail = theme.thumbnail_url ? '<div class="cwpk-theme-thumbnail"><img src="' + theme.thumbnail_url + '" alt="' + theme.name + '"></div>' : '';

                            html += '<tr class="cwpk-theme-row" data-theme-index="' + i + '">';
                            html += '<td><input type="checkbox" class="cwpk-theme-check" value="' + i + '" ' + (theme.status === 'active' ? 'disabled' : '') + '/></td>';
                            html += '<td class="cwpk-theme-name">' + thumbnail + '<strong>' + (theme.name || theme.slug) + '</strong>' + description + '</td>';
                            html += '<td>' + (theme.author || '-') + '</td>';
                            html += '<td>' + (theme.version || '-') + '</td>';
                            html += '<td>' + (theme.file_size || '-') + '</td>';
                            html += '<td>' + (theme.php_required || '-') + '</td>';
                            html += '<td>' + (theme.tested_up_to || '-') + '</td>';
                            html += '<td class="cwpk-status ' + statusClass + '">' + statusText + '</td>';
                            html += '<td>' + actions + '</td>';
                            html += '</tr>';
                        });
                    }

                    $('#cwpk-theme-list').html(html);
                },

                downloadSingle: function(e) {
                    e.preventDefault();
                    var button = $(e.target);
                    var themeIndex = button.data('theme-index');
                    var theme = this.themes[themeIndex];

                    if (!theme) {
                        alert('Theme data not found');
                        return;
                    }

                    button.text('Downloading...').prop('disabled', true);
                    var statusCell = button.closest('tr').find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Downloading...');

                    $.post(ajaxurl, {
                        action: 'cwpk_download_theme',
                        theme_data: theme,
                        security: '<?php echo wp_create_nonce('cwpk_theme_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            theme.local = true;
                            theme.status = 'downloaded';
                            statusCell.html('<span class="dashicons dashicons-download"></span> Downloaded');
                            button.replaceWith('<button class="button button-small cwpk-install-theme" data-theme="' + theme.slug + '">Install</button>');
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

                installTheme: function(e) {
                    e.preventDefault();
                    var self = this;
                    var button = $(e.target);
                    var theme = button.data('theme');
                    var row = button.closest('tr');

                    button.text('Installing...').prop('disabled', true);
                    var statusCell = row.find('.cwpk-status');
                    statusCell.html('<span class="spinner is-active"></span> Installing...');

                    $.post(ajaxurl, {
                        action: 'cwpk_install_theme',
                        theme: theme,
                        security: '<?php echo wp_create_nonce('cwpk_theme_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            statusCell.html('<span class="dashicons dashicons-minus"></span> Inactive');
                            statusCell.removeClass('cwpk-status-downloaded').addClass('cwpk-status-inactive');
                            button.replaceWith('<button class="button button-small cwpk-activate-theme" data-theme="' + theme + '">Activate</button>' +
                                ' <button class="button button-small cwpk-delete-theme" data-theme="' + theme + '">Delete</button>');
                        } else {
                            button.text('Install').prop('disabled', false);
                            statusCell.html('<span class="dashicons dashicons-warning"></span> Error');
                            alert('Installation failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Install').prop('disabled', false);
                        alert('Installation failed. Please try again.');
                    });
                },

                activateTheme: function(e) {
                    e.preventDefault();
                    var button = $(e.target);
                    var theme = button.data('theme');

                    if (!confirm('Are you sure you want to activate this theme? This will change your site\'s appearance.')) {
                        return;
                    }

                    button.text('Activating...').prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'cwpk_activate_theme',
                        theme: theme,
                        security: '<?php echo wp_create_nonce('cwpk_theme_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            button.text('Activate').prop('disabled', false);
                            alert('Activation failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Activate').prop('disabled', false);
                        alert('Activation failed. Please try again.');
                    });
                },

                deleteTheme: function(e) {
                    e.preventDefault();

                    if (!confirm('Are you sure you want to delete this theme? This action cannot be undone.')) {
                        return;
                    }

                    var button = $(e.target);
                    var theme = button.data('theme');

                    button.text('Deleting...').prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'cwpk_delete_theme',
                        theme: theme,
                        security: '<?php echo wp_create_nonce('cwpk_theme_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            button.text('Delete').prop('disabled', false);
                            alert('Deletion failed: ' + response.data);
                        }
                    }).fail(function() {
                        button.text('Delete').prop('disabled', false);
                        alert('Deletion failed. Please try again.');
                    });
                },

                downloadSelected: function() {
                    var selected = $('.cwpk-theme-check:checked:not(:disabled)');

                    if (selected.length === 0) {
                        alert('Please select themes to download');
                        return;
                    }

                    selected.each(function() {
                        var themeIndex = $(this).val();
                        var button = $('.cwpk-theme-row[data-theme-index="' + themeIndex + '"] .cwpk-download-theme');
                        if (button.length) {
                            button.click();
                        }
                    });
                },

                installDownloaded: function() {
                    $('.cwpk-install-theme').each(function() {
                        $(this).click();
                    });
                },

                toggleSelectAll: function(e) {
                    $('.cwpk-theme-check:not(:disabled)').prop('checked', e.target.checked);
                }
            };

            themeInstaller.init();
        });
        </script>
        <style>
            .cwpk-themes-table {
                min-width: 1000px;
            }
            .cwpk-theme-description {
                font-size: 12px;
                color: #666;
                margin-top: 4px;
                line-height: 1.4;
                max-width: 400px;
            }
            .cwpk-theme-thumbnail {
                max-width: 150px;
                margin-bottom: 8px;
            }
            .cwpk-theme-thumbnail img {
                max-width: 100%;
                height: auto;
                border: 1px solid #ddd;
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
            .cwpk-delete-theme {
                color: #a00;
            }
            .cwpk-delete-theme:hover {
                color: #dc3232;
                border-color: #dc3232;
            }
        </style>
        <?php
    }
}

// Initialize AJAX handlers
if (is_admin()) {
    $cwpk_theme_manager = new CWPK_Theme_Manager();

    add_action('wp_ajax_cwpk_get_theme_manifest', array($cwpk_theme_manager, 'ajax_get_manifest'));
    add_action('wp_ajax_cwpk_download_theme', array($cwpk_theme_manager, 'ajax_download_theme'));
    add_action('wp_ajax_cwpk_install_theme', array($cwpk_theme_manager, 'ajax_install_theme'));
    add_action('wp_ajax_cwpk_activate_theme', array($cwpk_theme_manager, 'ajax_activate_theme'));
    add_action('wp_ajax_cwpk_delete_theme', array($cwpk_theme_manager, 'ajax_delete_theme'));
}
