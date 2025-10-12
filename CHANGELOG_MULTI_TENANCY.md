# Multi-Tenancy API Updates - Changelog

## Overview

This changelog documents the updates made to the CribOps WP Kit WordPress plugin to support the new multi-tenancy features in the CribOps Platform API (CRIB-368).

## Date

October 12, 2025

## Changes Made

### 1. Authentication Handler - Site Limit Error Handling

**File:** `includes/class-cwpk-auth.php`

**Location:** Line 81-85

**Changes:**
- Added handling for HTTP 403 responses with `site_limit_exceeded` error
- Returns `WP_Error` with error code `site_limit_exceeded` and API message
- Allows WordPress plugin to gracefully handle site limit restrictions

**Code Added:**
```php
// Handle site limit exceeded (403)
if ($response_code === 403 && !empty($data['error']) && $data['error'] === 'site_limit_exceeded') {
    $message = !empty($data['message']) ? $data['message'] : 'Site limit exceeded. Please upgrade your plan or deactivate a site.';
    return new WP_Error('site_limit_exceeded', $message);
}
```

**Impact:**
- Users who exceed their site limit will see a clear error message
- Plugin provides direct link to upgrade plans
- Prevents confusion when authentication fails due to site limits

### 2. Installer Page - Site Limit Error Display

**File:** `includes/class-cwpk-installer.php`

**Location:** Line 148-166

**Changes:**
- Added check for site limit errors during authentication
- Displays prominent error message when site limit is exceeded
- Provides upgrade button with direct link to pricing page
- Reordered messaging to show specific errors before generic login prompts

**Code Added:**
```php
// Check if authentication failed due to site limit
$token = CWPKAuth::get_env_bearer_token();
if ($token) {
    $result = CWPKAuth::authenticate_with_token($token);
    if (is_wp_error($result) && $result->get_error_code() === 'site_limit_exceeded') {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Site Limit Exceeded:</strong> ' . esc_html($result->get_error_message());
        echo '</p><p><a href="https://cribops.com/pricing" target="_blank" class="button button-primary">Upgrade Your Plan</a> to add more sites.</p></div>';
        return;
    }
    // ... existing error handling ...
}
```

**Impact:**
- Clear, actionable error message when users hit site limits
- Direct path to upgrade via prominent button
- Prevents users from being stuck without knowing why they can't access features

### 3. Installer Page - Site Usage Display

**File:** `includes/class-cwpk-installer.php`

**Location:** Line 196-199 (call), Line 1872-1912 (method)

**Changes:**
- Added call to display site usage after user greeting
- Created new method `display_site_usage_notice()` to show site usage stats
- Displays different notices based on usage percentage:
  - **0-79%**: Info notice with current usage
  - **80-99%**: Warning notice with upgrade suggestion
  - **100%+**: Error notice with urgent upgrade call-to-action
  - **Unlimited**: Info notice showing unlimited status

**Code Added:**
```php
// Display site usage information
if (!empty($user_data['site_usage'])) {
    $this->display_site_usage_notice($user_data['site_usage']);
}
```

**New Method:**
```php
/**
 * Display site usage notice based on current usage
 *
 * @param array $site_usage Site usage data from API
 */
private function display_site_usage_notice($site_usage) {
    // Validates structure
    // Sanitizes values
    // Shows appropriate notice based on percentage:
    //   - Unlimited: Info notice
    //   - 80%+: Warning/Error notice with upgrade button
    //   - <80%: Info notice with stats
}
```

**Impact:**
- Users always see their current site usage
- Proactive warnings before hitting limits
- Clear upgrade path when approaching or at limit
- Validates and sanitizes API data for security

### 4. Resource Filtering - Verification

**Files Verified:**
- `includes/class-cwpk-manifest-installer.php` - Uses `/api/wp-kit/plugins` endpoint
- `includes/class-cwpk-installer.php` - Uses `$user_data['packages']`
- `includes/class-cwpk-mainwp-child.php` - Uses `$user_data['packages']`

**Verification Results:**
- ✅ WordPress plugin already uses API-provided resource lists
- ✅ No client-side organization filtering being performed
- ✅ API endpoints confirmed to filter by organization:
  - `/api/wp-kit/v1/authenticate-token` returns filtered packages/plugins/themes
  - `/api/wp-kit/plugins` calls `WpKit.list_active_plugins(user.organization_id)`
  - `/api/wp-kit/themes` calls `WpKit.list_active_themes(user.organization_id)`

**Impact:**
- Resource filtering is correctly handled server-side
- No duplicate or unauthorized resources will be shown
- WordPress plugin trusts API filtering (correct architecture)

## API Response Changes Supported

### New Fields Added

**`site_usage` object:**
```json
{
  "current_sites": 5,
  "site_limit": 10,
  "unlimited": false,
  "percentage_used": 50.0
}
```

