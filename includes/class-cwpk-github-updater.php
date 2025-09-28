<?php
/**
 * CWPKGitHubUpdater Class
 *
 * Handles plugin updates directly from GitHub releases
 *
 * @since 1.0.3
 */
class CWPKGitHubUpdater {

    private $github_username = 'CloudBedrock';  // Your GitHub username
    private $github_repo = 'cribops-wp-kit';    // Your GitHub repository name

    private $current_version;
    private $plugin_slug;
    private $plugin_basename;
    private $github_data;

    /**
     * Initialize the GitHub updater
     */
    public function __construct() {
        $plugin_file = plugin_dir_path(dirname(__FILE__)) . 'cribops-wp-kit.php';
        $plugin_data = get_file_data($plugin_file, array('Version' => 'Version'), false);

        $this->current_version = $plugin_data['Version'];
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug = dirname($this->plugin_basename);

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_plugin_folder'), 10, 3);
    }

    /**
     * Check GitHub for updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $github_data = $this->get_github_release();

        if ($github_data && version_compare($this->current_version, $github_data->tag_name, '<')) {
            $plugin_data = array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => str_replace('v', '', $github_data->tag_name),
                'url' => "https://github.com/{$this->github_username}/{$this->github_repo}",
                'package' => $this->get_download_url($github_data),
                'icons' => array(
                    'default' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo_light.svg'
                ),
                'tested' => '6.7.1',
                'requires' => '5.8',
                'requires_php' => '7.4'
            );

            $transient->response[$this->plugin_basename] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Get plugin info for WordPress plugin modal
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $github_data = $this->get_github_release();

        if (!$github_data) {
            return $result;
        }

        $plugin_info = array(
            'name' => 'CribOps WP-Kit',
            'slug' => $this->plugin_slug,
            'version' => str_replace('v', '', $github_data->tag_name),
            'author' => '<a href="https://cloudbedrock.com">CloudBedrock</a>',
            'author_profile' => 'https://github.com/' . $this->github_username,
            'homepage' => "https://github.com/{$this->github_username}/{$this->github_repo}",
            'download_link' => $this->get_download_url($github_data),
            'sections' => array(
                'description' => 'Comprehensive WordPress plugin management, license handling, and rapid site deployment toolkit.',
                'changelog' => $this->parse_changelog($github_data->body)
            ),
            'banners' => array(
                'low' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/banner-772x250.jpg',
                'high' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/banner-1544x500.jpg'
            ),
            'icons' => array(
                '1x' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/icon-128x128.png',
                '2x' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/icon-256x256.png',
                'svg' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo_light.svg'
            ),
            'tested' => '6.7.1',
            'requires' => '5.8',
            'requires_php' => '7.4',
            'last_updated' => $github_data->published_at
        );

        return (object) $plugin_info;
    }

    /**
     * Fix the plugin folder name after update
     */
    public function fix_plugin_folder($source, $remote_source, $upgrader) {
        global $wp_filesystem;

        if (!isset($upgrader->skin->plugin_info) || $upgrader->skin->plugin_info['Name'] !== 'CribOps WP-Kit') {
            return $source;
        }

        $corrected_source = trailingslashit($remote_source) . $this->plugin_slug . '/';

        if ($wp_filesystem->move($source, $corrected_source)) {
            return $corrected_source;
        }

        return $source;
    }

    /**
     * Get latest release from GitHub API
     */
    private function get_github_release() {
        if ($this->github_data) {
            return $this->github_data;
        }

        // Check transient first
        $transient_key = 'cwpk_github_release';
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            $this->github_data = $cached;
            return $cached;
        }

        // Fetch from GitHub API
        $api_url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";

        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data->tag_name)) {
            return false;
        }

        // Cache for 6 hours
        set_transient($transient_key, $data, 6 * HOUR_IN_SECONDS);
        $this->github_data = $data;

        return $data;
    }

    /**
     * Get download URL from release data
     */
    private function get_download_url($release_data) {
        if (!empty($release_data->assets)) {
            foreach ($release_data->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback to zipball URL if no assets
        return $release_data->zipball_url;
    }

    /**
     * Parse changelog from release body
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return '<p>No changelog available.</p>';
        }

        // Convert markdown to HTML (basic conversion)
        $changelog = wpautop($body);
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $changelog);

        return $changelog;
    }
}

// Initialize the updater
add_action('init', function() {
    if (is_admin()) {
        new CWPKGitHubUpdater();
    }
});