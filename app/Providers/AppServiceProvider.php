<?php

namespace App\Providers;

use App\Domain\LinkedIn\LinkedInApi;
use App\Domain\Meta\MetaGraph;
use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\TargetCleanup;
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
        // LinkedIn calls: built fresh too, so a newly saved Client Secret is used straight away.
        $this->app->bind(LinkedInApi::class, fn () => LinkedInApi::make());
        // The Hostinger folder: null until the owner has saved its details.
        $this->app->bind(RelayFolder::class, fn () => RelayFolder::fromSettings());
        // What Facebook and the Hostinger folder hold for a post, kept in step when the owner changes the post (SM-010).
        $this->app->bind(TargetCleanup::class, fn () => TargetCleanup::make());
        // The publisher: built fresh each time, so a newly saved key or Hostinger folder is used straight away.
        $this->app->bind(Publisher::class, fn () => Publisher::make());
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
