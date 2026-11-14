<?php

use App\Http\Middleware\EnsureLocalRequest;
use App\Support\DataDir;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Data (.env, database, storage, media) lives outside the code; .data-dir or SMM_DATA_DIR names the folder.
$dataDir = DataDir::resolve(dirname(__DIR__));

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request, the app's screens included: on the laptop only the laptop itself is answered.
        $middleware->prepend(EnsureLocalRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->useEnvironmentPath($dataDir);
$app->useStoragePath($dataDir.DIRECTORY_SEPARATOR.'storage');
$app->instance('smm.data_dir', $dataDir);

return $app;
