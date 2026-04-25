<?php

use FileBrowser\Http\Controllers\FileBrowserController;
use Illuminate\Support\Facades\Route;

$prefix = ltrim(config('filebrowser.prefix', '/file-browser'), '/');
$middleware = config('filebrowser.middleware', ['web', 'auth']);

// Health check (no auth)
Route::get($prefix . '/health', [FileBrowserController::class, 'health']);

// Public share routes (no auth)
Route::middleware('web')->prefix($prefix)->group(function () {
    Route::get('api/public/share/{hash}/{path?}', [FileBrowserController::class, 'publicShare'])->where('path', '.*');
    Route::get('api/public/dl/{hash}/{path?}', [FileBrowserController::class, 'publicDownload'])->where('path', '.*');
});

// Authenticated routes
Route::middleware($middleware)->prefix($prefix)->group(function () {
    // Frontend SPA
    Route::get('/', [FileBrowserController::class, 'index'])->name('filebrowser.index');

    // API
    Route::prefix('api')->group(function () {
        // Auth
        Route::post('login', [FileBrowserController::class, 'login']);
        Route::post('renew', [FileBrowserController::class, 'renew']);

        // Resources (CRUD)
        Route::get('resources{path?}', [FileBrowserController::class, 'resourceGet'])->where('path', '.*');
        Route::post('resources{path?}', [FileBrowserController::class, 'resourcePost'])->where('path', '.*');
        Route::put('resources{path?}', [FileBrowserController::class, 'resourcePut'])->where('path', '.*');
        Route::delete('resources{path?}', [FileBrowserController::class, 'resourceDelete'])->where('path', '.*');
        Route::patch('resources{path?}', [FileBrowserController::class, 'resourcePatch'])->where('path', '.*');

        // Extract archive (zip/tar/tar.gz/tar.bz2)
        Route::post('extract{path?}', [FileBrowserController::class, 'extract'])->where('path', '.*');

        // Settings (stub — returns defaults)
        Route::get('settings', [FileBrowserController::class, 'settingsGet']);
        Route::put('settings', [FileBrowserController::class, 'settingsPut']);

        // Users (stub — single virtual user from Laravel auth)
        Route::get('users', [FileBrowserController::class, 'usersList']);
        Route::get('users/{id}', [FileBrowserController::class, 'usersGet']);

        // Download / Raw
        Route::get('raw{path?}', [FileBrowserController::class, 'raw'])->where('path', '.*');

        // Preview / Thumbnails
        Route::get('preview/{size}{path}', [FileBrowserController::class, 'preview'])->where('path', '.*');

        // Subtitles
        Route::get('subtitle{path}', [FileBrowserController::class, 'subtitle'])->where('path', '.*');

        // Search
        Route::get('search{path?}', [FileBrowserController::class, 'search'])->where('path', '.*');

        // TUS Resumable Uploads
        Route::post('tus{path?}', [FileBrowserController::class, 'tusPost'])->where('path', '.*');
        Route::match(['head'], 'tus/{id}', [FileBrowserController::class, 'tusHead']);
        Route::patch('tus/{id}', [FileBrowserController::class, 'tusPatch']);
        Route::delete('tus/{id}', [FileBrowserController::class, 'tusDelete']);

        // Disk usage
        Route::get('usage{path?}', [FileBrowserController::class, 'usage'])->where('path', '.*');

        // Shares
        Route::get('shares', [FileBrowserController::class, 'shareList']);
        Route::get('share{path?}', [FileBrowserController::class, 'shareGet'])->where('path', '.*');
        Route::post('share{path?}', [FileBrowserController::class, 'shareCreate'])->where('path', '.*');
        Route::delete('share/{hash}', [FileBrowserController::class, 'shareDelete']);
    });

    // Vue Router catch-all
    Route::get('{any}', [FileBrowserController::class, 'index'])->where('any', '.*');
});
