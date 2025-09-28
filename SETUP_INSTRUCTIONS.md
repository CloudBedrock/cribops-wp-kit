# CribOps WP Kit - Setup Instructions

## Step-by-Step Setup Guide

### 1. API Setup (Elixir/Phoenix)

#### A. Start your Phoenix server:
```bash
cd ~/dev/cribops-public
source .env
mix phx.server
```

#### B. Re-run the seed data to include the license key:
```bash
cd ~/dev/cribops-public
source .env
mix run priv/repo/seeds_wp_kit.exs
```

#### C. Set the default license key (optional):
Add this to your `.env` file:
```bash
export WP_KIT_DEFAULT_LICENSE_KEY="YOUR-MASTER-LICENSE-KEY"
```

For testing, you can use: `CWPK-MASTER-LICENSE-KEY-2024`

### 2. WordPress Plugin Installation

#### A. Create the plugin ZIP file:
```bash
cd /Users/jhankins/dev/cribops-wp-kit

# Create a clean ZIP without git files and other dev files
zip -r ../cribops-wp-kit.zip . \
  -x "*.git*" \
  -x "*.DS_Store" \
  -x "node_modules/*" \
  -x "*.sh" \
  -x "test_*.sh" \
  -x "*.md" \
  -x "*.lock" \
  -x "*.log"

# The ZIP will be created at: /Users/jhankins/dev/cribops-wp-kit.zip
```

#### B. Install in WordPress:

1. **Upload the Plugin:**
   - Go to WordPress Admin → Plugins → Add New → Upload Plugin
   - Choose `cribops-wp-kit.zip`
   - Click "Install Now" then "Activate"

2. **Configure the Plugin:**
   Add to your `wp-config.php` (above `/* That's all, stop editing! */`):
   ```php
   // CribOps WP Kit Configuration
   define('WP_ENVIRONMENT_TYPE', 'development');
   define('CWPK_API_URL', 'https://cribops.cloudbedrock.com');
   ```

### 3. Initial Testing

#### A. Test Authentication:
1. Go to WordPress Admin → LaunchKit (or look for the API icon in menu)
2. You should see a login form in the header
3. Login with:
   - Email: `test@cribops.com`
   - Password: `password123`

#### B. Check API Response:
After logging in, check the browser's Developer Console Network tab. You should see:
- Request to: `https://cribops.cloudbedrock.com/wp-json/wplaunchify/v1/user-meta`
- Response includes: `default_key: "CWPK-MASTER-LICENSE-KEY-2024"`

#### C. Test License Injection:
1. Go to the "License Manager" tab in LaunchKit
2. You should see options to toggle between agency licenses and custom licenses
3. Check if the `default_key` is being applied to plugins

### 4. Plugin Installation Testing

#### A. Test with Free Plugins:
1. Go to the "Installer" tab
2. You should see a list of available plugins
3. Try installing a free plugin like Elementor (we set it up with WordPress.org URL)

#### B. For Premium Plugins:
You'll need to:
1. Upload actual plugin ZIP files to an S3 bucket OR
2. Use a temporary local file server for testing

### 5. Troubleshooting

#### Common Issues:

**"Connection Error" on login:**
- Check if Phoenix server is running
- Verify Cloudflare tunnel is working: `curl https://cribops.cloudbedrock.com/api/health`
- Check CORS headers in Phoenix

**"Invalid credentials":**
- Re-run the seed script
- Check the database: `mix run -e "CribopsPublic.WpKit.list_users() |> IO.inspect"`

**License key not appearing:**
- Check browser console for JavaScript errors
- Verify the API response includes `default_key`
- Check WordPress transients:
  ```php
  wp transient get lk_user_data
  ```

### 6. Setting Up S3 (For Production)

#### A. Create S3 Bucket:
```bash
aws s3 mb s3://cribops-wp-kit --region us-east-1
```

#### B. Upload Plugin Files:
```bash
# Example for uploading a plugin
aws s3 cp elementor-pro.zip s3://cribops-wp-kit/plugins/elementor-pro-3.18.0.zip
```

#### C. Update Plugin Records:
```elixir
# In iex -S mix
plugin = CribopsPublic.Repo.get_by(CribopsPublic.WpKit.Plugin, slug: "elementor-pro")
CribopsPublic.WpKit.Plugin.changeset(plugin, %{
  s3_key: "plugins/elementor-pro-3.18.0.zip",
  license_key: "YOUR-ELEMENTOR-PRO-LICENSE"
})
|> CribopsPublic.Repo.update!()
```

### 7. Testing the Complete Flow

Run the API test script:
```bash
cd ~/dev/cribops-public
./test_wp_kit_api.sh
```

Expected results:
- ✅ Authentication successful
- ✅ Update check successful
- ✅ Plugin list retrieved
- ✅ Package list retrieved

### 8. Production License Keys

For production, you'll need actual license keys for:

1. **Purchase Agency Licenses:**
   - ACF Pro (Advanced Custom Fields)
   - Elementor Pro
   - Gravity Forms
   - WP Rocket
   - Kadence Blocks Pro
   - SearchWP
   - AffiliateWP
   - FluentCRM/Community Pro

2. **Store in Environment:**
   ```bash
   export WP_KIT_DEFAULT_LICENSE_KEY="YOUR-ACTUAL-MASTER-KEY"
   ```

3. **Or Store Per Plugin:**
   Update the database with individual license keys for each premium plugin.

### 9. Monitor Access

Check access logs:
```elixir
# In iex -S mix
CribopsPublic.WpKit.AccessLog
|> CribopsPublic.Repo.all()
|> Enum.map(fn log ->
  %{action: log.action, success: log.success, inserted_at: log.inserted_at}
end)
```

### Quick Test Checklist

- [ ] Phoenix server running
- [ ] Cloudflare tunnel active
- [ ] Plugin installed in WordPress
- [ ] wp-config.php configured
- [ ] Can login with test credentials
- [ ] API returns `default_key`
- [ ] License Manager tab shows up
- [ ] Installer tab shows available plugins

## Need Help?

1. Check logs: `tail -f ~/dev/cribops-public/log/dev.log`
2. Test API directly: `curl https://cribops.cloudbedrock.com/api/health`
3. Review `API_REQUIREMENTS.md` for endpoint details