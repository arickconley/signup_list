<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOperationalTelemetry();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureOperationalTelemetry(): void
    {
        Queue::failing(function (JobFailed $event): void {
            $job = $event->job->resolveName();
            $context = [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $job,
                'job_uuid' => $event->job->uuid(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception::class,
                'error' => $event->exception->getMessage(),
            ];

            Log::error('queue.job_failed', $context);

            if (is_a($job, Mailable::class, true) || $job === SendQueuedNotifications::class) {
                Log::error('mail.job_failed', $context);
            }
        });
    }
}
