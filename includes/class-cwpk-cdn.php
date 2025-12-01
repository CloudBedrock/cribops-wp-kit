<?php
/**
 * CribOps WP-Kit CDN Integration
 *
 * Handles S3 media offloading and CloudFront CDN URL rewriting.
 * Supports automatic upload of new media and bulk sync of existing files.
 * Optional CSS/JS minification and CDN serving.
 *
 * Environment Variables:
 * - CWPK_S3_BUCKET: S3 bucket name (required)
 * - CWPK_CDN_URL: CloudFront distribution URL (required)
 * - CWPK_S3_REGION: AWS region (optional, defaults to us-east-1)
 * - CWPK_S3_PATH_PREFIX: Path prefix in bucket (optional)
 * - CWPK_CDN_ENABLED: Set to '1' to enable CDN (optional, defaults to enabled if bucket is set)
 * - CWPK_CDN_MINIFY: Set to '1' to enable CSS/JS minification (optional)
 *
 * AWS Credentials (in order of precedence):
 * 1. IAM Instance Profile (auto-detected when running on EC2/ECS)
 * 2. AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY environment variables
 */

if (!defined('ABSPATH')) {
    exit;
}

// AWS SDK classes are loaded dynamically via fully-qualified names
// to avoid fatal errors when vendor directory is missing

class CWPK_CDN {

    /** @var S3Client|null */
    private $s3_client = null;

    /** @var array Configuration cache */
    private $config = null;

    /** @var array URL mapping cache for current request */
    private $url_cache = array();

    /** @var string Table name for tracking synced items */
    private $table_name;

    /** @var CWPK_CDN Singleton instance */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - sets up hooks if CDN is configured
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cwpk_cdn_items';

        // Only initialize if CDN is properly configured AND SDK is available
        if (!$this->is_configured() || !$this->is_sdk_available()) {
            return;
        }

        // Media upload hooks
        add_filter('wp_update_attachment_metadata', array($this, 'handle_upload'), 110, 2);
        add_action('delete_attachment', array($this, 'handle_delete'), 10, 1);

