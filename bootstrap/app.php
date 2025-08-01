<?php

use App\Http\Middleware\ProfileCompletedMiddleware;
use App\Http\Middleware\SetTeamContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\HorizonBasicAuthMiddleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . "/../routes/web.php",
        api: __DIR__ . "/../routes/api.php",
        commands: __DIR__ . "/../routes/console.php",
        health: "/up",
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(
            except: ["webhooks/*", "authenticate", "login"],
        );
        $middleware->alias([
            "verified" => \App\Http\Middleware\EnsureEmailIsVerified::class,
            "role" => \Spatie\Permission\Middleware\RoleMiddleware::class,
            "permission" =>
                \Spatie\Permission\Middleware\PermissionMiddleware::class,
            "role_or_permission" =>
                \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            "webhook.auth" => \App\Http\Middleware\WebhookBasicAuth::class,
        ]);
        $middleware->api(append: [SetTeamContextMiddleware::class]);
        // $middleware->alias([
        //     "profile.complete" => ProfileCompletedMiddleware::class,
        // ]);
        $middleware->trustProxies(at: "*");
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })
    ->create();
