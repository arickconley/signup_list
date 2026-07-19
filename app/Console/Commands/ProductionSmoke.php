<?php

namespace App\Console\Commands;

use App\Mail\ProductionSmokeMail;
use App\Support\ProductionConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

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
        try {
            $statement = DB::connection()->getPdo()->query('SELECT 1');

            return $statement !== false && $statement->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
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

        return is_file($path) && filemtime($path) >= now()->subMinutes((int) config('deployment.scheduler.heartbeat_max_age_minutes'))->timestamp;
    }

    private function restoreEvidence(): bool
    {
        $path = (string) config('deployment.backup.restore_evidence_path');
        if (! is_file($path)) {
            return false;
        }
        $evidence = json_decode((string) File::get($path), true);

        return is_array($evidence) && ($evidence['integrity_check'] ?? null) === 'ok' && ($evidence['encrypted'] ?? false) === true
            && isset($evidence['restored_at'], $evidence['sha256']);
    }
}
