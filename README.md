# CribOps WP Kit

A comprehensive WordPress site management and deployment toolkit for agencies. Fork of LaunchKit Pro v2.13.2, completely rebuilt with self-hosted infrastructure.

## Overview

CribOps WP Kit is a WordPress plugin that provides:
- 🚀 Bulk plugin installation and management
- 📦 Prime Mover Pro integration for site templates
- 🔑 Automatic license key management for premium plugins
- 🎛️ Admin dashboard cleanup and customization
- 🔄 Self-hosted plugin repository with API backend
- ☁️ AWS S3/CloudFront CDN integration

## Attribution

Based on LaunchKit Pro v2.13.2 by WPLaunchify (https://wplaunchify.com)
Original plugin licensed under GPL v2 - See LICENSE.txt and AUTHORS.md for full attribution.

## System Architecture

### WordPress Plugin
- Centralized configuration management
- Environment-aware API endpoints
- Legacy WPLaunchify compatibility layer

### Elixir/Phoenix API Backend
- RESTful API for plugin/package distribution
- User authentication and authorization
- AWS S3 integration for file storage
- Comprehensive access logging

## Directory Structure

```
cribops-wp-kit/
├── assets/                      # Static assets
│   ├── css/
│   │   ├── cwpk-admin.css     # Admin panel styles
│   │   ├── cwpk-public.css    # Public styles
│   │   └── cwpk-wp-admin.css  # WP admin customizations
│   └── images/                 # Plugin images and icons
├── includes/                   # Core PHP classes
│   ├── class-cwpk-config.php         # ✨ Centralized API configuration
│   ├── class-cwpk-deleter.php        # Bulk plugin deletion
│   ├── class-cwpk-experimental.php   # Experimental features
│   ├── class-cwpk-functions.php      # Utility functions
│   ├── class-cwpk-installer.php      # Plugin installation manager
│   ├── class-cwpk-license-loader.php # License key automation
│   ├── class-cwpk-manager.php        # Plugin recipe manager
│   ├── class-cwpk-pluginmanager.php  # Plugin management core
│   └── class-cwpk-updater.php        # Self-hosted update system
├── cribops-wp-kit.php          # Main plugin file
├── wp-config-snippet.php       # Configuration template
├── API_REQUIREMENTS.md         # API documentation
├── AUTHORS.md                  # Attribution
├── IMPLEMENTATION_SUMMARY.md   # Technical overview
└── readme.txt                  # WordPress.org readme
```

## Configuration

### Environment Setup

Add to your `wp-config.php`:

```php
// Set environment type (auto-configures API URL)
define('WP_ENVIRONMENT_TYPE', 'development'); // or 'staging', 'production'

// Or manually set API URL
define('CWPK_API_URL', 'https://example.com'); // Development
// define('CWPK_API_URL', 'https://cribops.com'); // Production

// Optional: CDN URL for assets
define('CWPK_CDN_URL', 'https://cdn.cribops.com');

// Optional: API timeout (default: 30 seconds)
define('CWPK_API_TIMEOUT', 60);
```

## Features

### ✅ Implemented
- Centralized API configuration system
- WordPress Kit user authentication
- Plugin bundle management
- Prime Mover package distribution
- License key automation for 15+ premium plugins
- Legacy WPLaunchify API compatibility
- AWS S3 file storage integration
- Comprehensive access logging
- Environment-aware configuration

### 🔧 Plugin Management
- Bulk plugin installation
- Recipe-based plugin deployment
- Automatic license key injection
- Plugin update management
- Inactive plugin cleanup

### 🎨 Admin Customization
- Hide admin notices with toggle
- Disable plugin activation wizards
- Remove plugin dependencies (pre-WP 6.5 behavior)
- Whitelabel mode for client sites
- Disable WordPress update emails

### 📦 Supported Premium Plugins
- Elementor Pro
- WP Rocket
- Gravity Forms
- Advanced Custom Fields Pro
- Prime Mover Pro
- FluentCRM Pro
- AffiliateWP
- SearchWP
- WP All Import/Export Pro
- Kadence Blocks Pro
- LearnDash
- WooCommerce extensions
- And more...

## API Backend Setup

### Database Tables
- `wp_kit_users` - WordPress site administrators
- `wp_kit_sites` - Registered WordPress sites
- `wp_kit_plugins` - Available plugin catalog
- `wp_kit_packages` - Prime Mover packages
- `wp_kit_access_logs` - API access tracking
- `wp_kit_user_permissions` - Granular access control

### Required Environment Variables
```bash
# AWS Configuration
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_S3_BUCKET=cribops-wp-kit
AWS_REGION=us-east-1

# Optional
CDN_URL=https://cdn.cribops.com
```

## Testing

### Run API Tests
```bash
cd ~/dev/cribops-public
./test_wp_kit_api.sh
```

### Test Credentials
- Email: `test@cribops.com`
- Password: `password123`

### Seed Database
```bash
cd ~/dev/cribops-public
source .env
mix run priv/repo/seeds_wp_kit.exs
```

## Development

### Local Development Setup

1. **WordPress Plugin**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/your-org/cribops-wp-kit.git
   ```

2. **Elixir API Backend**
   ```bash
   cd ~/dev/cribops-public
   source .env
   mix ecto.migrate
   mix run priv/repo/seeds_wp_kit.exs
   mix phx.server
   ```

3. **Configure WordPress**
   - Add configuration to `wp-config.php`
   - Activate the plugin
   - Login with test credentials

### API Testing
The included `test_wp_kit_api.sh` script tests all endpoints:
- Authentication (legacy and new)
- Update checking
- Plugin listing and downloads
- Package management
- Bundle downloads

## Deployment

### Production Checklist
- [ ] Update `CWPK_API_URL` to production URL
- [ ] Upload plugin ZIP files to S3 bucket
- [ ] Upload Prime Mover packages to S3
- [ ] Configure CloudFront CDN (optional)
- [ ] Set up SSL certificates
- [ ] Configure Oban Pro for background jobs
- [ ] Add real license keys for premium plugins
- [ ] Set up monitoring and alerting

## Security

- Passwords hashed with bcrypt
- API authentication via Bearer tokens
- Presigned S3 URLs with expiration
- Comprehensive access logging
- Per-user and per-organization access control
- No direct S3 bucket exposure

## Migration from LaunchKit/WPLaunchify

The plugin maintains backward compatibility with legacy endpoints:
- `/wp-json/wplaunchify/v1/user-meta` → `/api/wp-kit/v1/user-meta`
- `/wp-content/uploads/software-bundle/` → S3/CDN distribution

## Support

For issues or questions:
- Check `IMPLEMENTATION_SUMMARY.md` for technical details
- Review `API_REQUIREMENTS.md` for API documentation
- Run `test_wp_kit_api.sh` for diagnostics

## License

GPL v2 or later - See LICENSE.txt for full license text.

## Changelog

### Version 1.0.12 (2024)
- Test release to verify GitHub updater functionality
- No functional changes - testing auto-update mechanism

### Version 1.0.11 (2024)
- Fixed GitHub updater plugin path detection issue
- Corrected plugin file location resolution in updater class
- Added fallback path detection for better reliability

### Version 1.0.10 (2024)
- Improved login form UI with smaller logo (60px height)
- Better styled input fields with proper padding
- Changed placeholder from 'Username' to 'Email' for clarity
- Enhanced form element styling and spacing

### Version 1.0.9 (2024)
- Fixed plugin directory structure for proper file organization
- Improved admin CSS for better login form visibility
- Made logo smaller and properly styled login interface
- Fixed wp-config.php syntax errors

### Version 1.0.8 (2024)
- Added "Check for Updates" link on plugin actions
- Clear cache and force update check functionality
- Added success notice after checking for updates

### Version 1.0.7 (2024)
- Updated virtual plugin headers to CribOps branding
- Fixed "Re-enable Dependent Plugin" showing old author info
- Updated plugin URI to cribops.com

### Version 1.0.6 (2024)
- Updated README configuration examples
- Improved documentation clarity

### Version 1.0.5 (2024)
- Fixed broken logo path (moved logo_light.svg to correct location)
- Updated all marketing URLs from wplaunchify.com to cribops.com
- Removed remaining WPLaunchify branding references
- Added fallback for logo display errors

### Version 1.0.4 (2024)
- Added GitHub-based auto-updater for seamless plugin updates
- Fixed fatal error on plugin activation (removed non-existent cwpk() method)
- Fixed all redirect URLs from wplk to cwpk
- Updated all branding from LaunchKit to CribOps WP Kit
- Changed logo to logo_light.svg
- Updated GitHub Actions to use GH_PAT secret

### Version 1.0.0 (2024)
- Initial fork from LaunchKit Pro v2.13.2
- Complete rebrand to CribOps WP Kit
- Implemented self-hosted API backend
- Added AWS S3 integration
- Created centralized configuration system
- Added comprehensive test suite
- Removed WPLaunchify dependencies