<?php
/**
 * LaunchKit License Manager
 * 
 * Handles license management for multiple premium plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CWPKLicenseKeyAutoloader {
    // API URL is now managed by CWPKConfig class
    private $license_verify_key = '__fluent_community_pro_license_verify';
    private $plugin_file;

    public function __construct( $plugin_file = null ) {
        // Ensure is_plugin_active is available.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        if ( null === $plugin_file ) {
            $plugin_file = dirname( dirname( __FILE__ ) ) . '/cribops-wp-kit.php';
        }
        $this->plugin_file = $plugin_file;

        $this->license_key_autoloader_check_default_key();

        add_action( 'admin_menu', array( $this, 'launchkit_license_menu' ) );
        add_action( 'admin_init', array( $this, 'conditionally_run_license_key_autoloader_save' ) );
        add_action( 'admin_init', array( $this, 'license_key_autoloader_check_default_key' ) );
        add_action( 'admin_head', array( $this, 'hide_fcom_portal_section_if_default_key' ) );

        $this->setup_enhanced_license_management();
        $this->setup_fluentboards_license_management();
        $this->setup_fluentcampaign_license_management();
        $this->setup_fluentsupport_license_management();
        $this->setup_fluentbooking_license_management();
        $this->setup_fluentforms_license_management();
        $this->setup_affiliatewp_license_management();
        $this->setup_searchwp_license_management();

        add_action( 'admin_init', array( $this, 'remove_license_notices' ), 999 );
        register_deactivation_hook( $this->plugin_file, array( $this, 'cleanup_on_deactivation' ) );
    }

    /**
     * Checks each plugin’s license key and updates it to the default if
     * no custom key flag is set and the stored key is empty or equal to the default.
     */
    public function license_key_autoloader_check_default_key() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';

        // Fluent Community Pro
        $stored_key = get_option( '__fluent_community_pro_license_key', '' );
        if ( ! get_option( '__fluent_using_custom_key' ) && ( empty( $stored_key ) || $stored_key === $default_key ) ) {
            update_option( '__fluent_community_pro_license_key', $default_key );
            $this->update_fluent_license_status( $default_key );
            update_option( $this->license_verify_key, time() );
            $this->setup_enhanced_license_management();
            delete_transient( 'fc_license_check_status' );
            add_filter( 'fluent_community_pro_license_status', array( $this, 'force_valid_license_status' ) );
        }
        // FluentBoards
        $stored_boards_key = get_option( '__fbs_plugin_license_key', '' );
        if ( ! get_option( '__fluentboards_using_custom_key' ) && ( empty( $stored_boards_key ) || $stored_boards_key === $default_key ) ) {
            update_option( '__fbs_plugin_license_key', $default_key );
            update_option( '__fbs_plugin_license', $this->override_fluentboards_license_status( null ) );
            $this->setup_fluentboards_license_management();
        }
        // AffiliateWP
        $stored_affiliatewp_key = get_option( 'affwp_license_key', '' );
        if ( ! get_option( '__affiliatewp_using_custom_key' ) && ( empty( $stored_affiliatewp_key ) || $stored_affiliatewp_key === $default_key ) ) {
            update_option( 'affwp_license_key', $default_key );
            $this->update_affiliatewp_license_status( $default_key );
            $this->setup_affiliatewp_license_management();
            $this->check_direct_affiliatewp_license_changes();
        }
        // SearchWP
        $stored_searchwp_key = get_option( 'searchwp_license_key', '' );
        if ( ! get_option( '__searchwp_using_custom_key' ) && ( empty( $stored_searchwp_key ) || $stored_searchwp_key === $default_key ) ) {
            update_option( 'searchwp_license_key', $default_key );
            $this->update_searchwp_license_status( $default_key );
            $this->setup_searchwp_license_management();
        }
    }

    private function setup_enhanced_license_management() {
        if ( get_option( '__fluent_using_custom_key' ) ) {
            remove_filter( 'pre_option___fluent_community_pro_license', array( $this, 'override_fluent_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluent_community_pro_license', array( $this, 'filter_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_plugin_update_transient' ) );
            remove_filter( 'fluent_community_pro_license_status', array( $this, 'force_valid_license_status' ) );
            remove_filter( 'fluent_community_pro_license_status_details', array( $this, 'force_valid_license_details' ) );
            remove_filter( 'rest_pre_dispatch', array( $this, 'handle_rest_license_check' ), 10 );
            remove_filter( 'pre_option_' . $this->license_verify_key, array( $this, 'force_license_verification_time' ) );
            remove_filter( 'pre_update_option___fluent_community_pro_license_key', array( $this, 'catch_direct_license_update' ), 10 );
            remove_action( 'wp_ajax_fc_pro_check_license_status', array( $this, 'handle_ajax_license_check' ), 1 );
            return;
        }
        add_filter( 'pre_option___fluent_community_pro_license', array( $this, 'override_fluent_license_status' ), 1 );
        add_filter( 'pre_update_option___fluent_community_pro_license', array( $this, 'filter_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_plugin_update_transient' ) );
        add_filter( 'fluent_community_pro_license_status', array( $this, 'force_valid_license_status' ) );
        add_filter( 'fluent_community_pro_license_status_details', array( $this, 'force_valid_license_details' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'handle_rest_license_check' ), 10, 3 );
        add_filter( 'pre_option_' . $this->license_verify_key, array( $this, 'force_license_verification_time' ) );
        add_filter( 'pre_update_option___fluent_community_pro_license_key', array( $this, 'catch_direct_license_update' ), 10, 2 );
        add_action( 'wp_ajax_fc_pro_check_license_status', array( $this, 'handle_ajax_license_check' ), 1 );
        $this->initialize_default_options();
    }

    private function setup_fluentboards_license_management() {
        if ( get_option( '__fluentboards_using_custom_key' ) ) {
            remove_filter( 'pre_option___fbs_plugin_license', array( $this, 'override_fluentboards_license_status' ), 1 );
            remove_filter( 'pre_update_option___fbs_plugin_license', array( $this, 'filter_fluentboards_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentboards_update_transient' ) );
            remove_filter( 'rest_pre_dispatch', array( $this, 'handle_fluentboards_rest_license_check' ), 10 );
            remove_action( 'wp_ajax_fbs_check_license_status', array( $this, 'handle_fluentboards_ajax_license_check' ), 1 );
            return;
        }
        add_filter( 'pre_option___fbs_plugin_license', array( $this, 'override_fluentboards_license_status' ), 1 );
        add_filter( 'pre_update_option___fbs_plugin_license', array( $this, 'filter_fluentboards_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentboards_update_transient' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'handle_fluentboards_rest_license_check' ), 10, 3 );
        add_action( 'wp_ajax_fbs_check_license_status', array( $this, 'handle_fluentboards_ajax_license_check' ), 1 );
        $this->initialize_fluentboards_default_options();
    }

    private function setup_fluentcampaign_license_management() {
        if ( get_option( '__fluentcampaign_using_custom_key' ) ) {
            remove_filter( 'pre_option___fluentcrm_campaign_license', array( $this, 'override_fluentcampaign_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluentcrm_campaign_license', array( $this, 'filter_fluentcampaign_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentcampaign_update_transient' ) );
            return;
        }
        add_filter( 'pre_option___fluentcrm_campaign_license', array( $this, 'override_fluentcampaign_license_status' ), 1 );
        add_filter( 'pre_update_option___fluentcrm_campaign_license', array( $this, 'filter_fluentcampaign_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentcampaign_update_transient' ) );
        $this->initialize_fluentcampaign_default_options();
    }

    private function setup_fluentsupport_license_management() {
        if ( get_option( '__fluentsupport_using_custom_key' ) ) {
            remove_filter( 'pre_option___fluentsupport_pro_license', array( $this, 'override_fluentsupport_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluentsupport_pro_license', array( $this, 'filter_fluentsupport_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentsupport_update_transient' ) );
            return;
        }
        add_filter( 'pre_option___fluentsupport_pro_license', array( $this, 'override_fluentsupport_license_status' ), 1 );
        add_filter( 'pre_update_option___fluentsupport_pro_license', array( $this, 'filter_fluentsupport_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentsupport_update_transient' ) );
        $this->initialize_fluentsupport_default_options();
    }

    private function setup_fluentbooking_license_management() {
        if ( get_option( '__fluentbooking_using_custom_key' ) ) {
            remove_filter( 'pre_option___fluent_booking_pro_license', array( $this, 'override_fluentbooking_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluent_booking_pro_license', array( $this, 'filter_fluentbooking_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentbooking_update_transient' ) );
            return;
        }
        add_filter( 'pre_option___fluent_booking_pro_license', array( $this, 'override_fluentbooking_license_status' ), 1 );
        add_filter( 'pre_update_option___fluent_booking_pro_license', array( $this, 'filter_fluentbooking_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentbooking_update_transient' ) );
        $this->initialize_fluentbooking_default_options();
    }

    private function setup_fluentforms_license_management() {
        if ( get_option( '__fluentforms_using_custom_key' ) ) {
            remove_filter( 'pre_option___fluentform_pro_license', array( $this, 'override_fluentforms_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluentform_pro_license', array( $this, 'filter_fluentforms_license_update' ), 10 );
            remove_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentforms_update_transient' ) );
            return;
        }
        add_filter( 'pre_option___fluentform_pro_license', array( $this, 'override_fluentforms_license_status' ), 1 );
        add_filter( 'pre_update_option___fluentform_pro_license', array( $this, 'filter_fluentforms_license_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'modify_fluentforms_update_transient' ) );
        $this->initialize_fluentforms_default_options();
    }

    private function setup_affiliatewp_license_management() {
        if ( get_option( '__affiliatewp_using_custom_key' ) ) {
            remove_filter( 'pre_transient_affwp_license_check', array( $this, 'override_affiliatewp_license_check' ) );
            remove_filter( 'affwp_settings_get_license_status', array( $this, 'override_affiliatewp_license_status' ) );
            remove_filter( 'pre_option_affwp_settings', array( $this, 'filter_affiliatewp_settings' ), 10 );
            remove_filter( 'pre_update_option_affwp_settings', array( $this, 'filter_affiliatewp_settings_update' ), 10 );
            remove_filter( 'affwp_is_license_valid', array( $this, 'return_true' ) );
            remove_filter( 'affwp_is_upgrade_required', array( $this, 'override_upgrade_required_affiliatewp' ), 10 );
            remove_filter( 'affwp_can_access_pro_features', array( $this, 'return_true' ) );
            remove_filter( 'affiliatewp_can_access_plus_features', array( $this, 'return_true' ) );
            remove_filter( 'option_affwp_license_key', array( $this, 'get_affiliatewp_license_key' ) );
            return;
        }
        add_filter( 'pre_transient_affwp_license_check', array( $this, 'override_affiliatewp_license_check' ) );
        add_filter( 'affwp_settings_get_license_status', array( $this, 'override_affiliatewp_license_status' ) );
        add_filter( 'pre_option_affwp_settings', array( $this, 'filter_affiliatewp_settings' ), 10, 1 );
        add_filter( 'pre_update_option_affwp_settings', array( $this, 'filter_affiliatewp_settings_update' ), 10, 2 );
        add_filter( 'affwp_is_license_valid', array( $this, 'return_true' ) );
        add_filter( 'affwp_is_upgrade_required', array( $this, 'override_upgrade_required_affiliatewp' ), 10, 2 );
        add_filter( 'affwp_can_access_pro_features', array( $this, 'return_true' ) );
        add_filter( 'affiliatewp_can_access_plus_features', array( $this, 'return_true' ) );
        add_filter( 'option_affwp_license_key', array( $this, 'get_affiliatewp_license_key' ) );
        $this->initialize_affiliatewp_default_options();
    }
    
    public function check_direct_affiliatewp_license_changes() {
        if ( get_option( '__affiliatewp_using_custom_key' ) ) {
            return;
        }
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $settings    = get_option( 'affwp_settings', array() );
        if ( isset( $settings['license_key'] ) && ! empty( $settings['license_key'] ) && $settings['license_key'] !== $default_key ) {
            update_option( '__affiliatewp_using_custom_key', true );
            $this->setup_affiliatewp_license_management();
        }
    }

    private function setup_searchwp_license_management() {
        if ( get_option( '__searchwp_using_custom_key' ) ) {
            return;
        }
        add_filter( 'option_searchwp_license_key', array( $this, 'get_searchwp_license_key' ) );
        add_filter( 'option_searchwp_license_status', array( $this, 'get_searchwp_license_status' ) );
        $this->initialize_searchwp_default_options();
    }

    public function catch_direct_license_update( $new_value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( $new_value && $new_value !== $default_key ) {
            remove_filter( 'pre_option___fluent_community_pro_license', array( $this, 'override_fluent_license_status' ), 1 );
            remove_filter( 'pre_update_option___fluent_community_pro_license', array( $this, 'filter_license_update' ), 10 );
            remove_filter( 'fluent_community_pro_license_status', array( $this, 'force_valid_license_status' ) );
            remove_filter( 'fluent_community_pro_license_status_details', array( $this, 'force_valid_license_details' ) );
            remove_filter( 'rest_pre_dispatch', array( $this, 'handle_rest_license_check' ), 10 );
            remove_filter( 'pre_option_' . $this->license_verify_key, array( $this, 'force_license_verification_time' ) );
            delete_option( '__fluent_community_pro_license' );
            delete_option( '__fluent_community_pro_license_verify' );
            delete_transient( 'fc_license_check_status' );
            if ( class_exists( 'FluentCommunityPro\\App\\Services\\PluginManager\\LicenseManager' ) ) {
                do_action( 'fluent_community_license_verify' );
            }
        }
        return $new_value;
    }

    public function cleanup_on_deactivation() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        // Fluent Community
        $stored_key = get_option( '__fluent_community_pro_license_key' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            delete_option( '__fluent_community_pro_license' );
            delete_option( '__fluent_community_pro_license_verify' );
            delete_option( '__fluent_community_pro_license_key' );
            delete_transient( 'fc_license_check_status' );
            if ( class_exists( 'FluentCommunityPro\\App\\Services\\PluginManager\\LicenseManager' ) ) {
                do_action( 'fluent_community_license_verify' );
            }
        }
        // FluentBoards
        $stored_boards_key = get_option( '__fbs_plugin_license_key' );
        if ( ! $stored_boards_key || $stored_boards_key === $default_key ) {
            delete_option( '__fbs_plugin_license' );
            delete_option( '__fbs_plugin_license_key' );
            if ( class_exists( 'FluentBoardsPro\\App\\Services\\PluginManager\\LicenseManager' ) ) {
                do_action( 'fluent_boards_license_verify' );
            }
        }
        // AffiliateWP
        $stored_affiliatewp_key = get_option( 'affwp_license_key' );
        if ( ! $stored_affiliatewp_key || $stored_affiliatewp_key === $default_key ) {
            $settings = get_option( 'affwp_settings', array() );
            if ( isset( $settings['license_key'] ) ) {
                unset( $settings['license_key'] );
            }
            if ( isset( $settings['license_status'] ) ) {
                unset( $settings['license_status'] );
            }
            update_option( 'affwp_settings', $settings );
            delete_transient( 'affwp_license_check' );
        }
        // SearchWP
        $stored_searchwp_key = get_option( 'searchwp_license_key' );
        if ( ! $stored_searchwp_key || $stored_searchwp_key === $default_key ) {
            delete_option( 'searchwp_license_key' );
            delete_option( 'searchwp_license_status' );
        }
    }

    // Fluent Community methods
    public function override_fluent_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return false;
        }
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time(),
            'activated_by'  => 'launchkit'
        );
    }

    // FluentBoards methods
    public function override_fluentboards_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return false;
        }
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time(),
            'activated_by'  => 'launchkit'
        );
    }

    // AffiliateWP methods
    public function override_affiliatewp_license_check() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'affwp_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return false;
        }
        return 'valid';
    }

    public function override_affiliatewp_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'affwp_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $value;
        }
        $license_data                = new stdClass();
        $license_data->success       = true;
        $license_data->license       = 'valid';
        $license_data->item_name     = 'AffiliateWP';
        $license_data->expires       = date( 'Y-m-d', strtotime( '+10 years' ) );
        $license_data->customer_name = 'License Override';
        $license_data->customer_email= 'admin@example.com';
        $license_data->price_id      = 3;
        return $license_data;
    }

    public function filter_affiliatewp_settings( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'affwp_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            $value['license_key'] = $default_key;
            $value['license_status'] = $this->override_affiliatewp_license_status( null );
        } else {
            $value = array(
                'license_key'    => $default_key,
                'license_status' => $this->override_affiliatewp_license_status( null )
            );
        }
        return $value;
    }

    public function filter_affiliatewp_settings_update( $new_value, $old_value ) {
        if ( isset( $new_value['license_key'] ) && ! empty( $new_value['license_key'] ) ) {
            $user_data   = get_transient( 'lk_user_data' );
            $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
            if ( $new_value['license_key'] !== $default_key ) {
                update_option( '__affiliatewp_using_custom_key', true );
                $this->setup_affiliatewp_license_management();
                return $new_value;
            }
        }
        if ( get_option( '__affiliatewp_using_custom_key' ) ) {
            return $new_value;
        }
        if ( isset( $new_value['license_status'] ) ) {
            $new_value['license_status'] = $this->override_affiliatewp_license_status( null );
        }
        return $new_value;
    }

    public function get_affiliatewp_license_key( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( get_option( '__affiliatewp_using_custom_key' ) ) {
            return $value;
        }
        return $default_key;
    }

    public function get_searchwp_license_key( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( get_option( '__searchwp_using_custom_key' ) ) {
            return $value;
        }
        return $default_key;
    }

    public function get_searchwp_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'searchwp_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $value;
        }
        return 'valid';
    }

    public function override_upgrade_required_affiliatewp( $result, $license_level ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'affwp_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $result;
        }
        return false;
    }

    public function force_valid_license_status( $status ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $status;
        }
        return 'valid';
    }

    public function force_valid_license_details( $details ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $details;
        }
        return array(
            'status'  => 'valid',
            'expires' => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'success' => true
        );
    }

    public function handle_rest_license_check( $result, $server, $request ) {
        if ( ! $request ) {
            return $result;
        }
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $result;
        }
        if ( $request->get_route() == '/fluent-community/v2/license/status' ||
            strpos( $request->get_route(), 'fluent-community/v2/license' ) !== false ) {
            return $this->get_valid_license_response();
        }
        return $result;
    }

    public function handle_fluentboards_rest_license_check( $result, $server, $request ) {
        if ( ! $request ) {
            return $result;
        }
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $result;
        }
        if ( strpos( $request->get_route(), 'fluent-boards/v2/license' ) !== false ) {
            return $this->get_valid_fluentboards_license_response();
        }
        return $result;
    }

    public function handle_ajax_license_check() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return;
        }
        wp_send_json( $this->get_valid_license_response() );
    }

    public function handle_fluentboards_ajax_license_check() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return;
        }
        wp_send_json( $this->get_valid_fluentboards_license_response() );
    }

    private function get_valid_license_response() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key' => $default_key,
            'status'      => 'valid',
            'expires'     => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'success'     => true
        );
    }

    private function get_valid_fluentboards_license_response() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key' => $default_key,
            'status'      => 'valid',
            'expires'     => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'success'     => true
        );
    }

    // FluentCampaign Pro methods
    public function override_fluentcampaign_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time()
        );
    }

    public function filter_fluentcampaign_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    private function initialize_fluentcampaign_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentcrm_campaign_license_key', '' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fluentcrm_campaign_license', $this->override_fluentcampaign_license_status( null ) );
        }
    }

    // FluentSupport Pro methods
    public function override_fluentsupport_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time()
        );
    }

    public function filter_fluentsupport_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    private function initialize_fluentsupport_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentsupport_pro_license_key', '' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fluentsupport_pro_license', $this->override_fluentsupport_license_status( null ) );
        }
    }

    // FluentBooking Pro methods
    public function override_fluentbooking_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time()
        );
    }

    public function filter_fluentbooking_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    // FluentForms Pro methods
    public function override_fluentforms_license_status( $value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        return array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time()
        );
    }

    public function filter_fluentforms_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    private function initialize_fluentforms_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentform_pro_license_key', '' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fluentform_pro_license', $this->override_fluentforms_license_status( null ) );
        }
    }

    private function initialize_fluentbooking_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_booking_pro_license_key', '' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fluent_booking_pro_license', $this->override_fluentbooking_license_status( null ) );
        }
    }

    public function force_license_verification_time() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return false;
        }
        return time();
    }

    private function initialize_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fluent_community_pro_license', $this->override_fluent_license_status( null ) );
            update_option( $this->license_verify_key, time() );
        }
    }

    private function initialize_fluentboards_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( '__fbs_plugin_license', $this->override_fluentboards_license_status( null ) );
        }
    }

    private function initialize_affiliatewp_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'affwp_license_key' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( 'affwp_license_key', $default_key );
            $settings                = get_option( 'affwp_settings', array() );
            $settings['license_key'] = $default_key;
            $settings['license_status'] = $this->override_affiliatewp_license_status( null );
            update_option( 'affwp_settings', $settings );
            set_transient( 'affwp_license_check', 'valid', DAY_IN_SECONDS );
        }
    }

    private function initialize_searchwp_default_options() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( 'searchwp_license_key' );
        if ( ! $stored_key || $stored_key === $default_key ) {
            update_option( 'searchwp_license_key', $default_key );
            update_option( 'searchwp_license_status', 'valid' );
        }
    }

    private function update_fluent_license_status( $default_key ) {
        update_option( '__fluent_community_pro_license', array(
            'license_key'   => $default_key,
            'status'        => 'valid',
            'expires'       => date( 'Y-m-d', strtotime( '+10 years' ) ),
            'price_id'      => '1',
            '_last_checked' => time()
        ) );
    }

    private function update_affiliatewp_license_status( $default_key ) {
        $settings = get_option( 'affwp_settings', array() );
        $settings['license_key'] = $default_key;
        $settings['license_status'] = $this->override_affiliatewp_license_status( null );
        update_option( 'affwp_settings', $settings );
        set_transient( 'affwp_license_check', 'valid', DAY_IN_SECONDS );
    }

    private function update_searchwp_license_status( $default_key ) {
        update_option( 'searchwp_license_status', 'valid' );
    }

    public function filter_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    public function filter_fluentboards_license_update( $value, $old_value ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            $value['status']  = 'valid';
            $value['expires'] = date( 'Y-m-d', strtotime( '+10 years' ) );
        }
        return $value;
    }

    public function modify_plugin_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_community_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluent-community-pro/fluent-community-pro.php'] ) ) {
            unset( $transient->response['fluent-community-pro/fluent-community-pro.php'] );
        }
        return $transient;
    }

    public function modify_fluentboards_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fbs_plugin_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluent-boards-pro/fluent-boards-pro.php'] ) ) {
            unset( $transient->response['fluent-boards-pro/fluent-boards-pro.php'] );
        }
        return $transient;
    }

    public function modify_fluentcampaign_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentcrm_campaign_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluentcampaign-pro/fluentcampaign-pro.php'] ) ) {
            unset( $transient->response['fluentcampaign-pro/fluentcampaign-pro.php'] );
        }
        return $transient;
    }

    public function modify_fluentsupport_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentsupport_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluent-support-pro/fluent-support-pro.php'] ) ) {
            unset( $transient->response['fluent-support-pro/fluent-support-pro.php'] );
        }
        return $transient;
    }

    public function modify_fluentbooking_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluent_booking_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluent-booking/fluent-booking.php'] ) ) {
            unset( $transient->response['fluent-booking/fluent-booking.php'] );
        }
        return $transient;
    }

    public function modify_fluentforms_update_transient( $transient ) {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key  = get_option( '__fluentform_pro_license_key' );
        if ( $stored_key && $stored_key !== $default_key ) {
            return $transient;
        }
        if ( isset( $transient->response['fluentformpro/fluentformpro.php'] ) ) {
            unset( $transient->response['fluentformpro/fluentformpro.php'] );
        }
        return $transient;
    }

    public function return_true() {
        return true;
    }

    public function remove_license_notices() {
        $user_data               = get_transient( 'lk_user_data' );
        $default_key             = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $stored_key              = get_option( '__fluent_community_pro_license_key' );
        $stored_boards_key       = get_option( '__fbs_plugin_license_key' );
        $stored_affiliatewp_key  = get_option( 'affwp_license_key' );
        $stored_searchwp_key     = get_option( 'searchwp_license_key' );
        if ( ( $stored_key && $stored_key !== $default_key ) &&
            ( $stored_boards_key && $stored_boards_key !== $default_key ) &&
            ( $stored_affiliatewp_key && $stored_affiliatewp_key !== $default_key ) &&
            ( $stored_searchwp_key && $stored_searchwp_key !== $default_key ) ) {
            return;
        }
        global $wp_filter;
        if ( isset( $wp_filter['admin_notices'] ) ) {
            foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $key => $callback ) {
                    if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) ) {
                        $class_name = get_class( $callback['function'][0] );
                        if ( strpos( $class_name, 'FluentCommunity' ) !== false ||
                            strpos( $class_name, 'FluentBoards' ) !== false ||
                            strpos( $class_name, 'Affiliate_WP' ) !== false ||
                            strpos( $class_name, 'SearchWP' ) !== false ) {
                            remove_action( 'admin_notices', $callback['function'], $priority );
                        }
                    }
                }
            }
        }
    }

    /**
     * ================================================================
     * UI: License Management Screen
     * ================================================================
     */
    public function launchkit_license_menu() {
        $parent_slug = 'cwpk';
        $page_slug   = 'license';
        $capability  = 'manage_options';
        add_submenu_page(
            $parent_slug,
            __( 'CribOps WP-Kit License', 'launchkit-license' ),
            __( 'CribOps WP-Kit License', 'launchkit-license' ),
            $capability,
            $page_slug,
            array( $this, 'license_key_autoloader_page' )
        );
    }

    /**
     * Displays a table with one row per supported plugin.
     * The options are displayed in the following order:
     * ACF Pro, AffiliateWP, FluentBoards, FluentCommunity, Kadence, SearchWP.
     */
    public function license_key_autoloader_page() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';

        // Get saved custom-key flags.
        $acf_custom           = get_option( 'acf_using_custom_key' );
        $affiliatewp_custom   = get_option( '__affiliatewp_using_custom_key' );
        $fluentboards_custom  = get_option( '__fluentboards_using_custom_key' );
        $fluentcampaign_custom= get_option( '__fluentcampaign_using_custom_key' );
        $fluentsupport_custom = get_option( '__fluentsupport_using_custom_key' );
        $fluentbooking_custom = get_option( '__fluentbooking_using_custom_key' );
        $fluentforms_custom   = get_option( '__fluentforms_using_custom_key' );
        $fluent_custom        = get_option( '__fluent_using_custom_key' );
        $kadence_custom       = get_option( 'stellarwp_uplink_using_custom_key_kadence' );
        $searchwp_custom      = get_option( '__searchwp_using_custom_key' );
        ?>
        <div class="wrap">
            <h1><?php _e( 'License Management', 'launchkit-license' ); ?></h1>
            <p><?php _e( 'Use the toggles below if you prefer to use your own license for each plugin instead of our pro licenses.', 'launchkit-license' ); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field( 'license_key_autoloader' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e( 'ACF Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[acf]" value="1" <?php checked( $acf_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'AffiliateWP License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[affiliatewp]" value="1" <?php checked( $affiliatewp_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentBoards Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluentboards]" value="1" <?php checked( $fluentboards_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentBooking Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluentbooking]" value="1" <?php checked( $fluentbooking_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentCampaign Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluentcampaign]" value="1" <?php checked( $fluentcampaign_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentCommunity Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluent]" value="1" <?php checked( $fluent_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentForms Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluentforms]" value="1" <?php checked( $fluentforms_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FluentSupport Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[fluentsupport]" value="1" <?php checked( $fluentsupport_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Kadence Pro License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[kadence]" value="1" <?php checked( $kadence_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'SearchWP License', 'launchkit-license' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_keys[searchwp]" value="1" <?php checked( $searchwp_custom, true ); ?> />
                                <?php _e( 'Use my own license', 'launchkit-license' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_license_settings" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'launchkit-license' ); ?>" />
                    <input type="submit" name="reset_all" class="button-secondary" value="<?php esc_attr_e( 'Reset All to Default', 'launchkit-license' ); ?>" />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Processes saving the license toggles or resetting all to default.
     * Runs only on POST requests.
     */
    public function license_key_autoloader_save() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }
        if ( ! check_admin_referer( 'license_key_autoloader' ) ) {
            return;
        }
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';

        // Reset all to default if requested.
        if ( isset( $_POST['reset_all'] ) ) {
            // Kadence
            delete_option( 'stellarwp_uplink_using_custom_key_kadence' );
            update_option( 'stellarwp_uplink_license_key_kadence-blocks-pro', $default_key );
            update_option( 'stellarwp_uplink_license_key_kadence-theme-pro', $default_key );
            // FluentCommunity
            delete_option( '__fluent_using_custom_key' );
            update_option( '__fluent_community_pro_license_key', $default_key );
            $this->update_fluent_license_status( $default_key );
            // FluentBoards
            delete_option( '__fluentboards_using_custom_key' );
            update_option( '__fbs_plugin_license_key', $default_key );
            // FluentCampaign Pro
            delete_option( '__fluentcampaign_using_custom_key' );
            update_option( '__fluentcrm_campaign_license', $this->override_fluentcampaign_license_status( null ) );
            // FluentSupport Pro
            delete_option( '__fluentsupport_using_custom_key' );
            update_option( '__fluentsupport_pro_license', $this->override_fluentsupport_license_status( null ) );
            // FluentBooking Pro
            delete_option( '__fluentbooking_using_custom_key' );
            update_option( '__fluent_booking_pro_license', $this->override_fluentbooking_license_status( null ) );
            // FluentForms Pro
            delete_option( '__fluentforms_using_custom_key' );
            update_option( '__fluentform_pro_license', $this->override_fluentforms_license_status( null ) );
            // AffiliateWP
            delete_option( '__affiliatewp_using_custom_key' );
            update_option( 'affwp_license_key', $default_key );
            $settings = get_option( 'affwp_settings', array() );
            $settings['license_key'] = $default_key;
            update_option( 'affwp_settings', $settings );
            $this->update_affiliatewp_license_status( $default_key );
            // SearchWP
            delete_option( '__searchwp_using_custom_key' );
            update_option( 'searchwp_license_key', $default_key );
            $this->update_searchwp_license_status( $default_key );
            // ACF Pro
            delete_option( 'acf_using_custom_key' );
            $license_data = array(
                'key' => $default_key,
                'url' => get_site_url()
            );
            update_option( 'acf_pro_license', base64_encode( serialize( $license_data ) ) );
        }
        // Otherwise, process save changes.
        if ( isset( $_POST['save_license_settings'] ) ) {
            $custom_keys = isset( $_POST['custom_keys'] ) ? $_POST['custom_keys'] : array();
            // Kadence
            if ( ! empty( $custom_keys['kadence'] ) ) {
                update_option( 'stellarwp_uplink_using_custom_key_kadence', true );
            } else {
                delete_option( 'stellarwp_uplink_using_custom_key_kadence' );
                update_option( 'stellarwp_uplink_license_key_kadence-blocks-pro', $default_key );
                update_option( 'stellarwp_uplink_license_key_kadence-theme-pro', $default_key );
            }
            // FluentCommunity
            if ( ! empty( $custom_keys['fluent'] ) ) {
                update_option( '__fluent_using_custom_key', true );
                delete_option( '__fluent_community_pro_license' );
                delete_option( '__fluent_community_pro_license_verify' );
                delete_option( '__fluent_community_pro_license_key' );
                delete_transient( 'fc_license_check_status' );
                $this->setup_enhanced_license_management();
            } else {
                delete_option( '__fluent_using_custom_key' );
                update_option( '__fluent_community_pro_license_key', $default_key );
                $this->update_fluent_license_status( $default_key );
                $this->setup_enhanced_license_management();
            }
            // FluentBoards
            if ( ! empty( $custom_keys['fluentboards'] ) ) {
                update_option( '__fluentboards_using_custom_key', true );
                delete_option( '__fbs_plugin_license' );
                delete_option( '__fbs_plugin_license_key' );
                $this->setup_fluentboards_license_management();
            } else {
                delete_option( '__fluentboards_using_custom_key' );
                update_option( '__fbs_plugin_license_key', $default_key );
                update_option( '__fbs_plugin_license', $this->override_fluentboards_license_status( null ) );
                $this->setup_fluentboards_license_management();
            }
            // FluentCampaign Pro
            if ( ! empty( $custom_keys['fluentcampaign'] ) ) {
                update_option( '__fluentcampaign_using_custom_key', true );
                delete_option( '__fluentcrm_campaign_license' );
                delete_option( '__fluentcrm_campaign_license_key' );
                $this->setup_fluentcampaign_license_management();
            } else {
                delete_option( '__fluentcampaign_using_custom_key' );
                update_option( '__fluentcrm_campaign_license', $this->override_fluentcampaign_license_status( null ) );
                $this->setup_fluentcampaign_license_management();
            }
            // FluentSupport Pro
            if ( ! empty( $custom_keys['fluentsupport'] ) ) {
                update_option( '__fluentsupport_using_custom_key', true );
                delete_option( '__fluentsupport_pro_license' );
                delete_option( '__fluentsupport_pro_license_key' );
                $this->setup_fluentsupport_license_management();
            } else {
                delete_option( '__fluentsupport_using_custom_key' );
                update_option( '__fluentsupport_pro_license', $this->override_fluentsupport_license_status( null ) );
                $this->setup_fluentsupport_license_management();
            }
            // FluentBooking Pro
            if ( ! empty( $custom_keys['fluentbooking'] ) ) {
                update_option( '__fluentbooking_using_custom_key', true );
                delete_option( '__fluent_booking_pro_license' );
                delete_option( '__fluent_booking_pro_license_key' );
                $this->setup_fluentbooking_license_management();
            } else {
                delete_option( '__fluentbooking_using_custom_key' );
                update_option( '__fluent_booking_pro_license', $this->override_fluentbooking_license_status( null ) );
                $this->setup_fluentbooking_license_management();
            }
            // FluentForms Pro
            if ( ! empty( $custom_keys['fluentforms'] ) ) {
                update_option( '__fluentforms_using_custom_key', true );
                delete_option( '__fluentform_pro_license' );
                delete_option( '__fluentform_pro_license_key' );
                $this->setup_fluentforms_license_management();
            } else {
                delete_option( '__fluentforms_using_custom_key' );
                update_option( '__fluentform_pro_license', $this->override_fluentforms_license_status( null ) );
                $this->setup_fluentforms_license_management();
            }
            // AffiliateWP
            if ( ! empty( $custom_keys['affiliatewp'] ) ) {
                update_option( '__affiliatewp_using_custom_key', true );
            } else {
                delete_option( '__affiliatewp_using_custom_key' );
                update_option( 'affwp_license_key', $default_key );
                $settings = get_option( 'affwp_settings', array() );
                $settings['license_key'] = $default_key;
                update_option( 'affwp_settings', $settings );
                $this->update_affiliatewp_license_status( $default_key );
            }
            // SearchWP
            if ( ! empty( $custom_keys['searchwp'] ) ) {
                update_option( '__searchwp_using_custom_key', true );
            } else {
                delete_option( '__searchwp_using_custom_key' );
                update_option( 'searchwp_license_key', $default_key );
                $this->update_searchwp_license_status( $default_key );
            }
            // ACF Pro
            if ( ! empty( $custom_keys['acf'] ) ) {
                update_option( 'acf_using_custom_key', true );
            } else {
                delete_option( 'acf_using_custom_key' );
                $license_data = array(
                    'key' => $default_key,
                    'url' => get_site_url()
                );
                update_option( 'acf_pro_license', base64_encode( serialize( $license_data ) ) );
            }
        }
        wp_redirect( admin_url( 'admin.php?page=cwpk&tab=license&settings-updated=true' ) );
        exit;
    }

    public function conditionally_run_license_key_autoloader_save() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cwpk' || ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'license' ) {
            return;
        }
        $this->license_key_autoloader_save();
    }

    /**
     * Hides certain notices when the default key is in use.
     */
    public function hide_fcom_portal_section_if_default_key() {
        $user_data   = get_transient( 'lk_user_data' );
        $default_key = isset( $user_data['default_key'] ) ? $user_data['default_key'] : '';
        $current_key = get_option( '__fluent_community_pro_license_key', '' );
        if ( $current_key === $default_key && get_option( '__fluent_community_pro_license' ) ) {
            echo '<style>.fcal_license_box, #fluent-community-pro-invalid-notice { display: none !important; }</style>';
        }
        $affwp_key = get_option( 'affwp_license_key', '' );
        if ( $affwp_key === $default_key ) {
            echo '<style>.affwp-license-notice, .affwp-admin-notice { display: none !important; }</style>';
        }
    }
}

// Initialize the plugin.
new CWPKLicenseKeyAutoloader();