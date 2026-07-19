<?php

use App\Actions\CancelParticipantSignup;
use App\Exceptions\CannotCancelParticipantSignup;
use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $databasePath, $accountId, $signupId, $readyPath, $gatePath] = $argv;

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $databasePath);
config()->set('database.connections.sqlite.busy_timeout', 100);
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
DB::purge('sqlite');
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
    $account = Account::query()->findOrFail((int) $accountId);
    app(CancelParticipantSignup::class)->handle($account, (int) $signupId);
    $result = 'success';
} catch (CannotCancelParticipantSignup $exception) {
    $result = str_contains($exception->getMessage(), 'Signup Sheet is busy')
        ? 'busy'
        : 'rejected';
} catch (Throwable) {
    $result = 'unexpected-error';
}

echo json_encode([
    'pid' => getmypid(),
    'result' => $result,
    'busy_timeout' => $busyTimeout,
    'action_elapsed_ms' => (hrtime(true) - $actionStartedAt) / 1_000_000,
], JSON_THROW_ON_ERROR);
