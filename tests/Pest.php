<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function configureReadyProduction(string $databasePath = '/srv/signup/database/database.sqlite'): void
{
    $sourceRef = '0123456789abcdef0123456789abcdef01234567';

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://signup.example',
        'session.secure' => true,
        'deployment.https.termination' => 'direct',
        'deployment.https.trusted_proxies' => [],
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
        'deployment.persistent_disk_path' => '/srv/signup',
        'queue.default' => 'database',
        'session.driver' => 'database',
        'cache.default' => 'database',
        'deployment.processes.web' => 1,
        'deployment.processes.queue' => 1,
        'deployment.processes.scheduler' => 1,
        'deployment.scheduler.heartbeat_path' => '/srv/signup/scheduler-heartbeat.json',
        'deployment.scheduler.heartbeat_max_age_minutes' => 5,
        'deployment.mail.provider' => 'human-selected-provider',
        'deployment.mail.sender_domain' => 'signup.example',
        'deployment.mail.smoke_to' => 'operator@example.net',
        'mail.default' => 'smtp',
        'mail.from.address' => 'notices@signup.example',
        'deployment.backup.destination' => '/mnt/human-selected-backups',
        'deployment.backup.age_recipient' => 'human-selected-age-recipient',
        'deployment.backup.schedule' => '15 3 * * *',
        'deployment.backup.retention_days' => 30,
        'deployment.backup.restore_evidence_path' => '/srv/signup/restore-evidence.json',
        'deployment.backup.restore_max_age_days' => 90,
        'deployment.disposable_email_domains.source_url' => 'https://blocklist.example/domains.txt',
        'deployment.disposable_email_domains.update_schedule' => '30 3 * * 1',
        'deployment.source.ref' => $sourceRef,
        'deployment.source.url' => "https://code.example/signup/tree/{$sourceRef}",
        'deployment.source.license_url' => "https://code.example/signup/blob/{$sourceRef}/LICENSE",
    ]);
}
