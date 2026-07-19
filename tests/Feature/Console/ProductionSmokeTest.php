<?php

use App\Mail\ProductionSmokeMail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** @return array{default: mixed, sqlite: mixed, pdo: PDO, transactions: int} */
function captureProductionSmokeDatabaseState(): array
{
    $connection = DB::connection('sqlite');
    $pdo = $connection->getPdo();
    $transactions = $connection->transactionLevel();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    return [
        'default' => config('database.default'),
        'sqlite' => config('database.connections.sqlite'),
        'pdo' => $pdo,
        'transactions' => $transactions,
    ];
}

/** @param array{default: mixed, sqlite: mixed, pdo: PDO, transactions: int} $state */
function restoreProductionSmokeDatabaseState(array $state): void
{
    DB::purge('sqlite');
    config([
        'database.default' => $state['default'],
        'database.connections.sqlite' => $state['sqlite'],
    ]);
    $connection = DB::connection('sqlite')->setPdo($state['pdo']);

    for ($transaction = 0; $transaction < $state['transactions']; $transaction++) {
        $connection->beginTransaction();
    }
}

test('the production smoke check covers live HTTPS, persistence, mail queueing, scheduling, restoration, and source links', function () {
    $databaseState = captureProductionSmokeDatabaseState();
    $disk = storage_path('framework/testing/production-smoke-'.Str::uuid());
    $database = $disk.'/database.sqlite';
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    $restoreEvidence = $disk.'/restore-evidence.json';

    File::ensureDirectoryExists($disk);
    File::put($database, '');
    File::put($restoreEvidence, json_encode([
        'restored_at' => now()->toIso8601String(),
        'backup' => 'signup-20260719T120000Z.sqlite.age',
        'sha256' => str_repeat('a', 64),
        'integrity_check' => 'ok',
        'encrypted' => true,
    ], JSON_THROW_ON_ERROR));

    configureReadyProduction($database);
    config([
        'deployment.persistent_disk_path' => $disk,
        'deployment.scheduler.heartbeat_path' => $heartbeat,
        'deployment.backup.restore_evidence_path' => $restoreEvidence,
    ]);
    DB::purge('sqlite');
    Artisan::call('app:scheduler-heartbeat');

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

        expect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'production_smoke_%'"))
            ->toBe([]);
    } finally {
        restoreProductionSmokeDatabaseState($databaseState);
        File::deleteDirectory($disk);
    }
});

test('the production smoke check rejects stale scheduler evidence even when the file is newly written', function () {
    $this->travelTo('2026-07-19 18:30:00 UTC');
    $databaseState = captureProductionSmokeDatabaseState();
    $disk = storage_path('framework/testing/production-smoke-stale-heartbeat-'.Str::uuid());
    $database = $disk.'/database.sqlite';
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    $restoreEvidence = $disk.'/restore-evidence.json';

    File::ensureDirectoryExists($disk);
    File::put($database, '');
    File::put($heartbeat, json_encode([
        'recorded_at' => now()->subMinutes(6)->toIso8601String(),
    ], JSON_THROW_ON_ERROR));
    touch($heartbeat, now()->timestamp);
    File::put($restoreEvidence, json_encode([
        'restored_at' => now()->toIso8601String(),
        'backup' => 'signup-20260719T120000Z.sqlite.age',
        'sha256' => str_repeat('a', 64),
        'integrity_check' => 'ok',
        'encrypted' => true,
    ], JSON_THROW_ON_ERROR));

    configureReadyProduction($database);
    config([
        'deployment.persistent_disk_path' => $disk,
        'deployment.scheduler.heartbeat_path' => $heartbeat,
        'deployment.backup.restore_evidence_path' => $restoreEvidence,
    ]);
    DB::purge('sqlite');
    Http::fake([
        'https://signup.example/*' => Http::response(<<<'HTML'
            <a href="https://code.example/signup/tree/0123456789abcdef0123456789abcdef01234567">Source for deployed version</a>
            <a href="https://code.example/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE">GNU Affero General Public License v3.0 or later</a>
            <p>No warranty is provided.</p>
            HTML),
    ]);
    Mail::fake();

    try {
        expect(Artisan::call('app:production-smoke'))->toBe(1)
            ->and(Artisan::output())->toContain('Scheduler heartbeat: FAIL');
    } finally {
        restoreProductionSmokeDatabaseState($databaseState);
        File::deleteDirectory($disk);
    }
});

