# CribOps WP Kit

A comprehensive WordPress site management and deployment toolkit for agencies. Orignally Forked from LaunchKit Pro v2.13.2. Provides support for plugins and themes, significant changes to files handling to only download selected files and provide more meta details of files from our curated plugins.

Supports secure, scalable, AWS S3 backed storage of central repository.

## Overview

CribOps WP Kit is a WordPress plugin that provides:
- 🚀 Bulk plugin installation and management
- 📦 Prime Mover Pro integration for site templates
- 🔑 Automatic license key management for premium plugins
- 🎛️ Admin dashboard cleanup and customization
- 🔄 Self-hosted plugin repository with API backend
- ☁️ AWS S3 integration

## Attribution

Based on LaunchKit Pro v2.13.2 by WPLaunchify (https://wplaunchify.com)
Original GitHub repository: https://github.com/wplaunchify/launchkit-pro
Original plugin licensed under GPL v2 - See LICENSE.txt and AUTHORS.md for full attribution.

## Key Architectural Changes from LaunchKit Pro

### Repository & Distribution Model Changes

**Original LaunchKit Pro:**
- Downloaded entire plugin ZIP files from WPLaunchify servers
- Static file bundles stored on traditional web servers
- Fixed plugin lists with predefined recipes
- Direct file downloads without granular tracking
- Monolithic plugin packages

**CribOps WP Kit:**
- **API-driven architecture** - All plugin/theme data served via RESTful API
- **Selective file downloading** - Only downloads files that are actually selected/needed
- **Dynamic metadata processing** - Each file's metadata (version, size, hash, dependencies) automatically processed in backend
- **Theme management addition** - Extended beyond plugins to include WordPress themes
- **Dynamic catalog loading** - Plugins, themes, and packages loaded from API in real-time
- **Granular file tracking** - Individual file access logging and metrics
- **AWS S3 integration** - Scalable cloud storage for all repository files
- **Per-file metadata** - Version control, checksums, and dependency tracking per file

### Technical Implementation Differences

**Data Flow:**
- LaunchKit Pro: WordPress → Direct file server → Download ZIP
- CribOps WP Kit: WordPress → API → Database → S3 → Selective download

**Authentication:**
- LaunchKit Pro: WPLaunchify account authentication only
- CribOps WP Kit: Flexible authentication with user permissions and site registration

**Update Mechanism:**
- LaunchKit Pro: Check for updates against static version files
- CribOps WP Kit: API-based version checking with real-time catalog updates

**Storage:**
- LaunchKit Pro: Traditional web server file storage
- CribOps WP Kit: AWS S3 with CDN support and signed URLs

## System Architecture

### WordPress Plugin
- Centralized configuration management
- Environment-aware API endpoints
- Legacy WPLaunchify compatibility layer
- Dynamic plugin/theme catalog loading
- Selective file installation

### Private Elixir/Phoenix API Backend
- RESTful API for plugin/theme/package distribution
- User authentication and authorization
- AWS S3 integration for file storage
- Comprehensive access logging
- Automatic metadata extraction and processing
- Real-time catalog updates
- Per-file version control and checksums

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


// Optional: CDN URL for assets
define('CWPK_CDN_URL', 'https://cdn.example.com');

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
AWS_S3_BUCKET=your_bucket
AWS_REGION=us-east-1

# Optional
CDN_URL=https://cdn.example.com



## License

GPL v2 or later - See LICENSE.txt for full license text.

## Changelog

### Version 1.0.53 (2025-10-05)
- Fixed plugin installation status detection on fresh WordPress installs
- Clear WordPress plugin cache to ensure accurate status display
- Improved plugin slug matching for various directory structures
- Added search/filter functionality to plugin installer screen
- Added search/filter functionality to theme installer screen
- Added search/filter functionality to packages screen
- Real-time filtering with result counts and no-results handling

### Version 1.0.52 (2025-10-05)
- Added proper GPL attribution and architectural documentation
- Updated README with comprehensive architectural overview
- Added AUTHORS.md file with complete attribution history
- Enhanced code documentation and licensing information

### Version 1.0.51 (2025-10-05)
- Fixed MainWP Child button to properly show installed state

### Version 1.0.50 (2025-10-05)
- Replaced Kadence theme installation with MainWP Child plugin

### Version 1.0.49 (2025-10-04)
- Fixed Update Software Bundle to re-download plugin ZIPs

### Version 1.0.48 (2025-10-04)
- Excluded CloudBedrock plugin notifications from hide notices feature

### Version 1.0.47 (2025-10-04)
- Added WordPress theme management functionality

### Version 1.0.45 (2025-09-30)
- Enhanced virtual plugin validation and error clearing

### Version 1.0.44 (2025-09-30)
- Fixed virtual plugin file validation error

### Version 1.0.43 (2025-09-30)
- Fixed validation bypass in API direct response path

### Version 1.0.42 (2025-09-30)
- Enhanced error logging and validation for unzip failures

### Version 1.0.41 (2025-09-30)
- Added detailed logging for download debugging

### Version 1.0.40 (2025-09-30)
- Added ZIP file validation to prevent JSON errors being saved as plugins

### Version 1.0.39 (2025-09-29)
- Show 'No packages currently available' when API returns empty

### Version 1.0.38 (2025-09-29)
- Added debugging for package image display

### Version 1.0.37 (2025-09-29)
- Fixed package image display with fallbacks

### Version 1.0.36 (2025-09-29)
- Updated package display to use thumbnail_url attribute

### Version 1.0.35 (2025-09-29)
- Updated package installer page slug and made images dynamic

### Version 1.0.34 (2025-09-29)
- Updated Prime Mover integration branding

### Version 1.0.17 (2025-09-28)
- Added virtual plugin display for dependency bypass feature
- Shows "Re-enable Dependent Plugin Deactivate & Delete" in plugins list when enabled
- Virtual plugin appears as active when checkbox is enabled in settings

### Version 1.0.16 (2025-09-28)
- Final fix for duplicate update notifications
- Ensure current version is properly set in checked list
- Double-check to prevent same-version updates
- Properly clean up stale update data

### Version 1.0.15 (2025-09-28)
- Fixed persistent update notification issue
- Implemented singleton pattern for GitHub updater
- Clear old update info before checking for new updates
- Prevent duplicate update notifications when already on latest version

### Version 1.0.14 (2025-09-28)
- Final test release to verify complete update functionality
- Added FS_METHOD direct filesystem access
- Fixed all permission issues for updates

### Version 1.0.13 (2025-09-28)
- Fixed critical version comparison bug in GitHub updater
- Removed 'v' prefix from version comparison to fix update detection

### Version 1.0.12 (2025-09-28)
- Test release to verify GitHub updater functionality
- No functional changes - testing auto-update mechanism

### Version 1.0.11 (2025-09-28)
- Fixed GitHub updater plugin path detection issue
- Corrected plugin file location resolution in updater class
- Added fallback path detection for better reliability

### Version 1.0.10 (2025-09-28)
- Improved login form UI with smaller logo (60px height)
- Better styled input fields with proper padding
- Changed placeholder from 'Username' to 'Email' for clarity
- Enhanced form element styling and spacing

### Version 1.0.9 (2025-09-28)
- Fixed plugin directory structure for proper file organization
- Improved admin CSS for better login form visibility
- Made logo smaller and properly styled login interface
- Fixed wp-config.php syntax errors

### Version 1.0.8 (2025-09-28)
- Added "Check for Updates" link on plugin actions
- Clear cache and force update check functionality
- Added success notice after checking for updates

### Version 1.0.7 (2025-09-28)
- Updated virtual plugin headers to CribOps branding
- Fixed "Re-enable Dependent Plugin" showing old author info
- Updated plugin URI to cribops.com

### Version 1.0.6 (2025-09-28)
- Updated README configuration examples
- Improved documentation clarity

### Version 1.0.5 (2025-09-28)
- Fixed broken logo path (moved logo_light.svg to correct location)
- Updated all marketing URLs from wplaunchify.com to cribops.com
- Removed remaining WPLaunchify branding references
- Added fallback for logo display errors

### Version 1.0.4 (2025-09-28)
- Added GitHub-based auto-updater for seamless plugin updates
- Fixed fatal error on plugin activation (removed non-existent cwpk() method)
- Fixed all redirect URLs from wplk to cwpk
- Updated all branding from LaunchKit to CribOps WP Kit
- Changed logo to logo_light.svg
- Updated GitHub Actions to use GH_PAT secret

### Version 1.0.0 (2025-09-28)
- Initial fork from LaunchKit Pro v2.13.2
- Complete rebrand to CribOps WP Kit
- Implemented self-hosted API backend
- Added AWS S3 integration
- Created centralized configuration system
- Added comprehensive test suite
- Removed WPLaunchify dependencies