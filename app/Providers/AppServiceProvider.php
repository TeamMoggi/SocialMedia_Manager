<?php

namespace App\Providers;

use App\Domain\Meta\MetaGraph;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
