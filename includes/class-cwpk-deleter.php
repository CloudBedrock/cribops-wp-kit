<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class CWPKDeleter {

    public function __construct() {
        add_action('plugins_loaded', array($this, 'launchkit_deleter_load_textdomain'));
        add_action('admin_menu', array($this, 'launchkit_deleter_menu'));
        add_filter('views_plugins', array($this, 'launchkit_add_delete_inactive_link'));
        add_action('wp_ajax_launchkit_cleanup_plugins', array($this, 'launchkit_ajax_cleanup_plugins'));
    }

    public function launchkit_deleter_load_textdomain() {
        load_plugin_textdomain('launchkit-deleter', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function launchkit_deleter_menu() {
        $parent_slug = 'cwpk';
        $page_slug = 'launchkit-deleter';
        $capability = 'manage_options';

        add_submenu_page(
            $parent_slug,
            __('CribOps WP-Kit Deleter', 'launchkit-deleter'),
            __('CribOps WP-Kit Deleter', 'launchkit-deleter'),
            $capability,
            $page_slug,
            array($this, 'launchkit_deleter_page')
        );

        add_action('admin_head', array($this, 'hide_deleter_submenu_item'));
    }

    public function hide_deleter_submenu_item() {
        global $submenu;
        $parent_slug = 'cwpk';
        $page_slug = 'launchkit-deleter';

        if (isset($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as &$item) {
                if ($item[2] === $page_slug) {
                    $item[4] = 'launchkit-deleter-hidden';
                    break;
                }
            }
        }

        echo '<style>.launchkit-deleter-hidden { display: none !important; }</style>';
    }

    public function launchkit_deleter_page() {
        if (isset($_POST['confirm_delete'])) {
            $selected_plugins = $_POST['selected_plugins'];
            $this->delete_selected_plugins($selected_plugins);

            echo '<div class="cwpk-success">' . __('Success! The selected plugins have been deleted', 'lk') . '</div>';
            echo '<div class="cwpk-refresh-button-container"><br/>';
            echo '<button class="cwpk-refresh-button button button-primary" onclick="location.reload();">' . __('Click To Refresh', 'lk') . '</button>';
            echo '</div>';
        }

        ?>
        <h1><?php _e('CribOps WP-Kit Deleter', 'lk'); ?></h1>
        <div class="wrap">
            <button id="launchkit-cleanup-button" class="button button-secondary"><?php _e('Cleanup All Inactive Plugins', 'lk'); ?></button>
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

                // Combine both deleted and manually deleted plugins into a single list
                var allDeletedPlugins = response.data.deleted.concat(response.data.manual);

                // Format the output with each plugin on a new line
                var deletedPluginsList = '<p><?php _e("Deleted Plugins:", "lk"); ?></p><ul>';
                allDeletedPlugins.forEach(function(plugin) {
                    deletedPluginsList += '<li>' + plugin + '</li>';
                });
                deletedPluginsList += '</ul>';

                document.getElementById('launchkit-summary').innerHTML = deletedPluginsList;

                // Show the "Click to Refresh" link in red
                var refreshLink = '<br/><a href="#" id="launchkit-refresh-link" style="color: red; text-decoration: underline;"><?php _e("Click To Refresh Plugin List", "lk"); ?></a>';
                document.getElementById('launchkit-summary').innerHTML += refreshLink;

                // Add event listener for the refresh link
                document.getElementById('launchkit-refresh-link').addEventListener('click', function(e) {
                    e.preventDefault(); // Prevent default link behavior
                    window.location.reload(); // Reload the page when the link is clicked
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

            <?php if (isset($_POST['delete_plugins'])) { ?>
                <div>
                    <p><?php _e('Are you sure you want to delete the selected plugins?', 'lk'); ?></p>
                    <form method="post" action="">
                        <?php
                        $selected_plugins = $_POST['selected_plugins'];
                        foreach ($selected_plugins as $plugin_file) {
                            echo '<input type="hidden" name="selected_plugins[]" value="' . esc_attr($plugin_file) . '">';
                        }
                        ?>
                        <input type="submit" name="confirm_delete" value="<?php _e('Yes, Delete Selected Plugins', 'lk'); ?>" class="button button-primary">
                        <a href="<?php echo admin_url('admin.php?page=launchkit-deleter'); ?>" class="button"><?php _e('Cancel', 'lk'); ?></a>
                    </form>
                </div>
            <?php } else { ?>
                <h4><?php _e('Or select specific plugins to delete...', 'lk'); ?></h4>
                <form method="post" action="">
                    <?php
                    $plugins = get_plugins();
                    $launchkit_plugin = 'launchkit';

                    if (!empty($plugins)) {
                        echo '<ul>';
                        echo "<li><input type='checkbox' id='select-all'> " . __('Select All', 'lk') . "</li>";
                        foreach ($plugins as $plugin_file => $plugin_data) {
                            if (strpos($plugin_file, $launchkit_plugin . '/') === 0) {
                                continue;
                            }
                            $plugin_name = $plugin_data['Name'];
                            echo "<li><input type='checkbox' name='selected_plugins[]' value='$plugin_file'> $plugin_name</li>";
                        }
                        echo '</ul>';
                        echo '<input type="submit" name="delete_plugins" value="' . __('Delete Selected Plugins', 'lk') . '" class="button button-primary">';
                    } else {
                        echo __('No plugins installed.', 'lk');
                    }
                    ?>
                </form>
            <?php } ?>

        </div>

        <script>
            document.getElementById('select-all').addEventListener('change', function() {
                var checkboxes = document.querySelectorAll('input[type="checkbox"]');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = this.checked;
                }
            });
        </script>
        <?php
    }

    private function delete_selected_plugins($selected_plugins) {
        $plugins_dir = WP_PLUGIN_DIR;

        foreach ($selected_plugins as $plugin_file) {
            $plugin_path = $plugins_dir . '/' . dirname($plugin_file);
            $this->remove_directory($plugin_path);
        }
    }

    private function remove_directory($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->remove_directory($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function launchkit_add_delete_inactive_link($views) {
        if (get_current_screen()->id !== 'plugin-install') {
            $delete_url = admin_url('admin.php?page=cwpk&tab=deleter');
            $views['delete_inactive'] = '<a href="' . esc_url($delete_url) . '" style="color: red;">' . __('Delete Inactive Plugins', 'lk') . '</a>';
        }
        return $views;
    }

    public function launchkit_ajax_cleanup_plugins() {
        check_ajax_referer('launchkit_cleanup_plugins_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $deleted_plugins = array();
        $manually_deleted_plugins = array();

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (!in_array($plugin_file, $active_plugins)) {
                $this->launchkit_safe_delete_plugin_files($plugin_file);
                $manually_deleted_plugins[] = $plugin_data['Name'];
            }
        }

        wp_send_json_success(array(
            'deleted' => $deleted_plugins,
            'manual'  => $manually_deleted_plugins,
        ));
    }

    private function launchkit_safe_delete_plugin_files($plugin_file) {
        if (!function_exists('wp_delete_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        if (file_exists($plugin_path)) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $wp_filesystem = new WP_Filesystem_Direct(null);
            $wp_filesystem->delete($plugin_path, true);
        }
    }
}

// Instantiate the class
new CWPKDeleter();