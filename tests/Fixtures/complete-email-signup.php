<?php

use App\Actions\CompleteOpenSignup;
use App\Data\CompleteSignupInput;
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
    app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
        sheetPublicId: $sheetPublicId,
        name: $name,
        phone: null,
        optionPublicIds: [$optionPublicId],
        email: $email,
        ipAddress: '192.0.2.20',
    ));

    $result = 'success';
} catch (CannotCompleteSignup $exception) {
    $result = str_contains($exception->getMessage(), 'Signup Sheet is busy')
        ? 'busy'
        : 'rejected';
} catch (Throwable) {
    $result = 'unexpected-error';
}

echo json_encode([
    'pid' => getmypid(),
    'result' => $result,
], JSON_THROW_ON_ERROR);
