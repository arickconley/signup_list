<?php

use App\Models\AccountAccessChallenge;
use App\Models\PendingAccountAssociation;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:cleanup-expired {--limit= : Maximum rows to remove from each data set}', function () {
    $limit = min(
        max(1, (int) ($this->option('limit') ?? config('account-access.cleanup_batch_size'))),
        (int) config('account-access.cleanup_max_batch_size'),
    );
    [$challengesRemoved, $associationsRemoved, $sessionsRemoved] = app(ImmediateDatabaseTransaction::class)
        ->run(function () use ($limit): array {
            $expiredChallengeIds = AccountAccessChallenge::query()
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            $challengesRemoved = AccountAccessChallenge::query()
                ->whereIn('id', $expiredChallengeIds)
                ->delete();

            $expiredAssociationIds = PendingAccountAssociation::query()
                ->where('created_at', '<=', now()->subDays((int) config('account-access.pending_association_retention_days')))
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            $associationsRemoved = PendingAccountAssociation::query()
                ->whereIn('id', $expiredAssociationIds)
                ->delete();

            $sessionTable = (string) config('session.table');
            $expiredSessionIds = DB::table($sessionTable)
                ->where('last_activity', '<=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            $sessionsRemoved = DB::table($sessionTable)
                ->whereIn('id', $expiredSessionIds)
                ->delete();

            return [$challengesRemoved, $associationsRemoved, $sessionsRemoved];
        });

    $this->info("Expired cleanup complete: challenges={$challengesRemoved}, pending_associations={$associationsRemoved}, sessions={$sessionsRemoved}.");
    Log::info('maintenance.cleanup_completed', [
        'account_access_challenges' => $challengesRemoved,
        'pending_account_associations' => $associationsRemoved,
        'sessions' => $sessionsRemoved,
        'limit_per_data_set' => $limit,
    ]);
})->purpose('Remove expired operational data');

Schedule::command('app:cleanup-expired')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('app:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
