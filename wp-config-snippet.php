<?php
/**
 * CribOps WP Kit Configuration
 *
 * Add these lines to your wp-config.php file to configure
 * the CribOps WP Kit plugin for different environments.
 */

// ============================================
// Environment Configuration
// ============================================

/**
 * Set the environment type (WordPress 5.5+)
 * Options: 'local', 'development', 'staging', 'production'
 */
define('WP_ENVIRONMENT_TYPE', 'development');

/**
 * Override the API URL for specific environments
 * Uncomment and modify as needed:
 */

// For local development with Cloudflare tunnel:
// define('CWPK_API_URL', 'https://cribops.cloudbedrock.com');

// For staging:
// define('CWPK_API_URL', 'https://staging.cribops.com');

// For production:
// define('CWPK_API_URL', 'https://cribops.com');

/**
 * Optional: Set a different CDN URL for assets
 * (if different from API URL)
 */
// define('CWPK_CDN_URL', 'https://cdn.cribops.com');

/**
 * Optional: Adjust API timeout (default: 30 seconds)
 */
// define('CWPK_API_TIMEOUT', 60);

/**
 * Development/Debug settings
 */
// define('WP_DEBUG', true);
// define('WP_DEBUG_LOG', true);
// define('WP_DEBUG_DISPLAY', false);