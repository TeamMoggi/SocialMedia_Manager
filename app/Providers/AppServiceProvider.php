<?php

namespace App\Providers;

use App\Domain\Meta\MetaGraph;
use App\Domain\Relay\RelayFolder;
use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Meta calls: built fresh each time, so a newly saved App Secret is used straight away.
        $this->app->bind(MetaGraph::class, fn () => MetaGraph::make());
        // The Hostinger folder: null until the owner has saved its details.
        $this->app->bind(RelayFolder::class, fn () => RelayFolder::fromSettings());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every call to Meta, LinkedIn or the website checks certificates against a real list. PHP on
        // Windows has none, so every secure call failed (curl error 60). This uses php.ini's file or
        // the system's list where one exists, else the list in composer/ca-bundle (Mozilla's).
        Http::globalOptions(['verify' => CaBundle::getSystemCaRootBundlePath()]);
    }
}
