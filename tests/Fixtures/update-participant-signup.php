<?php

use App\Actions\UpdateParticipantSignup;
use App\Data\UpdateParticipantSignupInput;
use App\Exceptions\CannotUpdateParticipantSignup;
use App\Models\Account;
use App\Models\Option;
use App\Models\Signup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $databasePath, $accountId, $signupId, $targetOptionPublicId, $readyPath, $gatePath] = $argv;

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $databasePath);
config()->set('database.connections.sqlite.busy_timeout', 100);
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
DB::purge('sqlite');

$busyTimeout = (int) DB::selectOne('PRAGMA busy_timeout')->timeout;
$account = Account::query()->findOrFail((int) $accountId);
$signup = Signup::query()->findOrFail((int) $signupId);
$originalOptionId = (int) $signup->optionClaims()->sole()->option_id;

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
    app(UpdateParticipantSignup::class)->handle($account, new UpdateParticipantSignupInput(
        signupId: $signup->id,
        name: $signup->name_snapshot,
        phone: $signup->phone_snapshot,
        optionPublicIds: [$targetOptionPublicId],
        nameConsent: $signup->name_consent,
        emailConsent: $signup->email_consent,
        phoneConsent: $signup->phone_consent,
    ));
    $result = 'success';
} catch (CannotUpdateParticipantSignup $exception) {
    $result = match (true) {
        in_array($targetOptionPublicId, $exception->unavailableOptionPublicIds, true) => 'unavailable',
        str_contains($exception->getMessage(), 'Signup Sheet is busy') => 'busy',
        default => 'rejected',
    };
} catch (Throwable) {
    $result = 'unexpected-error';
}

$observedTargetClaimedCount = (int) Option::query()
    ->where('public_id', $targetOptionPublicId)
    ->valueOrFail('claimed_count');

echo json_encode([
    'pid' => getmypid(),
    'signup_id' => $signup->id,
    'original_option_id' => $originalOptionId,
    'result' => $result,
    'busy_timeout' => $busyTimeout,
    'action_elapsed_ms' => (hrtime(true) - $actionStartedAt) / 1_000_000,
    'observed_target_claimed_count' => $observedTargetClaimedCount,
], JSON_THROW_ON_ERROR);
