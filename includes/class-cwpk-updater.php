<?php
/**
 * WPLKUpdater Class
 * 
 * Handles self-hosted plugin updates for LaunchKit Pro
 *
 * @since 2.13.2
 */
class CWPKUpdater {
    
    /**
     * The plugin current version
     * @var string
     */
    public $current_version;
    
    /**
     * The plugin remote update path
     * @var string
     */
    public $update_path;
    
    /**
     * Plugin Slug (plugin_directory/plugin_file.php)
     * @var string
     */
    public $plugin_slug;
    
    /**
     * Plugin name (plugin_file)
     * @var string
     */
    public $slug;
    
    /**
     * Plugin directory URL
     * @var string
     */
    public $plugin_dir_url;
    
    /**
     * The JSON info path
     * @var string
     */
    public $json_path;
    
    /**
     * Initialize a new instance of the WordPress Auto-Update class
     *
     * @return void
     */
    function __construct() {
        // Set up the plugin data using relative paths
        $plugin_file = plugin_dir_path(dirname(__FILE__)) . 'cribops-wp-kit.php';
        $plugin_data = get_file_data($plugin_file, array('Version' => 'Version'), false);
        $this->current_version = $plugin_data['Version'];
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_dir_url = plugin_dir_url(dirname(__FILE__));
        
        // Extract the slug name
        list($t1, $t2) = explode('/', $this->plugin_slug);
        $this->slug = str_replace('.php', '', $t2);
        
        // Set the update paths using centralized config
        $this->update_path = CWPKConfig::get_update_path();
        $this->json_path = $this->update_path . '/plugins/launchkit.json';
        
        // Define the alternative API for updating checking
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        
        // Define the alternative response for information checking
        add_filter('plugins_api', array($this, 'check_info'), 10, 3);
    }
    
    /**
     * Get the icon URL from the plugin directory
     *
     * @return string The URL to the plugin icon
     */
    private function get_plugin_icon_url() {
        // Replace with the actual path to your icon within the assets/images folder
        return $this->plugin_dir_url . 'assets/images/launchkit-logo.svg';
    }
    
    /**
     * Add our self-hosted plugin to the filter transient
     *
     * @param object $transient
     * @return object $transient
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get the remote version
        $remote = $this->get_remote_info();
        
        // If a newer version is available, add the update info to the transient
        if ($remote && version_compare($this->current_version, $remote->version, '<')) {
            $obj = new stdClass();
            $obj->slug = $this->slug;
            $obj->new_version = $remote->version;
            $obj->url = $this->update_path;
            $obj->package = $remote->download_url;
            $obj->tested = $remote->tested;
            $obj->requires_php = $remote->requires_php;
            
            // Add icon from local plugin folder
            $obj->icons = array(
                'default' => $this->get_plugin_icon_url(),
                '1x' => $this->get_plugin_icon_url(),
                '2x' => $this->get_plugin_icon_url()
            );
            
            $transient->response[$this->plugin_slug] = $obj;
        }
        
        return $transient;
    }
    
    /**
     * Get information about the remote version
     *
     * @return object|boolean The remote info if successful, false otherwise
     */
    public function get_remote_info() {
        // Try to get the remote info from the transient first
        if (false === ($remote = get_transient('cwpk_update_info'))) {
            // Get the remote info from the JSON file
            $response = wp_remote_get($this->json_path, array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json'
                )
            ));
            
            // Check if we got a valid response
            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
                return false;
            }
            
            // Get the body of the response
            $remote = json_decode(wp_remote_retrieve_body($response));
            
            // Cache the response for 12 hours
            set_transient('cwpk_update_info', $remote, 12 * HOUR_IN_SECONDS);
        }
        
        return $remote;
    }
    
    /**
     * Add our self-hosted plugin's information to the plugins_api function
     *
     * @param boolean $result
     * @param string $action
     * @param object $args
     * @return object
     */
    public function check_info($result, $action, $args) {
        // Check if this is the right plugin
        if (isset($args->slug) && $args->slug === $this->slug) {
            // Get information from the remote JSON file
            $remote = $this->get_remote_info();
            
            if (!$remote) {
                return $result;
            }
            
            $result = new stdClass();
            $result->name = $remote->name;
            $result->slug = $this->slug;
            $result->version = $remote->version;
            $result->tested = $remote->tested;
            $result->requires = $remote->requires;
            $result->requires_php = $remote->requires_php;
            $result->author = '<a href="https://wplaunchify.com">WPLaunchify</a>';
            $result->author_profile = 'https://wplaunchify.com';
            $result->download_link = $remote->download_url;
            $result->trunk = $remote->download_url;
            $result->last_updated = $remote->last_updated;
            
            if (isset($remote->sections) && is_object($remote->sections)) {
                $result->sections = array(
                    'description' => $remote->sections->description,
                    'installation' => $remote->sections->installation,
                    'changelog' => $remote->sections->changelog
                );
            }
            
            if (isset($remote->banners) && is_object($remote->banners)) {
                $result->banners = array(
                    'low' => $remote->banners->low,
                    'high' => $remote->banners->high
                );
            }
            
            // Add icon from local plugin folder
            $result->icons = array(
                'default' => $this->get_plugin_icon_url(),
                '1x' => $this->get_plugin_icon_url(),
                '2x' => $this->get_plugin_icon_url()
            );
            
            return $result;
        }
        
        return $result;
    }
}

// Initialize the updater
new CWPKUpdater();