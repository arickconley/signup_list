<?php

use App\Actions\CompleteOpenSignup;
use App\Data\CompleteSignupInput;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $databasePath, $sheetPublicId, $optionPublicId, $readyPath, $releasePath] = $argv;

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $databasePath);
config()->set('database.connections.sqlite.busy_timeout', 100);
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
DB::purge('sqlite');

try {
    app(ImmediateDatabaseTransaction::class)->run(
        function () use ($sheetPublicId, $optionPublicId, $readyPath, $releasePath): void {
            app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
                sheetPublicId: $sheetPublicId,
                name: 'Concurrent participant',
                phone: '555-0199',
                optionPublicIds: [$optionPublicId],
                nameConsent: true,
                phoneConsent: true,
            ));

            file_put_contents($readyPath, (string) getmypid());

            $releaseDeadline = microtime(true) + 10;

            while (! file_exists($releasePath) && microtime(true) < $releaseDeadline) {
                usleep(5_000);
            }

            if (! file_exists($releasePath)) {
                throw new RuntimeException('Timed out waiting to release the held Signup transaction.');
            }
        },
    );

    echo json_encode(['result' => 'committed'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'result' => 'failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));

    exit(1);
}
