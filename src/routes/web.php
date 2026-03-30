<?php

use FileBrowser\Http\Controllers\FileBrowserController;
use Illuminate\Support\Facades\Route;

$prefix = ltrim(config('filebrowser.prefix', '/file-browser'), '/');
$middleware = config('filebrowser.middleware', ['web', 'auth']);

Route::middleware($middleware)->prefix($prefix)->group(function () {
    // Frontend (Vue SPA) — catch-all
    Route::get('/', [FileBrowserController::class, 'index'])->name('filebrowser.index');

    // API routes
    Route::prefix('api')->group(function () {
        Route::get('resources{path?}', [FileBrowserController::class, 'resourceGet'])->where('path', '.*')->name('filebrowser.resource.get');
        Route::post('resources{path?}', [FileBrowserController::class, 'resourcePost'])->where('path', '.*')->name('filebrowser.resource.post');
        Route::put('resources{path?}', [FileBrowserController::class, 'resourcePut'])->where('path', '.*')->name('filebrowser.resource.put');
        Route::delete('resources{path?}', [FileBrowserController::class, 'resourceDelete'])->where('path', '.*')->name('filebrowser.resource.delete');
        Route::match(['patch'], 'resources{path?}', [FileBrowserController::class, 'resourcePatch'])->where('path', '.*')->name('filebrowser.resource.patch');

        Route::get('raw{path?}', [FileBrowserController::class, 'raw'])->where('path', '.*')->name('filebrowser.raw');
        Route::get('preview/{size}{path}', [FileBrowserController::class, 'preview'])->where('path', '.*')->name('filebrowser.preview');
        Route::get('search{path?}', [FileBrowserController::class, 'search'])->where('path', '.*')->name('filebrowser.search');
        Route::get('usage{path?}', [FileBrowserController::class, 'usage'])->where('path', '.*')->name('filebrowser.usage');

        // Auth — return current user info (for Vue frontend)
        Route::post('login', [FileBrowserController::class, 'login'])->name('filebrowser.login');
        Route::post('renew', [FileBrowserController::class, 'renew'])->name('filebrowser.renew');
    });

    // Catch-all for Vue Router HTML5 history mode
    Route::get('{any}', [FileBrowserController::class, 'index'])->where('any', '.*')->name('filebrowser.catchall');
});
