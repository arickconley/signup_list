<?php

use App\Http\Middleware\EnsureAccountHasPassword;
use App\Http\Middleware\EnsureAccountProfileIsComplete;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Webauthn\Exception\WebauthnException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.password' => EnsureAccountHasPassword::class,
            'profile.complete' => EnsureAccountProfileIsComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->map(
            WebauthnException::class,
            fn (WebauthnException $exception): InvalidPasskeyException => InvalidPasskeyException::make(
                'Unable to complete passkey request. Please try again.',
            ),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