        // URL rewriting hooks
        add_filter('wp_get_attachment_url', array($this, 'rewrite_attachment_url'), 100, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'rewrite_attachment_image_src'), 100, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'rewrite_srcset'), 100, 5);
        add_filter('the_content', array($this, 'rewrite_content_urls'), 100, 1);

        // CSS/JS minification hooks (if enabled)
        if ($this->is_minify_enabled()) {
            add_action('wp_enqueue_scripts', array($this, 'process_enqueued_assets'), 999);
            add_action('wp_print_styles', array($this, 'process_styles'), 999);
            add_action('wp_print_scripts', array($this, 'process_scripts'), 999);
        }

        // Admin hooks
        add_action('wp_ajax_cwpk_cdn_sync', array($this, 'ajax_sync_media'));
        add_action('wp_ajax_cwpk_cdn_sync_status', array($this, 'ajax_sync_status'));
        add_action('wp_ajax_cwpk_cdn_sync_batch', array($this, 'ajax_sync_batch'));
        add_action('wp_ajax_cwpk_cdn_test_connection', array($this, 'ajax_test_connection'));

        // Create tracking table on init
        add_action('admin_init', array($this, 'maybe_create_table'));
    }

    /**
     * Check if AWS SDK is available
     */
    public function is_sdk_available() {
        return class_exists('Aws\S3\S3Client');
    }

    /**
     * Check if CDN is properly configured
     */
    public function is_configured() {
        $config = $this->get_config();
        return !empty($config['bucket']) && !empty($config['cdn_url']);
    }

    /**
     * Check if CDN is fully operational (configured AND SDK available)
     */
    public function is_operational() {
        return $this->is_configured() && $this->is_sdk_available();
    }

    /**
     * Check if CDN is enabled
     */
    public function is_enabled() {
        if (!$this->is_operational()) {
            return false;
        }
        $enabled = getenv('CWPK_CDN_ENABLED');
        // Default to enabled if bucket is set and CWPK_CDN_ENABLED is not explicitly set to '0'
        return $enabled !== '0';
    }

    /**
     * Check if minification is enabled
     */
    public function is_minify_enabled() {
        return getenv('CWPK_CDN_MINIFY') === '1';
    }

    /**
     * Get configuration from environment
     */
    public function get_config() {
        if ($this->config !== null) {
            return $this->config;
        }

        $this->config = array(
            'bucket'      => getenv('CWPK_S3_BUCKET') ?: '',
            'cdn_url'     => rtrim(getenv('CWPK_CDN_URL') ?: '', '/'),
            'region'      => getenv('CWPK_S3_REGION') ?: 'us-east-1',
            'path_prefix' => trim(getenv('CWPK_S3_PATH_PREFIX') ?: '', '/'),
            'access_key'  => getenv('AWS_ACCESS_KEY_ID') ?: '',
            'secret_key'  => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
        );

        return $this->config;
    }

    /**
     * Get S3 client instance
     */
    public function get_s3_client() {
        if ($this->s3_client !== null) {
            return $this->s3_client;
        }

        // Check if AWS SDK is available
        if (!$this->is_sdk_available()) {
            return null;
        }

        $config = $this->get_config();

        $args = array(
            'region'  => $config['region'],
            'version' => 'latest',
        );

        // Only set credentials if explicitly provided (otherwise use IAM role)
        if (!empty($config['access_key']) && !empty($config['secret_key'])) {
            $args['credentials'] = array(
                'key'    => $config['access_key'],
                'secret' => $config['secret_key'],
            );
        }

        try {
            $this->s3_client = new \Aws\S3\S3Client($args);
        } catch (\Exception $e) {
            error_log('CWPK CDN: Failed to create S3 client - ' . $e->getMessage());
            return null;
        }

        return $this->s3_client;
    }

    /**
     * Create the tracking table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_path varchar(255) NOT NULL,
            s3_key varchar(255) NOT NULL,
            bucket varchar(255) NOT NULL,
            region varchar(50) NOT NULL,
            synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            file_hash varchar(64) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_path (attachment_id, source_path),
            KEY source_path (source_path(191)),
            KEY s3_key (s3_key(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Handle new media upload - upload to S3
     *
     * @param array $metadata Attachment metadata
     * @param int $attachment_id Attachment post ID
     * @return array Unchanged metadata
     */
    public function handle_upload($metadata, $attachment_id) {
        if (!$this->is_enabled()) {
            return $metadata;
        }

        // Don't process if already processing (prevent loops)
        if (get_post_meta($attachment_id, '_cwpk_cdn_processing', true)) {
            return $metadata;
        }

        update_post_meta($attachment_id, '_cwpk_cdn_processing', true);

        try {
            $this->upload_attachment_to_s3($attachment_id, $metadata);
        } catch (Exception $e) {
            error_log('CWPK CDN: Failed to upload attachment ' . $attachment_id . ' - ' . $e->getMessage());
        }

        delete_post_meta($attachment_id, '_cwpk_cdn_processing');

        return $metadata;
    }

    /**
     * Handle attachment deletion - remove from S3
     *
     * @param int $attachment_id Attachment post ID
     */
    public function handle_delete($attachment_id) {
        if (!$this->is_enabled()) {
            return;
        }

        global $wpdb;

        // Get all S3 keys for this attachment
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT s3_key FROM {$this->table_name} WHERE attachment_id = %d",
            $attachment_id
        ));

        if (empty($items)) {
            return;
        }

        $s3 = $this->get_s3_client();
        if (!$s3) {
            return;
        }

        $config = $this->get_config();

        // Delete each file from S3
        $objects = array();
        foreach ($items as $item) {
            $objects[] = array('Key' => $item->s3_key);
        }

        try {
            $s3->deleteObjects(array(
                'Bucket' => $config['bucket'],
                'Delete' => array('Objects' => $objects),
            ));
        } catch (\Aws\Exception\AwsException $e) {
            error_log('CWPK CDN: Failed to delete from S3 - ' . $e->getMessage());
        }

        // Remove from tracking table
        $wpdb->delete($this->table_name, array('attachment_id' => $attachment_id), array('%d'));
    }

    /**
     * Upload an attachment and all its sizes to S3
     *
     * @param int $attachment_id Attachment post ID
     * @param array|null $metadata Attachment metadata (fetched if not provided)
     * @return bool Success status
     */
    public function upload_attachment_to_s3($attachment_id, $metadata = null) {
        $s3 = $this->get_s3_client();
        if (!$s3) {
            return false;
        }

        if ($metadata === null) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }

        $config = $this->get_config();
        $upload_dir = wp_upload_dir();
        $base_file = get_attached_file($attachment_id);

        if (!$base_file || !file_exists($base_file)) {
            return false;
        }

        // Calculate relative path from uploads directory
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $base_file);
        $relative_dir = dirname($relative_path);

        // Build S3 key with optional prefix
        $s3_prefix = $config['path_prefix'] ? $config['path_prefix'] . '/' : '';

        // Files to upload: original + all sizes
        $files_to_upload = array();

        // Add original file
        $files_to_upload[] = array(
            'local_path'    => $base_file,
            'relative_path' => $relative_path,
            's3_key'        => $s3_prefix . $relative_path,
        );

        // Add image sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                $size_file = $upload_dir['basedir'] . '/' . $relative_dir . '/' . $size_data['file'];
                if (file_exists($size_file)) {
                    $size_relative = $relative_dir . '/' . $size_data['file'];
                    $files_to_upload[] = array(
                        'local_path'    => $size_file,
                        'relative_path' => $size_relative,
                        's3_key'        => $s3_prefix . $size_relative,
                    );
                }
            }
        }

        global $wpdb;
        $success = true;

        foreach ($files_to_upload as $file) {
            try {
                $mime_type = mime_content_type($file['local_path']);

                $s3->putObject(array(
                    'Bucket'       => $config['bucket'],
                    'Key'          => $file['s3_key'],
                    'SourceFile'   => $file['local_path'],
                    'ContentType'  => $mime_type,
                    'CacheControl' => 'max-age=31536000', // 1 year cache
                ));

                // Track in database
                $wpdb->replace($this->table_name, array(
                    'attachment_id' => $attachment_id,
                    'source_path'   => $file['relative_path'],
                    's3_key'        => $file['s3_key'],
                    'bucket'        => $config['bucket'],
                    'region'        => $config['region'],
                    'file_hash'     => md5_file($file['local_path']),
                ), array('%d', '%s', '%s', '%s', '%s', '%s'));

            } catch (\Aws\Exception\AwsException $e) {
                error_log('CWPK CDN: Failed to upload ' . $file['relative_path'] . ' - ' . $e->getMessage());
                $success = false;
            }
        }

        if ($success) {
            update_post_meta($attachment_id, '_cwpk_cdn_synced', current_time('mysql'));
        }

        return $success;
    }

    /**
     * Rewrite attachment URL to CDN
     *
     * @param string $url Original URL
     * @param int $attachment_id Attachment ID
     * @return string Rewritten URL
     */
    public function rewrite_attachment_url($url, $attachment_id) {
        if (!$this->is_enabled()) {
            return $url;
        }

        // Check cache first
        if (isset($this->url_cache[$url])) {
            return $this->url_cache[$url];
        }

        // Check if this attachment has been synced
        if (!$this->is_attachment_synced($attachment_id)) {
            return $url;
        }

        $cdn_url = $this->convert_to_cdn_url($url);
        $this->url_cache[$url] = $cdn_url;

        return $cdn_url;
    }

    /**
     * Rewrite image src array to CDN
     */
    public function rewrite_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (!$this->is_enabled() || empty($image) || !is_array($image)) {
            return $image;
        }

        if (!$this->is_attachment_synced($attachment_id)) {
            return $image;
        }

        if (!empty($image[0])) {
            $image[0] = $this->convert_to_cdn_url($image[0]);
        }

        return $image;
    }

    /**
     * Rewrite srcset URLs to CDN
     */
    public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->is_enabled() || empty($sources)) {
            return $sources;
        }

        if (!$this->is_attachment_synced($attachment_id)) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            $sources[$width]['url'] = $this->convert_to_cdn_url($source['url']);
        }

        return $sources;
    }

    /**
     * Rewrite URLs in post content
     */
    public function rewrite_content_urls($content) {
        if (!$this->is_enabled() || empty($content)) {
            return $content;
        }

        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $config = $this->get_config();

        // Fast path: Check if content contains upload URLs at all
        if (strpos($content, $upload_url) === false) {
            return $content;
        }

        // Replace upload URLs with CDN URLs
        // Pattern matches the uploads URL with optional path
        $pattern = preg_quote($upload_url, '/');
        $prefix = $config['path_prefix'] ? '/' . $config['path_prefix'] : '';

        $content = preg_replace_callback(
            '/' . $pattern . '\/([^"\'\s\)]+)/i',
            function($matches) use ($config, $prefix) {
                $relative_path = $matches[1];
                // Only rewrite if the file is tracked/synced
                if ($this->is_path_synced($relative_path)) {
                    return $config['cdn_url'] . $prefix . '/' . $relative_path;
                }
                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Convert a local URL to CDN URL
     */
    private function convert_to_cdn_url($url) {
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $config = $this->get_config();

        // Check if URL is from our uploads directory
        if (strpos($url, $upload_url) === false) {
            return $url;
        }

        // Extract relative path
        $relative_path = str_replace($upload_url . '/', '', $url);
        $prefix = $config['path_prefix'] ? '/' . $config['path_prefix'] : '';

        return $config['cdn_url'] . $prefix . '/' . $relative_path;
    }

    /**
     * Check if an attachment has been synced to S3
     */
    public function is_attachment_synced($attachment_id) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE attachment_id = %d",
            $attachment_id
        ));

        return $count > 0;
    }

    /**
     * Check if a specific path has been synced
     */
    private function is_path_synced($relative_path) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE source_path = %s",
            $relative_path
        ));

        return $count > 0;
    }

    /**
     * Get sync status for admin display
     */
    public function get_sync_status() {
        global $wpdb;

        // Total media attachments
        $total_attachments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );

        // Synced attachments (distinct)
        $synced_attachments = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM {$this->table_name}"
        );

        // Total files in S3 (including sizes)
        $total_files = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}"
        );

        return array(
            'total_attachments'  => $total_attachments,
            'synced_attachments' => $synced_attachments,
            'total_files_in_s3'  => $total_files,
            'pending'            => max(0, $total_attachments - $synced_attachments),
            'percent_complete'   => $total_attachments > 0 ? round(($synced_attachments / $total_attachments) * 100) : 100,
        );
    }

    /**
     * Get unsynced attachment IDs for batch processing
     */
    public function get_unsynced_attachment_ids($limit = 10) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$this->table_name} c ON p.ID = c.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%%'
            AND c.attachment_id IS NULL
            LIMIT %d",
            $limit
        );

        return $wpdb->get_col($sql);
    }

    // =========================================================================
    // CSS/JS Minification & CDN
    // =========================================================================

    /**
     * Process enqueued assets for CDN
     */
    public function process_enqueued_assets() {
        if (!$this->is_minify_enabled()) {
            return;
        }
        // This is a placeholder - actual processing happens in process_styles/process_scripts
    }

    /**
     * Process and upload CSS files to CDN
     */
    public function process_styles() {
        if (!$this->is_minify_enabled()) {
            return;
        }

        global $wp_styles;
        if (empty($wp_styles->queue)) {
            return;
        }

        $config = $this->get_config();

        foreach ($wp_styles->queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];

            // Only process local stylesheets
            if (!$this->is_local_asset($style->src)) {
                continue;
            }

            $cdn_url = $this->get_or_create_asset_cdn_url($style->src, 'css');
            if ($cdn_url) {
                $wp_styles->registered[$handle]->src = $cdn_url;
            }
        }
    }

    /**
     * Process and upload JS files to CDN
     */
    public function process_scripts() {
        if (!$this->is_minify_enabled()) {
            return;
        }

        global $wp_scripts;
        if (empty($wp_scripts->queue)) {
            return;
        }

        foreach ($wp_scripts->queue as $handle) {
            if (!isset($wp_scripts->registered[$handle])) {
                continue;
            }

            $script = $wp_scripts->registered[$handle];

            // Only process local scripts
            if (!$this->is_local_asset($script->src)) {
                continue;
            }

            $cdn_url = $this->get_or_create_asset_cdn_url($script->src, 'js');
            if ($cdn_url) {
                $wp_scripts->registered[$handle]->src = $cdn_url;
            }
        }
    }

    /**
     * Check if asset URL is local
     */
    private function is_local_asset($url) {
        if (empty($url)) {
            return false;
        }

        // Handle protocol-relative URLs
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        // Handle relative URLs
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return true;
        }

        $site_url = site_url();
        return strpos($url, $site_url) === 0;
    }

    /**
     * Get or create CDN URL for an asset (CSS/JS)
     */
    private function get_or_create_asset_cdn_url($src, $type) {
        // Convert URL to local path
        $local_path = $this->url_to_local_path($src);
        if (!$local_path || !file_exists($local_path)) {
            return false;
        }

        $config = $this->get_config();
        $file_hash = md5_file($local_path);
        $relative_path = 'assets/' . $type . '/' . $file_hash . '.' . $type;
        $s3_key = ($config['path_prefix'] ? $config['path_prefix'] . '/' : '') . $relative_path;

        // Check if already uploaded (by checking S3 directly or local cache)
        $cache_key = 'cwpk_asset_' . $file_hash;
        $cached_url = get_transient($cache_key);
        if ($cached_url) {
            return $cached_url;
        }

        // Read and optionally minify content
        $content = file_get_contents($local_path);
        if ($this->is_minify_enabled()) {
            $content = $this->minify_content($content, $type);
        }

        // Upload to S3
        $s3 = $this->get_s3_client();
        if (!$s3) {
            return false;
        }

        try {
            $mime_types = array(
                'css' => 'text/css',
                'js'  => 'application/javascript',
            );

            $s3->putObject(array(
                'Bucket'       => $config['bucket'],
                'Key'          => $s3_key,
                'Body'         => $content,
                'ContentType'  => $mime_types[$type] ?? 'application/octet-stream',
                'CacheControl' => 'max-age=31536000',
            ));

            $cdn_url = $config['cdn_url'] . '/' . $relative_path;
            set_transient($cache_key, $cdn_url, DAY_IN_SECONDS);

            return $cdn_url;

        } catch (\Aws\Exception\AwsException $e) {
            error_log('CWPK CDN: Failed to upload asset - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert URL to local filesystem path
     */
    private function url_to_local_path($url) {
        // Handle relative URLs
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return ABSPATH . ltrim($url, '/');
        }

        // Handle absolute URLs
        $site_url = site_url();
        if (strpos($url, $site_url) === 0) {
            $path = str_replace($site_url, '', $url);
            return ABSPATH . ltrim($path, '/');
        }

        return false;
    }

    /**
     * Minify CSS or JS content
     */
    private function minify_content($content, $type) {
        if ($type === 'css') {
            return $this->minify_css($content);
        } elseif ($type === 'js') {
            return $this->minify_js($content);
        }
        return $content;
    }

    /**
     * Basic CSS minification
     */
    private function minify_css($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        // Remove spaces around special characters
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);
        // Remove trailing semicolons before closing braces
        $css = str_replace(';}', '}', $css);
        return trim($css);
    }

    /**
     * Basic JS minification (conservative approach)
     */
    private function minify_js($js) {
        // Remove single-line comments (but not in strings)
        $js = preg_replace('#(?<!:)//[^\n\r]*#', '', $js);
        // Remove multi-line comments
        $js = preg_replace('#/\*.*?\*/#s', '', $js);
        // Remove extra whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        // Remove spaces around operators (conservative - only specific ones)
        $js = preg_replace('/\s*([{};,:])\s*/', '$1', $js);
        return trim($js);
    }

    // =========================================================================
    // AJAX Handlers for Admin
    // =========================================================================

    /**
     * AJAX: Test S3 connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('cwpk_cdn_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $s3 = $this->get_s3_client();
        if (!$s3) {
            wp_send_json_error('Failed to create S3 client. Check your credentials.');
        }

        $config = $this->get_config();

        try {
            // Try to list objects (limited to 1) to test access
            $result = $s3->listObjectsV2(array(
                'Bucket'  => $config['bucket'],
                'MaxKeys' => 1,
            ));

            wp_send_json_success(array(
                'message' => 'Connection successful! Bucket is accessible.',
                'bucket'  => $config['bucket'],
                'region'  => $config['region'],
            ));

        } catch (\Aws\Exception\AwsException $e) {
            wp_send_json_error('Connection failed: ' . $e->getAwsErrorMessage());
        }
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_sync_status() {
        check_ajax_referer('cwpk_cdn_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        wp_send_json_success($this->get_sync_status());
    }

    /**
     * AJAX: Start sync process
     */
    public function ajax_sync_media() {
        check_ajax_referer('cwpk_cdn_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $status = $this->get_sync_status();

        wp_send_json_success(array(
            'message'  => 'Sync started',
            'pending'  => $status['pending'],
            'batch_size' => 5,
        ));
    }

    /**
     * AJAX: Process a batch of media files
     */
    public function ajax_sync_batch() {
        check_ajax_referer('cwpk_cdn_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
        $attachment_ids = $this->get_unsynced_attachment_ids($batch_size);

        $results = array(
            'processed' => 0,
            'success'   => 0,
            'failed'    => 0,
            'remaining' => 0,
        );

        foreach ($attachment_ids as $attachment_id) {
            $results['processed']++;

            try {
                if ($this->upload_attachment_to_s3($attachment_id)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                error_log('CWPK CDN: Batch sync failed for attachment ' . $attachment_id . ' - ' . $e->getMessage());
            }
        }

        $status = $this->get_sync_status();
        $results['remaining'] = $status['pending'];
        $results['percent_complete'] = $status['percent_complete'];

        wp_send_json_success($results);
    }

    // =========================================================================
    // Admin Page Rendering
    // =========================================================================

    /**
     * Render the CDN admin tab content
     */
    public function render_admin_page() {
        $config = $this->get_config();
        $is_configured = $this->is_configured();
        $is_sdk_available = $this->is_sdk_available();
        $is_enabled = $this->is_enabled();
        $status = ($is_configured && $is_sdk_available) ? $this->get_sync_status() : null;
        ?>
        <div class="cwpk-cdn-admin">
            <h2><?php esc_html_e('CDN Settings', 'cwpk'); ?></h2>

            <div class="cwpk-cdn-status-box">
                <h3><?php esc_html_e('Configuration Status', 'cwpk'); ?></h3>
                <table class="form-table cwpk-cdn-config-table">
                    <tr>
                        <th><?php esc_html_e('S3 Bucket', 'cwpk'); ?></th>
                        <td>
                            <?php if ($config['bucket']): ?>
                                <span class="cwpk-cdn-status-ok"><?php echo esc_html($config['bucket']); ?></span>
                            <?php else: ?>
                                <span class="cwpk-cdn-status-error"><?php esc_html_e('Not configured', 'cwpk'); ?></span>
                                <br><code>CWPK_S3_BUCKET</code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('CDN URL', 'cwpk'); ?></th>
                        <td>
                            <?php if ($config['cdn_url']): ?>
                                <span class="cwpk-cdn-status-ok"><?php echo esc_html($config['cdn_url']); ?></span>
                            <?php else: ?>
                                <span class="cwpk-cdn-status-error"><?php esc_html_e('Not configured', 'cwpk'); ?></span>
                                <br><code>CWPK_CDN_URL</code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Region', 'cwpk'); ?></th>
                        <td><?php echo esc_html($config['region']); ?> <code>CWPK_S3_REGION</code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Path Prefix', 'cwpk'); ?></th>
                        <td>
                            <?php echo $config['path_prefix'] ? esc_html($config['path_prefix']) : '<em>' . esc_html__('None', 'cwpk') . '</em>'; ?>
                            <code>CWPK_S3_PATH_PREFIX</code>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('AWS Credentials', 'cwpk'); ?></th>
                        <td>
                            <?php if ($config['access_key']): ?>
                                <span class="cwpk-cdn-status-ok"><?php esc_html_e('Using environment credentials', 'cwpk'); ?></span>
                            <?php else: ?>
                                <span class="cwpk-cdn-status-ok"><?php esc_html_e('Using IAM role (recommended)', 'cwpk'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('AWS SDK', 'cwpk'); ?></th>
                        <td>
                            <?php if ($is_sdk_available): ?>
                                <span class="cwpk-cdn-status-ok"><?php esc_html_e('Installed', 'cwpk'); ?></span>
                            <?php else: ?>
                                <span class="cwpk-cdn-status-error"><?php esc_html_e('Not installed', 'cwpk'); ?></span>
                                <br><small><?php esc_html_e('Run: composer install in the plugin directory', 'cwpk'); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('CSS/JS Minification', 'cwpk'); ?></th>
                        <td>
                            <?php if ($this->is_minify_enabled()): ?>
                                <span class="cwpk-cdn-status-ok"><?php esc_html_e('Enabled', 'cwpk'); ?></span>
                            <?php else: ?>
                                <span class="cwpk-cdn-status-disabled"><?php esc_html_e('Disabled', 'cwpk'); ?></span>
                            <?php endif; ?>
                            <code>CWPK_CDN_MINIFY=1</code>
                        </td>
                    </tr>
                </table>

                <?php if ($is_configured && $is_sdk_available): ?>
                    <p>
                        <button type="button" class="button" id="cwpk-cdn-test-connection">
                            <?php esc_html_e('Test Connection', 'cwpk'); ?>
                        </button>
                        <span id="cwpk-cdn-test-result"></span>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($is_configured && $status): ?>
            <div class="cwpk-cdn-sync-box">
                <h3><?php esc_html_e('Media Sync Status', 'cwpk'); ?></h3>
                <div class="cwpk-cdn-sync-stats">
                    <div class="cwpk-cdn-stat">
                        <span class="cwpk-cdn-stat-value" id="cwpk-cdn-total"><?php echo esc_html($status['total_attachments']); ?></span>
                        <span class="cwpk-cdn-stat-label"><?php esc_html_e('Total Images', 'cwpk'); ?></span>
                    </div>
                    <div class="cwpk-cdn-stat">
                        <span class="cwpk-cdn-stat-value" id="cwpk-cdn-synced"><?php echo esc_html($status['synced_attachments']); ?></span>
                        <span class="cwpk-cdn-stat-label"><?php esc_html_e('Synced to S3', 'cwpk'); ?></span>
                    </div>
                    <div class="cwpk-cdn-stat">
                        <span class="cwpk-cdn-stat-value" id="cwpk-cdn-pending"><?php echo esc_html($status['pending']); ?></span>
                        <span class="cwpk-cdn-stat-label"><?php esc_html_e('Pending', 'cwpk'); ?></span>
                    </div>
                    <div class="cwpk-cdn-stat">
                        <span class="cwpk-cdn-stat-value" id="cwpk-cdn-files"><?php echo esc_html($status['total_files_in_s3']); ?></span>
                        <span class="cwpk-cdn-stat-label"><?php esc_html_e('Files in S3', 'cwpk'); ?></span>
                    </div>
                </div>

                <div class="cwpk-cdn-progress-container" style="display: none;">
                    <div class="cwpk-cdn-progress-bar">
                        <div class="cwpk-cdn-progress-fill" id="cwpk-cdn-progress-fill" style="width: <?php echo esc_attr($status['percent_complete']); ?>%"></div>
                    </div>
                    <span class="cwpk-cdn-progress-text" id="cwpk-cdn-progress-text"><?php echo esc_html($status['percent_complete']); ?>%</span>
                </div>

                <p class="cwpk-cdn-sync-actions">
                    <?php if ($status['pending'] > 0): ?>
                        <button type="button" class="button button-primary" id="cwpk-cdn-sync-start">
                            <?php esc_html_e('Sync Existing Media', 'cwpk'); ?>
                        </button>
                        <button type="button" class="button" id="cwpk-cdn-sync-stop" style="display: none;">
                            <?php esc_html_e('Stop', 'cwpk'); ?>
                        </button>
                    <?php else: ?>
                        <span class="cwpk-cdn-status-ok"><?php esc_html_e('All media is synced!', 'cwpk'); ?></span>
                    <?php endif; ?>
                </p>
                <p id="cwpk-cdn-sync-status"></p>
            </div>
            <?php endif; ?>

            <?php if (!$is_configured || !$is_sdk_available): ?>
            <div class="cwpk-cdn-setup-box">
                <h3><?php esc_html_e('Setup Instructions', 'cwpk'); ?></h3>

                <?php if (!$is_sdk_available): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                    <strong><?php esc_html_e('AWS SDK not installed', 'cwpk'); ?></strong><br>
                    <?php esc_html_e('Run the following command in the plugin directory:', 'cwpk'); ?>
                    <pre style="margin: 5px 0 0 0;">composer install</pre>
                </div>
                <?php endif; ?>

                <?php if (!$is_configured): ?>
                <p><?php esc_html_e('To enable CDN integration, add the following environment variables:', 'cwpk'); ?></p>
                <pre>
# Required
CWPK_S3_BUCKET=your-bucket-name
CWPK_CDN_URL=https://your-cloudfront-distribution.cloudfront.net

# Optional
CWPK_S3_REGION=us-east-1
CWPK_S3_PATH_PREFIX=wp-content/uploads
CWPK_CDN_MINIFY=1

# If not using IAM roles (EC2/ECS), provide credentials:
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
                </pre>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <style>
                .cwpk-cdn-admin { max-width: 800px; }
                .cwpk-cdn-status-box, .cwpk-cdn-sync-box, .cwpk-cdn-setup-box {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 15px 20px;
                    margin-bottom: 20px;
                }
                .cwpk-cdn-config-table th { width: 150px; padding: 8px 10px 8px 0; }
                .cwpk-cdn-config-table td { padding: 8px 0; }
                .cwpk-cdn-config-table code { font-size: 11px; color: #666; margin-left: 10px; }
                .cwpk-cdn-status-ok { color: #00a32a; font-weight: 600; }
                .cwpk-cdn-status-error { color: #d63638; font-weight: 600; }
                .cwpk-cdn-status-disabled { color: #787c82; }
                .cwpk-cdn-sync-stats { display: flex; gap: 30px; margin: 15px 0; }
                .cwpk-cdn-stat { text-align: center; }
                .cwpk-cdn-stat-value { display: block; font-size: 24px; font-weight: 600; color: #1d2327; }
                .cwpk-cdn-stat-label { font-size: 12px; color: #50575e; }
                .cwpk-cdn-progress-container { margin: 15px 0; }
                .cwpk-cdn-progress-bar { height: 20px; background: #dcdcde; border-radius: 10px; overflow: hidden; }
                .cwpk-cdn-progress-fill { height: 100%; background: #2271b1; transition: width 0.3s; }
                .cwpk-cdn-progress-text { display: inline-block; margin-top: 5px; font-weight: 600; }
                .cwpk-cdn-setup-box pre { background: #f0f0f1; padding: 15px; overflow-x: auto; font-size: 12px; }
            </style>

            <script>
            jQuery(document).ready(function($) {
                var syncRunning = false;
                var nonce = '<?php echo wp_create_nonce('cwpk_cdn_nonce'); ?>';

                // Test connection
                $('#cwpk-cdn-test-connection').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#cwpk-cdn-test-result');

                    $btn.prop('disabled', true).text('<?php esc_html_e('Testing...', 'cwpk'); ?>');
                    $result.text('');

                    $.post(ajaxurl, {
                        action: 'cwpk_cdn_test_connection',
                        nonce: nonce
                    }, function(response) {
                        if (response.success) {
                            $result.html('<span class="cwpk-cdn-status-ok">' + response.data.message + '</span>');
                        } else {
                            $result.html('<span class="cwpk-cdn-status-error">' + response.data + '</span>');
                        }
                    }).fail(function() {
                        $result.html('<span class="cwpk-cdn-status-error"><?php esc_html_e('Request failed', 'cwpk'); ?></span>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('Test Connection', 'cwpk'); ?>');
                    });
                });

                // Start sync
                $('#cwpk-cdn-sync-start').on('click', function() {
                    syncRunning = true;
                    $(this).hide();
                    $('#cwpk-cdn-sync-stop').show();
                    $('.cwpk-cdn-progress-container').show();
                    processBatch();
                });

                // Stop sync
                $('#cwpk-cdn-sync-stop').on('click', function() {
                    syncRunning = false;
                    $(this).hide();
                    $('#cwpk-cdn-sync-start').show();
                    $('#cwpk-cdn-sync-status').text('<?php esc_html_e('Sync stopped', 'cwpk'); ?>');
                });

                function processBatch() {
                    if (!syncRunning) return;

                    $.post(ajaxurl, {
                        action: 'cwpk_cdn_sync_batch',
                        nonce: nonce,
                        batch_size: 5
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#cwpk-cdn-synced').text(parseInt($('#cwpk-cdn-synced').text()) + data.success);
                            $('#cwpk-cdn-pending').text(data.remaining);
                            $('#cwpk-cdn-progress-fill').css('width', data.percent_complete + '%');
                            $('#cwpk-cdn-progress-text').text(data.percent_complete + '%');
                            $('#cwpk-cdn-sync-status').text('<?php esc_html_e('Processed', 'cwpk'); ?> ' + data.processed + ' <?php esc_html_e('files', 'cwpk'); ?>...');

                            if (data.remaining > 0 && syncRunning) {
                                setTimeout(processBatch, 500);
                            } else {
                                syncRunning = false;
                                $('#cwpk-cdn-sync-stop').hide();
                                $('#cwpk-cdn-sync-status').text('<?php esc_html_e('Sync complete!', 'cwpk'); ?>');
                                if (data.remaining === 0) {
                                    $('#cwpk-cdn-sync-start').replaceWith('<span class="cwpk-cdn-status-ok"><?php esc_html_e('All media is synced!', 'cwpk'); ?></span>');
                                } else {
                                    $('#cwpk-cdn-sync-start').show();
                                }
                            }
                        } else {
                            syncRunning = false;
                            $('#cwpk-cdn-sync-stop').hide();
                            $('#cwpk-cdn-sync-start').show();
                            $('#cwpk-cdn-sync-status').html('<span class="cwpk-cdn-status-error">' + response.data + '</span>');
                        }
                    }).fail(function() {
                        syncRunning = false;
                        $('#cwpk-cdn-sync-stop').hide();
                        $('#cwpk-cdn-sync-start').show();
                        $('#cwpk-cdn-sync-status').html('<span class="cwpk-cdn-status-error"><?php esc_html_e('Request failed', 'cwpk'); ?></span>');
                    });
                }
            });
            </script>
        </div>
        <?php
    }
}
