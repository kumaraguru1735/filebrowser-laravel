<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    | The URL prefix for the file browser. Default: /file-browser
    */
    'prefix' => env('FILEBROWSER_PREFIX', '/file-browser'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Middleware applied to all file browser routes.
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Root Path Resolver
    |--------------------------------------------------------------------------
    | A callable that receives the Request and returns the root filesystem path.
    | Example: fn($request) => '/home/' . auth()->user()->username
    |
    | Set to null to use the default (current user's home directory).
    */
    'root_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Blocked Extensions
    |--------------------------------------------------------------------------
    | File extensions that cannot be uploaded (security).
    */
    'blocked_extensions' => ['phtml', 'phar'],

    /*
    |--------------------------------------------------------------------------
    | Max Upload Size (bytes)
    |--------------------------------------------------------------------------
    | Maximum file upload size. Default: 500MB
    */
    'max_upload_size' => 500 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Name
    |--------------------------------------------------------------------------
    | Display name shown in the header.
    */
    'name' => env('FILEBROWSER_NAME', 'File Browser'),

    /*
    |--------------------------------------------------------------------------
    | Event Hooks
    |--------------------------------------------------------------------------
    | Shell commands to run on file events. Env vars: FILE, SCOPE, TRIGGER, USERNAME, DESTINATION
    | Example: 'upload' => ['echo "$FILE uploaded by $USERNAME"']
    */
    'hooks' => [
        // 'upload' => [],
        // 'save' => [],
        // 'delete' => [],
        // 'copy' => [],
        // 'rename' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    | Control which operations are allowed. Set to false to disable.
    | Keys: create, modify, delete, rename, download, share
    */
    'permissions' => [
        'create' => true,
        'modify' => true,
        'delete' => true,
        'rename' => true,
        'download' => true,
        'share' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Directory / File Mode
    |--------------------------------------------------------------------------
    | Default permissions for newly created directories and files.
    */
    'dir_mode' => 0755,
    'file_mode' => 0644,
];
