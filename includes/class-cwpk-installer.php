<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WPLKInstaller Class
 *
 * @since 2.11.5
 */
class CWPKInstaller {

    // API URL is now managed by CWPKConfig class

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Add sub-menus
        add_action('admin_menu', array($this, 'launchkit_installer_menu'));
        add_action('admin_menu', array($this, 'launchkit_packages_menu'));

        // callbacks
        add_action('wp_ajax_check_for_updates', array($this, 'check_for_updates_callback'));
        add_action('wp_ajax_install_prime_mover', array($this, 'install_prime_mover_callback'));
        add_action('in_admin_header', array($this, 'launchkit_banner_on_prime_mover_backup_menu'));
        add_action('wp_ajax_upload_package_from_url', array($this, 'upload_package_from_url_callback'));
        add_action('wp_ajax_check_package_download_progress', array($this, 'check_package_download_progress_callback'));
        add_action('wp_ajax_get_prime_package', array($this, 'get_prime_package_callback'));
        add_action('admin_menu', array($this, 'get_prime_submenu'));

        // Skip prime mover activation script
        add_action('admin_footer', array($this, 'skip_prime_mover_activation_script'));

        add_action('wp_ajax_install_mainwp_child', array($this, 'install_mainwp_child_callback'));
        add_action('wp_ajax_install_base_launchkit', array($this, 'install_base_launchkit_callback'));

        add_action('wp_ajax_install_plugin', array($this, 'install_plugin_callback'));
        add_action('wp_ajax_check_plugin_updates', array($this, 'check_plugin_updates_callback'));
        add_action('wp_ajax_refresh_packages', array($this, 'refresh_packages_callback'));

        // Auto-activate Prime Mover Pro license if available
        add_action('admin_init', array($this, 'auto_activate_prime_mover_license'));

