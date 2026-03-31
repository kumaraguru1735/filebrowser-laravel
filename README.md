# File Browser for Laravel

A full-featured file manager for Laravel applications. Laravel port of [filebrowser/filebrowser](https://github.com/filebrowser/filebrowser) — the Go backend is replaced with PHP/Laravel controllers while keeping the original Vue 3 frontend.

**100% Go feature parity. All upstream bug fixes applied.**

## Features

- Browse files and directories (list & grid view)
- Code editor (Ace) with syntax highlighting for 30+ languages
- Preview images, videos, audio, and PDFs
- Upload files with drag & drop
- TUS resumable uploads for large files
- Download files or directories as archive (ZIP + 8 TAR formats)
- Real-time streaming search with type filters
- Copy, move, rename, delete operations with conflict resolution
- Permission management (chmod) with recursive option
- Disk usage display
- Share links with password protection and expiry
- Public share access (no auth required)
- Subtitle conversion (SRT/ASS/SSA to WebVTT)
- Image thumbnails with GD resize and disk caching
- User quota management
- Event hooks (shell commands on upload/save/delete/copy/rename)
- Keyboard shortcuts
- Multi-language support (20+ languages)
- Light & dark theme
- Per-operation permission enforcement

## Installation

```bash
composer require kumaraguru/filebrowser-laravel:dev-master
```

### Publish Assets & Config

```bash
php artisan vendor:publish --tag=filebrowser-assets --force
php artisan vendor:publish --tag=filebrowser-config
php artisan migrate  # Creates filebrowser_shares table
```

### Nginx Setup

The file browser serves a Vue SPA — URLs like `/file-browser/files/index.php` must route through Laravel, not nginx's PHP handler:

```nginx
location ^~ /file-browser {
    rewrite ^/file-browser/(.*)$ /index.php?$query_string break;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_param REQUEST_URI /file-browser/$1$is_args$args;
    include fastcgi_params;
}
```

### CSRF Exclusion

The Vue SPA uses JWT auth, not CSRF tokens. Exclude the API routes:

```php
// bootstrap/app.php
$middleware->validateCsrfTokens(except: ['file-browser/api/*']);
```

## Configuration

Edit `config/filebrowser.php`:

```php
return [
    'prefix' => '/file-browser',
    'middleware' => ['web', 'auth'],
    'name' => 'File Browser',

    // Root path resolver — controls which directory users see
    'root_resolver' => fn($request) => '/home/' . auth()->user()->username,

    // Blocked upload extensions
    'blocked_extensions' => ['phtml', 'phar'],

    // Max upload size (bytes)
    'max_upload_size' => 500 * 1024 * 1024,

    // Max file size for text editor (prevents DoS)
    'max_content_size' => 5 * 1024 * 1024,

    // Per-operation permissions
    'permissions' => [
        'create' => true, 'modify' => true, 'delete' => true,
        'rename' => true, 'download' => true, 'share' => true,
    ],

    // File/directory creation modes
    'dir_mode' => 0755,
    'file_mode' => 0644,

    // Disk quota — return max bytes, 0 for unlimited
    'quota_resolver' => null,
    // Example: fn($user, $root) => 500 * 1024 * 1024,

    // Event hooks (env vars: FILE, SCOPE, TRIGGER, USERNAME, DESTINATION)
    'hooks' => [
        // 'upload' => ['echo "$FILE uploaded"'],
    ],
];
```

### Model Integration

Alternatively, add `getFileBrowserRoot()` to your User model:

```php
class User extends Authenticatable
{
    public function getFileBrowserRoot(): string
    {
        return '/home/' . $this->username;
    }
}
```

## API Endpoints

### Resources (CRUD)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/resources/{path}` | List directory or get file info + content |
| POST | `/api/resources/{path}` | Upload file or create directory |
| PUT | `/api/resources/{path}` | Update/save file content |
| DELETE | `/api/resources/{path}` | Delete file or directory |
| PATCH | `/api/resources/{path}` | Copy (`?action=copy`) or Move (`?action=rename`) |

### Download & Preview

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/raw/{path}` | Download file or ZIP/TAR archive |
| GET | `/api/preview/{size}/{path}` | Image thumbnail (thumb=256px, big=1080px) |
| GET | `/api/subtitle/{path}` | Convert SRT/ASS to WebVTT |

### Search & Usage

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/search/{path}?query=` | Streaming NDJSON search (supports `type:image`, `case:sensitive`) |
| GET | `/api/usage/{path}` | Disk usage (total + used bytes) |

### Shares

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/shares` | List all shares (admin sees all) |
| GET | `/api/share/{path}` | Get shares for a path |
| POST | `/api/share/{path}` | Create share (optional password + expiry) |
| DELETE | `/api/share/{hash}` | Delete a share |
| GET | `/api/public/share/{hash}/{path}` | Browse shared file/dir (no auth) |
| GET | `/api/public/dl/{hash}/{path}` | Download shared file (no auth) |

### TUS Resumable Uploads

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/tus/{path}` | Initialize upload (returns Location header) |
| HEAD | `/api/tus/{id}` | Get upload offset/progress |
| PATCH | `/api/tus/{id}` | Upload chunk |
| DELETE | `/api/tus/{id}` | Cancel upload |

### Auth

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/login` | Get JWT token (session-based) |
| POST | `/api/renew` | Renew JWT token |
| GET | `/health` | Health check |

## Upstream Bug Fixes Applied

All relevant open issues from [filebrowser/filebrowser](https://github.com/filebrowser/filebrowser/issues) have been fixed:

| Issue | Fix |
|-------|-----|
| [#5627](https://github.com/filebrowser/filebrowser/issues/5627) | Skip inaccessible subfolders instead of 500 error |
| [#5294](https://github.com/filebrowser/filebrowser/issues/5294) | Cap text editor at 5MB to prevent memory exhaustion |
| [#5683](https://github.com/filebrowser/filebrowser/issues/5683) | Allow copy/move to root directory |
| [#5835](https://github.com/filebrowser/filebrowser/issues/5835) | Share requires both share + download permissions |
| [#5834](https://github.com/filebrowser/filebrowser/issues/5834) | Reject negative/zero TUS Upload-Length |
| [#5239](https://github.com/filebrowser/filebrowser/issues/5239) | Block sharing root directory (too broad) |
| [#5216](https://github.com/filebrowser/filebrowser/issues/5216) | Validate JWT signature on token renewal |
| [#2078](https://github.com/filebrowser/filebrowser/issues/2078) | Support BMP thumbnails, skip GIF resize |
| [#5306](https://github.com/filebrowser/filebrowser/issues/5306) | Normalize paths in rename (strip trailing slashes) |

### Ported PRs

| PR | Feature |
|----|---------|
| [#5876](https://github.com/filebrowser/filebrowser/pull/5876) | Reject negative TUS upload-length |
| [#5875](https://github.com/filebrowser/filebrowser/pull/5875) | Check download perm when sharing |
| [#5873](https://github.com/filebrowser/filebrowser/pull/5873) | Deep conflict resolution in directory copy |
| [#5832](https://github.com/filebrowser/filebrowser/pull/5832) | Optional directory sizes via `?dirSizes=true` |
| [#5658](https://github.com/filebrowser/filebrowser/pull/5658) | User quota management system |

## Security

- Path traversal protection: `realpath()` + `str_starts_with()`
- Blocked file extensions (configurable)
- Per-operation permission enforcement
- JWT signed with HMAC-SHA256 (app key)
- Signature validation on token renewal
- Root directory sharing blocked
- TUS upload size validation (max 10GB, configurable)
- Disk quota enforcement
- CSRF excluded for API (uses JWT)

## License

Apache License 2.0 (same as original filebrowser)
