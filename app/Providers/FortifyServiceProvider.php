<?php

namespace App\Providers;

use App\Actions\Fortify\ResetAccountPassword;
use App\Http\Middleware\PreventDirectTwoFactorManagement;
use App\Http\Responses\PasswordResetLinkRequestResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            FailedPasswordResetLinkRequestResponseContract::class,
            PasswordResetLinkRequestResponse::class,
        );
        $this->app->bind(
            SuccessfulPasswordResetLinkRequestResponseContract::class,
            PasswordResetLinkRequestResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordResetMiddleware();
        $this->configurePasskeyManagementMiddleware();
        $this->configureTwoFactorManagementMiddleware();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetAccountPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }

    /**
     * Limit password reset mail work across addresses from one source.
     */
    private function configurePasswordResetMiddleware(): void
    {
        $this->addMiddlewareToRoutes(
            ['password.email'],
            ['throttle:password-reset'],
        );
    }

    /**
     * Require a verified Account and fresh authentication for passkey management.
     */
    private function configurePasskeyManagementMiddleware(): void
    {
        $this->addMiddlewareToRoutes(
            ['passkey.registration-options', 'passkey.store', 'passkey.destroy'],
            ['password.confirm', 'verified'],
        );
    }

    /**
     * Limit two-factor authentication management to verified Accounts with passwords.
     */
    private function configureTwoFactorManagementMiddleware(): void
    {
        $this->addMiddlewareToRoutes(
            [
                'two-factor.confirm',
                'two-factor.disable',
                'two-factor.enable',
                'two-factor.qr-code',
                'two-factor.recovery-codes',
                'two-factor.regenerate-recovery-codes',
                'two-factor.secret-key',
            ],
            ['account.password', 'verified', PreventDirectTwoFactorManagement::class],
        );
    }

    /**
     * @param  list<string>  $routeNames
     * @param  list<class-string|string>  $middleware
     */
    private function addMiddlewareToRoutes(array $routeNames, array $middleware): void
    {
        $this->app->booted(function () use ($routeNames, $middleware): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (in_array($route->getName(), $routeNames, strict: true)) {
                    $route->middleware($middleware);
                }
            }
        });
    }
}
