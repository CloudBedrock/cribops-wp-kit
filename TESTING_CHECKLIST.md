# CribOps WP Kit - Testing Checklist

## Pre-Installation

- [ ] **Phoenix Server Running**
  ```bash
  cd ~/dev/cribops-public && source .env && mix phx.server
  ```

- [ ] **Re-run Seed Data**
  ```bash
  cd ~/dev/cribops-public && source .env && mix run priv/repo/seeds_wp_kit.exs
  ```

- [ ] **Verify API is Accessible**
  ```bash
  curl https://cribops.cloudbedrock.com/api/health
  ```

## WordPress Installation

- [ ] **Upload Plugin**
  - File location: `/Users/jhankins/cribops-wp-kit.zip`
  - WordPress Admin → Plugins → Add New → Upload Plugin

- [ ] **Add to wp-config.php**
  ```php
  define('WP_ENVIRONMENT_TYPE', 'development');
  define('CWPK_API_URL', 'https://cribops.cloudbedrock.com');
  ```

- [ ] **Activate Plugin**

## Testing API Connection

- [ ] **Find LaunchKit Menu**
  - Look for API icon in WordPress admin menu
  - Or navigate to: `/wp-admin/admin.php?page=cwpk`

- [ ] **Login Test**
  - Email: `test@cribops.com`
  - Password: `password123`
  - Should see "Logged in as: ******" after success

- [ ] **Check Browser Console**
  - Open Developer Tools → Network tab
  - Look for request to `/wp-json/wplaunchify/v1/user-meta`
  - Response should include:
    ```json
    {
      "can_access_launchkit": true,
      "default_key": "CWPK-MASTER-LICENSE-KEY-2024",
      "first_name": "Test",
      "last_name": "User"
    }
    ```

## Testing Features

### License Manager Tab
- [ ] Navigate to License Manager tab
- [ ] Should see toggles for:
  - ACF Pro License
  - AffiliateWP License
  - FluentBoards Pro License
  - FluentCommunity Pro License
  - Kadence Pro License
  - SearchWP License
- [ ] Test "Reset All to Default" button
- [ ] Test "Save Changes" button

### Installer Tab
- [ ] Should see "Software Bundle Plugin Installer"
- [ ] If logged in, should see welcome message with first name
- [ ] Check for plugin list (may be empty initially)

### Settings Tab
- [ ] Check all settings checkboxes work:
  - Hide LaunchKit from Admin Menu
  - Disable All Plugin Activation Wizards
  - Hide All Admin Notices
  - Disable LearnDash License Management
  - Disable WordPress Plugin Manager Dependencies
  - Disable WordPress Sending Update Emails

### Other Tools Tab
- [ ] Should see links to:
  - Deleter
  - Recipe Manager
  - Account (if logged in)
  - License (if logged in)
  - Packages (if logged in)

## Verify License Injection

For testing the license injection, if you have any of these plugins installed:

- [ ] **ACF Pro**: Check if license is auto-filled
- [ ] **Elementor Pro**: Check if license is auto-filled
- [ ] **SearchWP**: Check Settings → License
- [ ] **AffiliateWP**: Check Settings → License

## API Endpoints Test

Run the test script:
```bash
cd ~/dev/cribops-public
./test_wp_kit_api.sh
```

Expected results:
- [ ] Authentication successful (both endpoints)
- [ ] Update check returns version info
- [ ] Plugin list accessible
- [ ] Package list accessible

## Troubleshooting

If login fails:
1. Check Phoenix console for errors
2. Verify Cloudflare tunnel is active
3. Check WordPress debug.log: `tail -f wp-content/debug.log`

If license key not appearing:
1. Check transient data:
   ```php
   wp transient get lk_user_data
   ```
2. Check PHP error log
3. Verify API response in browser network tab

## Notes

**What's Working:**
- API authentication endpoint
- License key distribution via `default_key`
- Plugin management UI
- Settings management

**What Needs Real Data:**
- Actual plugin ZIP files (need S3 upload)
- Real license keys for premium plugins
- Prime Mover packages (.wprime files)

**Next Steps After Testing:**
1. Purchase agency licenses for premium plugins
2. Set up S3 bucket and upload plugin ZIPs
3. Update database with real S3 URLs
4. Test actual plugin installations