test('the production smoke check rejects SQLite state that disappears across a connection reopen', function () {
    $databaseState = captureProductionSmokeDatabaseState();
    $disk = storage_path('framework/testing/production-smoke-volatile-sqlite-'.Str::uuid());
    $database = $disk.'/database.sqlite';
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    $restoreEvidence = $disk.'/restore-evidence.json';

    File::ensureDirectoryExists($disk);
    File::put($database, '');
    File::put($restoreEvidence, json_encode([
        'restored_at' => now()->toIso8601String(),
        'backup' => 'signup-20260719T120000Z.sqlite.age',
        'sha256' => str_repeat('a', 64),
        'integrity_check' => 'ok',
        'encrypted' => true,
    ], JSON_THROW_ON_ERROR));

    configureReadyProduction($database);
    config([
        'deployment.persistent_disk_path' => $disk,
        'deployment.scheduler.heartbeat_path' => $heartbeat,
        'deployment.backup.restore_evidence_path' => $restoreEvidence,
    ]);
    DB::purge('sqlite');
    DB::connection('sqlite')->getPdo();
    Artisan::call('app:scheduler-heartbeat');
    File::delete($database);

    Http::fake([
        'https://signup.example/*' => Http::response(<<<'HTML'
            <a href="https://code.example/signup/tree/0123456789abcdef0123456789abcdef01234567">Source for deployed version</a>
            <a href="https://code.example/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE">GNU Affero General Public License v3.0 or later</a>
            <p>No warranty is provided.</p>
            HTML),
    ]);
    Mail::fake();

    try {
        expect(Artisan::call('app:production-smoke'))->toBe(1)
            ->and(Artisan::output())->toContain('SQLite persistent database: FAIL');
    } finally {
        restoreProductionSmokeDatabaseState($databaseState);
        File::deleteDirectory($disk);
    }
});

test('the production smoke check rejects invalid or expired restore evidence', function (string $restoredAt, string $sha256) {
    $this->travelTo('2026-07-19 18:30:00 UTC');
    $databaseState = captureProductionSmokeDatabaseState();
    $disk = storage_path('framework/testing/production-smoke-invalid-restore-'.Str::uuid());
    $database = $disk.'/database.sqlite';
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    $restoreEvidence = $disk.'/restore-evidence.json';

    File::ensureDirectoryExists($disk);
    File::put($database, '');
    File::put($restoreEvidence, json_encode([
        'restored_at' => $restoredAt,
        'backup' => 'signup-20260719T120000Z.sqlite.age',
        'sha256' => $sha256,
        'integrity_check' => 'ok',
        'encrypted' => true,
    ], JSON_THROW_ON_ERROR));

    configureReadyProduction($database);
    config([
        'deployment.persistent_disk_path' => $disk,
        'deployment.scheduler.heartbeat_path' => $heartbeat,
        'deployment.backup.restore_evidence_path' => $restoreEvidence,
    ]);
    DB::purge('sqlite');
    Artisan::call('app:scheduler-heartbeat');
    Http::fake([
        'https://signup.example/*' => Http::response(<<<'HTML'
            <a href="https://code.example/signup/tree/0123456789abcdef0123456789abcdef01234567">Source for deployed version</a>
            <a href="https://code.example/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE">GNU Affero General Public License v3.0 or later</a>
            <p>No warranty is provided.</p>
            HTML),
    ]);
    Mail::fake();

    try {
        expect(Artisan::call('app:production-smoke'))->toBe(1)
            ->and(Artisan::output())->toContain('Restore evidence: FAIL');
    } finally {
        restoreProductionSmokeDatabaseState($databaseState);
        File::deleteDirectory($disk);
    }
})->with([
    'unparseable restoration time' => ['not-a-time', str_repeat('a', 64)],
    'expired restoration time' => ['2026-01-01T00:00:00+00:00', str_repeat('a', 64)],
    'future restoration time' => ['2026-07-20T18:30:00+00:00', str_repeat('a', 64)],
    'short checksum' => ['2026-07-19T18:30:00+00:00', str_repeat('a', 63)],
    'non-hex checksum' => ['2026-07-19T18:30:00+00:00', str_repeat('g', 64)],
]);
