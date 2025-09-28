# CribOps WP Kit - API Requirements

This document outlines all API endpoints needed for the CribOps WP Kit plugin to replace the WPLaunchify dependency with your self-hosted Elixir application.

## Base Configuration

Replace all instances of `https://wplaunchify.com` with your API URL (e.g., `https://api.cribops.com` or during development: `http://localhost:4000`).

## Required API Endpoints

### 1. User Authentication & Metadata
**Endpoint:** `POST /wp-json/cribops/v1/user-meta`

**Purpose:** Authenticate users and retrieve their access permissions and available resources.

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "user_password",
  "site_url": "https://client-site.com"
}
```

**Response (200 OK):**
```json
{
  "can_access_launchkit": true,
  "first_name": "John",
  "last_name": "Doe",
  "email": "user@example.com",
  "launchkit_package_url": "https://api.cribops.com/packages/launchkit-base.wprime",
  "package_one_url": "https://api.cribops.com/packages/package-one.wprime",
  "package_two_url": "https://api.cribops.com/packages/package-two.wprime",
  "package_three_url": "https://api.cribops.com/packages/package-three.wprime"
}
```

**Error Response (401 Unauthorized):**
```json
{
  "error": "Invalid credentials",
  "message": "The provided email or password is incorrect"
}
```

### 2. Plugin Update Check
**Endpoint:** `GET /wp-content/uploads/software-bundle/launchkit-updates.json`

**Purpose:** Check for plugin updates and provide version information.

**Response:**
```json
{
  "version": "1.0.1",
  "download_url": "https://api.cribops.com/downloads/cribops-wp-kit-1.0.1.zip",
  "details_url": "https://api.cribops.com/plugin/cribops-wp-kit/changelog",
  "sections": {
    "description": "CribOps WP Kit - WordPress site management toolkit",
    "changelog": "Version 1.0.1: Bug fixes and improvements"
  },
  "requires": "5.0",
  "tested": "6.7.1",
  "requires_php": "7.4"
}
```

### 3. Software Bundle Download
**Endpoint:** `GET /downloads/software-bundle.zip`

**Purpose:** Provide the main software bundle containing available plugins.

**Response:** Binary ZIP file containing WordPress plugins

**Expected ZIP Structure:**
```
software-bundle.zip
├── plugin-one.zip
├── plugin-two.zip
├── plugin-three.zip
└── metadata.json
```

### 4. Individual Plugin Downloads
**Endpoint:** `GET /downloads/plugins/{plugin-slug}.zip`

**Purpose:** Download individual WordPress plugins.

**Response:** Binary ZIP file of the requested plugin

### 5. Prime Mover Package Downloads
**Endpoint:** `GET /packages/{package-name}.wprime`

**Purpose:** Download Prime Mover site packages for rapid deployment.

**Response:** Binary .wprime file

## Authentication & Security

### Headers Required
All authenticated requests should include:
```
Authorization: Bearer {token}
Content-Type: application/json
```

### CORS Configuration
Ensure your Elixir application allows CORS requests from WordPress sites:
```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization
```

## Implementation Notes for Elixir Application

### 1. User Management
- Store user credentials securely (use bcrypt for password hashing)
- Track which users have access to which resources
- Implement session management with 12-hour expiration (matching WordPress transient cache)

### 2. File Storage
- Store plugin ZIP files in a secure directory
- Implement download tracking and rate limiting
- Support resume capability for large file downloads

### 3. License Management
The plugin expects to receive license keys for premium plugins. Your API should provide:
- Valid license keys for supported plugins
- License validation endpoints if needed

### 4. Supported Premium Plugins
Your API should be able to provide license keys for:
- FluentCommunity Pro
- AffiliateWP
- SearchWP
- WP All Import Pro
- WP All Export Pro
- Gravity Forms
- Advanced Custom Fields Pro
- WP Rocket
- Elementor Pro
- LearnDash
- WooCommerce Extensions
- Kadence Blocks Pro
- Prime Mover Pro

## Migration Path

1. **Phase 1:** Set up basic authentication endpoint
2. **Phase 2:** Implement plugin bundle delivery
3. **Phase 3:** Add Prime Mover package support
4. **Phase 4:** Implement license key management
5. **Phase 5:** Add update checking mechanism

## Environment Variables for WordPress Plugin

Add these to your WordPress wp-config.php during development:
```php
define('CRIBOPS_API_URL', 'http://localhost:4000');
define('CRIBOPS_API_KEY', 'your-api-key-here');
```

## Testing Endpoints

Use these curl commands to test your Elixir API:

```bash
# Test authentication
curl -X POST http://localhost:4000/wp-json/cribops/v1/user-meta \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password","site_url":"http://test.local"}'

# Test update check
curl http://localhost:4000/wp-content/uploads/software-bundle/launchkit-updates.json

# Test plugin download
curl -O http://localhost:4000/downloads/plugins/example-plugin.zip
```

## Error Handling

All endpoints should return appropriate HTTP status codes:
- 200: Success
- 401: Unauthorized
- 403: Forbidden (valid auth but no access)
- 404: Resource not found
- 429: Rate limit exceeded
- 500: Server error

Error responses should follow this format:
```json
{
  "error": "error_code",
  "message": "Human readable message",
  "details": {} // Optional additional details
}
```