<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * CWPKDevTools Class
 *
 * Handles development tools like MailPit, ngrok, and SSL certificates
 *
 * @since 1.5.0
 */
class CWPKDevTools {

    /**
     * Constructor
     *
     * @since 1.5.0
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_devtools_menu'));
        add_action('wp_ajax_cwpk_get_ngrok_url', array($this, 'get_ngrok_url'));
        add_action('wp_ajax_cwpk_configure_smtp', array($this, 'configure_smtp'));
    }

    /**
     * Add Development Tools submenu
     *
     * @since 1.5.0
     */
    public function add_devtools_menu() {
        $parent_slug = 'cwpk';
        $page_slug = 'cwpk-devtools';
        $capability = 'manage_options';

        add_submenu_page(
            $parent_slug,
            __('Development Tools', 'cwpk'),
            __('Development Tools', 'cwpk'),
            $capability,
            $page_slug,
            array($this, 'render_admin_page')
        );
    }

    /**
     * Get current ngrok URL from API
     *
     * @since 1.5.0
     */
    public function get_ngrok_url() {
        check_ajax_referer('cwpk_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Try to get ngrok URL from ngrok API (assumes ngrok container is running)
        $ngrok_api = 'http://localhost:4040/api/tunnels';
        $response = wp_remote_get($ngrok_api);

        if (is_wp_error($response)) {
            wp_send_json_error('ngrok is not running or not accessible');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['tunnels']) && !empty($data['tunnels'])) {
            $tunnel = $data['tunnels'][0];
            wp_send_json_success(array(
                'url' => $tunnel['public_url'],
                'proto' => $tunnel['proto'],
            ));
        }

        wp_send_json_error('No active ngrok tunnels found');
    }

    /**
     * Configure WordPress SMTP to use MailPit
     *
     * @since 1.5.0
     */
    public function configure_smtp() {
        check_ajax_referer('cwpk_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check if MailPit constants are defined
        if (!defined('MAILPIT_SMTP_HOST')) {
            wp_send_json_error('MailPit is not configured in wp-config.php');
        }

        // Return MailPit configuration
        wp_send_json_success(array(
            'smtp_host' => MAILPIT_SMTP_HOST,
            'smtp_port' => MAILPIT_SMTP_PORT,
            'web_ui' => defined('MAILPIT_WEB_UI') ? MAILPIT_WEB_UI : 'http://localhost:8025',
        ));
    }

    /**
     * Check if MailPit is available
     *
     * @since 1.5.0
     * @return bool
     */
    public static function is_mailpit_available() {
        if (!defined('MAILPIT_SMTP_HOST')) {
            return false;
        }

        // Try to connect to MailPit web UI
        $web_ui = defined('MAILPIT_WEB_UI') ? MAILPIT_WEB_UI : 'http://localhost:8025';
        $response = wp_remote_get($web_ui, array('timeout' => 2));

        return !is_wp_error($response);
    }

    /**
     * Check if ngrok is available
     *
     * @since 1.5.0
     * @return bool
     */
    public static function is_ngrok_available() {
        $ngrok_api = 'http://localhost:4040/api/tunnels';
        $response = wp_remote_get($ngrok_api, array('timeout' => 2));

        return !is_wp_error($response);
    }

    /**
     * Render Development Tools admin page
     *
     * @since 1.5.0
     */
    public static function render_admin_page() {
        $mailpit_available = self::is_mailpit_available();
        $ngrok_available = self::is_ngrok_available();
        ?>
        <div class="wrap">
            <h1>Development Tools</h1>

            <!-- MailPit Section -->
            <div class="card">
                <h2>MailPit - Email Testing</h2>
                <?php if ($mailpit_available): ?>
                    <p>MailPit is running and ready to capture emails.</p>
                    <p>
                        <strong>SMTP Host:</strong> <?php echo esc_html(defined('MAILPIT_SMTP_HOST') ? MAILPIT_SMTP_HOST : 'mailpit'); ?><br>
                        <strong>SMTP Port:</strong> <?php echo esc_html(defined('MAILPIT_SMTP_PORT') ? MAILPIT_SMTP_PORT : '1025'); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url(defined('MAILPIT_WEB_UI') ? MAILPIT_WEB_UI : 'http://localhost:8025'); ?>"
                           class="button button-primary" target="_blank">
                            Open MailPit Web UI
                        </a>
                    </p>
                    <p class="description">All emails sent from WordPress will be captured by MailPit instead of being sent to real addresses.</p>
                <?php else: ?>
                    <p>MailPit is not running. Start it with:</p>
                    <code>docker compose --profile mailpit up -d</code>
                <?php endif; ?>
            </div>

            <!-- ngrok Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>ngrok - Secure Tunneling</h2>
                <?php if ($ngrok_available): ?>
                    <p>ngrok tunnel is active.</p>
                    <div id="ngrok-url-container">
                        <button type="button" class="button" id="get-ngrok-url">Get ngrok URL</button>
                        <div id="ngrok-url-display" style="margin-top: 10px; display: none;">
                            <strong>Public URL:</strong> <span id="ngrok-url"></span>
                            <button type="button" class="button button-small" id="copy-ngrok-url">Copy URL</button>
                        </div>
                    </div>
                    <p class="description" style="margin-top: 10px;">Share this URL to access your local WordPress site from anywhere.</p>
                <?php else: ?>
                    <p>ngrok is not running. Start it with:</p>
                    <code>docker compose --profile ngrok up -d</code>
                    <p class="description">You'll need to set NGROK_AUTHTOKEN in your .env file first.</p>
                <?php endif; ?>
            </div>

            <!-- SMTP Plugin Recommendation -->
            <div class="card" style="margin-top: 20px;">
                <h2>SMTP Configuration</h2>
                <p>To send emails via MailPit, you can:</p>
                <ul>
                    <li><strong>Option 1:</strong> Use a plugin like <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank">WP Mail SMTP</a> and configure it with the MailPit settings above.</li>
                    <li><strong>Option 2:</strong> Use code to configure wp_mail() to use MailPit SMTP directly.</li>
                </ul>
            </div>
        </div>

        <?php if ($ngrok_available): ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#get-ngrok-url').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Loading...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cwpk_get_ngrok_url',
                        nonce: '<?php echo wp_create_nonce('cwpk_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#ngrok-url').text(response.data.url);
                            $('#ngrok-url-display').show();
                        } else {
                            alert('Error: ' + (response.data || 'Could not get ngrok URL'));
                        }
                    },
                    error: function() {
                        alert('Error connecting to ngrok API');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Refresh URL');
                    }
                });
            });

            $('#copy-ngrok-url').on('click', function() {
                var url = $('#ngrok-url').text();
                navigator.clipboard.writeText(url).then(function() {
                    alert('URL copied to clipboard!');
                });
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }
}
