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
     */
    public function handle_mainwp_request($information, $action) {
        if (!isset($information['action'])) {
            return $information;
        }

        // Check if this is a CribOps action
        if (strpos($information['action'], 'cribops_') !== 0) {
            return $information;
        }

        // Get the specific action
        $action = str_replace('cribops_', '', $information['action']);
        $args = isset($information['args']) ? $information['args'] : array();

        // Handle the action
        switch ($action) {
            case 'get_status':
                return $this->get_plugin_status();

            case 'get_settings':
                return $this->get_plugin_settings();

            case 'update_settings':
                return $this->update_plugin_settings($args);

            case 'install_plugins':
                return $this->install_plugin_recipe($args);

            case 'get_installed_plugins':
                return $this->get_installed_plugins_list();

            case 'manage_licenses':
                return $this->manage_license_keys($args);

            case 'get_logs':
                return $this->get_activity_logs();

            case 'run_bulk_install':
                return $this->run_bulk_installation($args);

            case 'sync':
                return $this->sync_with_dashboard();

            default:
                return array('error' => 'Unknown CribOps action: ' . $action);
        }
    }

    /**
     * Get CribOps WP Kit status
     */
    private function get_plugin_status() {
        $status = array(
            'installed' => true,
            'version' => defined('WPLK_VERSION') ? WPLK_VERSION : CribOpsWPKit::VERSION,
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
     * Install plugin recipe
     */
    private function install_plugin_recipe($args) {
        if (!class_exists('CWPK_Installer')) {
            return array('error' => 'CWPK_Installer class not found');
        }

        $recipe = isset($args['recipe']) ? $args['recipe'] : 'essential';
        $installer = new CWPK_Installer();

        // Define recipe plugins
        $recipes = array(
            'essential' => array(
                'classic-editor',
                'duplicate-post',
                'regenerate-thumbnails',
                'wp-mail-smtp'
            ),
            'security' => array(
                'wordfence',
                'limit-login-attempts-reloaded',
                'wp-force-ssl'
            ),
            'performance' => array(
                'wp-super-cache',
                'autoptimize',
                'wp-sweep'
            ),
            'ecommerce' => array(
                'woocommerce',
                'woocommerce-payments',
                'woocommerce-pdf-invoices-packing-slips'
            )
        );

        $plugins_to_install = isset($recipes[$recipe]) ? $recipes[$recipe] : array();

        if (empty($plugins_to_install)) {
            return array('error' => 'Invalid recipe selected');
        }

        $results = array();
        foreach ($plugins_to_install as $plugin_slug) {
            // Use the installer's method to install plugin
            $result = $installer->install_plugin($plugin_slug);
            $results[$plugin_slug] = $result;
        }

        return array(
            'success' => true,
            'recipe' => $recipe,
            'results' => $results
        );
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
        return array(
            'cribops_data' => array(
                'cribops_installed' => true,
                'cribops_version' => defined('WPLK_VERSION') ? WPLK_VERSION : CribOpsWPKit::VERSION,
                'cribops_active' => true,
                'last_sync' => current_time('mysql'),
                'settings' => get_option('cwpk_settings', array()),
                'auth_status' => get_transient('lk_logged_in') ? 'authenticated' : 'not_authenticated'
            )
        );
    }

    /**
     * Add CribOps data to sync
     */
    public function sync_cribops_data($information, $data) {
        $information['cribops_data'] = array(
            'cribops_installed' => true,
            'cribops_version' => defined('WPLK_VERSION') ? WPLK_VERSION : CribOpsWPKit::VERSION,
            'cribops_active' => true,
            'settings_count' => count(get_option('cwpk_settings', array())),
            'authenticated' => get_transient('lk_logged_in') ? true : false
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
            'cribops_install_plugins',
            'cribops_get_installed_plugins',
            'cribops_manage_licenses',
            'cribops_get_logs',
            'cribops_run_bulk_install',
            'cribops_sync'
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
}

// Initialize the MainWP Child integration
if (defined('MAINWP_CHILD_VERSION')) {
    CWPK_MainWP_Child::get_instance();
}