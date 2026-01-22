<?php
/**
 * CribOps WP-Kit Plugin Manager
 *
 * Integrates bundled plugin updates from the "cribops-wp-kit" folder with WordPress's update flow.
 * Version: 2.0.2
 */

// Force direct filesystem access so that FTP/FTPS prompts are bypassed.
if ( ! defined( 'FS_METHOD' ) ) {
    define( 'FS_METHOD', 'direct' );
}
add_filter( 'filesystem_method', function() {
    return 'direct';
});
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
    define( 'FS_CHMOD_DIR', 0755 );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
    define( 'FS_CHMOD_FILE', 0644 );
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CWPKPluginManager {
    private $update_dir;
    private $bundle_plugins = [];

    public function __construct() {
        // Only load functionality if the user is properly authenticated with WPLaunchify.
        if ( ! $this->user_has_access() ) {
            add_action( 'admin_notices', array( $this, 'admin_notice_no_access' ) );
            return;
        }

        $upload_dir = wp_upload_dir();
        $this->update_dir = trailingslashit( $upload_dir['basedir'] ) . 'cribops-wp-kit';

        // Ensure the update directory exists.
        if ( ! file_exists( $this->update_dir ) ) {
            wp_mkdir_p( $this->update_dir );
        }

        // Register hooks for individual updates.
        add_filter( 'plugin_row_meta', [ $this, 'add_launchkit_update_link' ], 10, 4 );
        add_action( 'admin_print_footer_scripts-plugins.php', [ $this, 'update_script' ] );
        add_action( 'wp_ajax_launchkit_update_plugin', [ $this, 'handle_update' ] );
        add_action( 'admin_menu', [ $this, 'add_debug_page' ] );

        // Add our "Add Software Bundle Plugins" link near the "Add New" button on the Plugins page.
        add_action( 'admin_head-plugins.php', [ $this, 'add_check_updates_button' ] );

        // Add an admin bar button so you can trigger updates from any admin page.
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_button' ], 100 );
        add_action( 'admin_head', [ $this, 'admin_bar_script' ] );

        // Register bulk update actions.
        add_filter( 'bulk_actions-plugins', [ $this, 'register_bulk_update_action' ] );
        add_filter( 'handle_bulk_actions-plugins', [ $this, 'handle_bulk_update_action' ], 10, 3 );
        add_action( 'admin_notices', [ $this, 'bulk_update_admin_notice' ] );

        // Add a new view tab to filter for Software Bundle Plugins.
        add_filter( 'views_plugins', [ $this, 'add_bundle_tab' ] );
        add_filter( 'all_plugins', [ $this, 'filter_bundle_plugins' ] );

        // Load bundled plugins.
        $this->scan_bundle_directory();
    }

    /**
     * Check if the current user is authenticated with WPLaunchify and has proper membership.
     *
     * @return bool
     */
    private function user_has_access() {
        // Use the centralized auth check which handles both bearer token and email/password
        if (!class_exists('CWPKAuth')) {
            return false;
        }

        // Check if authenticated via any method and refresh if needed
        if (!CWPKAuth::is_authenticated()) {
            return false;
        }

        // Ensure user data is fresh - if it's missing but we're authenticated, refresh it
        $user_data = get_transient('lk_user_data');
        if (!$user_data) {
            // Try to refresh authentication
            $token = CWPKAuth::get_env_bearer_token();
            if ($token) {
                $result = CWPKAuth::authenticate_with_token($token);
                if (!is_wp_error($result)) {
                    set_transient('cwpk_token_auth', true, HOUR_IN_SECONDS);
                    set_transient('lk_user_data', $result, HOUR_IN_SECONDS);
                    set_transient('lk_logged_in', true, HOUR_IN_SECONDS);
                    $user_data = $result;
                }
            }
        }

        return ($user_data && !empty($user_data['can_access_launchkit']));
    }

    /**
     * Display an admin notice if the user is not properly authenticated.
     */
    public function admin_notice_no_access() {
        echo '<div class="notice notice-error"><p>';
        echo 'CribOps WP-Kit Plugin Manager is disabled. Please log in with a valid subscription to use these features.';
        echo '</p></div>';
    }

    /**
     * Scan the cribops-wp-kit directory for plugin ZIP files.
     */
    private function scan_bundle_directory() {
        $zip_files = glob( $this->update_dir . '/*.zip' );
        if ( empty( $zip_files ) ) {
            return;
        }
        foreach ( $zip_files as $zip_file ) {
            $plugin_info = $this->extract_plugin_info( $zip_file );
            if ( $plugin_info ) {
                $this->bundle_plugins[ $plugin_info['folder'] ] = $plugin_info;
            }
        }
    }

    /**
     * Extract plugin information from a ZIP file.
     */
    private function extract_plugin_info( $zip_file ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }
        $zip = new ZipArchive();
        if ( $zip->open( $zip_file ) !== true ) {
            return false;
        }
        // Common plugin directories to check.
        $known_plugins = [
            'woocommerce/woocommerce.php',
            'woocommerce-smart-coupons/woocommerce-smart-coupons.php',
            'elementor/elementor.php',
            'kadence-blocks/kadence-blocks.php',
            'kadence-blocks-pro/kadence-blocks-pro.php'
        ];
        $plugin_info = null;
        // First check known plugins.
        foreach ( $known_plugins as $plugin_path ) {
            $index = $zip->locateName( $plugin_path );
            if ( $index !== false ) {
                $content = $zip->getFromIndex( $index );
                $headers = $this->parse_plugin_headers( $content );
                if ( ! empty( $headers['Version'] ) ) {
                    $folder = dirname( $plugin_path );
                    $plugin_info = [
                        'folder'  => $folder,
                        'file'    => $plugin_path,
                        'name'    => $headers['Name'] ?: $folder,
                        'version' => $headers['Version'],
                        'package' => $zip_file
                    ];
                    break;
                }
            }
        }
        // If not found, scan the ZIP for any plugin.
        if ( ! $plugin_info ) {
            $folders = [];
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $filename = $zip->getNameIndex( $i );
                $parts = explode( '/', $filename );
                if ( count( $parts ) > 1 ) {
                    $folders[ $parts[0] ] = true;
                }
            }
            foreach ( array_keys( $folders ) as $folder ) {
                $potential_file = "$folder/$folder.php";
                $index = $zip->locateName( $potential_file );
                if ( $index === false ) {
                    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                        $filename = $zip->getNameIndex( $i );
                        if ( dirname( $filename ) === $folder && pathinfo( $filename, PATHINFO_EXTENSION ) === 'php' ) {
                            $index = $i;
                            $potential_file = $filename;
                            break;
                        }
                    }
                }
                if ( $index !== false ) {
                    $content = $zip->getFromIndex( $index );
                    $headers = $this->parse_plugin_headers( $content );
                    if ( ! empty( $headers['Version'] ) ) {
                        $plugin_info = [
                            'folder'  => $folder,
                            'file'    => $potential_file,
                            'name'    => $headers['Name'] ?: $folder,
                            'version' => $headers['Version'],
                            'package' => $zip_file
                        ];
                        break;
                    }
                }
            }
        }
        $zip->close();
        return $plugin_info;
    }

    /**
     * Parse plugin headers from file content.
     */
    private function parse_plugin_headers( $content ) {
        $headers = [
            'Name'    => '',
            'Version' => ''
        ];
        if ( preg_match( '/Plugin Name:\s*(.+)$/mi', $content, $matches ) ) {
            $headers['Name'] = trim( $matches[1] );
        }
        if ( preg_match( '/Version:\s*(.+)$/mi', $content, $matches ) ) {
            $headers['Version'] = trim( $matches[1] );
        }
        return $headers;
    }

    /**
     * Add Software Bundle update link to the plugin row.
     *
     * - If the bundled version is newer than the installed version, show the "Update With Software Bundle" link.
     * - If the bundled version equals the installed version and a repository update is available, show a free-update link.
     * - Otherwise, do not add any extra link.
     */
    public function add_launchkit_update_link( $plugin_meta, $plugin_file, $plugin_data, $status ) {
        if ( ! $this->user_has_access() ) {
            return $plugin_meta;
        }
        $folder = dirname( $plugin_file );
        if ( $folder === '.' ) {
            return $plugin_meta;
        }
        if ( isset( $this->bundle_plugins[ $folder ] ) ) {
            $bundle_plugin = $this->bundle_plugins[ $folder ];
            $bundle_version = $bundle_plugin['version'];
            $installed_version = $plugin_data['Version'];
            if ( version_compare( $bundle_version, $installed_version, '>' ) ) {
                $plugin_meta[] = '<a href="#" class="launchkit-update" style="font-weight: 500;color: #E55B10;" data-plugin="' . esc_attr( $plugin_file ) . '" data-package="' . esc_attr( $bundle_plugin['package'] ) . '">Update With Software Bundle</a>';
            } elseif ( version_compare( $bundle_version, $installed_version, '==' ) ) {
                $updates = get_site_transient( 'update_plugins' );
                if ( isset( $updates->response[ $plugin_file ] ) ) {
                    $update_url = wp_nonce_url( admin_url( "update.php?action=upgrade-plugin&plugin=" . urlencode( $plugin_file ) ), 'upgrade-plugin_' . $plugin_file );
                    $plugin_meta[] = '<a href="' . esc_url( $update_url ) . '" class="launchkit-update free-update" style="font-weight: 500;color: #E55B10;">Update With Software Bundle</a>';
                }
            }
        }
        return $plugin_meta;
    }

    /**
     * Add JavaScript for handling single-plugin updates.
     */
    public function update_script() {
        if ( ! $this->user_has_access() ) {
            return;
        }
        $nonce = wp_create_nonce( 'launchkit_update_nonce' );
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.launchkit-update').on('click', function(e) {
                if ($(this).hasClass('free-update')) {
                    return;
                }
                e.preventDefault();
                var button = $(this);
                var plugin = button.data('plugin');
                var package = button.data('package');
                button.text('Updating...').css('opacity', 0.7);
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'launchkit_update_plugin',
                        plugin: plugin,
                        package: package,
                        _nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('Updated!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            button.text('Update Failed');
                            console.error(response.data);
                        }
                    },
                    error: function() {
                        button.text('Update Failed');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle the plugin update AJAX request.
     */
    public function handle_update() {
        if ( ! $this->user_has_access() ) {
            wp_send_json_error( 'Access denied' );
        }
        check_ajax_referer( 'launchkit_update_nonce', '_nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        $plugin_file  = sanitize_text_field( $_POST['plugin'] );
        $package_file = sanitize_text_field( $_POST['package'] );
        if ( empty( $plugin_file ) || empty( $package_file ) || ! file_exists( $package_file ) ) {
            wp_send_json_error( 'Invalid plugin or package' );
        }
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
        $was_active = is_plugin_active( $plugin_file );
        if ( is_dir( $plugin_dir ) ) {
            $this->recursive_delete( $plugin_dir );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("CribOps WP-Kit Update: Deleted existing plugin directory: " . $plugin_dir);
            }
        }
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        if ( ! WP_Filesystem() ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("WP_Filesystem failed to initialize.");
            }
            wp_send_json_error("Filesystem access error. WP_Filesystem failed to initialize.");
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log("Plugin directory permissions: " . substr(sprintf('%o', fileperms(WP_PLUGIN_DIR)), -4));
        }
        $result = unzip_file( $package_file, WP_PLUGIN_DIR );
        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("CribOps WP-Kit Update Error: unzip_file failed for package $package_file with error: " . $result->get_error_message());
            }
            wp_send_json_error( $result->get_error_message() );
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("CribOps WP-Kit Update: unzip_file succeeded for package $package_file.");
            }
        }
        if ( $was_active ) {
            activate_plugin( $plugin_file );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("CribOps WP-Kit Update: Plugin reactivated: " . $plugin_file);
            }
        }
        wp_send_json_success( 'Plugin updated successfully' );
    }

    /**
     * Recursively delete a directory.
     */
    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $objects = scandir( $dir );
        foreach ( $objects as $object ) {
            if ( $object == '.' || $object == '..' ) {
                continue;
            }
            $path = $dir . '/' . $object;
            if ( is_dir( $path ) ) {
                $this->recursive_delete( $path );
            } else {
                unlink( $path );
            }
        }
        rmdir( $dir );
    }

    /**
     * Add a debug page to the LaunchKit menu.
     */
    public function add_debug_page() {
        add_submenu_page(
            'cwpk',
            'CribOps WP-Kit Debug',
            'Debug',
            'manage_options',
            'launchkit-debug',
            [ $this, 'render_debug_page' ]
        );
    }

    /**
     * Render the debug page.
     */
    public function render_debug_page() {
        if ( ! $this->user_has_access() ) {
            echo '<div class="wrap"><h1>Access Denied</h1><p>Please log in with a valid subscription to view debug info.</p></div>';
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>Debug</h1>';
        echo '<h2>Bundle Directory</h2>';
        echo '<p>Path: ' . esc_html( $this->update_dir ) . '</p>';
        echo '<p>Status: ' . ( is_dir( $this->update_dir ) ? 'Directory exists' : 'Directory missing' ) . '</p>';
        $zip_files = glob( $this->update_dir . '/*.zip' );
        echo '<h2>ZIP Files</h2>';
        echo '<p>Found ' . count( $zip_files ) . ' ZIP files</p>';
        if ( ! empty( $zip_files ) ) {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr><th>Filename</th><th>Size</th></tr></thead><tbody>';
            foreach ( $zip_files as $zip ) {
                echo '<tr>';
                echo '<td>' . esc_html( basename( $zip ) ) . '</td>';
                echo '<td>' . esc_html( size_format( filesize( $zip ) ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>Bundle Plugins</h2>';
        if ( empty( $this->bundle_plugins ) ) {
            echo '<p>No plugins detected in bundle</p>';
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr><th>Plugin</th><th>Version</th><th>Package</th></tr></thead><tbody>';
            foreach ( $this->bundle_plugins as $plugin ) {
                echo '<tr>';
                echo '<td>' . esc_html( $plugin['name'] ) . '</td>';
                echo '<td>' . esc_html( $plugin['version'] ) . '</td>';
                echo '<td>' . esc_html( basename( $plugin['package'] ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>Installed Plugins</h2>';
        $installed = get_plugins();
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Plugin</th><th>Installed Version</th><th>Bundle Version</th><th>Status</th></tr></thead><tbody>';
        foreach ( $installed as $file => $data ) {
            $folder = dirname( $file );
            if ( $folder === '.' ) continue;
            $bundle_version = isset( $this->bundle_plugins[ $folder ] ) ? $this->bundle_plugins[ $folder ]['version'] : null;
            $status = '';
            if ( $bundle_version ) {
                if ( version_compare( $bundle_version, $data['Version'], '>' ) ) {
                    $status = '<span style="color:green;">Update available</span>';
                } else {
                    $status = '<span style="color:blue;">Up to date</span>';
                }
            } else {
                $status = '<span style="color:gray;">Not in bundle</span>';
            }
            echo '<tr>';
            echo '<td>' . esc_html( $data['Name'] ) . '</td>';
            echo '<td>' . esc_html( $data['Version'] ) . '</td>';
            echo '<td>' . ( $bundle_version ? esc_html( $bundle_version ) : 'N/A' ) . '</td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Add an "Add Software Bundle Plugins" link on the Plugins page.
     * This link is placed immediately after the "Add New" button and directs to the LaunchKit admin page.
     */
    public function add_check_updates_button() {
        if ( ! $this->user_has_access() ) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($){
            var addNewBtn = $('.wrap .page-title-action').first();
            if ( addNewBtn.length ) {
                var addLaunchKitLink = $('<a class="page-title-action" href="<?php echo admin_url("admin.php?page=cwpk"); ?>">Add Software Bundle Plugins</a>');
                addNewBtn.after(addLaunchKitLink);
            }
        });
        </script>
        <?php
    }

    /**
     * Add an admin bar button for "Update Software Bundle" so it is accessible from any admin page.
     */
    public function add_admin_bar_button( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! $this->user_has_access() ) {
            return;
        }
        $args = array(
            'id'    => 'check_launchkit_updates',
            'title' => 'Update Software Bundle',
            'href'  => '#',
            'meta'  => array(
                'title' => 'Update Software Bundle',
            ),
        );
        $wp_admin_bar->add_node( $args );
    }

    /**
     * Output the JavaScript that handles the admin bar button click.
     */
    public function admin_bar_script() {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! $this->user_has_access() ) {
            return;
        }
        $nonce = wp_create_nonce( 'check_for_updates_nonce' );
        ?>
        <script>
        jQuery(document).ready(function($){
            $('#wp-admin-bar-check_launchkit_updates a').on('click', function(e){
                e.preventDefault();
                var link = $(this);
                link.text('Checking...').css({
                    'pointer-events': 'none',
                    'opacity': '0.7'
                });
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'check_for_updates',
                        security: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if ( response.success ) {
                            link.text('Updates Fetched! Reloading...');
                            setTimeout(function(){
                                location.reload();
                            }, 1000);
                        } else {
                            link.text('Update Software Bundle').css({
                                'pointer-events': '',
                                'opacity': '1'
                            });
                            alert(response.data || 'Failed to check for updates. Please try again.');
                        }
                    },
                    error: function() {
                        link.text('Update Software Bundle').css({
                            'pointer-events': '',
                            'opacity': '1'
                        });
                        alert('An error occurred while checking for updates. Please try again.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Register a new bulk action for updating LaunchKit plugins.
     */
    public function register_bulk_update_action( $actions ) {
        if ( ! $this->user_has_access() ) {
            return $actions;
        }
        $actions['update_launchkit_plugins'] = __( 'Update Software Bundle Plugins', 'launchkit' );
        return $actions;
    }

    /**
     * Handle the bulk update action.
     *
     * @param string $redirect_to The URL to redirect to.
     * @param string $action      The bulk action.
     * @param array  $plugin_files List of plugin file paths.
     *
     * @return string
     */
    public function handle_bulk_update_action( $redirect_to, $action, $plugin_files ) {
        if ( $action !== 'update_launchkit_plugins' ) {
            return $redirect_to;
        }
        $updated = 0;
        $failed  = 0;
        $plugins = get_plugins();
        foreach ( $plugin_files as $plugin_file ) {
            $folder = dirname( $plugin_file );
            if ( $folder === '.' ) {
                continue;
            }
            if ( isset( $this->bundle_plugins[ $folder ] ) ) {
                $bundle_plugin = $this->bundle_plugins[ $folder ];
                if ( ! isset( $plugins[ $plugin_file ] ) ) {
                    continue;
                }
                $installed_version = $plugins[ $plugin_file ]['Version'];
                $bundle_version    = $bundle_plugin['version'];
                if ( version_compare( $bundle_version, $installed_version, '>' ) ) {
                    $plugin_dir = WP_PLUGIN_DIR . '/' . $folder;
                    $was_active = is_plugin_active( $plugin_file );
                    if ( is_dir( $plugin_dir ) ) {
                        $this->recursive_delete( $plugin_dir );
                    }
                    require_once( ABSPATH . 'wp-admin/includes/file.php' );
                    $result = unzip_file( $bundle_plugin['package'], WP_PLUGIN_DIR );
                    if ( is_wp_error( $result ) ) {
                        $failed++;
                        continue;
                    }
                    if ( $was_active ) {
                        activate_plugin( $plugin_file );
                    }
                    $updated++;
                }
            }
        }
        $redirect_to = add_query_arg(
            [
                'bulk_updated' => $updated,
                'bulk_failed'  => $failed
            ],
            $redirect_to
        );
        return $redirect_to;
    }

    /**
     * Display an admin notice summarizing the bulk update results.
     */
    public function bulk_update_admin_notice() {
        if ( isset( $_REQUEST['bulk_updated'] ) || isset( $_REQUEST['bulk_failed'] ) ) {
            $updated = intval( $_REQUEST['bulk_updated'] );
            $failed  = intval( $_REQUEST['bulk_failed'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf( '%d plugin(s) updated successfully. %d plugin(s) failed to update.', $updated, $failed );
            echo '</p></div>';
        }
    }

    /**
     * Add a new view tab in the Plugins page for "Software Bundle Plugins".
     */
    public function add_bundle_tab( $views ) {
        $current = isset( $_GET['bundle_only'] ) ? intval( $_GET['bundle_only'] ) : 0;
        $url = add_query_arg( 'bundle_only', 1 );
        $class = $current ? 'current' : '';
        $views['bundle_only'] = '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">Software Bundle Plugins</a>';
        return $views;
    }

    /**
     * Filter the plugins list to show only plugins that are in the software bundle if the view tab is active.
     */
    public function filter_bundle_plugins( $all_plugins ) {
        if ( isset( $_GET['bundle_only'] ) && intval( $_GET['bundle_only'] ) === 1 ) {
            $filtered = [];
            foreach ( $all_plugins as $plugin_key => $plugin_data ) {
                $folder = dirname( $plugin_key );
                if ( isset( $this->bundle_plugins[ $folder ] ) ) {
                    $filtered[ $plugin_key ] = $plugin_data;
                }
            }
            return $filtered;
        }
        return $all_plugins;
    }
}

// Instantiate the Plugin Manager.
new CWPKPluginManager();
