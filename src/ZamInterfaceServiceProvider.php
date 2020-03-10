<?php

namespace ZamApps\ZamInterface;

use Illuminate\Support\ServiceProvider;
use ZamApps\ZamInterface\Services\GeneralService;

class ZamInterfaceServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/Migrations/' => database_path('migrations')
        ], 'migrations');
    }

    /**
     * Register the service providers
     *
     * @return void
     */
    public function register()
    {
        $this->registerGeneralService();
    }

    /**
     * Register the GeneralService
     * @return void
     */
    public function registerGeneralService()
    {
        $this->app->singleton(GeneralService::class, function($app) {
            return $app['ZamApps\ZamInterface\Services\GeneralService'];
        });
    }
}
