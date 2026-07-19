<?php

use App\Mail\AccountAccessMail;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

test('a failed queued email produces structured queue and mail failure events', function () {
    Log::spy();
    $payload = json_encode([
        'uuid' => 'failed-mail-uuid',
        'displayName' => AccountAccessMail::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ], JSON_THROW_ON_ERROR);
    $job = new SyncJob(app(), $payload, 'database', 'mail');

    event(new JobFailed('database', $job, new RuntimeException('Transport unavailable')));

    Log::shouldHaveReceived('error')
        ->with('queue.job_failed', Mockery::on(fn (array $context): bool => $context === [
            'connection' => 'database',
            'queue' => 'sync',
            'job' => AccountAccessMail::class,
            'job_uuid' => 'failed-mail-uuid',
            'attempts' => 1,
            'exception' => RuntimeException::class,
            'error' => 'Transport unavailable',
        ]))
        ->once();
    Log::shouldHaveReceived('error')
        ->with('mail.job_failed', Mockery::on(fn (array $context): bool => $context['job'] === AccountAccessMail::class
            && $context['job_uuid'] === 'failed-mail-uuid'))
        ->once();
});
