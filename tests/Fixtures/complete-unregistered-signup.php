<?php

use App\Actions\CompleteUnregisteredSignup;
use App\Exceptions\CannotCompleteSignup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $databasePath, $sheetPublicId, $optionPublicId, $name, $readyPath, $gatePath] = $argv;
$attemptCounterPath = $argv[7] ?? null;

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $databasePath);
config()->set('database.connections.sqlite.busy_timeout', 100);
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
DB::purge('sqlite');
$pdo = DB::connection('sqlite')->getPdo();

if (is_string($attemptCounterPath)) {
    $pdo->sqliteCreateFunction(
        'record_signup_attempt',
        fn (): int => file_put_contents($attemptCounterPath, '1', FILE_APPEND),
    );
}
$busyTimeout = (int) DB::selectOne('PRAGMA busy_timeout')->timeout;

file_put_contents($readyPath, (string) getmypid());

$gateDeadline = microtime(true) + 5;

while (! file_exists($gatePath) && microtime(true) < $gateDeadline) {
    usleep(5_000);
}

if (! file_exists($gatePath)) {
    echo json_encode(['pid' => getmypid(), 'result' => 'gate-timeout'], JSON_THROW_ON_ERROR);
    exit(1);
}

try {
    $actionStartedAt = hrtime(true);
    app(CompleteUnregisteredSignup::class)->handle(
        $sheetPublicId,
        $name,
        null,
        [$optionPublicId],
    );

    $result = 'success';
} catch (CannotCompleteSignup $exception) {
    $result = match (true) {
        $exception->unavailableOptionNames !== [] => 'unavailable',
        str_contains($exception->getMessage(), 'signup list is busy') => 'busy',
        default => 'rejected',
    };
} catch (Throwable) {
    $result = 'unexpected-error';
}

echo json_encode([
    'pid' => getmypid(),
    'result' => $result,
    'busy_timeout' => $busyTimeout,
    'action_elapsed_ms' => (hrtime(true) - $actionStartedAt) / 1_000_000,
], JSON_THROW_ON_ERROR);
