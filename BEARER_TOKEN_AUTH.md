# Bearer Token Authentication for CribOps WP Kit

## Overview

The CribOps WP Kit plugin now supports two authentication methods:
1. **Bearer Token Authentication** (NEW) - Automatic authentication via API token
2. **Username/Password Authentication** - Traditional login via the admin interface

Bearer token authentication allows users to configure their API token once via environment variable, eliminating the need to log in manually on each site.

## Benefits of Bearer Token Authentication

- **Simplified First-Use Experience**: No need to manually log in on each WordPress site
- **Better for Automation**: Ideal for CI/CD pipelines and automated deployments
- **Increased Security**: No passwords stored in WordPress transients
- **Persistent Authentication**: Token auth doesn't expire during the session

## Setup Instructions

### For WordPress Sites

1. **Copy the example environment file**:
   ```bash
   cp .env.example .env
   ```

2. **Get your API token from CribOps**:
   - Log into your CribOps account
   - Navigate to your account settings or WP Kit dashboard
   - Generate or retrieve your API token

3. **Add your token to the .env file**:
   ```env
   CWPK_BEARER_TOKEN=your_actual_token_here
   ```

4. **Visit the plugin admin page**:
   - Navigate to WordPress Admin → CribOps WP-Kit
   - You should see "Authenticated via API Token" in green
   - No login required!

### For Server Administrators

Set the environment variable at the system level:

**Apache (in .htaccess or httpd.conf)**:
```apache
SetEnv CWPK_BEARER_TOKEN your_token_here
```

**Nginx (in PHP-FPM pool config)**:
```ini
env[CWPK_BEARER_TOKEN] = your_token_here
```

**Docker**:
```dockerfile
ENV CWPK_BEARER_TOKEN=your_token_here
```

## API Changes

### New Endpoint

```
POST /api/wp-kit/v1/authenticate-token
```

**Headers**:
```
Authorization: Bearer YOUR_TOKEN_HERE
Content-Type: application/json
```

**Body**:
```json
{
  "site_url": "https://yoursite.com"
}
```

### Database Changes

The `wp_kit_users` table now includes an `api_key` field for storing bearer tokens.

**Migration Required**:
```bash
cd /path/to/cribops-public
source .env
mix ecto.migrate
```

## How It Works

1. The plugin checks for a bearer token in the environment variables
2. If found, it authenticates with the API using the token
3. The API validates the token and returns user data
4. The plugin caches the authentication for 1 hour
5. If token auth fails, the plugin falls back to username/password login

## Visual Indicators

- **Token Authentication**: Green badge showing "Authenticated via API Token"
- **Password Authentication**: Gray badge with logout option
- **No Authentication**: Login form displayed

## Security Considerations

- Never commit `.env` files to version control
- Use `.gitignore` to exclude `.env` files
- Rotate tokens periodically
- Each user should have their own unique token
- Tokens should have sufficient entropy (minimum 32 characters)

## Troubleshooting

### Token Not Working

1. **Check token validity**:
   - Ensure the token is correct and hasn't been revoked
   - Verify the user account is active with kit access

2. **Check environment variable**:
   ```php
   // Add to your theme's functions.php temporarily
   var_dump(getenv('CWPK_BEARER_TOKEN'));
   ```

3. **Clear authentication cache**:
   - Delete WordPress transients:
     - `cwpk_token_auth`
     - `lk_logged_in`
     - `lk_user_data`

### Fallback to Password Auth

If token authentication fails, you'll see:
- Yellow warning: "Bearer token found but authentication failed"
- Login form will be displayed
- You can still use username/password authentication

## Generating API Tokens (Admin Only)

For CribOps administrators to generate tokens for users:

```elixir
# In Elixir console
user = CribopsPublic.WpKit.get_user!(user_id)
{:ok, token} = CribopsPublic.WpKit.get_or_create_api_token(user)
IO.puts("Token for #{user.email}: #{token}")
```

## Migration Path

1. Existing users continue to work with username/password
2. Users can optionally add bearer tokens for convenience
3. Both methods work simultaneously
4. No breaking changes to existing integrations