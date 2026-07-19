<?php

use Illuminate\Support\Facades\Artisan;

test('production validation reports unsafe or missing deployment configuration', function () {
    config([
        'app.env' => 'staging',
        'app.debug' => true,
        'app.key' => null,
        'app.url' => 'http://signup.test',
        'session.secure' => false,
    ]);

    expect(Artisan::call('app:production-check'))->toBe(1);

    expect(Artisan::output())->toContain(
        'APP_ENV',
        'APP_DEBUG',
        'APP_KEY',
        'APP_URL',
        'SESSION_SECURE_COOKIE',
        'HTTPS_TERMINATION',
        'DB_DATABASE',
        'PERSISTENT_DISK_PATH',
        'QUEUE_CONNECTION',
        'SCHEDULER_HEARTBEAT_PATH',
        'MAIL_PROVIDER',
        'MAIL_SENDER_DOMAIN',
        'SMOKE_TEST_EMAIL',
        'BACKUP_DESTINATION',
        'BACKUP_AGE_RECIPIENT',
        'BACKUP_SCHEDULE',
        'BACKUP_RETENTION_DAYS',
        'BACKUP_RESTORE_EVIDENCE_PATH',
        'DISPOSABLE_EMAIL_BLOCKLIST_SOURCE_URL',
        'DISPOSABLE_EMAIL_BLOCKLIST_UPDATE_SCHEDULE',
        'SOURCE_CODE_REF',
        'SOURCE_CODE_URL',
        'SOURCE_LICENSE_URL',
    );
});

test('production validation accepts the supported single-instance configuration', function () {
    configureReadyProduction();

    expect(Artisan::call('app:production-check'))->toBe(0)
        ->and(Artisan::output())->toContain('Production configuration is ready.');
});

test('production validation rejects invalid or weak application keys', function (string $key) {
    configureReadyProduction();
    config(['app.key' => $key]);

    expect(Artisan::call('app:production-check'))->toBe(1)
        ->and(Artisan::output())->toContain('APP_KEY must contain a strong generated application key.');
})->with([
    'invalid length' => ['x'],
    'repeated bytes' => ['base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='],
]);

test('production validation requires operational evidence paths inside the persistent disk', function (string $outsidePath) {
    configureReadyProduction();
    config([
        'deployment.scheduler.heartbeat_path' => $outsidePath.'/scheduler-heartbeat.json',
        'deployment.backup.restore_evidence_path' => $outsidePath.'/restore-evidence.json',
    ]);

    expect(Artisan::call('app:production-check'))->toBe(1)
        ->and(Artisan::output())
        ->toContain('SCHEDULER_HEARTBEAT_PATH must be inside PERSISTENT_DISK_PATH.')
        ->toContain('BACKUP_RESTORE_EVIDENCE_PATH must be inside PERSISTENT_DISK_PATH.');
})->with([
    'outside directory' => ['/tmp'],
    'path traversal' => ['/srv/signup/../tmp'],
]);
