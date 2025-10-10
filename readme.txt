=== CribOps WP Kit ===

Contributors: @1wdtv
Tags: ecommerce, membership, marketing, sales-funnels, checkout
Tested up to: 6.7.1
Requires PHP: 7.4
Stable tag: 1.3.2
Description: Everything you need to Launch, Grow, Market & Monetize with WordPress
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CribOps WP Kit makes it possible for anyone get up and running with a fully functional WordPress business site in just a few minutes.


== License ==

CribOps WP Kit is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
CribOps WP Kit is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with CribOps WP Kit.


== Description ==

CribOps WP Kit provides everything everything you need to Launch, Grow, Market & Monetize with WordPress.

* CribOps WP Kit is free to install and use. Additional premium features & hands-on help are [available here](https://cribops.com/pricing)

---

CribOps WP Kit makes it possible for anyone get up and running with a fully functional WordPress business site in just a few minutes. 

CribOps WP Kit provides a curated, white-label admin experience for WordPress that makes it possible to launch powerful solutions in minutes instead of hours or days.

CribOps WP Kit offers powerful features by curating the best-in-class WordPress plugins into complementary features.

These features include:

* Bookings
* Customer Support
* eCommerce
* Events & Ticketing
* Forms Management
* Directories
* Learning Management
* Membership
* Marketing Automation
* Sales Funnels
* Social Networking
* Subscriptions
* ..and more

With just one click you can be up and running with a fully functional business site based on CribOps WP Kit.

Instead of creating Frankenstein Monsters by purchasing and installing random plugins at the flea market, CribOps WP Kit provides you an optimized WordPress admin experience for instant delivery and management of a curated software stack, with expert help for your WordPress "solution" as a whole. 


## Changelog

10-10-2025  - version 1.3.2
* Feature   - auto-fill Prime Mover Pro license key from PRIME_MOVER_PLUGIN_LICENSE_KEY constant
* Feature   - add chown/chgrp after wp_mkdir_p to fix recurring permission issues
* Tweak     - improved JavaScript selectors for license field detection
* Tweak     - add MutationObserver to detect dynamically loaded license forms

10-10-2025  - version 1.3.1
* Fix       - resolved recurring directory permission issues in manifest installer
* Fix       - added chmod after wp_mkdir_p to ensure proper web server permissions
* Fix       - added file removal before move and copy fallback for cross-filesystem operations

04-15-2025  - version 2.13.2
* Feature   - added self hosted and removed github updater so php 7x works

04-09-2025  - version 2.13.1
* Tweak    - added clickwhale notices to be hidden

04-01-2025  - version 2.13.0
* Tweak     - improved license management interface and conditional application

03-17-2025  - version 2.12.1
* Tweak     - improved notifications when plugins require update and different hosts

03-16-2025  - version 2.12.0
* Feature   - added integration to WP plugin installer
* Feature   - improved login and api checkout
* Feature   - improved navigation and update experience

01-07-2025  - version 2.11.9
* Feature   - added fluent boards licensing

12-10-2024  - version 2.11.8
* Tweak     - improved fluentcommunity notices 

12-09-2024  - version 2.11.7
* Tweak     - updated fluentcommunity notices 

12-07-2024  - version 2.11.6
* Tweak     - removed css hiding fc license page


12-05-2024  - version 2.11.5
* Tweak     - fixed installer for ZipWP.com sandbox use


12-02-2024  - version 2.11.4
* Tweak     - fixed license manager scope to prevent ajax interference formiddable forms


11-30-2024  - version 2.11.3
* Tweak     - restored updater functionality

11-30-2024  - version 2.11.2
* Tweak     - further refined github updater

11-30-2024  - version 2.11.1
* Tweak     - backwards compatiblity for github updater to 7.x

11-26-2024  - version 2.11.0
* Tweak     - restored original github updater file for automatic updates from github 

11-16-2024  - version 2.10.2
* Fix       - fixed undefined array key "plugin" in GitHubUpdater.php on line 138

11-13-2024  - version 2.10.1
* Fix       - fixed updater from free plugin due to githubupdloader

11-12-2024  - version 2.10.0
* Feature   - changed plugin name to pro for wp repository version override funtion

11-09-2024  - version 2.0.9
* Feature   - added github hosted repository with automatic plugin updater

11-09-2024  - version 2.0.8
* Tweak     - improved license key management, added FluentCommunity Pro and ACFPro

11-07-2024  - version 2.0.7
* Fix       - updated free features and pro upgrader

10-27-2024  - version 2.0.6
* Tweak     - added select all to the admin features menu

10-20-2024  - version 2.0.5
* Feature   - simplified navigation and setup pages

09-07-2024  - version 2.0.4
* Feature   - added Plugin Cleanup (delete) for all inactive plugins

07-21-2024  - version 2.0.3
* Feature   - added Plugin Manager for activating and deactivating and saving custom plugin recipes

07-03-2024  - version 2.0.2
* Tweak	    - added Profile Builder Free and Pro, Simple History

07-03-2024  - version 2.0.1
* Fix	    - removed BuddyBoss, BuddyPress, Youzify compatibility functions for conflict with Kadence (to be restored)

07-02-2024  - version 2.0.0
* Feature   - completely revamped interface and features

10-13-2022  - version 1.0.8
* Tweak  	- css to hide sales banners in wc my account dash wbcom

08-05-2022  - version 1.0.7
* Tweak  	- updated highlighting of plugin description

08-05-2022  - version 1.0.6 
* Tweak  	- updated styles and descriptions

07-31-2022  - version 1.0.5
* Tweak     - updated admin style and version number tracking

07-26-2022  - version 1.0.4
* Tweak     - updated admin css to include plugin page style options

07-24-2022  - version 1.0.3
* Tweak     - updated launchkit.php to correct callback names for admin sections

07-18-2022  - version 1.0.2
* Feature   - added option to change admin email without confirmation email
* Feature   - added option to remove wplk1 tag and redirect to /cancel-wplk1 page if wplk1 subscription cancelled
* Tweak     - adjusted css for one column checkout

07-10-2022  - version 1.0.1
* Feature   - added custom thank you redirection for three pages with matching product categories (wplk1, wplk3, wplk3)
* Tweak     - added custom css for Blocky theme for one column wc checkout

07-04-2022 	- version 1.0.1
* First Release!