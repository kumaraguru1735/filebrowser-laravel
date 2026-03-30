# File Browser for Laravel

A beautiful file manager for Laravel applications. Laravel port of [filebrowser/filebrowser](https://github.com/filebrowser/filebrowser) — the Go backend is replaced with Laravel controllers while keeping the original Vue 3 frontend.

## Features

- Browse files and directories (list & grid view)
- Code editor (Ace) with syntax highlighting for 30+ languages
- Preview images, videos, audio, and PDFs
- Upload files with drag & drop
- Download files or directories as ZIP
- Real-time file search
- Copy, move, rename, delete operations
- Permission management (chmod)
- Disk usage display
- Keyboard shortcuts
- Multi-language support (20+ languages)
- Light & dark theme

## Installation

```bash
composer require kumaraguru/filebrowser-laravel
```

### Publish Assets

```bash
php artisan vendor:publish --tag=filebrowser-assets
php artisan vendor:publish --tag=filebrowser-config
```

## Configuration

Edit `config/filebrowser.php`:

```php
return [
    // URL prefix
    'prefix' => '/file-browser',

    // Middleware
    'middleware' => ['web', 'auth'],

    // Root path resolver
    'root_resolver' => function ($request) {
        return '/home/' . auth()->user()->username;
    },

    // Blocked file extensions
    'blocked_extensions' => ['phtml', 'phar'],

    // Display name
    'name' => 'File Browser',
];
```

### Root Path Resolver

The `root_resolver` config determines which directory each user can access:

```php
// Per-user home directory
'root_resolver' => fn($request) => '/home/' . auth()->user()->username,

// Static path
'root_resolver' => fn($request) => storage_path('app/files'),
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

## Usage

After installation, visit `yourapp.com/file-browser` (or your configured prefix).

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/resources/{path}` | List directory or get file info |
| POST | `/api/resources/{path}` | Upload file or create directory |
| PUT | `/api/resources/{path}` | Update file content |
| DELETE | `/api/resources/{path}` | Delete file or directory |
| PATCH | `/api/resources/{path}` | Copy or move |
| GET | `/api/raw/{path}` | Download file or ZIP |
| GET | `/api/preview/{size}/{path}` | Image preview |
| GET | `/api/search/{path}` | Search files |
| GET | `/api/usage/{path}` | Disk usage |

## License

Apache License 2.0 (same as original filebrowser)
