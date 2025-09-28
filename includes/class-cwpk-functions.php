<?php 

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
    * WPLK Functions LaunchKit
    *
    *
    * @since 1.0.0
    */

/*
Plugin Name: Re-enable Dependent Plugin Deactivate & Delete
Plugin URI: https://cribops.com
Description: Restores the plugin manager behavior to pre version 6.5 capability
Version: 1.0.10
Author: CribOps Development Team
Author URI: https://cribops.com
*/

/*
 * Add the required JavaScript to handle removing the disabled attribute from checkboxes.
 */

function lk_enable_plugin_deactivation_js() {
    ?>
    <script type="text/javascript">
        /* WordPress will load jQuery and hook the script */
        jQuery(document).ready(function($) {
            // Remove the disabled attribute from checkboxes on the plugin manager page
            $('input[type="checkbox"]').removeAttr('disabled');
            
            // Hide the "Required by" and "Requires" sections
            $('.required-by, .requires').hide();
        });
    </script>
    <?php
}

/*
 * Add the CSS to hide the "Required by" and "Requires" sections from the plugin manager page.
 */


function lk_enable_plugin_deactivation_css() {
    echo '<style type="text/css">
        .required-by, .requires, .deactivate, .delete {
            display: none;
        }
    </style>';
}


function lk_add_deactivate_link($actions, $plugin_file) {
    if (is_plugin_active($plugin_file)) {
        $deactivate_link = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'deactivate',
                    'plugin' => $plugin_file,
                ),
                admin_url('plugins.php')
            ),
            'deactivate-plugin_' . $plugin_file
        );

        $actions['deactivate_link'] = '<a href="' . esc_url($deactivate_link) . '">' . __('Deactivate') . '</a>';
    }

    return $actions;
}

function lk_add_delete_link($actions, $plugin_file) {
    if (!is_plugin_active($plugin_file)) {
        $delete_link = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'delete-selected',
                    'checked[]' => $plugin_file,
                ),
                admin_url('plugins.php')
            ),
            'bulk-plugins'
        );

        $actions['delete_link'] = '<a href="' . esc_url($delete_link) . '">' . __('Delete') . '</a>';
    }

    return $actions;
}