        // Auto-activate Automatic.css license if available
        add_action('admin_init', array($this, 'auto_activate_automatic_css_license'));
    }

    /**
     * Add the Installer Submenu
     */
    public function launchkit_installer_menu() {
        $parent_slug = 'cwpk'; // The slug of the LaunchKit plugin's main menu
        $page_slug   = 'launchkit-installer'; // The slug for the submenu page
        $capability  = 'manage_options';

        add_submenu_page(
            $parent_slug,
            __('CribOps WP-Kit Installer', 'launchkit-installer'),
            __('CribOps WP-Kit Installer', 'launchkit-installer'),
            $capability,
            $page_slug,
            array($this, 'lk_get_meta_plugin_installer_page')
        );

        // Hide the submenu visually
        add_action('admin_head', array($this, 'launchkit_hide_installer_submenu_item'));
    }

    /**
     * Hide the empty space of the hidden Installer submenu item
     */
    public function launchkit_hide_installer_submenu_item() {
        global $submenu;
        $parent_slug = 'cwpk';
        $page_slug   = 'launchkit-installer';

        if (isset($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as &$item) {
                if ($item[2] === $page_slug) {
                    $item[4] = 'launchkit-installer-hidden';
                    break;
                }
            }
        }

        echo '<style>.launchkit-installer-hidden { display: none !important; }</style>';
    }

    /**
     * Add the Packages Submenu
     */
    public function launchkit_packages_menu() {
        $parent_slug = 'cwpk'; // The slug of the LaunchKit plugin's main menu
        $page_slug   = 'cwpk-packages'; // The slug for the submenu page
        $capability  = 'manage_options';

        add_submenu_page(
            $parent_slug,
            __('CribOps WP-Kit Packages', 'cwpk-packages'),
            __('CribOps WP-Kit Packages', 'cwpk-packages'),
            $capability,
            $page_slug,
            array($this, 'get_prime_page')
        );

        // Hide the submenu visually
        add_action('admin_head', array($this, 'launchkit_hide_packages_submenu_item'));
    }

    /**
     * Hide the empty space of the hidden Packages submenu item
     */
    public function launchkit_hide_packages_submenu_item() {
        global $submenu;
        $parent_slug = 'cwpk';
        $page_slug   = 'cwpk-packages';

        if (isset($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as &$item) {
                if ($item[2] === $page_slug) {
                    $item[4] = 'cwpk-packages-hidden';
                    break;
                }
            }
        }

        echo '<style>.cwpk-packages-hidden { display: none !important; }</style>';
    }

    /**
     * Main "Installer" / Installer Page
     */
    public function lk_get_meta_plugin_installer_page() {
        // Check authentication using the new auth system
        $is_authenticated = CWPKAuth::is_authenticated();
        $user_data = get_transient('lk_user_data');
        $auth_type = CWPKAuth::get_auth_type();

        // Standard WP admin wrap
      //  echo '<div class="wrap">';
        echo '<h1>Software Bundle Plugin Installer</h1>';

        // If not logged in
        if (! $is_authenticated) {
            // Check if authentication failed due to site limit
            $token = CWPKAuth::get_env_bearer_token();
            if ($token) {
                $result = CWPKAuth::authenticate_with_token($token);
                if (is_wp_error($result) && $result->get_error_code() === 'site_limit_exceeded') {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>Site Limit Exceeded:</strong> ' . esc_html($result->get_error_message());
                    echo '</p><p><a href="https://cribops.com/pricing" target="_blank" class="button button-primary">Upgrade Your Plan</a> to add more sites.</p></div>';
                    return;
                }
                echo '<div class="cwpk-notice" style="background: #fff3cd; border-left-color: #ffc107;"><p>Bearer token found but authentication failed. Please check your token configuration or use username/password login.</p></div>';
            } else {
                echo '<div class="cwpk-notice"><p>You are logged-out. Please log in via the header using your CribOps username and password.</p></div>';
            }

            echo '<p>Unlock All Features By Subscribing To <a href="https://cribops.com/pricing" target="_blank">CribOps WP-Kit Software Bundle</a></p>';
            echo '</div>'; // .wrap
            return;
        }

        // If logged in, check if can_access_launchkit
        $can_access_launchkit = ! empty($user_data['can_access_launchkit']);
        $first_name           = isset($user_data['first_name']) ? $user_data['first_name'] : '';

        if (! $can_access_launchkit) {
            // Logged in but no membership
            echo '<div class="notice notice-error"><p>';
            echo 'You are logged in, but your account does not have Software Bundle access. ';
            echo 'Please check that you have a current Subscription with <a href="https://cribops.com" target="_blank">CribOps</a>.';
            echo '</p></div>';
            echo '</div>'; // .wrap
            return;
        }

        // Logged in + can_access_launchkit = true
        // Show appropriate greeting based on auth type
        if (!empty($first_name)) {
            if ($auth_type === 'token') {
                echo '<p>Hi ' . esc_html($first_name) . ', you have Software Bundle access.</p>';
            } else {
                echo '<p>Hi ' . esc_html($first_name) . ', you are logged in with Software Bundle.</p>';
            }
        } elseif ($auth_type === 'credentials') {
            // No name available but using password auth
            echo '<p>You are logged in with Software Bundle access.</p>';
        }
        // For token auth without a name, no greeting needed since the green badge shows auth status

        // Display site usage information
        if (!empty($user_data['site_usage'])) {
            $this->display_site_usage_notice($user_data['site_usage']);
        }

        // Check if Prime Mover plugin is installed
        $prime_mover_installed = is_plugin_active('prime-mover/prime-mover.php');
        // Check if MainWP Child plugin is installed
        $mainwp_child_installed = is_plugin_active('mainwp-child/mainwp-child.php');
        ?>
        <p>
            <?php if (! $mainwp_child_installed) : ?>
                <button type="button" class="button button-primary" id="install_mainwp_child">Install MainWP Child</button>
            <?php else : ?>
                <button type="button" class="button button-primary" disabled>MainWP Child Installed</button>
            <?php endif; ?>
            <?php if (! $prime_mover_installed) : ?>
                <button type="button" class="button button-primary" id="install_prime_mover">Install Prime Mover</button>
            <?php else : ?>
                <button type="button" class="button button-primary" id="install_base_launchkit_package">Install Base LaunchKit Package</button>
                <button type="button" class="button button-primary" id="view_packages">View Packages</button>
            <?php endif; ?>
        </p>
        <?php
        // Add sub-navigation tabs for Plugins and Themes
        $installer_tab = isset($_GET['installer_tab']) ? $_GET['installer_tab'] : 'plugins';
        ?>
        <style>
            .cwpk-installer-tabs {
                margin: 20px 0;
                border-bottom: 1px solid #ccc;
            }
            .cwpk-installer-tabs a {
                display: inline-block;
                padding: 10px 20px;
                text-decoration: none;
                border: 1px solid transparent;
                margin-bottom: -1px;
            }
            .cwpk-installer-tabs a.active {
                background: #fff;
                border: 1px solid #ccc;
                border-bottom: 1px solid #fff;
            }
        </style>
        <nav class="cwpk-installer-tabs">
            <a href="?page=cwpk&tab=installer&installer_tab=plugins" class="<?php echo $installer_tab === 'plugins' ? 'active' : ''; ?>">Plugins</a>
            <a href="?page=cwpk&tab=installer&installer_tab=themes" class="<?php echo $installer_tab === 'themes' ? 'active' : ''; ?>">Themes</a>
            <a href="?page=cwpk&tab=installer&installer_tab=packages" class="<?php echo $installer_tab === 'packages' ? 'active' : ''; ?>">Packages</a>
        </nav>

        <?php
        if ($installer_tab === 'themes') {
            // Display theme installer
            if (class_exists('CWPK_Theme_Manager')) {
                $theme_manager = new CWPK_Theme_Manager();
                $theme_manager->display_theme_installer();
            } else {
                echo '<p>Theme manager not available. Please ensure the theme manager class is loaded.</p>';
            }
        } elseif ($installer_tab === 'packages') {
            // Display packages installer
            $this->display_packages_tab();
        } else {
            // Display plugin updates & table
            $this->fetch_latest_launchkit_plugins();
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // MainWP Child
            $('#install_mainwp_child').click(function() {
                var button = $(this);
                button.text('Installing...').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'install_mainwp_child',
                    security: '<?php echo wp_create_nonce("install_mainwp_child_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        button.text('MainWP Child Installed').prop('disabled', true);
                        if (response.data && response.data.message) {
                            alert(response.data.message);
                        }
                        // Reload page to update button states
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        button.text('Installation Failed').prop('disabled', false);
                        if (response.data) {
                            alert('Installation Error: ' + response.data);
                        }
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    button.text('Installation Failed').prop('disabled', false);
                    alert('Request failed: ' + textStatus + ' - ' + errorThrown);
                });
            });

            // Prime Mover
            $('#install_prime_mover').click(function() {
                var button = $(this);
                button.text('Installing...').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'install_prime_mover',
                    security: '<?php echo wp_create_nonce("install_prime_mover_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        button.text('Prime Mover Installed').prop('disabled', true);
                        if (response.data && response.data.message) {
                            alert(response.data.message);
                        }
                        location.reload();
                    } else {
                        button.text('Installation Failed').prop('disabled', false);
                        if (response.data) {
                            alert('Installation Error: ' + response.data);
                            console.error('Prime Mover installation error:', response.data);
                        }
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    button.text('Installation Failed').prop('disabled', false);
                    alert('Request failed: ' + textStatus + ' - ' + errorThrown);
                    console.error('AJAX error:', textStatus, errorThrown);
                });
            });

            // Base LaunchKit
            $('#install_base_launchkit_package').click(function() {
                var button = $(this);
                button.text('Installing...').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'get_prime_package',
                    security: '<?php echo wp_create_nonce("get_prime_package_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        button.text('Base LaunchKit Package Installed').prop('disabled', true);
                    } else {
                        button.text('Installation Failed').prop('disabled', false);
                    }
                }).fail(function() {
                    button.text('Installation Failed').prop('disabled', false);
                });
            });

            // View Packages
            $('#view_packages').click(function() {
                window.location.href = '<?php echo admin_url('admin.php?page=migration-panel-backup-menu'); ?>';
            });
        });
        </script>
        <?php
       // echo '</div>'; // .wrap
    }

    /**
     * Display plugin updates using API manifest method
     */
    public function fetch_latest_launchkit_plugins() {
        $user_data = get_transient('lk_user_data');

        // Always use manifest-based installer for plugin list
        $this->display_manifest_installer();
    }

    /**
     * Display "Plugins Being Tested" and the plugin table
     */
    public function display_plugin_updates() {
        echo '<div class="plugin-updates">';
        echo '<h3 style="display:inline-block">Plugins Being Tested</h3><br/>';
        echo '<span>Plugins with available updates that we need to test before releasing to the Software Bundle.</span>';
        echo '<a href="#" id="check_plugin_updates" style="display: inline-block; margin-left: 10px;">Check Plugins For Updates</a>';
        echo '<ul id="plugin_update_list"></ul>';
        echo '</div>';
        echo '<style>
            .plugin-updates {
                margin-bottom: 20px; 
                border: 1px dashed #DEDEDD;
                background: #f6f6f6;
                border-radius: 10px;
                padding: 0 10px;
            }
            .plugin-updates h3 { margin-bottom: 10px; }
            .plugin-updates ul { margin-left: 20px; }
            #check_plugin_updates {
                display: inline-block;
                margin-bottom: 10px;
                text-decoration: none;
            }
        </style>';
        echo '<script>
            jQuery(document).ready(function($) {
                function updatePluginList() {
                    $("#check_plugin_updates").text("Checking for updates...").css("pointer-events", "none");
                    $.post(ajaxurl, {
                        action: "check_plugin_updates",
                        security: "' . wp_create_nonce("check_plugin_updates_nonce") . '"
                    }, function(response) {
                        $("#plugin_update_list").html(response);
                        $("#check_plugin_updates").text("Check Plugins For Updates").css("pointer-events", "auto");
                    });
                }
                updatePluginList(); // initial load
                $("#check_plugin_updates").click(function(e) {
                    e.preventDefault();
                    updatePluginList();
                });
            });
        </script>';
    }

    /**
     * Show the table of "Plugins Available To Update"
     */
    public function lk_display_plugins_table($target_dir, $upload_dir) {
        $last_download_timestamp = get_option('lk_last_download_timestamp', 0);
        date_default_timezone_set('America/Chicago');
        $last_updated_date = $last_download_timestamp > 0 ? date('F j, Y \a\t g:ia', $last_download_timestamp) : 'Never';

        // "Plugins Being Tested"
        $this->display_plugin_updates();

        echo '<h3 style="display:inline-block;">Plugins Available To Update</h3>';
        echo '<span style="margin-left: 10px;">Last Updated: ' . $last_updated_date . ' (Chicago Time)</span>';
        echo '<div style="clear:both; margin-bottom:10px;"></div>';
        echo '<button type="button" id="install_selected" class="button button-primary">Install Selected Plugins</button>';

        // Cleanup
        ?>
        <button type="button" id="launchkit-cleanup-button" class="button button-secondary"><?php _e('Cleanup All Inactive Plugins', 'lk'); ?></button>
        <div id="launchkit-progress"></div>
        <div id="launchkit-summary"></div>
        <script type="text/javascript">
        document.getElementById('launchkit-cleanup-button').addEventListener('click', function() {
            document.getElementById('launchkit-progress').textContent = '<?php _e('Cleaning up inactive plugins...', 'lk'); ?>';
            document.getElementById('launchkit-summary').innerHTML = '';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        document.getElementById('launchkit-progress').textContent = '<?php _e('Cleanup complete.', 'lk'); ?>';

                        var allDeleted = response.data.deleted.concat(response.data.manual);
                        var deletedList = '<p><?php _e("Deleted Plugins:", "lk"); ?></p><ul>';
                        allDeleted.forEach(function(plugin) {
                            deletedList += '<li>' + plugin + '</li>';
                        });
                        deletedList += '</ul>';

                        document.getElementById('launchkit-summary').innerHTML = deletedList;

                        var refreshLink = '<br/><a href="#" id="launchkit-refresh-link" style="color:red;text-decoration:underline;"><?php _e("Click To Refresh Plugin List", "lk"); ?></a>';
                        document.getElementById('launchkit-summary').innerHTML += refreshLink;

                        document.getElementById('launchkit-refresh-link').addEventListener('click', function(e) {
                            e.preventDefault();
                            window.location.reload();
                        });
                    } else {
                        document.getElementById('launchkit-progress').textContent = '<?php _e("Error:", "lk"); ?> ' + response.data;
                    }
                } else {
                    document.getElementById('launchkit-progress').textContent = '<?php _e("An error occurred.", "lk"); ?>';
                }
            };
            xhr.send('action=launchkit_cleanup_plugins&security=<?php echo wp_create_nonce('launchkit_cleanup_plugins_nonce'); ?>');
        });
        </script>
        <?php

        echo '<div class="lk-plugin-installer-form">';
        echo '<form id="plugin_installer_form">';
        echo '<table class="wp-list-table widefat fixed striped" style="width:100%; max-width:800px;" id="plugin-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width:30px;" data-column-name="Select"><input type="checkbox" id="select_all" /></th>';
        echo '<th class="plugin-file-column sortable" data-sort="plugin-file" style="width:300px;" data-column-name="Plugin File"><span>Plugin File (Click To Download)</span><span class="sorting-indicator">&#9660;</span></th>';
        echo '<th class="sortable" data-sort="last-update" data-column-name="Last Update"><span>Last Update</span><span class="sorting-indicator">&#9660;</span></th>';
        echo '<th style="width:100px;" data-column-name="Status">Status</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $plugin_files = glob("$target_dir/*.zip");
        if ($plugin_files) {
            foreach ($plugin_files as $file) {
                $plugin_name  = basename($file, ".zip");
                $plugin_slug  = sanitize_title($plugin_name);
                $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin_slug);
                $status       = $is_installed ? 'Installed' : 'Not Installed';

                $action_button = $is_installed
                    ? 'Installed'
                    : "<button type='button' class='button install_button' data-url='" . esc_url(trailingslashit($upload_dir['baseurl']) . "cribops-wp-kit/" . basename($file)) . "'>Install</button>";

                $last_modified = date('Y-m-d', filemtime($file));
                $download_link = esc_url(trailingslashit($upload_dir['baseurl']) . "cribops-wp-kit/" . basename($file));

                echo "<tr>";
                echo "<td><input type='checkbox' class='plugin_checkbox' data-url='$download_link' data-slug='$plugin_slug'></td>";
                echo "<td data-plugin-file='$plugin_name'><a href='$download_link' download>$plugin_name.zip</a></td>";
                echo "<td data-last-update='$last_modified'>" . date('F j, Y', strtotime($last_modified)) . "</td>";
                echo "<td class='plugin_status'>$action_button</td>";
                echo "</tr>";
            }
        } else {
            echo '<tr><td colspan="4">No plugin files found. Click "Check For Updates" to download them.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
        echo '</div>';

        $ajax_nonce = wp_create_nonce('plugin_installer_nonce');
        ?>
        <script type="text/javascript">
        (function($){
            // Single plugin install
            $(document).on('click', '.install_button', function() {
                var button = $(this);
                var plugin_url = button.data('url');
                var status_element = button.closest('tr').find('.plugin_status');

                status_element.html('Installing... <img src="<?php echo includes_url('images/spinner.gif'); ?>" alt="Installing...">');

                $.post(ajaxurl, {
                    action: 'install_plugin',
                    plugin_url: plugin_url,
                    security: '<?php echo $ajax_nonce; ?>'
                }, function(response) {
                    if (response.success) {
                        status_element.html('Installed');
                    } else {
                        status_element.html('Installation failed: ' + response.data);
                    }
                });
            });

            // Bulk install
            $('#install_selected').click(function() {
                $('.plugin_checkbox').each(function() {
                    if ($(this).is(':checked')) {
                        $(this).closest('tr').find('.install_button').trigger('click');
                    }
                });
            });

            // Select all
            $('#select_all').change(function() {
                $('.plugin_checkbox').prop('checked', this.checked);
            });

            // Sorting
            $('.sortable').click(function() {
                var table = $('#plugin-table');
                var tbody = table.find('tbody');
                var rows = tbody.find('tr').toArray();
                var sortColumn = $(this).data('sort');
                var sortOrder = $(this).hasClass('asc') ? 'desc' : 'asc';

                rows.sort(function(a, b) {
                    var aValue = $(a).find('td[data-' + sortColumn + ']').data(sortColumn);
                    var bValue = $(b).find('td[data-' + sortColumn + ']').data(sortColumn);

                    if (sortColumn === 'last-update') {
                        aValue = new Date(aValue);
                        bValue = new Date(bValue);
                        return (sortOrder === 'asc') ? aValue - bValue : bValue - aValue;
                    } else {
                        aValue = aValue ? aValue.toString() : '';
                        bValue = bValue ? bValue.toString() : '';
                        return (sortOrder === 'asc')
                            ? aValue.localeCompare(bValue)
                            : bValue.localeCompare(aValue);
                    }
                });

                tbody.empty();
                $.each(rows, function(index, row) {
                    tbody.append(row);
                });

                $('.sortable').removeClass('asc desc');
                $(this).addClass(sortOrder);
                $(this).find('.sorting-indicator').html(sortOrder === 'asc' ? '&#9650;' : '&#9660;');
            });

        })(jQuery);
        </script>
        <style>
            /* Responsive table */
            @media screen and (max-width: 782px) {
                .lk-plugin-installer-form table.wp-list-table {
                    display: block;
                    overflow-x: auto;
                }
                .lk-plugin-installer-form table.wp-list-table thead,
                .lk-plugin-installer-form table.wp-list-table tbody,
                .lk-plugin-installer-form table.wp-list-table tr,
                .lk-plugin-installer-form table.wp-list-table td,
                .lk-plugin-installer-form table.wp-list-table th {
                    display: block;
                }
                .lk-plugin-installer-form table.wp-list-table thead tr {
                    position: absolute;
                    top: -9999px;
                    left: -9999px;
                }
                .lk-plugin-installer-form table.wp-list-table tr {
                    border: 1px solid #ccc;
                    margin-bottom: 10px;
                }
                .lk-plugin-installer-form table.wp-list-table td {
                    border: none;
                    border-bottom: 1px solid #eee;
                    position: relative;
                    padding-left: 5%;
                    text-align: left;
                }
                .lk-plugin-installer-form table.wp-list-table td:before {
                    position: absolute;
                    top: 6px;
                    left: 6px;
                    width: 45%;
                    padding-right: 10px;
                    white-space: nowrap;
                    content: attr(data-column-name);
                    font-weight: bold;
                }
            }
            .sortable { cursor: pointer; }
            .sortable span { display: inline-block; vertical-align: middle; }
            .sorting-indicator {
                margin-left: 5px;
                margin-top:-15px;
                color: #000;
            }
            .sortable.asc .sorting-indicator,
            .sortable.desc .sorting-indicator {
                color: #333;
                margin-top:-15px;
            }
        </style>
        <?php
    }

    /**
     * AJAX: check_for_updates - Re-download all plugins from manifest
     */
    public function check_for_updates_callback() {
        check_ajax_referer('check_for_updates_nonce', 'security');

        if (!class_exists('CWPK_Manifest_Installer')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cwpk-manifest-installer.php';
        }

        $manifest_installer = new CWPK_Manifest_Installer();
        $plugins = $manifest_installer->get_plugin_manifest();

        if (is_wp_error($plugins)) {
            wp_send_json_error('Failed to fetch plugin manifest: ' . $plugins->get_error_message());
        }

        $updated = 0;
        $errors = array();

        // Get list of currently installed plugins to check for updates
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed_plugins = get_plugins();

        foreach ($plugins as $plugin) {
            // Skip if no download URL available
            if (empty($plugin['slug'])) {
                continue;
            }

            // Check if plugin is installed and get version
            $is_installed = false;
            $installed_version = null;

            foreach ($installed_plugins as $plugin_file => $plugin_info) {
                $plugin_slug = dirname($plugin_file);
                if ($plugin_slug === $plugin['slug']) {
                    $is_installed = true;
                    $installed_version = $plugin_info['Version'];
                    break;
                }
            }

            // Only re-download if plugin is installed (to update it)
            if (!$is_installed) {
                continue;
            }

            // Re-download the plugin to get latest version
            $result = $manifest_installer->download_plugin($plugin);

            if (is_wp_error($result)) {
                $errors[] = $plugin['name'] . ': ' . $result->get_error_message();
            } else {
                $updated++;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error('Downloaded ' . $updated . ' plugin(s). Errors: ' . implode(', ', $errors));
        } else {
            wp_send_json_success('Successfully updated ' . $updated . ' plugin bundle file(s)');
        }
    }

    /**
     * AJAX: install_plugin
     */
    public function install_plugin_callback() {
        check_ajax_referer('plugin_installer_nonce', 'security');

        $plugin_url = isset($_POST['plugin_url']) ? sanitize_text_field($_POST['plugin_url']) : '';
        if (empty($plugin_url)) {
            wp_send_json_error('No plugin URL provided.');
        }

        $upload_dir            = wp_upload_dir();
        $cribops_updates_dir = trailingslashit($upload_dir['basedir']) . 'cribops-wp-kit/';
        $file_path             = $cribops_updates_dir . basename($plugin_url);

        if (! file_exists($file_path)) {
            wp_send_json_error('The file does not exist: ' . $file_path);
        }

        if (! is_readable($file_path)) {
            wp_send_json_error('The file is not readable: ' . $file_path);
        }

        WP_Filesystem();
        $plugin_slug = sanitize_title(basename($file_path, '.zip'));
        $plugin_dir  = WP_PLUGIN_DIR . '/' . $plugin_slug;

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

    private function delete_directory($dir) {
        if (! is_dir($dir)) {
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
     * AJAX: install_mainwp_child
     */
    public function install_mainwp_child_callback() {
        check_ajax_referer('install_mainwp_child_nonce', 'security');

        // Use WP_PLUGIN_DIR if defined, otherwise construct it from ABSPATH
        if (defined('WP_PLUGIN_DIR')) {
            $plugins_dir = WP_PLUGIN_DIR;
        } else {
            $plugins_dir = ABSPATH . 'wp-content/plugins';
        }

        $plugin_file = 'mainwp-child/mainwp-child.php';
        $plugin_path = $plugins_dir . '/' . $plugin_file;

        // Check if already installed
        if (file_exists($plugin_path)) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                wp_send_json_error('Plugin exists but activation failed: ' . $result->get_error_message());
            }
            wp_send_json_success(array('message' => 'MainWP Child was already installed and has been activated.'));
        } else {
            // Include necessary files
            if (!function_exists('plugins_api')) {
                include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            if (!class_exists('WP_Ajax_Upgrader_Skin')) {
                include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                include_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
            }

            // Get plugin information from WordPress.org
            $api = plugins_api('plugin_information', array(
                'slug' => 'mainwp-child',
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
                wp_send_json_error('Failed to get plugin information from WordPress.org: ' . $api->get_error_message());
            }

            // Install the plugin
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result   = $upgrader->install($api->download_link);

            if (is_wp_error($result)) {
                wp_send_json_error('Installation failed: ' . $result->get_error_message());
            } elseif (is_wp_error($skin->result)) {
                wp_send_json_error('Installation process error: ' . $skin->result->get_error_message());
            } elseif ($skin->get_errors()->has_errors()) {
                wp_send_json_error('Installation errors: ' . implode(', ', $skin->get_error_messages()));
            } else {
                // Activate the plugin
                $activation_result = activate_plugin($plugin_file);
                if (is_wp_error($activation_result)) {
                    wp_send_json_error('Plugin installed but activation failed: ' . $activation_result->get_error_message());
                }
                wp_send_json_success(array('message' => 'MainWP Child installed and activated successfully.'));
            }
        }
    }

    /**
     * AJAX: install_prime_mover
     */
    public function install_prime_mover_callback() {
        check_ajax_referer('install_prime_mover_nonce', 'security');

        // Use WP_PLUGIN_DIR if defined, otherwise construct it from ABSPATH
        if (defined('WP_PLUGIN_DIR')) {
            $plugins_dir = WP_PLUGIN_DIR;
        } else {
            $plugins_dir = ABSPATH . 'wp-content/plugins';
        }

        $plugin_file = 'prime-mover/prime-mover.php';
        $plugin_path = $plugins_dir . '/' . $plugin_file;

        // Check if already installed
        if (file_exists($plugin_path)) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                wp_send_json_error('Plugin exists but activation failed: ' . $result->get_error_message());
            }
            $this->skip_prime_mover_activation_script();
            wp_send_json_success(array('message' => 'Plugin was already installed and has been activated.'));
        } else {
            // Include necessary files
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
                wp_send_json_error('Failed to get plugin information from WordPress.org: ' . $api->get_error_message());
            }

            // Install the plugin
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result   = $upgrader->install($api->download_link);

            if (is_wp_error($result)) {
                wp_send_json_error('Installation failed: ' . $result->get_error_message());
            } elseif (is_wp_error($skin->result)) {
                wp_send_json_error('Installation process error: ' . $skin->result->get_error_message());
            } elseif ($skin->get_errors()->has_errors()) {
                wp_send_json_error('Installation errors: ' . implode(', ', $skin->get_error_messages()));
            } else {
                // Activate the plugin
                $activation_result = activate_plugin($plugin_file);
                if (is_wp_error($activation_result)) {
                    wp_send_json_error('Plugin installed but activation failed: ' . $activation_result->get_error_message());
                }
                $this->skip_prime_mover_activation_script();
                wp_send_json_success(array('message' => 'Prime Mover installed and activated successfully.'));
            }
        }
    }

    /**
     * After installing Prime Mover, auto-click the "skip activation" button
     */
    /**
     * Display manifest-based installer
     */
    public function display_manifest_installer() {
        if (class_exists('CWPK_Manifest_Installer')) {
            $manifest = new CWPK_Manifest_Installer();
            $manifest->display_manifest_installer();
        } else {
            // Load the manifest installer class if not already loaded
            $plugin_dir = plugin_dir_path(dirname(__FILE__));
            if (file_exists($plugin_dir . 'includes/class-cwpk-manifest-installer.php')) {
                require_once $plugin_dir . 'includes/class-cwpk-manifest-installer.php';
                $manifest = new CWPK_Manifest_Installer();
                $manifest->display_manifest_installer();
            } else {
                echo '<p>Manifest installer not available.</p>';
            }
        }
    }

    public function skip_prime_mover_activation_script() {
        $current_url = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';
        if ($current_url === '/wp-admin/admin.php?page=migration-panel-settings') {
            ?>
            <script>
            jQuery(document).ready(function($){
                var skipLink = null;
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            skipLink = $(mutation.target).find('#skip_activation').first();
                            if (skipLink.length > 0 && !window.skipPrimeMoverActivationClicked) {
                                window.skipPrimeMoverActivationClicked = true;
                                window.location.href = skipLink.attr('href');
                            }
                        }
                    });
                });
                observer.observe(document.body, { childList: true, subtree: true });
            });
            </script>
            <?php
        }
    }

    /**
     * Submenu for Prime Mover packages
     */
    public function get_prime_submenu() {
        add_submenu_page(
            'lk-get-meta-plugin-installer', // slug of the parent
            'Packages',
            'Packages',
            'manage_options',
            'get-prime',
            array($this, 'get_prime_page')
        );
    }

    /**
     * Show a CribOps WP-Kit banner on Prime Mover admin pages
     */
    public function launchkit_banner_on_prime_mover_backup_menu() {
        $current_screen = get_current_screen();
        $current_url    = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';

        $is_prime_mover_page = (
            strpos($current_url, '/wp-admin/admin.php?page=migration-panel') === 0 ||
            strpos($current_url, '/wp-admin/tools.php?page=migration-tools') === 0
        );

        if (
            strpos($current_screen->id, 'prime-mover_page_') === 0 ||
            $current_screen->id === 'tools_page_migration-tools' ||
            $is_prime_mover_page ||
            $current_screen->base === 'toplevel_page_migration-panel-settings'
        ) {
            ?>
            <div class="launchkit-banner">
                <div class="launchkit-banner-content">
                    <a href="<?php echo admin_url('admin.php?page=cwpk-packages'); ?>" class="button button-primary">Browse CribOps WP-Kit Packages</a>
                    <span class="launchkit-banner-text">Launch in Seconds!</span>
                </div>
            </div>
            <style>
            .launchkit-banner {
                background-color: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border-bottom: 1px solid #ccc;
                margin-left: -20px;
            }
            .launchkit-banner-content {
                display: flex;
                align-items: center;
            }
            .launchkit-banner-text {
                margin-left: 20px;
            }
            </style>
            <?php
        }
    }

    /**
     * Display packages tab content (for use in installer tab)
     */
    public function display_packages_tab() {
        $user_data = get_transient('lk_user_data');

        // Check if Prime Mover or Prime Mover Pro is installed
        $prime_mover_installed = is_plugin_active('prime-mover/prime-mover.php') || is_plugin_active('prime-mover-pro/prime-mover.php');

        if (!$prime_mover_installed) {
            ?>
            <div class="notice notice-warning">
                <p><strong>Prime Mover Required</strong></p>
                <p>To install packages, you need to have Prime Mover or Prime Mover Pro installed and activated.</p>
                <p><a href="?page=cwpk&tab=installer&installer_tab=plugins" class="button">Go to Plugins Tab to Install Prime Mover</a></p>
            </div>
            <?php
            return;
        }

        // Get packages array from API
        $packages = isset($user_data['packages']) && is_array($user_data['packages']) ? $user_data['packages'] : array();

        // Debug: Show what we're getting from the API
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo '<!-- Debug: Package data from API: ' . esc_html(json_encode($packages)) . ' -->';
        }

        ?>
        <div id="launchkit_package_notice" style="display: none;"></div>
        <div style="margin-bottom: 15px;">
            <a class="button" href="<?php echo admin_url('admin.php?page=migration-panel-backup-menu'); ?>">View Your Installed Packages</a>
            <button type="button" id="cwpk-refresh-packages" class="button" style="margin-left: 10px;">
                <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Refresh Package List
            </button>
        </div>

            <!-- Progress Modal -->
            <div id="cwpk-progress-modal" style="display: none;">
                <div class="cwpk-progress-overlay"></div>
                <div class="cwpk-progress-content">
                    <h2>Downloading Package</h2>
                    <div class="cwpk-progress-bar-container">
                        <div id="cwpk-progress-bar-fill" class="cwpk-progress-bar-fill"></div>
                        <div class="cwpk-progress-bar-text">
                            <span id="cwpk-progress-percent">0%</span>
                        </div>
                    </div>
                    <div id="cwpk-progress-details" class="cwpk-progress-details">Initializing...</div>
                    <div id="cwpk-progress-message" class="cwpk-progress-message">Starting download...</div>
                </div>
            </div>

            <!-- Package Selection -->
            <h3>Select Package</h3>
            <?php if (!empty($packages)) : ?>
            <div style="margin-bottom: 15px;">
                <input type="text" id="cwpk-package-search" placeholder="Search packages by name..." style="width: 300px; padding: 5px;">
                <span id="cwpk-package-search-results" style="margin-left: 10px; color: #666;"></span>
            </div>
            <?php endif; ?>
            <?php if (empty($packages)) : ?>
                <div class="notice notice-info">
                    <p><strong>No packages currently available.</strong></p>
                    <p>Please check back later or contact your administrator for available packages.</p>
                </div>
            <?php else : ?>
                <div class="package-selection-row">
                    <?php foreach ($packages as $index => $package) :
                        $package_name = isset($package['name']) ? $package['name'] : 'Package ' . ($index + 1);
                        $package_url = isset($package['url']) ? $package['url'] : '';

                        // Check for thumbnail_url and provide fallback
                        if (!empty($package['thumbnail_url']) && $package['thumbnail_url'] !== null) {
                            $thumbnail_url = $package['thumbnail_url'];
                        } else {
                            // Use placeholder image with package name
                            $thumbnail_url = 'https://placehold.co/300x200/2271b1/ffffff?text=' . urlencode($package_name);
                        }

                        // Debug output for each package
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            echo '<!-- Package ' . ($index + 1) . ' - Name: ' . esc_html($package_name) . ', Thumbnail: ' . esc_html($thumbnail_url) . ' -->';
                        }
                    ?>
                    <div class="package-selection-column" data-package-name="<?php echo esc_attr(strtolower($package_name)); ?>">
                        <div class="package-image-wrapper">
                            <img src="<?php echo esc_url($thumbnail_url); ?>"
                                 alt="<?php echo esc_attr($package_name); ?>"
                                 class="package-image"
                                 onerror="this.onerror=null; this.src='https://placehold.co/300x200/2271b1/ffffff?text=<?php echo urlencode($package_name); ?>';"
                                 title="Image URL: <?php echo esc_attr($thumbnail_url); ?>">
                        </div>
                        <button class="button upload-package-button" data-package-url="<?php echo esc_url($package_url); ?>">Upload <?php echo esc_html($package_name); ?></button>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Custom Package Upload -->
            <h3>Upload Your Own Package</h3>
            <form method="post" id="custom_package_upload_form">
                <label for="custom_package_url">
                    Add the URL of any package
                    <a href="/wp-admin/tools.php?page=migration-tools&blog_id=1&action=prime_mover_create_backup_action">you have created</a>:
                </label>
                <input type="text" id="custom_package_url" name="custom_package_url" placeholder="Enter package URL">
                <button type="submit" class="button upload-package-button">Upload</button>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var progressInterval = null;
            var currentPackageUrl = null;

            // Package search functionality
            $('#cwpk-package-search').on('keyup search', function() {
                var searchTerm = $(this).val().toLowerCase();
                var visibleCount = 0;
                var totalCount = 0;

                $('.package-selection-column').each(function() {
                    var packageCol = $(this);
                    var packageName = packageCol.data('package-name');

                    totalCount++;

                    if (searchTerm === '' || packageName.indexOf(searchTerm) !== -1) {
                        packageCol.show();
                        visibleCount++;
                    } else {
                        packageCol.hide();
                    }
                });

                // Update results count
                if (searchTerm === '') {
                    $('#cwpk-package-search-results').text('');
                } else {
                    $('#cwpk-package-search-results').text('Showing ' + visibleCount + ' of ' + totalCount + ' packages');
                }

                // Handle "no results" message
                if (visibleCount === 0 && totalCount > 0) {
                    if ($('#cwpk-no-package-results').length === 0) {
                        $('.package-selection-row').append('<div id="cwpk-no-package-results" style="width: 100%; text-align: center; padding: 40px 20px; color: #666;">No packages found matching "' + searchTerm + '"</div>');
                    }
                } else {
                    $('#cwpk-no-package-results').remove();
                }
            });

            function formatBytes(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            }

            function checkDownloadProgress() {
                if (!currentPackageUrl) return;

                $.post(ajaxurl, {
                    action: 'check_package_download_progress',
                    package_url: currentPackageUrl,
                    security: '<?php echo wp_create_nonce("check_download_progress_nonce"); ?>'
                }, function(response) {
                    if (!response.success) return;

                    var status = response.data;
                    var progressPercent = status.progress || 0;
                    var downloaded = status.downloaded || 0;
                    var total = status.total || 0;

                    $('#cwpk-progress-bar-fill').css('width', progressPercent + '%');
                    $('#cwpk-progress-percent').text(progressPercent.toFixed(1) + '%');

                    if (total > 0) {
                        $('#cwpk-progress-details').text(formatBytes(downloaded) + ' / ' + formatBytes(total));
                    } else if (downloaded > 0) {
                        $('#cwpk-progress-details').text(formatBytes(downloaded) + ' downloaded');
                    } else {
                        $('#cwpk-progress-details').text('Starting download...');
                    }

                    if (status.status === 'completed') {
                        clearInterval(progressInterval);
                        progressInterval = null;
                        $('#cwpk-progress-message').html('<span style="color: #46b450;">✓ Package downloaded successfully!</span>');
                        setTimeout(function() {
                            $('#cwpk-progress-modal').fadeOut();
                            $('#launchkit_package_notice').html('<div class="notice notice-success"><p>Package uploaded successfully. <a href="<?php echo admin_url('admin.php?page=migration-panel-backup-menu'); ?>">View in Prime Mover</a></p></div>').show();
                            $('.upload-package-button').prop('disabled', false).text('Upload Package');
                        }, 2000);
                    } else if (status.status === 'failed') {
                        clearInterval(progressInterval);
                        progressInterval = null;
                        $('#cwpk-progress-message').html('<span style="color: #dc3232;">✗ ' + status.message + '</span>');
                        setTimeout(function() {
                            $('#cwpk-progress-modal').fadeOut();
                            $('#launchkit_package_notice').html('<div class="notice notice-error"><p>' + status.message + '</p></div>').show();
                            $('.upload-package-button').prop('disabled', false).text('Upload Package');
                        }, 3000);
                    }
                });
            }

            $('.upload-package-button').click(function(e) {
                e.preventDefault();
                var button = $(this);
                var packageUrl = button.data('package-url');
                if (!packageUrl) {
                    packageUrl = $('#custom_package_url').val().trim();
                    if (!packageUrl) {
                        alert('Please enter a valid package URL.');
                        return;
                    }
                }

                currentPackageUrl = packageUrl;
                button.prop('disabled', true).text('Starting...');

                // Show progress modal
                $('#cwpk-progress-modal').fadeIn();
                $('#cwpk-progress-bar-fill').css('width', '0%');
                $('#cwpk-progress-percent').text('0%');
                $('#cwpk-progress-details').text('Initializing...');
                $('#cwpk-progress-message').html('Downloading package...');

                // Start progress polling
                progressInterval = setInterval(checkDownloadProgress, 2000);

                // Start the download
                $.post(ajaxurl, {
                    action: 'upload_package_from_url',
                    package_url: packageUrl,
                    security: '<?php echo wp_create_nonce("upload_package_from_url_nonce"); ?>'
                }, function(response) {
                    // Download completed or failed - progress polling will handle UI updates
                }).fail(function(xhr, status, error) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    $('#cwpk-progress-message').html('<span style="color: #dc3232;">✗ Request failed: ' + error + '</span>');
                    setTimeout(function() {
                        $('#cwpk-progress-modal').fadeOut();
                        $('#launchkit_package_notice').html('<div class="notice notice-error"><p>Request failed: ' + error + '</p></div>').show();
                        button.prop('disabled', false).text('Upload Package');
                    }, 3000);
                });
            });

            // Refresh packages button
            $('#cwpk-refresh-packages').click(function(e) {
                e.preventDefault();
                var button = $(this);
                var originalHtml = button.html();

                button.prop('disabled', true).html('<span class="dashicons dashicons-update spin-icon" style="margin-top: 3px;"></span> Refreshing...');

                $.post(ajaxurl, {
                    action: 'refresh_packages',
                    security: '<?php echo wp_create_nonce("refresh_packages_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to refresh packages: ' + (response.data || 'Unknown error'));
                        button.prop('disabled', false).html(originalHtml);
                    }
                }).fail(function() {
                    alert('Failed to refresh packages');
                    button.prop('disabled', false).html(originalHtml);
                });
            });
        });
        </script>
        <style>
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .spin-icon {
                animation: spin 1s linear infinite;
                display: inline-block;
            }
            .package-selection-row {
                display: flex;
                justify-content: left;
                margin-bottom: 20px;
            }
            .package-selection-column {
                background-color: #ffffff;
                border: 2px solid #cccccc;
                border-radius: 5px;
                padding: 10px;
                margin: 0 10px;
                text-align: center;
                width: 300px;
            }
            .package-image-wrapper {
                height: auto;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom:10px;
            }
            .package-image {
                max-width: 100%;
                max-height: 100%;
            }
            .button.upload-package-button {
                margin-top:0px;
            }

            /* Progress Modal Styles */
            #cwpk-progress-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 100000;
            }
            .cwpk-progress-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
            }
            .cwpk-progress-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                min-width: 500px;
                max-width: 600px;
            }
            .cwpk-progress-content h2 {
                margin: 0 0 20px 0;
                font-size: 22px;
                color: #23282d;
            }
            .cwpk-progress-bar-container {
                position: relative;
                width: 100%;
                height: 30px;
                background: #e5e5e5;
                border-radius: 15px;
                overflow: hidden;
                margin-bottom: 15px;
            }
            .cwpk-progress-bar-fill {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
                background: linear-gradient(90deg, #2271b1 0%, #135e96 100%);
                transition: width 0.3s ease;
                border-radius: 15px;
            }
            .cwpk-progress-bar-text {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                color: #23282d;
                font-size: 14px;
            }
            .cwpk-progress-details {
                text-align: center;
                color: #50575e;
                font-size: 14px;
                margin-bottom: 10px;
            }
            .cwpk-progress-message {
                text-align: center;
                color: #50575e;
                font-size: 13px;
                font-style: italic;
            }
        </style>
        <?php
    }

    /**
     * Packages page (standalone - wraps display_packages_tab)
     */
    public function get_prime_page() {
        $user_data = get_transient('lk_user_data');

        // Must have can_access_launchkit
        if (!$user_data || empty($user_data['can_access_launchkit'])) {
            ?>
            <div class="wrap">
                <h1>CribOps WP-Kit Packages</h1>
                <div class="notice notice-warning"><p>Sorry, please <a href="<?php echo admin_url('admin.php?page=cwpk&tab=installer'); ?>">log in with proper credentials</a> to view available packages.</p></div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="wrap">
            <h1>Install CribOps WP-Kit Packages</h1>
            <?php $this->display_packages_tab(); ?>
        </div>
        <?php
    }

    /**
     * AJAX: upload_package_from_url
     */
    public function upload_package_from_url_callback() {
        check_ajax_referer('upload_package_from_url_nonce', 'security');

        $package_url = isset($_POST['package_url']) ? esc_url_raw($_POST['package_url']) : '';
        if (empty($package_url)) {
            wp_send_json_error(['message' => 'No package URL provided.']);
        }

        $upload_dir  = ABSPATH . 'wp-content/uploads/prime-mover-export-files/1/';
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
        }
        $upload_file = $upload_dir . basename(parse_url($package_url, PHP_URL_PATH));
        $download_key = 'cwpk_download_' . md5($package_url);

        // Remove existing file if it exists to avoid conflicts
        if (file_exists($upload_file)) {
            @unlink($upload_file);
        }

        // Set unlimited execution time for large downloads
        set_time_limit(0);
        @ini_set('max_execution_time', 0);

        // Initialize download status
        set_transient($download_key, array(
            'status' => 'downloading',
            'progress' => 0,
            'downloaded' => 0,
            'total' => 0,
            'message' => 'Starting download...',
            'start_time' => time()
        ), 3600);

        // Download with progress tracking using cURL for better control
        $ch = curl_init($package_url);
        $fp = fopen($upload_file, 'w+');

        if ($fp === false) {
            set_transient($download_key, array(
                'status' => 'failed',
                'message' => 'Could not open file for writing'
            ), 300);
            wp_send_json_error(['message' => 'Could not open file for writing']);
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);

        // Progress callback (PHP 5.5+ signature)
        $start_time = time();
        $last_update = 0;
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $download_size, $downloaded, $upload_size, $uploaded) use ($download_key, $start_time, &$last_update) {
            // Only update transient every 2 seconds to avoid overhead
            $now = time();
            if ($now - $last_update >= 2 || $downloaded === $download_size) {
                $progress = $download_size > 0 ? round(($downloaded / $download_size) * 100, 1) : 0;
                set_transient($download_key, array(
                    'status' => 'downloading',
                    'progress' => $progress,
                    'downloaded' => $downloaded,
                    'total' => $download_size,
                    'message' => 'Downloading...',
                    'start_time' => $start_time
                ), 3600);
                $last_update = $now;
            }
        });

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        fclose($fp);

        if ($result === false || $http_code !== 200) {
            @unlink($upload_file);
            set_transient($download_key, array(
                'status' => 'failed',
                'message' => 'Download failed: ' . ($curl_error ?: 'HTTP ' . $http_code)
            ), 300);
            wp_send_json_error(['message' => 'Download failed: ' . ($curl_error ?: 'HTTP ' . $http_code)]);
        }

        // Verify file exists and has content
        if (!file_exists($upload_file) || filesize($upload_file) === 0) {
            set_transient($download_key, array(
                'status' => 'failed',
                'message' => 'Downloaded file is empty or missing'
            ), 300);
            wp_send_json_error(['message' => 'Downloaded file is empty or missing']);
        }

        // Success!
        set_transient($download_key, array(
            'status' => 'completed',
            'progress' => 100,
            'downloaded' => filesize($upload_file),
            'total' => filesize($upload_file),
            'message' => 'Package uploaded successfully',
            'file' => basename($upload_file)
        ), 300);

        wp_send_json_success(['message' => 'Package uploaded successfully.']);
    }

    /**
     * AJAX: check_package_download_progress
     */
    public function check_package_download_progress_callback() {
        check_ajax_referer('check_download_progress_nonce', 'security');

        $package_url = isset($_POST['package_url']) ? esc_url_raw($_POST['package_url']) : '';
        if (empty($package_url)) {
            wp_send_json_error(['message' => 'No package URL provided.']);
        }

        $download_key = 'cwpk_download_' . md5($package_url);
        $status = get_transient($download_key);

        if ($status === false) {
            wp_send_json_success([
                'status' => 'not_started',
                'progress' => 0,
                'message' => 'Download not started'
            ]);
        } else {
            wp_send_json_success($status);
        }
    }

    /**
     * The function that actually downloads the base .wprime package
     */
    public function get_prime_function() {
        $user_data = get_transient('lk_user_data');
        if ($user_data && ! empty($user_data['launchkit_package_url'])) {
            $remote_file_url = $user_data['launchkit_package_url'];
            $local_dir       = ABSPATH . 'wp-content/uploads/prime-mover-export-files/1/';
            if (! file_exists($local_dir)) {
                wp_mkdir_p($local_dir);
            }
            $local_file_path = $local_dir . basename($remote_file_url);
            // Use extended timeout for large package files (up to 10 minutes)
            // Stream directly to file to avoid memory issues with large packages
            $response        = wp_remote_get($remote_file_url, array(
                'timeout' => 600,
                'stream' => true,
                'filename' => $local_file_path
            ));
            if (is_wp_error($response)) {
                return ['success' => false, 'message' => 'Error downloading file: ' . $response->get_error_message()];
            }
            // When streaming, the file is already written to disk
            if (file_exists($local_file_path) && filesize($local_file_path) > 0) {
                return ['success' => true, 'message' => 'File successfully downloaded to ' . $local_file_path];
            } else {
                return ['success' => false, 'message' => 'Error saving file to directory.'];
            }
        }
        return ['success' => false, 'message' => 'Access denied. You do not have permission to download CribOps WP-Kit packages.'];
    }

    /**
     * AJAX: get_prime_package
     */
    public function get_prime_package_callback() {
        check_ajax_referer('get_prime_package_nonce', 'security');

        $result = $this->get_prime_function();
        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX: check_plugin_updates (for "Plugins Being Tested")
     */
    public function check_plugin_updates_callback() {
        check_ajax_referer('check_plugin_updates_nonce', 'security');

        wp_update_plugins();
        $plugins = get_plugins();
        $updates = get_site_transient('update_plugins');

        if (! empty($updates->response)) {
            foreach ($updates->response as $plugin_file => $plugin_data) {
                $plugin_name = isset($plugins[$plugin_file]['Name']) ? $plugins[$plugin_file]['Name'] : $plugin_file;
                echo '<li>' . esc_html($plugin_name) . ' - Version ' . esc_html($plugin_data->new_version) . '</li>';
            }
        } else {
            echo '<li>No plugin updates available at this time</li>';
        }
        wp_die();
    }

    /**
     * AJAX: refresh_packages - Force refresh package list from API
     */
    public function refresh_packages_callback() {
        check_ajax_referer('refresh_packages_nonce', 'security');

        // Delete the cached user data to force a refresh
        delete_transient('lk_user_data');
        delete_transient('cwpk_token_auth');

        // Re-authenticate to get fresh data
        $token = CWPKAuth::get_env_bearer_token();
        if ($token) {
            $result = CWPKAuth::authenticate_with_token($token);
            if (!is_wp_error($result)) {
                set_transient('cwpk_token_auth', true, HOUR_IN_SECONDS);
                set_transient('lk_user_data', $result, HOUR_IN_SECONDS);
                wp_send_json_success(['message' => 'Packages refreshed successfully']);
            } else {
                wp_send_json_error('Failed to authenticate: ' . $result->get_error_message());
            }
        } else {
            wp_send_json_error('No authentication token available');
        }
    }

    /**
     * Auto-activate Prime Mover Pro license if env variable is set
     *
     * @since 1.3.2
     */
    public function auto_activate_prime_mover_license() {
        // Get license key from environment
        $license_key = defined('PRIME_MOVER_PLUGIN_LICENSE_KEY') ? PRIME_MOVER_PLUGIN_LICENSE_KEY : '';

        if (empty($license_key)) {
            return;
        }

        // Check if Prime Mover Pro is active
        if (!function_exists('pm_fs')) {
            return;
        }

        // Auto-fill the license key field on Prime Mover pages
        add_action('admin_footer', function() use ($license_key) {
            ?>
            <script type="text/javascript">
            (function($) {
                if (typeof $ === 'undefined') return;

                var licenseKey = <?php echo json_encode($license_key); ?>;

                function tryFillLicense() {
                    // Use multiple selectors to find the license input field
                    var $input = $('input[placeholder*="license" i], input[aria-label*="license" i], textarea[placeholder*="license" i]').first();

                    // Fallback: look for any input in the license activation form
                    if (!$input.length) {
                        $input = $('.fs-modal-license-activation input[type="text"]').first();
                    }

                    // Another fallback: find input near "Enter your license key" text
                    if (!$input.length) {
                        $input = $('label:contains("License key"), p:contains("license key")').closest('form, div').find('input[type="text"]').first();
                    }

                    if ($input.length && !$input.val()) {
                        $input.val(licenseKey).trigger('change').trigger('input').trigger('keyup');

                        // Try to enable the activate button
                        var $btn = $('button[disabled]:contains("Activate License")');
                        if ($btn.length) {
                            $btn.prop('disabled', false).removeAttr('disabled').removeClass('disabled');
                        }

                        console.log('Prime Mover license auto-filled');
                        return true;
                    }
                    return false;
                }

                // Try immediately on page load
                $(document).ready(function() {
                    setTimeout(tryFillLicense, 100);
                    setTimeout(tryFillLicense, 500);
                    setTimeout(tryFillLicense, 1000);
                    setTimeout(tryFillLicense, 2000);
                });

                // Also watch for modal/dialog appearances
                var observer = new MutationObserver(function(mutations) {
                    tryFillLicense();
                });

                $(document).ready(function() {
                    observer.observe(document.body, { childList: true, subtree: true });
                });
            })(jQuery);
            </script>
            <?php
        });
    }

    /**
     * Auto-activate Automatic.css license if env variable is set
     *
     * @since 1.4.0
     */
    public function auto_activate_automatic_css_license() {
        // Get license key from environment
        $license_key = defined('AUTOMATIC_CSS_LICENSE_KEY') ? AUTOMATIC_CSS_LICENSE_KEY : '';

        if (empty($license_key)) {
            return;
        }

        // Check if Automatic.css plugin is active
        if (!is_plugin_active('automaticcss-plugin/automaticcss-plugin.php')) {
            return;
        }

        // Check if license is already activated
        $current_license = get_option('automatic_css_license_key');
        $license_status = get_option('automatic_css_license_status');

        // If license is already set and valid, don't reactivate
        if ($current_license === $license_key && $license_status === 'valid') {
            return;
        }

        // Store the license key
        update_option('automatic_css_license_key', $license_key);

        // Activate the license via EDD API
        $api_params = array(
            'edd_action'  => 'activate_license',
            'license'     => $license_key,
            'item_id'     => 164, // Automatic.css product ID
            'item_name'   => rawurlencode('Automatic.css'),
            'url'         => site_url(),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
        );

        // Call the Automatic.css licensing API
        $response = wp_remote_post(
            'https://automaticcss.com/',
            array(
                'timeout'   => 15,
                'sslverify' => true,
                'body'      => $api_params,
            )
        );

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $license_data = json_decode($body);

            if (isset($license_data->license)) {
                update_option('automatic_css_license_status', $license_data->license);
            }
        }

        // Also auto-fill the license key field on Automatic.css pages using JavaScript
        add_action('admin_footer', function() use ($license_key) {
            ?>
            <script type="text/javascript">
            (function($) {
                if (typeof $ === 'undefined') return;

                var licenseKey = <?php echo json_encode($license_key); ?>;

                function tryFillLicense() {
                    // Look for the Automatic.css license input field
                    var $input = $('input[name="automatic_css_license_key"]');

                    if ($input.length && !$input.val()) {
                        $input.val(licenseKey).trigger('change').trigger('input').trigger('keyup');
                        console.log('Automatic.css license auto-filled');
                        return true;
                    }
                    return false;
                }

                // Try immediately on page load
                $(document).ready(function() {
                    setTimeout(tryFillLicense, 100);
                    setTimeout(tryFillLicense, 500);
                    setTimeout(tryFillLicense, 1000);
                });

                // Also watch for dynamic content loading
                var observer = new MutationObserver(function(mutations) {
                    tryFillLicense();
                });

                $(document).ready(function() {
                    observer.observe(document.body, { childList: true, subtree: true });
                });
            })(jQuery);
            </script>
            <?php
        });
    }

    /**
     * Display site usage notice based on current usage
     *
     * @param array $site_usage Site usage data from API
     */
    private function display_site_usage_notice($site_usage) {
        // Validate site_usage structure
        if (!is_array($site_usage) ||
            !isset($site_usage['current_sites']) ||
            !isset($site_usage['site_limit'])) {
            return; // Invalid structure - skip display
        }

        // Sanitize values
        $current = intval($site_usage['current_sites']);
        $limit = intval($site_usage['site_limit']);
        $unlimited = !empty($site_usage['unlimited']);
        $percentage = isset($site_usage['percentage_used']) ? floatval($site_usage['percentage_used']) : 0;

        // Display unlimited notice
        if ($unlimited) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Site Usage:</strong> You have <strong>unlimited sites</strong> on your plan. Currently using ' . $current . ' sites.';
            echo '</p></div>';
            return;
        }

        // Warning when at 80% or higher
        if ($percentage >= 80) {
            $notice_type = ($percentage >= 100) ? 'notice-error' : 'notice-warning';
            echo '<div class="notice ' . $notice_type . '"><p>';
            echo '<strong>Site Usage:</strong> You are using ' . $current . ' of ' . $limit . ' sites (' . number_format($percentage, 0) . '%).';

            if ($percentage >= 100) {
                echo ' You have reached your site limit. <a href="https://cribops.com/pricing" target="_blank" class="button button-small button-primary" style="margin-left: 10px;">Upgrade Your Plan</a> to add more sites.';
            } else {
                echo ' <a href="https://cribops.com/pricing" target="_blank" class="button button-small" style="margin-left: 10px;">Upgrade</a> if you need more sites.';
            }
            echo '</p></div>';
        } elseif ($current > 0) {
            // Show info notice for usage below 80%
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Site Usage:</strong> You are using ' . $current . ' of ' . $limit . ' sites (' . number_format($percentage, 0) . '%).';
            echo '</p></div>';
        }
    }
}

// Instantiate
new CWPKInstaller();