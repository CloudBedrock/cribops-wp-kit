# Theme API Requirements for CribOps WP Kit

## Overview
This document outlines the API endpoints needed to support WordPress theme management in the CribOps WP Kit plugin.

## Required API Endpoints

### 1. Get Theme Manifest
**Endpoint:** `GET /api/wp-kit/v1/themes`
**Authentication:** Bearer token (user email)
**Purpose:** Retrieve list of available themes for download

**Request Headers:**
```
Authorization: Bearer {user_email}
Content-Type: application/json
```

**Response Format:**
```json
{
  "themes": [
    {
      "slug": "theme-slug",
      "name": "Theme Name",
      "author": "Author Name",
      "description": "Theme description",
      "version": "1.0.0",
      "file_size": 1234567,
      "requires_php": "7.4",
      "tested_up_to": "6.7",
      "s3_url": "https://s3.amazonaws.com/.../theme.zip",
      "cdn_url": "https://cdn.example.com/theme.zip",
      "download_url": "https://api.example.com/download/theme.zip",
      "thumbnail_url": "https://cdn.example.com/screenshots/theme.png",
      "type": "theme"
    }
  ]
}
```

**Response Codes:**
- `200 OK`: Success
- `401 Unauthorized`: Invalid or missing authentication
- `403 Forbidden`: User doesn't have access to themes
- `500 Internal Server Error`: Server error

### 2. Download Theme
**Endpoint:** `GET /api/wp-kit/v1/themes/{slug}/download`
**Authentication:** Bearer token (user email)
**Purpose:** Get download URL or direct download for a specific theme

**Request Headers:**
```
Authorization: Bearer {user_email}
```

**Response Options:**

**Option A - Redirect to S3 (Preferred):**
```
HTTP/1.1 302 Found
Location: https://s3.amazonaws.com/bucket/themes/theme-slug.zip?presigned-params
```

**Option B - Direct Response:**
```
HTTP/1.1 200 OK
Content-Type: application/zip
Content-Disposition: attachment; filename="theme-slug.zip"
[Binary ZIP data]
```

**Response Codes:**
- `200 OK`: Direct download
- `302 Found`: Redirect to download URL
- `401 Unauthorized`: Invalid authentication
- `403 Forbidden`: User doesn't have access
- `404 Not Found`: Theme not found
- `500 Internal Server Error`: Server error

## Database Schema Recommendations

### Themes Table
```sql
CREATE TABLE themes (
  id SERIAL PRIMARY KEY,
  slug VARCHAR(255) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  author VARCHAR(255),
  description TEXT,
  version VARCHAR(50),
  file_size BIGINT,
  requires_php VARCHAR(20),
  tested_up_to VARCHAR(20),
  s3_key VARCHAR(500),
  s3_bucket VARCHAR(255),
  cdn_url TEXT,
  thumbnail_url TEXT,
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_themes_slug ON themes(slug);
CREATE INDEX idx_themes_active ON themes(active);
```

### Theme Access Logs Table
```sql
CREATE TABLE theme_access_logs (
  id SERIAL PRIMARY KEY,
  theme_id INTEGER REFERENCES themes(id),
  user_email VARCHAR(255),
  action VARCHAR(50), -- 'list', 'download'
  ip_address VARCHAR(45),
  user_agent TEXT,
  accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_theme_logs_theme ON theme_access_logs(theme_id);
CREATE INDEX idx_theme_logs_user ON theme_access_logs(user_email);
CREATE INDEX idx_theme_logs_date ON theme_access_logs(accessed_at);
```

## AWS S3 Integration

### S3 Bucket Structure
```
cribops-wp-kit/
├── themes/
│   ├── theme-slug-1.zip
│   ├── theme-slug-2.zip
│   └── ...
└── screenshots/
    ├── theme-slug-1.png
    ├── theme-slug-2.png
    └── ...
```

### Presigned URL Generation
When generating S3 presigned URLs:
- Expiration: 3600 seconds (1 hour)
- HTTP Method: GET
- Content-Type: application/zip

Example presigned URL generation (pseudo-code):
```
url = s3.generate_presigned_url(
  method='GET',
  bucket='cribops-wp-kit',
  key='themes/theme-slug.zip',
  expiration=3600
)
```

## User Authentication

### Access Control
Users must have:
- Valid authentication token (email)
- `can_access_launchkit` flag set to `true` in their user data
- Active subscription/license

### User Data Response
When user logs in via `/api/wp-kit/v1/user-meta`, include:
```json
{
  "email": "user@example.com",
  "first_name": "John",
  "can_access_launchkit": true,
  "can_access_themes": true
}
```

## Security Considerations

1. **Authentication**: All endpoints require Bearer token authentication
2. **Rate Limiting**: Implement rate limiting to prevent abuse
3. **Access Logging**: Log all theme access for audit trail
4. **File Validation**: Validate uploaded theme ZIP files before storage
5. **Presigned URL Expiration**: Keep presigned URLs short-lived (1 hour max)
6. **CORS**: Configure CORS to allow requests from WordPress sites

## Implementation Priority

1. **Phase 1 - Basic Theme Listing** (High Priority)
   - Create themes database table
   - Implement GET /api/wp-kit/v1/themes endpoint
   - Return basic theme metadata

2. **Phase 2 - S3 Integration** (High Priority)
   - Set up S3 bucket and upload themes
   - Implement presigned URL generation
   - Implement GET /api/wp-kit/v1/themes/{slug}/download endpoint

3. **Phase 3 - Access Control** (Medium Priority)
   - Add user permission checks
   - Implement access logging
   - Add rate limiting

4. **Phase 4 - Advanced Features** (Low Priority)
   - Theme screenshots/thumbnails
   - Theme categories/tags
   - Theme search functionality
   - Version management

## Testing Endpoints

### Test Theme List
```bash
curl -X GET https://cribops.com/api/wp-kit/v1/themes \
  -H "Authorization: Bearer test@example.com" \
  -H "Content-Type: application/json"
```

### Test Theme Download
```bash
curl -X GET https://cribops.com/api/wp-kit/v1/themes/twentytwentyfour/download \
  -H "Authorization: Bearer test@example.com" \
  -o theme.zip
```

## WordPress Plugin Integration

The WordPress plugin expects:
1. Theme manifest with array of theme objects
2. Each theme object must have `slug`, `name`, and either `s3_url`, `cdn_url`, or `download_url`
3. HTTP 302 redirect or direct ZIP file download
4. ZIP file must be valid and contain theme files in root or single subdirectory

## Error Handling

All error responses should follow this format:
```json
{
  "error": "Error message here",
  "code": "ERROR_CODE",
  "details": "Additional error details if available"
}
```

Common error codes:
- `UNAUTHORIZED`: Missing or invalid authentication
- `FORBIDDEN`: User doesn't have permission
- `NOT_FOUND`: Theme not found
- `INVALID_REQUEST`: Malformed request
- `SERVER_ERROR`: Internal server error
