<?php

use App\Actions\CompleteUnregisteredSignup;
use App\Exceptions\CannotCompleteSignup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $databasePath, $sheetPublicId, $optionPublicId, $name, $email, $readyPath, $gatePath] = $argv;

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $databasePath);
config()->set('database.connections.sqlite.busy_timeout', 100);
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
DB::purge('sqlite');

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
    app(CompleteUnregisteredSignup::class)->handle(
        $sheetPublicId,
        $name,
        null,
        [$optionPublicId],
        $email,
        '192.0.2.20',
    );

    $result = 'success';
} catch (CannotCompleteSignup $exception) {
    $result = str_contains($exception->getMessage(), 'signup list is busy')
        ? 'busy'
        : 'rejected';
} catch (Throwable) {
    $result = 'unexpected-error';
}

echo json_encode([
    'pid' => getmypid(),
    'result' => $result,
], JSON_THROW_ON_ERROR);
