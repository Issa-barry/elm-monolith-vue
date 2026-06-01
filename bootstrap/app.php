<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\AuthRedirects;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'module' => \App\Http\Middleware\RequireModuleEnabled::class,
            'require.site' => \App\Http\Middleware\RequireSiteAssigned::class,
            'active.livreur' => \App\Http\Middleware\RequireActiveLivreur::class,
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            return AuthRedirects::defaultPathForUser($request->user());
        });

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Forcer JSON sur toutes les routes /api/* quel que soit le header Accept
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*'));
    })->create();
