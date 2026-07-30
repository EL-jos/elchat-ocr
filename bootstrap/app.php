<?php

use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnsureAttemptToken;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\VerifyWidgetOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->validateCsrfTokens(except: [
            'social/*',
            'webhooks/*',
            '/paypal/webhook',
            '/stripe/webhook'
            //'webhooks/facebook',
            //'webhooks/slack',
            //'webhooks/telegram/*',  // ✅ ajout
        ]);
        
        $middleware->alias([
            'verified' => EnsureUserIsVerified::class,
            'jwt.auth' => JwtAuthenticate::class,
            'widget.origin' => VerifyWidgetOrigin::class,
            'check.subscription' => CheckSubscription::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();