<?php

namespace App\Providers;

use App\Domain\Meta\MetaGraph;
use App\Domain\Relay\RelayFolder;
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
        //
    }
}
