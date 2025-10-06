# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

CribOps WordPress Kit (formerly LaunchKit Pro v2.13.2) is a WordPress plugin designed for agency site management and deployment. It provides bulk plugin installation, license management, and rapid site deployment using Prime Mover templates.

**Attribution:** This plugin is based on LaunchKit Pro by WPLaunchify, licensed under GPL v2. See AUTHORS.md for full attribution.

## Architecture

### Core Components

The plugin follows WordPress plugin architecture with tabbed admin interface:

1. **Main Entry**: `launchkit.php` - Initializes plugin and loads admin UI with tabs (Installer, License Manager, Settings, Other Tools)

2. **Key Classes** in `/includes/`:
   - `WPLKInstaller`: Handles bulk plugin installations from WPLaunchify repository
   - `WPLKManager`: Plugin recipe management and bulk operations
   - `WPLKLicenseKeyAutoloader`: Auto-loads license keys for premium plugins
   - `WPLKUpdater`: Self-hosted plugin update system
   - `WPLKDeleter`: Bulk plugin deletion utilities

3. **Admin Features**: Modifies WordPress admin behavior (hide notices, disable wizards, whitelabeling)

### External Dependencies

Currently integrated with WPLaunchify.com API for:
- User authentication (`/wp-json/wplaunchify/v1/user-meta`)
- Plugin bundle downloads
- License validation
- Update server (`/wp-content/uploads/software-bundle`)

**Important**: Per README.md, these are being migrated to self-hosted infrastructure.

## Development Commands

This plugin currently lacks modern development tooling. Standard WordPress plugin development practices apply:

```bash
# No build process - direct PHP file editing
# No test suite currently implemented
# CSS files are edited directly in /assets/css/
```

## Key Implementation Notes

### Plugin Activation Flow
1. User authentication via WPLaunchify API (being replaced)
2. Plugin list retrieved from repository
3. Bulk installation through WordPress Plugin API
4. License keys auto-populated for supported plugins

### Supported Premium Plugins for License Management
- FluentCommunity Pro
- AffiliateWP
- SearchWP
- WP All Import/Export Pro
- Gravity Forms
- Advanced Custom Fields Pro
- WP Rocket
- Elementor Pro
- Others (check `class-wplk-license-loader.php` for full list)

### WordPress Options Used
- `wplk_settings`: Main settings array
- `launchkit_hide_notices`: Admin notice visibility
- Transients: `lk_logged_in`, `lk_user_data` for authentication

## Migration Goals (from README)

1. Replace WPLaunchify API with self-hosted repository
2. Implement custom Prime Mover Pro integration
3. Remove external authentication dependencies
4. Add agency-specific access control
5. Host .wprime packages on agency infrastructure

## WordPress Coding Standards

- Use WordPress naming conventions (e.g., `wplk_` prefix for functions)
- Follow WordPress Plugin API patterns
- Use nonces for security in admin forms
- Sanitize and escape all user inputs
- Use WordPress options API for settings storage

## CRITICAL RULES - DO NOT BREAK

### ⚠️ NEVER MODIFY WORKING API/MANIFEST CODE

**The following classes are WORKING and handle API communication with the production repository:**

1. **`CWPK_Manifest_Installer`** (`includes/class-cwpk-manifest-installer.php`)
   - Fetches plugins from production API: `https://cribops.com/api/wp-kit/plugins`
   - Uses bearer token from `lk_user_data` transient
   - Already handles installation status, downloads, validation
   - **DO NOT CHANGE HOW THIS FETCHES OR FORMATS DATA**

2. **`CWPK_Theme_Manager`** (`includes/class-cwpk-theme-manager.php`)
   - Fetches themes from production API
   - **DO NOT CHANGE HOW THIS FETCHES OR FORMATS DATA**

3. **API Endpoint Structure:**
   - Plugins: `{api_url}/api/wp-kit/plugins` (NOT `/v1/plugins`)
   - Themes: `{api_url}/api/wp-kit/themes` (NOT `/v1/themes`)
   - Packages: Stored in `lk_user_data['packages']` transient
   - **DO NOT ADD `/v1/` TO THESE PATHS**

### MainWP Integration Architecture

**Correct Pattern:**
```
MainWP Dashboard → Instructs → Child Site → Uses existing WORKING classes → Production API
                                         ↓
                                Child returns data
                                         ↓
                            MainWP displays results
```

**When adding MainWP integration:**
- REUSE the existing manifest classes (CWPK_Manifest_Installer, CWPK_Theme_Manager)
- DO NOT create new API calls
- DO NOT change API endpoint paths
- DO NOT modify how credentials are used
- Child site already has working code - just call it and return results