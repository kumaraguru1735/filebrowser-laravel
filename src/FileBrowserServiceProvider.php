<?php

namespace FileBrowser;

use Illuminate\Support\ServiceProvider;

class FileBrowserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/filebrowser.php', 'filebrowser');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/config/filebrowser.php' => config_path('filebrowser.php'),
        ], 'filebrowser-config');

        // Publish assets (built Vue frontend)
        $this->publishes([
            __DIR__ . '/../resources/dist' => public_path('vendor/filebrowser'),
        ], 'filebrowser-assets');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filebrowser');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/filebrowser'),
        ], 'filebrowser-views');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }
}
