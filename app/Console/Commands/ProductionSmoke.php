<?php

namespace App\Console\Commands;

use App\Mail\ProductionSmokeMail;
use App\Support\ProductionConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ProductionSmoke extends Command
{
    protected $signature = 'app:production-smoke';

    protected $description = 'Run a non-destructive production readiness smoke check';

    public function handle(ProductionConfiguration $configuration): int
    {
        if ($configuration->errors() !== []) {
            $this->error('Production configuration is not ready.');

            return self::FAILURE;
        }

        $checks = [
            'HTTPS and source links' => $this->httpsAndSourceLinks(),
            'SQLite persistent database' => $this->database(),
            'Mail smoke message queued' => $this->mail(),
            'Scheduler heartbeat' => $this->heartbeat(),
            'Restore evidence' => $this->restoreEvidence(),
        ];

        foreach ($checks as $label => $passed) {
            $this->line($label.': '.($passed ? 'PASS' : 'FAIL'));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function httpsAndSourceLinks(): bool
    {
        $response = Http::timeout(10)->get(rtrim((string) config('app.url'), '/').'/');
        $ref = (string) config('deployment.source.ref');

        return $response->successful()
            && $response->body() !== ''
            && str_contains($response->body(), (string) config('deployment.source.url'))
            && str_contains($response->body(), (string) config('deployment.source.license_url'))
            && str_contains($response->body(), $ref)
            && str_contains($response->body(), 'No warranty');
    }

    private function database(): bool
    {
        $connectionName = (string) config('database.default');
        $probeTable = 'production_smoke_'.Str::lower(Str::random(16));
        $probeValue = (string) Str::uuid();
        $probeCreated = false;

        try {
            $connection = DB::connection($connectionName);

            if ($connection->getDriverName() !== 'sqlite') {
                return false;
            }

            $connection->statement("CREATE TABLE \"{$probeTable}\" (value TEXT NOT NULL)");
            $probeCreated = true;
            $connection->table($probeTable)->insert(['value' => $probeValue]);

            DB::purge($connectionName);

            return DB::connection($connectionName)
                ->table($probeTable)
                ->where('value', $probeValue)
                ->exists();
        } catch (\Throwable) {
            return false;
        } finally {
            if ($probeCreated) {
                try {
                    DB::connection($connectionName)
                        ->statement("DROP TABLE IF EXISTS \"{$probeTable}\"");
                } catch (\Throwable) {
                    // The failed reopen is already reported by the smoke result.
                }
            }
        }
    }

    private function mail(): bool
    {
        try {
            Mail::to((string) config('deployment.mail.smoke_to'))->queue(new ProductionSmokeMail);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function heartbeat(): bool
    {
        $path = (string) config('deployment.scheduler.heartbeat_path');

        if (! is_file($path)) {
            return false;
        }

        $evidence = json_decode((string) File::get($path), true);

        if (! is_array($evidence) || ! is_string($evidence['recorded_at'] ?? null)) {
            return false;
        }

        try {
            $recordedAt = Date::parse($evidence['recorded_at']);
        } catch (\Throwable) {
            return false;
        }

        return $recordedAt->betweenIncluded(
            now()->subMinutes((int) config('deployment.scheduler.heartbeat_max_age_minutes')),
            now(),
        );
    }

    private function restoreEvidence(): bool
    {
        $path = (string) config('deployment.backup.restore_evidence_path');
        if (! is_file($path)) {
            return false;
        }
        $evidence = json_decode((string) File::get($path), true);

        if (! is_array($evidence)
            || ($evidence['integrity_check'] ?? null) !== 'ok'
            || ($evidence['encrypted'] ?? false) !== true
            || ! is_string($evidence['restored_at'] ?? null)
            || ! is_string($evidence['sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/i', $evidence['sha256']) !== 1
        ) {
            return false;
        }

        try {
            $restoredAt = Date::parse($evidence['restored_at']);
        } catch (\Throwable) {
            return false;
        }

        return $restoredAt->betweenIncluded(
            now()->subDays((int) config('deployment.backup.restore_max_age_days')),
            now(),
        );
    }
}