**New Error Response:**
```json
HTTP 403
{
  "error": "site_limit_exceeded",
  "message": "Site limit reached..."
}
```

### Organization-Filtered Resources

**Before:** API returned ALL resources
**After:** API returns only organization + shared resources

The WordPress plugin already handled this correctly by using the API-provided lists.

## Backward Compatibility

### Graceful Degradation

All changes are backward compatible:

1. **Missing `site_usage` field:**
   - Check: `if (!empty($user_data['site_usage']))`
   - Result: No site usage display (old behavior)

2. **Old API without 403 error:**
   - Still handles generic authentication failures
   - Falls back to existing error messages

3. **Existing installations:**
   - No breaking changes
   - New features activate automatically when API is updated

## Testing Recommendations

### Manual Testing Checklist

- [ ] **Site Limit Exceeded (403)**
  - Authenticate with token from 11th site (when limit is 10)
  - Verify error message displays with upgrade button
  - Confirm cannot access Software Bundle features

- [ ] **Site Usage Display - 0%**
  - Authenticate with 0 sites active
  - Verify no usage notice (or shows 0 of X)

- [ ] **Site Usage Display - 50%**
  - Authenticate with 5/10 sites
  - Verify info notice shows "5 of 10 sites (50%)"

- [ ] **Site Usage Display - 85%**
  - Authenticate with 8-9/10 sites
  - Verify warning notice shows with "Upgrade" button

- [ ] **Site Usage Display - 100%**
  - Authenticate with 10/10 sites (at limit)
  - Verify error notice shows with "Upgrade Your Plan" button

- [ ] **Site Usage Display - Unlimited**
  - Authenticate as super admin or unlimited plan user
  - Verify shows "unlimited sites" message

- [ ] **Resource Filtering**
  - Authenticate as organization admin
  - Verify only see organization + shared resources
  - Authenticate as different organization admin
  - Verify different resource set

- [ ] **Backward Compatibility**
  - Test against old API (before multi-tenancy)
  - Verify plugin works without site_usage field
  - Verify no errors in browser console

### Automated Testing (Future)

Consider adding automated tests for:
- Site limit error handling in auth class
- Site usage display rendering
- API response parsing and validation

## Security Considerations

### Data Validation

All user-provided data is validated and sanitized:

```php
// Validate structure
if (!is_array($site_usage) ||
    !isset($site_usage['current_sites']) ||
    !isset($site_usage['site_limit'])) {
    return; // Skip display
}

// Sanitize values
$current = intval($site_usage['current_sites']);
$limit = intval($site_usage['site_limit']);
$unlimited = !empty($site_usage['unlimited']);
$percentage = isset($site_usage['percentage_used']) ? floatval($site_usage['percentage_used']) : 0;
```

### Output Escaping

All dynamic output is escaped:

```php
echo '<strong>Site Limit Exceeded:</strong> ' . esc_html($result->get_error_message());
```

### Trust Boundaries

- WordPress plugin trusts API-filtered resource lists (correct - server is source of truth)
- API validates organization ownership before returning resources
- No client-side security decisions

## Related Documentation

- **Phoenix API:** [WP Kit WordPress Plugin Updates](../cribops-public/docs/WP_KIT_WORDPRESS_PLUGIN_UPDATES.md)
- **API Changes:** [WP Kit Multi-Tenancy Guide](../cribops-public/docs/WP_KIT_MULTI_TENANCY_GUIDE.md)
- **Implementation Status:** [WP Kit Implementation Status](../cribops-public/WP_KIT_IMPLEMENTATION_STATUS.md)

## Summary

### Files Modified

1. `includes/class-cwpk-auth.php` - Added site limit error handling (4 lines)
2. `includes/class-cwpk-installer.php` - Added site limit display and usage notices (59 lines)

### Lines Added

- **Total lines added:** 63
- **Total lines modified:** 10
- **New methods:** 1 (`display_site_usage_notice`)

### Features Added

- ✅ Site limit error handling
- ✅ Site usage display (0-100%+)
- ✅ Warning notices at 80%+ usage
- ✅ Upgrade prompts and CTAs
- ✅ Unlimited plan support
- ✅ Backward compatibility

### No Changes Required

- ✅ Resource filtering (already correct)
- ✅ API endpoint usage (already correct)
- ✅ Authentication flow (already correct)

## Next Steps

1. **Test the changes:**
   - Run through manual testing checklist
   - Verify all scenarios work as expected

2. **Deploy to staging:**
   - Test with multi-tenancy API on staging environment
   - Verify site limit enforcement works

3. **Update plugin version:**
   - Bump version number to reflect multi-tenancy support
   - Update plugin changelog

4. **Deploy to production:**
   - Coordinate with Phoenix API deployment
   - Monitor for any authentication issues

5. **User communication:**
   - Notify users about new site usage visibility
   - Explain site limit enforcement if applicable

## Questions or Issues?

Contact: support@cribops.com
