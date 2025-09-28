# CribOps Kit

A fork of LaunchKit Pro v2.13.2, modified for agency use with self-hosted plugin repository.

## Overview

CribOps Kit is a WordPress plugin that provides:
- Centralized plugin management from agency-licensed software repository
- Prime Mover Pro integration for site templates
- Admin notification management
- Dashboard cleanup utilities

## Original Source

Forked from LaunchKit Pro (GPL v2 licensed) - https://wplaunchify.com

## Key Modifications Planned

1. **Repository Management**
   - Replace WPLaunchify API with self-hosted repository
   - Implement agency license key management
   - Custom plugin bundle configuration

2. **Prime Mover Integration**
   - Use Prime Mover Pro for creating custom site templates
   - Host .wprime packages on agency servers
   - Remove dependency on WPLaunchify packages

3. **Authentication**
   - Replace or remove WPLaunchify authentication
   - Implement agency-specific access control

## Directory Structure

```
cribops-kit/
├── assets/           # CSS, JS, images
├── includes/         # PHP class files
│   ├── class-wplk-deleter.php
│   ├── class-wplk-experimental.php
│   ├── class-wplk-functions-launchkit.php
│   ├── class-wplk-installer.php
│   ├── class-wplk-license-loader.php
│   ├── class-wplk-manager.php
│   ├── class-wplk-pluginmanager.php
│   └── class-wplk-updater.php
├── launchkit.php     # Main plugin file (to be renamed)
└── readme.txt        # WordPress plugin readme
```

## Features Retained

- Admin notice hiding system
- Plugin activation wizard bypass
- Dashboard cleanup options
- Bulk plugin installation interface

## License

GPL v2 or later (inherited from original LaunchKit Pro)

## Development Notes

- Original uses 12-hour cache for plugin bundles
- Downloads ~300MB bundle containing 115+ premium plugins
- Prime Mover packages stored in `/wp-content/uploads/prime-mover-export-files/1/`
- Plugin cache stored in `/wp-content/uploads/launchkit-updates/`

## Next Steps

1. Rename plugin and update headers
2. Remove WPLaunchify API dependencies
3. Implement custom repository system
4. Configure Prime Mover Pro integration
5. Update authentication mechanism
6. Test with agency plugin repository