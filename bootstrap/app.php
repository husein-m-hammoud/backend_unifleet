<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',      // register API routes under /api prefix
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Allow the React frontend (localhost:8080) to call our API
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Trust Sanctum token auth on API routes
        $middleware->statefulApi();

        // Route guards: manager-only routes + per-page grants for scoped users.
        $middleware->alias([
            'manager' => \App\Http\Middleware\EnsureManager::class,
            'page'    => \App\Http\Middleware\EnsurePageAccess::class,
            // Stricter than `manager` (which admins pass): diagnostics expose
            // logs and config state, so they are owner-only.
            'owner'   => \App\Http\Middleware\EnsureOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
