<?php
/**
 * CribOps WP Kit MainWP Child Integration
 *
 * Handles communication with MainWP Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class CWPK_MainWP_Child {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into MainWP Child plugin if it's active
        add_filter('mainwp_child_extra_execution', array($this, 'handle_mainwp_request'), 10, 2);

        // Add CribOps data to sync
        add_filter('mainwp_site_sync_others_data', array($this, 'sync_cribops_data'), 10, 2);

        // Register available functions with MainWP
        add_filter('mainwp_child_callable_functions', array($this, 'register_callable_functions'));

        // Add status reporting
        add_filter('mainwp_child_reports_data', array($this, 'add_reports_data'), 10, 2);
    }

    /**
     * Handle MainWP Dashboard requests
     *
     * @param array $information The information array to be returned
     * @param array $post The POST data from MainWP Dashboard
     */
    public function handle_mainwp_request($information, $post) {
        // The action comes in the $post array, not $information
        if (!isset($post['action'])) {
            return $information;
        }

        // Check if this is a CribOps action
        if (strpos($post['action'], 'cribops_') !== 0) {
            return $information;
        }

        // Get the specific action from POST data
        $action = str_replace('cribops_', '', $post['action']);
        $args = isset($post['args']) ? $post['args'] : array();

        // Handle the action and merge result into information array
        $result = array();

        switch ($action) {
            case 'get_status':
                $result = $this->get_plugin_status();
                break;

            case 'get_settings':
                $result = $this->get_plugin_settings();
                break;

            case 'update_settings':
                $result = $this->update_plugin_settings($args);
                break;

            case 'get_available_themes':
                $result = $this->get_available_themes();
                break;

            case 'get_available_packages':
                $result = $this->get_available_packages();
                break;

            case 'get_installed_plugins':
                $result = $this->get_installed_plugins_list();
                break;

            case 'manage_licenses':
                $result = $this->manage_license_keys($args);
                break;

            case 'get_logs':
                $result = $this->get_activity_logs();
                break;

            case 'run_bulk_install':
                $result = $this->run_bulk_installation($args);
                break;

            case 'sync':
                $result = $this->sync_with_dashboard();
                // Log activity
                if (isset($result['cribops_data'])) {
                    self::log_activity('mainwp_sync', 'Synced with MainWP Dashboard');
                }
                break;

            // New enhanced plugin/theme management actions
            case 'get_available_plugins':
                $result = $this->get_available_plugins();
                break;

            case 'get_installed_themes':
                $result = $this->get_installed_themes_list();
                break;

            case 'install_single_plugin':
                $result = $this->install_single_plugin($args);
                break;

            case 'activate_plugin':
                $result = $this->activate_plugin($args);
                break;

            case 'deactivate_plugin':
                $result = $this->deactivate_plugin($args);
                break;

            case 'delete_plugin':
                $result = $this->delete_plugin($args);
                break;

            case 'install_theme':
                $result = $this->install_theme($args);
                break;

            case 'activate_theme':
                $result = $this->activate_theme($args);
                break;

            case 'delete_theme':
                $result = $this->delete_theme($args);
                break;

            case 'get_plugin_details':
                $result = $this->get_plugin_details($args);
                break;

            default:
                $result = array('error' => 'Unknown CribOps action: ' . $action);
        }

        // Merge our result into the information array
        if (!empty($result)) {
            $information = array_merge($information, $result);
        }

        return $information;
    }

    /**
     * Get CribOps WP Kit status
     */
    private function get_plugin_status() {
        $status = array(
            'installed' => true,
            'version' => defined('WPLK_VERSION') ? WPLK_VERSION : (class_exists('CribOpsWPKit') ? CribOpsWPKit::VERSION : '1.1.6'),
            'active' => true,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'settings' => get_option('cwpk_settings', array()),
            'last_activity' => get_option('cwpk_last_activity', ''),
            'authenticated' => get_transient('lk_logged_in') ? true : false
        );

        // Check authentication status
        $auth_type = get_option('cwpk_auth_type', 'email');
        $status['auth_type'] = $auth_type;

        if ($auth_type === 'bearer') {
            $status['bearer_token_set'] = !empty(get_option('cwpk_bearer_token', ''));
        }

        return array('status' => $status);
    }

    /**
     * Get plugin settings
     */
    private function get_plugin_settings() {
        $settings = get_option('cwpk_settings', array());
        $auth_settings = array(
            'auth_type' => get_option('cwpk_auth_type', 'email'),
            'api_endpoint' => get_option('cwpk_api_endpoint', 'https://cribops.cloudbedrock.com/api/wp-kit/v1/')
        );

        return array(
            'settings' => $settings,
            'auth_settings' => $auth_settings
        );
    }

    /**
     * Update plugin settings
     */
    private function update_plugin_settings($args) {
        if (!isset($args['settings'])) {
            return array('error' => 'No settings provided');
        }

        $settings = $args['settings'];

        // Update main settings
        if (isset($settings['cwpk_settings'])) {
            update_option('cwpk_settings', $settings['cwpk_settings']);
        }

        // Update auth settings
        if (isset($settings['auth_type'])) {
            update_option('cwpk_auth_type', sanitize_text_field($settings['auth_type']));
        }

        if (isset($settings['api_endpoint'])) {
            update_option('cwpk_api_endpoint', esc_url_raw($settings['api_endpoint']));
        }

        if (isset($settings['bearer_token'])) {
            update_option('cwpk_bearer_token', sanitize_text_field($settings['bearer_token']));
        }

        return array('success' => true, 'message' => 'Settings updated successfully');
    }

    /**
     * Get available themes from CribOps repository
     * Reuses the existing CWPK_Theme_Manager class that the child site already uses
     */
    private function get_available_themes() {
        if (!class_exists('CWPK_Theme_Manager')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cwpk-theme-manager.php';
        }

        $theme_manager = new CWPK_Theme_Manager();
        $themes = $theme_manager->get_theme_manifest();

        if (is_wp_error($themes)) {
            return array('error' => 'Failed to fetch themes: ' . $themes->get_error_message());
        }

        return array('themes' => $themes);
    }

    /**
     * Get available packages from CribOps repository
     * Packages are stored in the lk_user_data transient after login
     */
    private function get_available_packages() {
        $user_data = get_transient('lk_user_data');

        if (!$user_data || !isset($user_data['packages'])) {
            return array('packages' => array());
        }

        return array('packages' => $user_data['packages']);
    }

    /**
     * Get installed plugins list
     */
    private function get_installed_plugins_list() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $plugins_list = array();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugins_list[] = array(
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'author' => $plugin_data['Author'],
                'active' => in_array($plugin_file, $active_plugins)
            );
        }

        return array('plugins' => $plugins_list);
    }

    /**
     * Manage license keys
     */
    private function manage_license_keys($args) {
        if (!class_exists('CWPK_License_Loader')) {
            return array('error' => 'License loader not available');
        }

        $action = isset($args['license_action']) ? $args['license_action'] : 'get';

        switch ($action) {
            case 'get':
                // Get current license keys
                $licenses = get_option('cwpk_license_keys', array());
                return array('licenses' => $licenses);

            case 'set':
                // Set license keys
                if (!isset($args['licenses'])) {
                    return array('error' => 'No licenses provided');
                }

                $licenses = $args['licenses'];
                update_option('cwpk_license_keys', $licenses);

                // Trigger license loader to apply keys
                $loader = new CWPK_License_Loader();
                $loader->auto_load_licenses();

                return array('success' => true, 'message' => 'Licenses updated');

            case 'activate':
                // Activate specific license
                if (!isset($args['plugin']) || !isset($args['license_key'])) {
                    return array('error' => 'Plugin and license key required');
                }

                // Implementation depends on specific plugin
                return array('success' => true, 'message' => 'License activation attempted');

            default:
                return array('error' => 'Invalid license action');
        }
    }

    /**
     * Get activity logs
     */
    private function get_activity_logs() {
        $logs = get_option('cwpk_activity_logs', array());

        // Limit to last 100 entries
        $logs = array_slice($logs, -100);

        return array('logs' => $logs);
    }

    /**
     * Run bulk installation
     */
    private function run_bulk_installation($args) {
        if (!class_exists('CWPK_Manager')) {
            return array('error' => 'Manager class not available');
        }

        $manifest_id = isset($args['manifest_id']) ? $args['manifest_id'] : '';

        if (empty($manifest_id)) {
            return array('error' => 'No manifest ID provided');
        }

        // Get the manifest data
        $manifests = get_option('cwpk_manifests', array());

        if (!isset($manifests[$manifest_id])) {
            return array('error' => 'Manifest not found');
        }

        $manifest = $manifests[$manifest_id];
        $manager = new CWPK_Manager();

        // Run the installation
        $result = $manager->install_from_manifest($manifest);

        return array(
            'success' => $result['success'],
            'message' => $result['message'],
            'installed' => $result['installed'],
            'failed' => $result['failed']
        );
    }

    /**
     * Sync with MainWP Dashboard
     */
    private function sync_with_dashboard() {
        // Determine actual auth method being used
        $bearer_token = CWPKAuth::get_env_bearer_token();
        $using_bearer = !empty($bearer_token);

        // Get actual API URL
        $api_url = CWPKConfig::get_api_url();

        return array(
            'cribops_data' => array(
                'cribops_installed' => true,
                'cribops_version' => defined('WPLK_VERSION') ? WPLK_VERSION : (class_exists('CribOpsWPKit') ? CribOpsWPKit::VERSION : '1.1.8'),
                'cribops_active' => true,
                'last_sync' => current_time('mysql'),
                'settings' => get_option('cwpk_settings', array()),
                'auth_status' => get_transient('lk_logged_in') ? 'authenticated' : 'not_authenticated',
                'repository_info' => array(
                    'api_url' => $api_url,
                    'using_bearer' => $using_bearer,
                    'configured' => true
                )
            )
        );
    }

    /**
     * Add CribOps data to sync
     */
    public function sync_cribops_data($information, $data) {
        // Determine actual auth method being used
        $bearer_token = CWPKAuth::get_env_bearer_token();
        $using_bearer = !empty($bearer_token);

        // Get actual API URL
        $api_url = CWPKConfig::get_api_url();

        $information['cribops_data'] = array(
            'cribops_installed' => true,
            'cribops_version' => defined('WPLK_VERSION') ? WPLK_VERSION : CribOpsWPKit::VERSION,
            'cribops_active' => true,
            'settings_count' => count(get_option('cwpk_settings', array())),
            'authenticated' => get_transient('lk_logged_in') ? true : false,
            'repository_info' => array(
                'api_url' => $api_url,
                'using_bearer' => $using_bearer,
                'configured' => true
            )
        );

        return $information;
    }

    /**
     * Register callable functions with MainWP
     */
    public function register_callable_functions($functions) {
        $cribops_functions = array(
            'cribops_get_status',
            'cribops_get_settings',
            'cribops_update_settings',
            'cribops_get_installed_plugins',
            'cribops_manage_licenses',
            'cribops_get_logs',
            'cribops_run_bulk_install',
            'cribops_sync',
            // Enhanced functions
            'cribops_get_available_plugins',
            'cribops_get_available_themes',
            'cribops_get_available_packages',
            'cribops_get_installed_themes',
            'cribops_install_single_plugin',
            'cribops_activate_plugin',
            'cribops_deactivate_plugin',
            'cribops_delete_plugin',
            'cribops_install_theme',
            'cribops_activate_theme',
            'cribops_delete_theme',
            'cribops_get_plugin_details'
        );

        return array_merge($functions, $cribops_functions);
    }

    /**
     * Add reports data for MainWP Pro Reports
     */
    public function add_reports_data($data, $website) {
        $data['cribops'] = array(
            'plugin_installs' => get_option('cwpk_install_count', 0),
            'last_activity' => get_option('cwpk_last_activity', ''),
            'active_licenses' => count(get_option('cwpk_license_keys', array()))
        );

        return $data;
    }

    /**
     * Log activity
     */
    public static function log_activity($action, $details = '') {
        $logs = get_option('cwpk_activity_logs', array());

        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'action' => $action,
            'details' => $details,
            'user' => wp_get_current_user()->user_login
        );

        // Keep only last 500 entries
        $logs = array_slice($logs, -500);

        update_option('cwpk_activity_logs', $logs);
        update_option('cwpk_last_activity', current_time('mysql'));
    }

    /**
     * Get available plugins from CribOps repository
     * Reuses the existing CWPK_Manifest_Installer class that the child site already uses
     */
    private function get_available_plugins() {
        if (!class_exists('CWPK_Manifest_Installer')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cwpk-manifest-installer.php';
        }

        $manifest_installer = new CWPK_Manifest_Installer();
        $plugins = $manifest_installer->get_plugin_manifest();

        if (is_wp_error($plugins)) {
            return array('error' => 'Failed to fetch plugins: ' . $plugins->get_error_message());
        }

        return array('plugins' => $plugins);
    }

    /**
     * Get installed themes list
     */
    private function get_installed_themes_list() {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();

        $themes_list = array();
        foreach ($all_themes as $theme_slug => $theme) {
            $themes_list[] = array(
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'author' => $theme->get('Author'),
                'active' => ($active_theme->get_stylesheet() === $theme_slug),
                'parent_theme' => $theme->parent() ? $theme->parent()->get('Name') : '',
                'screenshot' => $theme->get_screenshot()
            );
        }

        return array('themes' => $themes_list);
    }

    /**
     * Install a single plugin
     */
    private function install_single_plugin($args) {
        if (!isset($args['plugin_slug'])) {
            return array('error' => 'No plugin slug provided');
        }

        $plugin_slug = sanitize_text_field($args['plugin_slug']);
        $activate = isset($args['activate']) ? (bool)$args['activate'] : false;

        // Include necessary files
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!class_exists('WP_Ajax_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
        }

        // Try to get plugin info from WordPress.org
        $api = plugins_api('plugin_information', array(
            'slug' => $plugin_slug,
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
                'compatibility' => false,
                'homepage' => false,
                'donate_link' => false,
            ),
        ));

        if (is_wp_error($api)) {
            // Try CribOps repository
            return $this->install_from_cribops_repo($plugin_slug, $activate);
        }

        // Install the plugin
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            return array('error' => 'Installation failed: ' . $result->get_error_message());
        }

        // Get the installed plugin file
        $plugin_file = $this->get_plugin_file($plugin_slug);

        if ($activate && $plugin_file) {
            $activated = activate_plugin($plugin_file);
            if (is_wp_error($activated)) {
                return array(
                    'success' => true,
                    'installed' => true,
                    'activated' => false,
                    'message' => 'Plugin installed but activation failed: ' . $activated->get_error_message()
                );
            }
        }

        self::log_activity('plugin_install', 'Installed plugin: ' . $plugin_slug);

        return array(
            'success' => true,
            'installed' => true,
            'activated' => $activate,
            'plugin_file' => $plugin_file,
            'message' => 'Plugin installed successfully'
        );
    }

    /**
     * Install from CribOps repository
     */
    private function install_from_cribops_repo($plugin_slug, $activate = false) {
        // Check if we have a CWPK_Installer class
        if (class_exists('CWPK_Installer')) {
            $installer = new CWPK_Installer();
            $result = $installer->install_plugin($plugin_slug);

            if ($result && $activate) {
                $plugin_file = $this->get_plugin_file($plugin_slug);
                if ($plugin_file) {
                    activate_plugin($plugin_file);
                }
            }

            return array(
                'success' => $result,
                'installed' => $result,
                'activated' => $activate && $result,
                'message' => $result ? 'Plugin installed from CribOps repository' : 'Installation failed'
            );
        }

        return array('error' => 'CribOps installer not available');
    }

    /**
     * Get plugin file from slug
     */
    private function get_plugin_file($plugin_slug) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $plugin_slug . '/') === 0) {
                return $plugin_file;
            }
        }

        // Try with .php extension
        $possible_file = $plugin_slug . '/' . $plugin_slug . '.php';
        if (isset($all_plugins[$possible_file])) {
            return $possible_file;
        }

        return false;
    }

    /**
     * Activate a plugin
     */
    private function activate_plugin($args) {
        if (!isset($args['plugin_file'])) {
            return array('error' => 'No plugin file provided');
        }

        $plugin_file = sanitize_text_field($args['plugin_file']);

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return array('error' => 'Activation failed: ' . $result->get_error_message());
        }

        self::log_activity('plugin_activate', 'Activated plugin: ' . $plugin_file);

        return array(
            'success' => true,
            'message' => 'Plugin activated successfully'
        );
    }

    /**
     * Deactivate a plugin
     */
    private function deactivate_plugin($args) {
        if (!isset($args['plugin_file'])) {
            return array('error' => 'No plugin file provided');
        }

        $plugin_file = sanitize_text_field($args['plugin_file']);

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin_file);

        self::log_activity('plugin_deactivate', 'Deactivated plugin: ' . $plugin_file);

        return array(
            'success' => true,
            'message' => 'Plugin deactivated successfully'
        );
    }

    /**
     * Delete a plugin
     */
    private function delete_plugin($args) {
        if (!isset($args['plugin_file'])) {
            return array('error' => 'No plugin file provided');
        }

        $plugin_file = sanitize_text_field($args['plugin_file']);

        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Deactivate first if active
        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }

        $result = delete_plugins(array($plugin_file));

        if (is_wp_error($result)) {
            return array('error' => 'Deletion failed: ' . $result->get_error_message());
        }

        self::log_activity('plugin_delete', 'Deleted plugin: ' . $plugin_file);

        return array(
            'success' => true,
            'message' => 'Plugin deleted successfully'
        );
    }

    /**
     * Install a theme
     */
    private function install_theme($args) {
        if (!isset($args['theme_slug'])) {
            return array('error' => 'No theme slug provided');
        }

        $theme_slug = sanitize_text_field($args['theme_slug']);
        $activate = isset($args['activate']) ? (bool)$args['activate'] : false;

        if (!class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        if (!class_exists('WP_Ajax_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
        }

        // Try to install from WordPress.org
        $api = themes_api('theme_information', array(
            'slug' => $theme_slug,
        ));

        if (is_wp_error($api)) {
            return array('error' => 'Theme not found: ' . $api->get_error_message());
        }

        $upgrader = new Theme_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            return array('error' => 'Installation failed: ' . $result->get_error_message());
        }

        if ($activate) {
            switch_theme($theme_slug);
        }

        self::log_activity('theme_install', 'Installed theme: ' . $theme_slug);

        return array(
            'success' => true,
            'installed' => true,
            'activated' => $activate,
            'message' => 'Theme installed successfully'
        );
    }

    /**
     * Activate a theme
     */
    private function activate_theme($args) {
        if (!isset($args['theme_slug'])) {
            return array('error' => 'No theme slug provided');
        }

        $theme_slug = sanitize_text_field($args['theme_slug']);

        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            return array('error' => 'Theme not found');
        }

        switch_theme($theme_slug);

        self::log_activity('theme_activate', 'Activated theme: ' . $theme_slug);

        return array(
            'success' => true,
            'message' => 'Theme activated successfully'
        );
    }

    /**
     * Delete a theme
     */
    private function delete_theme($args) {
        if (!isset($args['theme_slug'])) {
            return array('error' => 'No theme slug provided');
        }

        $theme_slug = sanitize_text_field($args['theme_slug']);

        if (!function_exists('delete_theme')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        // Can't delete active theme
        $active_theme = wp_get_theme();
        if ($active_theme->get_stylesheet() === $theme_slug) {
            return array('error' => 'Cannot delete active theme');
        }

        $result = delete_theme($theme_slug);

        if (is_wp_error($result)) {
            return array('error' => 'Deletion failed: ' . $result->get_error_message());
        }

        self::log_activity('theme_delete', 'Deleted theme: ' . $theme_slug);

        return array(
            'success' => true,
            'message' => 'Theme deleted successfully'
        );
    }

    /**
     * Get detailed plugin information
     */
    private function get_plugin_details($args) {
        if (!isset($args['plugin_file'])) {
            return array('error' => 'No plugin file provided');
        }

        $plugin_file = sanitize_text_field($args['plugin_file']);

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        if (!file_exists($plugin_path)) {
            return array('error' => 'Plugin file not found');
        }

        $plugin_data = get_plugin_data($plugin_path);
        $active = is_plugin_active($plugin_file);

        return array(
            'details' => array(
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'description' => $plugin_data['Description'],
                'author' => $plugin_data['Author'],
                'author_uri' => $plugin_data['AuthorURI'],
                'plugin_uri' => $plugin_data['PluginURI'],
                'active' => $active,
                'network' => $plugin_data['Network'],
                'requires_wp' => $plugin_data['RequiresWP'],
                'requires_php' => $plugin_data['RequiresPHP']
            )
        );
    }
}

// Initialize the MainWP Child integration
// This file is only included when MainWP Child is detected, so we can initialize directly
CWPK_MainWP_Child::get_instance();