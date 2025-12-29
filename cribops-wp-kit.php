<?php
/**
 * Plugin Name: CribOps WP Kit
 * Plugin URI:  https://github.com/CloudBedrock/cribops-wp-kit
 * Short Description: WordPress site management and deployment toolkit for agencies.
 * Description: Comprehensive WordPress plugin management, license handling, and rapid site deployment using Prime Mover templates. Fork of LaunchKit Pro v2.13.2.
 * Version:     1.9.6
 * Author:      CribOps Development Team
 * Author URI:  https://cribops.com
 * Text Domain: cwpk
 * Tested up to: 6.7.1
 * Requires PHP: 7.4
 * Update URI:  https://github.com/CloudBedrock/cribops-wp-kit
 * License:     GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Based on LaunchKit Pro v2.13.2 by WPLaunchify (https://wplaunchify.com)
 * Original GitHub repository: https://github.com/wplaunchify/launchkit-pro
 * Original plugin available at: https://wordpress.org/plugins/launchkit/
 */

if (!defined('ABSPATH')) exit;

// Load Composer autoloader for AWS SDK (if vendor exists)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Prevent redeclaration by checking if the class already exists.
if (!class_exists('CribOpsWPKit')) {

    class CribOpsWPKit {

        const VERSION = '1.9.6';

        public function __construct() {
            register_activation_hook(__FILE__, array($this, 'check_and_delete_original_plugin'));
            add_action('admin_init', array($this, 'save_plugin_settings'));
            add_action('init', array($this, 'setup_constants'));
            add_action('plugins_loaded', array($this, 'init'));
            add_action('plugins_loaded', array($this, 'includes'));
            add_action('admin_menu', array($this, 'cwpk_add_admin_menu'));
            add_action('admin_init', array($this, 'cwpk_settings_init'));
            add_action('admin_enqueue_scripts', array($this, 'cwpk_add_script_to_menu_page'));
            add_action('init', array($this, 'cwpk_apply_settings'));
            add_action('wp_enqueue_scripts', array($this, 'cwpk_add_public_style'), 999);
            add_action('admin_footer', array($this, 'add_select_all_script'));
            add_action('admin_init', array($this, 'clear_virtual_plugin_errors'));

            // Login/Logout handlers
            add_action('admin_post_cwpk_login', array($this, 'cwpk_handle_login'));
            add_action('admin_post_cwpk_logout', array($this, 'cwpk_handle_logout'));

            // License cleanup AJAX handler
            add_action('wp_ajax_cwpk_clear_licenses', array($this, 'ajax_clear_licenses'));
        }

        public function check_and_delete_original_plugin() {
            $original_plugin_slug = 'launchkit/cribops-wp-kit.php';
            if (is_plugin_active($original_plugin_slug)) {
                deactivate_plugins($original_plugin_slug);
            }
            $plugin_path = WP_PLUGIN_DIR . '/' . dirname($original_plugin_slug);
            if (is_dir($plugin_path)) {
                $this->delete_plugin_directory($plugin_path);
            }
        }

        private function delete_plugin_directory($plugin_path) {
            if (!class_exists('WP_Filesystem_Base')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
            global $wp_filesystem;
            $wp_filesystem->delete($plugin_path, true);
        }

        public function add_select_all_script() {
            $screen = get_current_screen();
            if (is_object($screen) && $screen->id == 'toplevel_page_cwpk' && (!isset($_GET['tab']) || $_GET['tab'] === 'settings')) {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.cwpk-settings-container .form-table tbody').prepend(
                        '<tr>' +
                        '<th scope="row"><?php esc_html_e("Select/Deselect All Options", "cwpk"); ?></th>' +
                        '<td><input type="checkbox" id="select-all-checkboxes"></td>' +
                        '</tr>'
                    );
                    var $checkboxes = $('input[name^="cwpk_settings[cwpk_checkbox_field_"]');
                    var allChecked = $checkboxes.length === $checkboxes.filter(":checked").length;
                    $('#select-all-checkboxes').prop("checked", allChecked);
                    $('#select-all-checkboxes').change(function() {
                        var isChecked = $(this).prop("checked");
                        $checkboxes.prop("checked", isChecked).trigger("change");
                    });
                    $checkboxes.change(function() {
                        var allChecked = $checkboxes.length === $checkboxes.filter(":checked").length;
                        $('#select-all-checkboxes').prop("checked", allChecked);
                    });
                });
                </script>
                <?php
            }
        }

        public function cwpk_add_public_style() {
            wp_register_style('cwpk-public', WPLK_DIR_URL . 'assets/css/cwpk-public.css', false, '1.0.8');
            wp_enqueue_style('cwpk-public');
        }

        public function wplk() {
            load_plugin_textdomain('cwpk');
        }


        public function init() {
            // Load configuration first
            require_once('includes/class-cwpk-config.php');

            // Load authentication handler
            require_once('includes/class-cwpk-auth.php');

            // Load other components
            require_once('includes/class-cwpk-deleter.php');
            require_once('includes/class-cwpk-devtools.php');
            require_once('includes/class-cwpk-functions.php');
            require_once('includes/class-cwpk-installer.php');
            require_once('includes/class-cwpk-manifest-installer.php');
            require_once('includes/class-cwpk-license-loader.php');
            require_once('includes/class-cwpk-manager.php');
            require_once('includes/class-cwpk-pluginmanager.php');
            require_once('includes/class-cwpk-theme-manager.php');
            // GitHub-based updates (replaces legacy self-hosted updater removed in 1.9.5)
            require_once('includes/class-cwpk-github-updater.php');
            require_once('includes/class-cwpk-cdn.php');

            // Initialize development tools
            new CWPKDevTools();

            // Initialize CDN integration
            CWPK_CDN::get_instance();

            // Load MainWP Child integration if MainWP Child plugin is active
            // Check multiple ways to detect MainWP Child plugin
            $mainwp_child_active = false;

            // Method 1: Check if constant is defined (may not work in all contexts)
            if (defined('MAINWP_CHILD_VERSION')) {
                $mainwp_child_active = true;
            }

            // Method 2: Check if plugin file exists and is active
            if (!$mainwp_child_active) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
                if (is_plugin_active('mainwp-child/mainwp-child.php')) {
                    $mainwp_child_active = true;
                }
            }

            // Method 3: Check if MainWP Child classes exist
            if (!$mainwp_child_active && class_exists('MainWP_Child')) {
                $mainwp_child_active = true;
            }

            // Load our integration if MainWP Child is detected
            if ($mainwp_child_active) {
                require_once('includes/class-cwpk-mainwp-child.php');
            }

            // Check authentication on admin init
            add_action('admin_init', array('CWPKAuth', 'check_authentication'));
        }

        public function setup_constants() {
            if (!defined('VERSION')) {
                $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
                $plugin_version = $plugin_data['Version'];
                define('VERSION', $plugin_version);
            }
            if (!defined('WPLK_DIR_PATH')) {
                define('WPLK_DIR_PATH', plugin_dir_path(__FILE__));
            }
            if (!defined('WPLK_PLUGIN_PATH')) {
                define('WPLK_PLUGIN_PATH', plugin_basename(__FILE__));
            }
            if (!defined('WPLK_DIR_URL')) {
                define('WPLK_DIR_URL', plugin_dir_url(__FILE__));
            }
        }

        public function includes() {
            // Additional includes if needed.
        }

        public function cwpk_add_admin_menu() {
            $parent_slug = 'cwpk';
            $capability = 'manage_options';
            add_menu_page(
                'CribOps WP-Kit',
                'CribOps WP-Kit',
                $capability,
                $parent_slug,
                array($this, 'cwpk_options_page'),
                'dashicons-cloud'
            );
            add_action('admin_head', array($this, 'hide_cwpk_admin_submenu_item'));
        }

        public function hide_cwpk_admin_submenu_item() {
            $parent_slug = 'cwpk';
            echo '<style>';
            echo '#toplevel_page_' . $parent_slug . ' ul.wp-submenu.wp-submenu-wrap, #adminmenu .wp-submenu a[href="admin.php?page=' . $parent_slug . '"] { display: none; }';
            echo '</style>';
        }

        public function cwpk_add_script_to_menu_page() {
            $screen = get_current_screen();
            if (is_object($screen) && $screen->id == 'toplevel_page_cwpk') {
                wp_register_style('cwpk-admin', WPLK_DIR_URL . 'assets/css/cwpk-admin.css', false, '1.0');
                wp_enqueue_style('cwpk-admin');
            }
            wp_register_style('cwpk-wp-admin', WPLK_DIR_URL . 'assets/css/cwpk-wp-admin.css', false, '1.0');
            wp_enqueue_style('cwpk-wp-admin');
        }

        public function cwpk_settings_init() { 
            register_setting('cwpk_options_page', 'cwpk_settings');
            add_settings_section(
                'cwpk_options_section_base',
                esc_html__('', 'cwpk'),
                array($this, 'cwpk_settings_section_base'),
                'cwpk_options_page'
            );
            add_settings_field(
                'cwpk_checkbox_field_004',
                esc_html__('Hide CribOps WP-Kit from Admin Menu (Whitelabel For Client Sites)', 'cwpk') . '<p class="description">' . esc_html__('For Agency Owners With Client sites. Use "page=cwpk" in the URL to access it.', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_004_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
            add_settings_field(
                'cwpk_checkbox_field_000',
                esc_html__('Disable All Plugin Activation Wizards', 'cwpk') . '<p class="description">' . esc_html__('To ensure that you stay on the plugin manager page when activating plugins.', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_000_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
            add_settings_field(
                'cwpk_checkbox_field_001',
                esc_html__('Hide All Admin Notices', 'cwpk') . '<p class="description">' . esc_html__('A clean dashboard is a productive dashboard! Click Notices Hidden to reveal.', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_001_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
            add_settings_field(
                'cwpk_checkbox_field_002',
                esc_html__('Disable LearnDash License Management Plugin', 'cwpk') . '<p class="description">' . esc_html__('Removes this unnecessary plugin.', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_002_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
            add_settings_field(
                'cwpk_checkbox_field_003',
                esc_html__('Disable WordPress Plugin Manager Dependencies', 'cwpk') . '<p class="description">' . esc_html__('Restores Plugin Manager to pre-6.5 capabilities for total control.', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_003_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
            add_settings_field(
                'cwpk_checkbox_field_005',
                esc_html__('Disable WordPress Sending Update Emails', 'cwpk') . '<p class="description">' . esc_html__('For Core, Updates and Themes', 'cwpk') . '</p>',
                array($this, 'cwpk_checkbox_field_005_render'),
                'cwpk_options_page',
                'cwpk_options_section_base'
            );
        }

        public function cwpk_checkbox_field_000_render() {
            $options = get_option('cwpk_settings');
            $checked = isset($options['cwpk_checkbox_field_000']) && $options['cwpk_checkbox_field_000'] === '1' ? 1 : 0;
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_000]' <?php checked($checked, 1); ?> value='1'>
            <?php
        }

        public function cwpk_checkbox_field_001_render() {
            $options = get_option('cwpk_settings');
            $checked = isset($options['cwpk_checkbox_field_001']) && $options['cwpk_checkbox_field_001'] === '1' ? 1 : 0;
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_001]' <?php checked($checked, 1); ?> value='1'>
            <?php
        }

        public function cwpk_checkbox_field_002_render() {
            $options = get_option('cwpk_settings');
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_002]' <?php checked(isset($options['cwpk_checkbox_field_002']), 1); ?> value='1'>
            <?php
        }

        public function cwpk_checkbox_field_003_render() {
            $options = get_option('cwpk_settings');
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_003]' <?php checked(isset($options['cwpk_checkbox_field_003']), 1); ?> value='1'>
            <?php
        }

        public function cwpk_checkbox_field_004_render() {
            $options = get_option('cwpk_settings');
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_004]' <?php checked(isset($options['cwpk_checkbox_field_004']), 1); ?> value='1'>
            <?php
        }

        public function cwpk_checkbox_field_005_render() {
            $options = get_option('cwpk_settings');
            ?>
            <input type='checkbox' name='cwpk_settings[cwpk_checkbox_field_005]' <?php checked(isset($options['cwpk_checkbox_field_005']), 1); ?> value='1'>
            <?php
        }

        public function cwpk_settings_section_base() {
            /* reserved for future use */
        }
        
        public function cwpk_options_page() {
            ?>
            <div id="cwpk-page">
<!-- Page Header -->
<div class="cwpk-dashboard__header">
  <!-- Left side: Logo + Login form together -->
  <div class="cwpk-header-left" style="display: flex; align-items: center;">
    <div class="cwpk-logo-container">
      <a href="https://cribops.com/pricing" target="_blank">
        <img src="<?php echo WPLK_DIR_URL; ?>assets/images/logo_light.svg" alt="CribOps WP-Kit" style="max-height: 60px; width: auto;" onerror="this.style.display='none'">
      </a>
    </div>

    <!-- Inline Login Area -->
    <?php
    $auth_type = CWPKAuth::get_auth_type();
    if ( $auth_type === 'token' ) :
        // Token authentication - no logout needed
    ?>
      <div class="cwpk-dashboard__login" style="margin-left:50px; align-items: center;border: 1px solid #28a745;padding: 8px 12px; border-radius: 4px; background: #d4edda;display: flex;">
        <span style="color: #155724;"><?php esc_html_e('Authenticated via API Token', 'cwpk'); ?></span>
      </div>
    <?php elseif ( $auth_type === 'credentials' ) :
        // Username/password authentication - show logout
    ?>
      <div class="cwpk-dashboard__login" style="margin-left:50px; align-items: center;border: 1px solid #ddd;padding: 8px 12px; border-radius: 4px; background: #f9f9f9;display: flex;">
        <span><?php esc_html_e('Logged in as: ******', 'cwpk'); ?></span>
        &nbsp;|&nbsp;
        <a href="<?php echo admin_url('admin-post.php?action=cwpk_logout'); ?>" class="cwpk-logout-link">
          <?php esc_html_e('Logout', 'cwpk'); ?>
        </a>
      </div>
    <?php else : ?>
      <div style="margin-left: 50px; display: flex; flex-direction: column; gap: 8px;">
        <form class="cwpk-login-inline" method="post"
              action="<?php echo admin_url('admin-post.php'); ?>"
              style="display: flex; align-items: center; gap: 10px;">
          <input type="hidden" name="action" value="cwpk_login">

          <label style="font-weight: 600; color: #333;">
            <?php esc_html_e('Login:', 'cwpk'); ?>
          </label>

          <input type="text"
                 name="cwpk_username"
                 placeholder="<?php esc_attr_e('Email', 'cwpk'); ?>"
                 style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 180px;">

          <input type="password"
                 name="cwpk_password"
                 placeholder="<?php esc_attr_e('Password', 'cwpk'); ?>"
                 style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 140px;">

          <input type="submit" value="<?php esc_attr_e('Login', 'cwpk'); ?>"
                 style="padding: 6px 16px; background: #2271b1; color: white; border: none; border-radius: 4px; cursor: pointer;">
        </form>
        <div style="font-size: 12px; margin-left: 50px;">
          <?php esc_html_e('Need an account?', 'cwpk'); ?>
          <a href="https://cribops.com/pricing" target="_blank" style="color: #2271b1; text-decoration: none; font-weight: 600;">
            <?php esc_html_e('Sign up here', 'cwpk'); ?>
          </a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right side: Version -->
  <div class="cwpk-dashboard__version" style="margin-left: auto;">
    <?php echo 'v' . VERSION; ?>
  </div>
</div>



                <!-- Page Navigation Tabs -->
                <?php 
                $default_tab = 'installer';
                $tab = isset($_GET['tab']) ? $_GET['tab'] : $default_tab;
                ?>

                <nav class="nav-tab-wrapper">
                    <a href="?page=cwpk&tab=installer" class="nav-tab <?php if ($tab === 'installer'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Installer', 'cwpk'); ?></a>
                    <?php /* COMMENTED OUT: License Manager tab - uncomment to re-enable
                    <a href="?page=cwpk&tab=license" class="nav-tab <?php if ($tab === 'license'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('License Manager', 'cwpk'); ?></a>
                    */ ?>
                    <a href="?page=cwpk&tab=license-cleanup" class="nav-tab <?php if ($tab === 'license-cleanup'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('License Cleanup', 'cwpk'); ?></a>
                    <a href="?page=cwpk&tab=cdn" class="nav-tab <?php if ($tab === 'cdn'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('CDN', 'cwpk'); ?></a>
                    <a href="?page=cwpk&tab=settings" class="nav-tab <?php if ($tab === 'settings'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Settings', 'cwpk'); ?></a>
                    <a href="?page=cwpk&tab=featured" class="nav-tab <?php if ($tab === 'featured'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Other Tools', 'cwpk'); ?></a>

</nav>

                <div class="cwpk-wrap">
                    <div class="tab-content">
                        <?php switch($tab) :
                        case 'installer': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $installer = new CWPKInstaller();
                                    $installer->lk_get_meta_plugin_installer_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'deleter': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $deleter = new CWPKDeleter();
                                    $deleter->launchkit_deleter_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'manager': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $manager = new CWPKManager();
                                    $manager->launchkit_manager_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'license': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $license = new CWPKLicenseKeyAutoloader();
                                    $license->license_key_autoloader_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'cdn': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $cdn = CWPK_CDN::get_instance();
                                    $cdn->render_admin_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'packages': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php
                                    $packages = new CWPKInstaller();
                                    $packages->get_prime_page();
                                    ?>
                                </div>
                            </div>
                        <?php break;
                        case 'license-cleanup': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <?php $this->render_license_cleanup_page(); ?>
                                </div>
                            </div>
                        <?php break;
                        case 'settings': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <h1><?php esc_html_e('Settings', 'cwpk'); ?></h1>
                                    <style>
                                        .cwpk-settings-container ul {
                                            list-style: disc;
                                            text-align: left;
                                            max-width: 80%;
                                            margin: 0 auto;
                                        }
                                        .postbox {
                                            margin-bottom: 20px;
                                        }
                                        .postbox .hndle {
                                            margin-top:0;
                                            cursor: pointer;
                                            padding: 10px;
                                            background-color: #f1f1f1;
                                            border: 1px solid #ccc;
                                        }
                                        .postbox .inside {
                                            padding: 10px;
                                            border: 1px solid #ccc;
                                            border-top: none;
                                        }
                                        .postbox.closed .inside {
                                            display: none;
                                        }
                                        .postbox h3.hndle {
                                            text-align: left;
                                            padding-left:15px;
                                        }
                                    </style>
                                    <form id="launchkit" action='options.php' method='post'>
                                        <?php
                                        settings_fields('cwpk_options_page');
                                        echo '<div class="cwpk-settings-container">';
                                        do_settings_sections('cwpk_options_page');
                                        echo '</div>';
                                        submit_button();
                                        ?>
                                    </form>
                                </div>
                            </div>
                        <?php break;
                        case 'featured': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <h1><?php esc_html_e('Other Tools', 'cwpk'); ?></h1>
                                    <p>For Launching & Managing Your Site</p>
                                    <nav class="nav-tab-wrapper-more">
                                        <a href="?page=cwpk&tab=deleter" class="nav-tab <?php if ($tab === 'deleter'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Deleter', 'cwpk'); ?></a><span class="nav-description">Delete any or all plugins instantly.</span>
                                        <a href="?page=cwpk&tab=manager" class="nav-tab <?php if ($tab === 'manager'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Recipe Manager', 'cwpk'); ?></a><span class="nav-description">Create & manage plugin recipes.</span>
                                        <?php $logged_in = get_transient('lk_logged_in'); ?>
                                        <?php if ($logged_in) : ?>
                                        <a href="?page=cwpk&tab=account" class="nav-tab <?php if ($tab === 'account'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Account', 'cwpk'); ?></a><span class="nav-description">Create your WPLaunchify access account.</span>
                                        <a href="?page=cwpk&tab=license" class="nav-tab <?php if ($tab === 'license'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('License', 'cwpk'); ?></a><span class="nav-description">Manage software licenses.</span>
                                        <a href="?page=cwpk&tab=packages" class="nav-tab <?php if ($tab === 'packages'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Packages', 'cwpk'); ?></a><span class="nav-description">Create or import full site templates.</span>
                                        <a href="?page=launchkit-debug" class="nav-tab <?php if ($tab === 'debug'): ?>nav-tab-active<?php endif; ?>"><?php esc_html_e('Debug', 'cwpk'); ?></a><span class="nav-description">Debug CribOps WP-Kit Software Bundle</span>
                                        <?php endif; ?>
                                    </nav>
                                </div>
                            </div>
                        <?php break;
                        case 'account': ?>
                            <div class="cwpk-dashboard__content">
                                <div class="cwpk-inner">
                                    <h1><?php esc_html_e('Account', 'cwpk'); ?></h1>
                                    <br/>
                                    <div class="cwpk-settings-container">
                                        <span class="dashicons dashicons-update"></span>
                                        <h2>Get Your Pro Account</h2>
                                        <p> Available with concierge support from WPLaunchify.</p>
                                        <p><a href="https://cribops.com/pricing" class="cwpk-button cwpk-featured" target="_blank">Upgrade To Pro Now!</a></p>
                                    </div>
                                    <div class="cwpk-settings-container">
                                        <h2>Subscribe To The WPLaunchClub Newsletter</h2>
                                        <p>WordPress Tutorials For Business Owners<br/>
                                        Membership, Marketing Automation, Online Courses, eCommerce & BuddyBoss</p>
                                        <a href="https://cribops.com/newsletter" class="cwpk-button cwpk-featured" target="_blank">Subscribe Now (It's Free)</a>
                                    </div>
                                </div>
                            </div>
                        <?php break;
                        endswitch; ?>
                    </div>
                </div>
            </div>
            <?php
        }

        public function cwpk_handle_login() {
            $username = isset($_POST['cwpk_username']) ? sanitize_text_field($_POST['cwpk_username']) : '';
            $password = isset($_POST['cwpk_password']) ? sanitize_text_field($_POST['cwpk_password']) : '';

            if (!empty($username) && !empty($password)) {
                $response = wp_remote_post(CWPKConfig::get_wplaunchify_api_endpoint() . '/user-meta', array(
                    'body' => array(
                        'email'    => $username,
                        'password' => $password,
                        'site_url' => site_url()
                    )
                ));

                if (is_wp_error($response)) {
                    set_transient('lk_logged_in', false, 12 * HOUR_IN_SECONDS);
                    wp_redirect(admin_url('admin.php?page=cwpk&login_error=connection'));
                    exit;
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $user_data     = json_decode($response_body, true);

                if ($response_code === 200 && !empty($user_data) && !empty($user_data['can_access_launchkit'])) {
                    set_transient('lk_logged_in', true, 12 * HOUR_IN_SECONDS);
                    set_transient('lk_user_data', $user_data, 12 * HOUR_IN_SECONDS);
                } else {
                    set_transient('lk_logged_in', false, 12 * HOUR_IN_SECONDS);
                    wp_redirect(admin_url('admin.php?page=cwpk&login_error=invalid'));
                    exit;
                }
            }
            wp_redirect(admin_url('admin.php?page=cwpk'));
            exit;
        }

        public function cwpk_handle_logout() {
            delete_transient('lk_logged_in');
            delete_transient('lk_username');
            delete_transient('lk_user_data');
            wp_redirect(admin_url('admin.php?page=cwpk'));
            exit;
        }

        public function lk_enable_plugin_deactivation_js() {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.inactive-plugin').removeClass('inactive-plugin');
                $('tr.inactive').find('.deactivate').removeClass('edit-plugin');
            });
            </script>
            <?php
        }

        public function lk_enable_plugin_deactivation_css() {
            ?>
            <style type="text/css">
                .plugins .inactive-plugin .delete-plugin {
                    display: inline-block !important;
                }
                .plugins .inactive .deactivate {
                    display: inline-block !important;
                }
            </style>
            <?php
        }

        /**
         * Add the dependency bypass as a virtual plugin to the plugins list
         */
        public function add_dependency_bypass_virtual_plugin($plugins) {
            // Only show on plugins page to avoid activation issues
            global $pagenow;
            if ($pagenow !== 'plugins.php') {
                return $plugins;
            }

            $virtual_plugin_file = 'cwpk-dependency-bypass/cwpk-dependency-bypass.php';

            // Only add if not already present
            if (!isset($plugins[$virtual_plugin_file])) {
                $plugins[$virtual_plugin_file] = array(
                    'Name' => 'Re-enable Dependent Plugin Deactivate & Delete',
                    'PluginURI' => 'https://cribops.com',
                    'Version' => '1.0.31',
                    'Description' => 'Restores the plugin manager behavior to pre version 6.5 capability',
                    'Author' => 'CribOps Development Team',
                    'AuthorURI' => 'https://cribops.com',
                    'TextDomain' => 'cwpk-dependency-bypass',
                    'DomainPath' => '',
                    'Network' => false,
                    'RequiresPHP' => '',
                    'RequiresWP' => '',
                    'UpdateURI' => '',
                );
            }

            return $plugins;
        }

        /**
         * Mark the virtual plugin as active (only on plugins page)
         */
        public function add_dependency_bypass_to_active_plugins($active_plugins) {
            // Only modify on plugins page to avoid activation issues
            global $pagenow;
            if ($pagenow !== 'plugins.php') {
                return $active_plugins;
            }

            $virtual_plugin_file = 'cwpk-dependency-bypass/cwpk-dependency-bypass.php';

            if (!in_array($virtual_plugin_file, $active_plugins)) {
                $active_plugins[] = $virtual_plugin_file;
            }

            return $active_plugins;
        }

        /**
         * Prevent WordPress from validating the virtual plugin file
         */
        public function validate_dependency_bypass_plugin($is_valid, $plugin_file) {
            if ($plugin_file === 'cwpk-dependency-bypass/cwpk-dependency-bypass.php') {
                return true; // Always report as valid
            }
            return $is_valid;
        }

        /**
         * Prevent WordPress from checking if virtual plugin file exists
         */
        public function virtual_plugin_file_exists($exists, $file) {
            if (strpos($file, 'cwpk-dependency-bypass/cwpk-dependency-bypass.php') !== false) {
                return true;
            }
            return $exists;
        }

        /**
         * Remove virtual plugin from active plugins validation check
         */
        public function filter_validate_active_plugins($plugins) {
            return array_diff($plugins, array('cwpk-dependency-bypass/cwpk-dependency-bypass.php'));
        }

        /**
         * Clear any stored errors about the virtual plugin
         */
        public function clear_virtual_plugin_errors() {
            // Remove from the list of plugins that were deactivated due to errors
            $deactivated = get_option('active_plugins_before_deactivation', array());
            if (is_array($deactivated)) {
                $key = array_search('cwpk-dependency-bypass/cwpk-dependency-bypass.php', $deactivated);
                if ($key !== false) {
                    unset($deactivated[$key]);
                    update_option('active_plugins_before_deactivation', $deactivated);
                }
            }

            // Clear from active_plugins option if it shouldn't be there
            $active = get_option('active_plugins', array());
            if (is_array($active)) {
                $options = get_option('cwpk_settings');
                $bypass_enabled = isset($options['cwpk_checkbox_field_003']) && $options['cwpk_checkbox_field_003'] == '1';

                if (!$bypass_enabled) {
                    // Remove virtual plugin if setting is disabled
                    $key = array_search('cwpk-dependency-bypass/cwpk-dependency-bypass.php', $active);
                    if ($key !== false) {
                        unset($active[$key]);
                        update_option('active_plugins', array_values($active));
                    }
                }
            }
        }

        public function lk_add_deactivate_link($actions, $plugin_file) {
            if(isset($actions['deactivate'])) {
                $actions['deactivate'] = str_replace('class="edit-plugin"', '', $actions['deactivate']);
            }
            return $actions;
        }

        public function lk_add_delete_link($actions, $plugin_file) {
            if(isset($actions['delete'])) {
                $actions['delete'] = str_replace('class="delete-plugin"', 'class="delete-plugin" style="display:inline-block;"', $actions['delete']);
            }
            return $actions;
        }

        public function cwpk_apply_settings() {
            $options = get_option('cwpk_settings');

            if(isset($options['cwpk_checkbox_field_000']) && $options['cwpk_checkbox_field_000'] == '1') {
                function lk_prevent_plugin_activation_redirect() {
                    if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/wp-admin/plugins.php') !== false) {
                        $redirect_pages = array(
                            'kadence-starter-templates',
                            'searchwp-welcome',
                            'cptui_main_menu',
                            'sc-about',
                            'woocommerce-events-help',
                            'fooevents-introduction',
                            'fooevents-settings',
                            'yith-licence-activation',
                            'profile-builder-basic-info',
                            'bp-components',
                        );
                        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
                        if (in_array($current_page, $redirect_pages)) {
                            wp_redirect(admin_url('plugins.php'));
                            exit();
                        }
                    }
                }
                add_action('admin_init', 'lk_prevent_plugin_activation_redirect', 1);
                add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true');

                function lk_disable_wc_setup_wizard_redirect() {
                    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-admin' && isset($_GET['path']) && $_GET['path'] === '/setup-wizard') {
                        wp_redirect(admin_url());
                        exit;
                    }
                }
                add_action('admin_init', 'lk_disable_wc_setup_wizard_redirect');
            }

            if(isset($options['cwpk_checkbox_field_001']) && $options['cwpk_checkbox_field_001'] == '1') {
                function lk_admin_bar_button($wp_admin_bar) {
                    $hide_notices = get_option('launchkit_hide_notices', false);
                    $button_text = $hide_notices ? 'Notices Hidden' : 'Hide Notices';
                    $button_id = 'launchkit-hide-notices';
                    $button_class = $hide_notices ? 'launchkit-show-notices' : 'launchkit-hide-notices';
                    $notice_count_html = $hide_notices ? '<span class="launchkit-notice-count">!</span>' : '';
                    $args = array(
                        'id' => $button_id,
                        'title' => $button_text . $notice_count_html,
                        'href' => '#',
                        'meta' => array('class' => $button_class)
                    );
                    $wp_admin_bar->add_node($args);
                }
                add_action('admin_bar_menu', 'lk_admin_bar_button', 999);

                function lk_enqueue_scripts() {
                    wp_enqueue_script('jquery');
                    ?>
                    <style>
                        #wp-admin-bar-launchkit-hide-notices .ab-item,
                        #wp-admin-bar-launchkit-show-notices .ab-item {
                            color: #fff;
                            background-color: transparent;
                        }
                        #wp-admin-bar-launchkit-hide-notices .launchkit-notice-count,
                        #wp-admin-bar-launchkit-show-notices .launchkit-notice-count {
                            display: inline-block;
                            min-width: 18px;
                            height: 18px;
                            border-radius: 9px;
                            margin: 7px 0 0 2px;
                            vertical-align: top;
                            font-size: 11px;
                            line-height: 1.6;
                            text-align: center;
                            background-color: #ff0000;
                            color: #fff;
                        }
                    </style>
                    <script>
                        jQuery(document).ready(function($) {
                            $('.launchkit-hide-notices, .launchkit-show-notices').on('click', function(e) {
                                e.preventDefault();
                                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                    action: 'launchkit_toggle_notices'
                                }, function(response) {
                                    if (response.success) {
                                        location.reload();
                                    }
                                });
                            });
                        });
                    </script>
                    <?php
                }
                add_action('admin_footer', 'lk_enqueue_scripts');

                function lk_toggle_notices() {
                    $hide_notices = get_option('launchkit_hide_notices', false);
                    update_option('launchkit_hide_notices', !$hide_notices);
                    wp_send_json_success();
                }
                add_action('wp_ajax_launchkit_toggle_notices', 'lk_toggle_notices');

                function lk_hide_notices_css() {
                    $hide_notices = get_option('launchkit_hide_notices', false);
                    if ($hide_notices) {
                        echo '<style>
                            body.wp-admin #lmnExt,
                            body.wp-admin #wp-admin-bar-seedprod_admin_bar,
                            body.wp-admin .update-nag:not(.cloudbedrock_plugin),
                            body.wp-admin .updated:not(.cloudbedrock_plugin),
                            body.wp-admin .error:not(.cloudbedrock_plugin),
                            body.wp-admin .is-dismissible:not(.cloudbedrock_plugin),
                            body.wp-admin .notice:not(.cloudbedrock_plugin),
                            body.wp-admin .wp-pointer-left,
                            #yoast-indexation-warning,
                            li#wp-admin-bar-searchwp_support,
                            .searchwp-license-key-bar,
                            .searchwp-settings-statistics-upsell,
                            .dashboard_page_searchwp-welcome .swp-button.swp-button--xl:nth-child(2),
                            .dashboard_page_searchwp-welcome .swp-content-block.swp-bg--black,
                            .woocommerce-layout__header-tasks-reminder-bar,
                            #woocommerce-activity-panel #activity-panel-tab-setup,
                            span.wp-ui-notification.searchwp-menu-notification-counter,
                            .yzp-heading,
                            .youzify-affiliate-banner,
                            .pms-cross-promo,
                            .yzp-heading,
                            .fs-slug-clickwhale
                            {
                                display: none !important;
                            }
                            .lf-always-show-notice,
                            .cloudbedrock_plugin { display:block!important;}
                            a.searchwp-sidebar-add-license-key,
                            a.searchwp-sidebar-add-license-key:hover,
                            a.searchwp-sidebar-add-license-key:focus,
                            a.searchwp-sidebar-add-license-key:active {
                                color: rgba(240,246,252,.7) !important;
                                background-color: inherit !important;
                                font-weight: normal !important;
                            }
                        </style>';
                    }
                }
                add_action('admin_head', 'lk_hide_notices_css');

                function lk_remove_searchwp_about_us_submenu($submenu_pages) {
                    unset($submenu_pages['about-us']);
                    return $submenu_pages;
                }
                add_filter('searchwp\options\submenu_pages', 'lk_remove_searchwp_about_us_submenu', 999);

                function lk_change_searchwp_license_submenu_label() {
                    ?>
                    <script type="text/javascript">
                        jQuery(document).ready(function($) {
                            $('.searchwp-sidebar-add-license-key a').text('General Settings');
                        });
                    </script>
                    <?php
                }
                add_action('admin_footer', 'lk_change_searchwp_license_submenu_label');
            }

            if (isset($options['cwpk_checkbox_field_002']) && $options['cwpk_checkbox_field_002'] == '1') {
                add_filter('plugins_api', 'lk_disable_learndash_license_management_install', 10, 3);
                function lk_disable_learndash_license_management_install($api, $action, $args) {
                    if (isset($args->slug) && $args->slug === 'learndash-hub') {
                        return new WP_Error('plugin_disabled', 'The LearnDash license management plugin is disabled.');
                    }
                    return $api;
                }
                add_action('admin_init', 'lk_deactivate_learndash_license_management');
                function lk_deactivate_learndash_license_management() {
                    $plugin = 'learndash-hub/learndash-hub.php';
                    if (is_plugin_active($plugin)) {
                        deactivate_plugins($plugin);
                    }
                }
                add_action('admin_head-plugins.php', 'lk_learndash_license_management_row_style');
                function lk_learndash_license_management_row_style() {
                    echo '<style>tr.inactive[data-slug="learndash-licensing-management"] { display:none; }</style>';
                }
            }

            if (isset($options['cwpk_checkbox_field_003']) && $options['cwpk_checkbox_field_003'] == '1') {
                add_action('admin_head', array($this, 'lk_enable_plugin_deactivation_js'));
                add_action('admin_print_styles', array($this, 'lk_enable_plugin_deactivation_css'));
                add_filter('plugin_action_links', array($this, 'lk_add_deactivate_link'), 10, 2);
                add_filter('plugin_action_links', array($this, 'lk_add_delete_link'), 10, 2);

                // Add virtual plugin to plugins list
                add_filter('all_plugins', array($this, 'add_dependency_bypass_virtual_plugin'));
                add_filter('option_active_plugins', array($this, 'add_dependency_bypass_to_active_plugins'));

                // Prevent WordPress from checking if the virtual plugin file exists
                add_filter('validate_plugin', array($this, 'validate_dependency_bypass_plugin'), 10, 2);
                add_filter('file_exists', array($this, 'virtual_plugin_file_exists'), 10, 2);
                add_filter('validate_active_plugins', array($this, 'filter_validate_active_plugins'));
            }

            if (isset($options['cwpk_checkbox_field_004']) && $options['cwpk_checkbox_field_004'] == '1') {
                add_action('admin_menu', 'hide_cwpk_admin_menu');
                function hide_cwpk_admin_menu() {
                    remove_menu_page('cwpk');
                }
            }

            if (isset($options['cwpk_checkbox_field_005']) && $options['cwpk_checkbox_field_005'] == '1') {
                add_filter('auto_plugin_update_send_email', '__return_false');    
                add_filter('auto_theme_update_send_email', '__return_false');
                add_filter('auto_core_update_send_email', 'lf_disable_core_update_send_email', 10, 4);
                function lf_disable_core_update_send_email($send, $type, $core_update, $result) {
                    if (!empty($type) && $type == 'success') {
                        return false;
                    }
                    return true;
                }
            }
            return;
        }

        public function save_plugin_settings() {
            if (isset($_POST['cwpk_settings']) && is_array($_POST['cwpk_settings'])) {
                $options = get_option('cwpk_settings', array());
                if (!is_array($options)) {
                    $options = array();
                }
                foreach ($_POST['cwpk_settings'] as $key => $value) {
                    if (is_string($key) && strpos($key, 'cwpk_checkbox_field_') === 0) {
                        $options[$key] = (is_string($value) && $value === '1') ? '1' : '0';
                    }
                }
                update_option('cwpk_settings', $options);
            }
        }

        /**
         * License Cleanup Page
         */
        public function render_license_cleanup_page() {
            ?>
            <h1><?php esc_html_e('License Cleanup', 'cwpk'); ?></h1>
            <p><?php esc_html_e('Clear hard-coded licenses that may have been set by earlier versions of this plugin. This will allow you to enter your own legitimate licenses.', 'cwpk'); ?></p>

            <div id="license-cleanup-status" style="margin: 20px 0;"></div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Plugin', 'cwpk'); ?></th>
                        <th style="width: 50%;"><?php esc_html_e('Current Status', 'cwpk'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Action', 'cwpk'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $licenses = $this->get_license_status();
                    foreach ($licenses as $plugin => $info) {
                        $row_class = $info['has_license'] ? 'license-found' : '';
                        ?>
                        <tr class="<?php echo esc_attr($row_class); ?>">
                            <td><strong><?php echo esc_html($info['name']); ?></strong></td>
                            <td>
                                <?php if ($info['has_license']) : ?>
                                    <span style="color: #d63638;">
                                        ⚠ <?php esc_html_e('License found', 'cwpk'); ?>
                                        <?php if (!empty($info['expires'])) : ?>
                                            - <?php echo esc_html($info['expires']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color: #00a32a;">✓ <?php esc_html_e('No license set', 'cwpk'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($info['has_license']) : ?>
                                    <button type="button" class="button clear-single-license" data-plugin="<?php echo esc_attr($plugin); ?>">
                                        <?php esc_html_e('Clear License', 'cwpk'); ?>
                                    </button>
                                <?php else : ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <button type="button" id="clear-all-licenses" class="button button-primary button-large">
                    <?php esc_html_e('Clear All Licenses', 'cwpk'); ?>
                </button>
            </p>

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                function showMessage(message, type) {
                    var className = type === 'success' ? 'notice-success' : 'notice-error';
                    $('#license-cleanup-status').html('<div class="notice ' + className + ' is-dismissible"><p>' + message + '</p></div>');
                    setTimeout(function() {
                        $('#license-cleanup-status').fadeOut(function() {
                            $(this).html('').show();
                        });
                    }, 3000);
                }

                $('.clear-single-license').on('click', function() {
                    var $button = $(this);
                    var plugin = $button.data('plugin');

                    if (!confirm('Are you sure you want to clear the license for ' + plugin + '?')) {
                        return;
                    }

                    $button.prop('disabled', true).text('Clearing...');

                    $.post(ajaxurl, {
                        action: 'cwpk_clear_licenses',
                        plugin: plugin,
                        nonce: '<?php echo wp_create_nonce('cwpk_clear_licenses'); ?>'
                    }, function(response) {
                        if (response.success) {
                            showMessage(response.data.message, 'success');
                            location.reload();
                        } else {
                            showMessage(response.data.message || 'Error clearing license', 'error');
                            $button.prop('disabled', false).text('Clear License');
                        }
                    });
                });

                $('#clear-all-licenses').on('click', function() {
                    var $button = $(this);

                    if (!confirm('Are you sure you want to clear ALL licenses? This cannot be undone.')) {
                        return;
                    }

                    $button.prop('disabled', true).text('Clearing all licenses...');

                    $.post(ajaxurl, {
                        action: 'cwpk_clear_licenses',
                        plugin: 'all',
                        nonce: '<?php echo wp_create_nonce('cwpk_clear_licenses'); ?>'
                    }, function(response) {
                        if (response.success) {
                            showMessage(response.data.message, 'success');
                            location.reload();
                        } else {
                            showMessage(response.data.message || 'Error clearing licenses', 'error');
                            $button.prop('disabled', false).text('Clear All Licenses');
                        }
                    });
                });
            });
            </script>

            <style>
                .license-found {
                    background-color: #fff8e5;
                }
            </style>
            <?php
        }

        /**
         * Get license status for all managed plugins
         */
        private function get_license_status() {
            $licenses = array();

            // FluentCRM Campaign
            $fluentcrm_license = get_option('__fluentcrm_campaign_license', array());
            $licenses['fluentcampaign'] = array(
                'name' => 'FluentCRM Campaign',
                'has_license' => !empty($fluentcrm_license) && is_array($fluentcrm_license) && !empty($fluentcrm_license['license_key']),
                'expires' => isset($fluentcrm_license['expires']) ? 'Expires: ' . $fluentcrm_license['expires'] : ''
            );

            // Fluent Community Pro
            $fluent_license = get_option('__fluent_community_pro_license', array());
            $licenses['fluentcommunity'] = array(
                'name' => 'Fluent Community Pro',
                'has_license' => !empty($fluent_license) && is_array($fluent_license) && !empty($fluent_license['license_key']),
                'expires' => isset($fluent_license['expires']) ? 'Expires: ' . $fluent_license['expires'] : ''
            );

            // FluentBoards
            $boards_license = get_option('__fbs_plugin_license', array());
            $licenses['fluentboards'] = array(
                'name' => 'FluentBoards Pro',
                'has_license' => !empty($boards_license) && is_array($boards_license) && !empty($boards_license['license_key']),
                'expires' => isset($boards_license['expires']) ? 'Expires: ' . $boards_license['expires'] : ''
            );

            // FluentSupport Pro
            $support_license = get_option('__fluentsupport_pro_license', array());
            $licenses['fluentsupport'] = array(
                'name' => 'FluentSupport Pro',
                'has_license' => !empty($support_license) && is_array($support_license) && !empty($support_license['license_key']),
                'expires' => isset($support_license['expires']) ? 'Expires: ' . $support_license['expires'] : ''
            );

            // FluentBooking Pro
            $booking_license = get_option('__fluent_booking_pro_license', array());
            $licenses['fluentbooking'] = array(
                'name' => 'FluentBooking Pro',
                'has_license' => !empty($booking_license) && is_array($booking_license) && !empty($booking_license['license_key']),
                'expires' => isset($booking_license['expires']) ? 'Expires: ' . $booking_license['expires'] : ''
            );

            // FluentForms Pro
            $forms_license = get_option('__fluentform_pro_license', array());
            $licenses['fluentforms'] = array(
                'name' => 'FluentForms Pro',
                'has_license' => !empty($forms_license) && is_array($forms_license) && !empty($forms_license['license_key']),
                'expires' => isset($forms_license['expires']) ? 'Expires: ' . $forms_license['expires'] : ''
            );

            // AffiliateWP
            $affwp_key = get_option('affwp_license_key', '');
            $affwp_settings = get_option('affwp_settings', array());
            $licenses['affiliatewp'] = array(
                'name' => 'AffiliateWP',
                'has_license' => !empty($affwp_key) || (!empty($affwp_settings['license_key'])),
                'expires' => ''
            );

            // SearchWP
            $searchwp_key = get_option('searchwp_license_key', '');
            $licenses['searchwp'] = array(
                'name' => 'SearchWP',
                'has_license' => !empty($searchwp_key),
                'expires' => ''
            );

            // ACF Pro
            $acf_license = get_option('acf_pro_license', '');
            $licenses['acf'] = array(
                'name' => 'ACF Pro',
                'has_license' => !empty($acf_license),
                'expires' => ''
            );

            // Kadence
            $kadence_blocks = get_option('stellarwp_uplink_license_key_kadence-blocks-pro', '');
            $kadence_theme = get_option('stellarwp_uplink_license_key_kadence-theme-pro', '');
            $licenses['kadence'] = array(
                'name' => 'Kadence Pro',
                'has_license' => !empty($kadence_blocks) || !empty($kadence_theme),
                'expires' => ''
            );

            return $licenses;
        }

        /**
         * AJAX handler to clear licenses
         */
        public function ajax_clear_licenses() {
            check_ajax_referer('cwpk_clear_licenses', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'));
            }

            $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

            if (empty($plugin)) {
                wp_send_json_error(array('message' => 'No plugin specified'));
            }

            if ($plugin === 'all') {
                $this->clear_all_licenses();
                wp_send_json_success(array('message' => 'All licenses cleared successfully'));
            } else {
                $result = $this->clear_single_license($plugin);
                if ($result) {
                    wp_send_json_success(array('message' => 'License cleared successfully'));
                } else {
                    wp_send_json_error(array('message' => 'Failed to clear license'));
                }
            }
        }

        /**
         * Clear a single plugin's license
         */
        private function clear_single_license($plugin) {
            switch ($plugin) {
                case 'fluentcampaign':
                    delete_option('__fluentcrm_campaign_license');
                    delete_option('__fluentcrm_campaign_license_key');
                    delete_option('__fluentcampaign_using_custom_key');
                    return true;

                case 'fluentcommunity':
                    delete_option('__fluent_community_pro_license');
                    delete_option('__fluent_community_pro_license_key');
                    delete_option('__fluent_community_pro_license_verify');
                    delete_option('__fluent_using_custom_key');
                    delete_transient('fc_license_check_status');
                    return true;

                case 'fluentboards':
                    delete_option('__fbs_plugin_license');
                    delete_option('__fbs_plugin_license_key');
                    delete_option('__fluentboards_using_custom_key');
                    return true;

                case 'fluentsupport':
                    delete_option('__fluentsupport_pro_license');
                    delete_option('__fluentsupport_pro_license_key');
                    delete_option('__fluentsupport_using_custom_key');
                    return true;

                case 'fluentbooking':
                    delete_option('__fluent_booking_pro_license');
                    delete_option('__fluent_booking_pro_license_key');
                    delete_option('__fluentbooking_using_custom_key');
                    return true;

                case 'fluentforms':
                    delete_option('__fluentform_pro_license');
                    delete_option('__fluentform_pro_license_key');
                    delete_option('__fluentforms_using_custom_key');
                    return true;

                case 'affiliatewp':
                    delete_option('affwp_license_key');
                    $settings = get_option('affwp_settings', array());
                    if (isset($settings['license_key'])) {
                        unset($settings['license_key']);
                    }
                    if (isset($settings['license_status'])) {
                        unset($settings['license_status']);
                    }
                    update_option('affwp_settings', $settings);
                    delete_option('__affiliatewp_using_custom_key');
                    delete_transient('affwp_license_check');
                    return true;

                case 'searchwp':
                    delete_option('searchwp_license_key');
                    delete_option('searchwp_license_status');
                    delete_option('__searchwp_using_custom_key');
                    return true;

                case 'acf':
                    delete_option('acf_pro_license');
                    delete_option('acf_using_custom_key');
                    return true;

                case 'kadence':
                    delete_option('stellarwp_uplink_license_key_kadence-blocks-pro');
                    delete_option('stellarwp_uplink_license_key_kadence-theme-pro');
                    delete_option('stellarwp_uplink_using_custom_key_kadence');
                    return true;

                default:
                    return false;
            }
        }

        /**
         * Clear all licenses
         */
        private function clear_all_licenses() {
            $plugins = array(
                'fluentcampaign',
                'fluentcommunity',
                'fluentboards',
                'fluentsupport',
                'fluentbooking',
                'fluentforms',
                'affiliatewp',
                'searchwp',
                'acf',
                'kadence'
            );

            foreach ($plugins as $plugin) {
                $this->clear_single_license($plugin);
            }
        }
    }

    new CribOpsWPKit();
}
?>