<?php

use App\Mail\ProductionSmokeMail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('the production smoke check covers live HTTPS, persistence, mail queueing, scheduling, restoration, and source links', function () {
    $disk = storage_path('framework/testing/production-smoke-'.Str::uuid());
    $database = database_path('database.sqlite');
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    $restoreEvidence = $disk.'/restore-evidence.json';

    mkdir($disk, 0777, true);
    touch($database);
    File::put($heartbeat, json_encode([
        'recorded_at' => now()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));
    File::put($restoreEvidence, json_encode([
        'restored_at' => now()->toIso8601String(),
        'backup' => 'signup-20260719T120000Z.sqlite.age',
        'sha256' => str_repeat('a', 64),
        'integrity_check' => 'ok',
        'encrypted' => true,
    ], JSON_THROW_ON_ERROR));

    configureReadyProduction($database);
    config([
        'deployment.persistent_disk_path' => dirname($database),
        'deployment.scheduler.heartbeat_path' => $heartbeat,
        'deployment.backup.restore_evidence_path' => $restoreEvidence,
    ]);

    Http::fake([
        'https://signup.example/*' => Http::response(<<<'HTML'
            <a href="https://code.example/signup/tree/0123456789abcdef0123456789abcdef01234567">Source for deployed version</a>
            <a href="https://code.example/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE">GNU Affero General Public License v3.0 or later</a>
            <p>No warranty is provided.</p>
            HTML),
    ]);
    Mail::fake();

    try {
        expect(Artisan::call('app:production-smoke'))->toBe(0);

        expect(Artisan::output())->toContain(
            'HTTPS and source links: PASS',
            'SQLite persistent database: PASS',
            'Mail smoke message queued: PASS',
            'Scheduler heartbeat: PASS',
            'Restore evidence: PASS',
        );

        Mail::assertQueued(
            ProductionSmokeMail::class,
            fn (ProductionSmokeMail $mail): bool => $mail->hasTo('operator@example.net'),
        );
    } finally {
        DB::purge('sqlite');
        File::deleteDirectory($disk);
    }
});
