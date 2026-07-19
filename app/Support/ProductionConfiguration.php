<?php

namespace App\Support;

use Cron\CronExpression;
use Illuminate\Encryption\Encrypter;

final class ProductionConfiguration
{
    /** @return list<string> */
    public function errors(): array
    {
        $errors = [];

        if (config('app.env') !== 'production') {
            $errors[] = 'APP_ENV must be production.';
        }

        if (config('app.debug') !== false) {
            $errors[] = 'APP_DEBUG must be false.';
        }

        if (! $this->hasStrongApplicationKey(config('app.key'), config('app.cipher'))) {
            $errors[] = 'APP_KEY must contain a strong generated application key.';
        }

        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $errors[] = 'APP_URL must use HTTPS.';
        }

        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        $termination = config('deployment.https.termination');

        if (! in_array($termination, ['direct', 'proxy'], true)) {
            $errors[] = 'HTTPS_TERMINATION must be direct or proxy.';
        } elseif ($termination === 'proxy' && config('deployment.https.trusted_proxies') === []) {
            $errors[] = 'TRUSTED_PROXIES must list the trusted HTTPS proxy addresses.';
        }

        $databasePath = (string) config('database.connections.sqlite.database');
        $persistentDiskPath = (string) config('deployment.persistent_disk_path');

        if (config('database.default') !== 'sqlite') {
            $errors[] = 'DB_CONNECTION must be sqlite.';
        }

        if (! $this->isAbsolutePath($databasePath) || $databasePath === ':memory:') {
            $errors[] = 'DB_DATABASE must be an absolute path on the persistent disk.';
        }

        if (! $this->isAbsolutePath($persistentDiskPath)) {
            $errors[] = 'PERSISTENT_DISK_PATH must be an absolute mounted-disk path.';
        } elseif (! $this->isPathInside($databasePath, $persistentDiskPath)) {
            $errors[] = 'DB_DATABASE must be inside PERSISTENT_DISK_PATH.';
        }

        if (config('queue.default') !== 'database') {
            $errors[] = 'QUEUE_CONNECTION must be database.';
        }

        if (config('session.driver') !== 'database') {
            $errors[] = 'SESSION_DRIVER must be database.';
        }

        if (config('cache.default') !== 'database') {
            $errors[] = 'CACHE_STORE must be database.';
        }

        foreach (['web' => 'WEB_INSTANCES', 'queue' => 'QUEUE_WORKERS', 'scheduler' => 'SCHEDULER_PROCESSES'] as $process => $variable) {
            if (config("deployment.processes.{$process}") !== 1) {
                $errors[] = "{$variable} must be exactly 1.";
            }
        }

        $heartbeatPath = (string) config('deployment.scheduler.heartbeat_path');

        if (! $this->isAbsolutePath($heartbeatPath)) {
            $errors[] = 'SCHEDULER_HEARTBEAT_PATH must be an absolute path on the persistent disk.';
        } elseif (! $this->isPathInside($heartbeatPath, $persistentDiskPath)) {
            $errors[] = 'SCHEDULER_HEARTBEAT_PATH must be inside PERSISTENT_DISK_PATH.';
        }

        if (config('deployment.scheduler.heartbeat_max_age_minutes', 0) < 1) {
            $errors[] = 'SCHEDULER_HEARTBEAT_MAX_AGE_MINUTES must be positive.';
        }

        if (blank(config('deployment.mail.provider'))) {
            $errors[] = 'MAIL_PROVIDER requires a human-selected provider.';
        }

        if (in_array(config('mail.default'), ['array', 'log'], true)) {
            $errors[] = 'MAIL_MAILER must use the selected production provider.';
        }

        $senderDomain = (string) config('deployment.mail.sender_domain');
        $fromAddress = (string) config('mail.from.address');

        if (! filter_var($senderDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $errors[] = 'MAIL_SENDER_DOMAIN requires a human-selected valid domain.';
        } elseif (strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1)) !== strtolower($senderDomain)) {
            $errors[] = 'MAIL_FROM_ADDRESS must use MAIL_SENDER_DOMAIN.';
        }

        if (! filter_var(config('deployment.mail.smoke_to'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'SMOKE_TEST_EMAIL requires a human-selected delivery destination.';
        }

        if (! $this->isAbsolutePath((string) config('deployment.backup.destination'))) {
            $errors[] = 'BACKUP_DESTINATION must be a human-selected absolute mounted path.';
        }

        if (blank(config('deployment.backup.age_recipient'))) {
            $errors[] = 'BACKUP_AGE_RECIPIENT requires the selected encryption recipient.';
        }

        if (! $this->isCronExpression(config('deployment.backup.schedule'))) {
            $errors[] = 'BACKUP_SCHEDULE requires a valid human-selected cron expression.';
        }

        if (config('deployment.backup.retention_days', 0) < 1) {
            $errors[] = 'BACKUP_RETENTION_DAYS requires a positive human-selected value.';
        }

        $restoreEvidencePath = (string) config('deployment.backup.restore_evidence_path');

        if (! $this->isAbsolutePath($restoreEvidencePath)) {
            $errors[] = 'BACKUP_RESTORE_EVIDENCE_PATH must be an absolute path on the persistent disk.';
        } elseif (! $this->isPathInside($restoreEvidencePath, $persistentDiskPath)) {
            $errors[] = 'BACKUP_RESTORE_EVIDENCE_PATH must be inside PERSISTENT_DISK_PATH.';
        }

        if (config('deployment.backup.restore_max_age_days', 0) < 1) {
            $errors[] = 'BACKUP_RESTORE_MAX_AGE_DAYS requires a positive human-selected value.';
        }

        if (! $this->isHttpsUrl(config('deployment.disposable_email_domains.source_url'))) {
            $errors[] = 'DISPOSABLE_EMAIL_BLOCKLIST_SOURCE_URL requires a human-selected HTTPS source.';
        }

        if (! $this->isCronExpression(config('deployment.disposable_email_domains.update_schedule'))) {
            $errors[] = 'DISPOSABLE_EMAIL_BLOCKLIST_UPDATE_SCHEDULE requires a valid human-selected cron expression.';
        }

        $sourceRef = (string) config('deployment.source.ref');

        if (preg_match('/\A[0-9a-f]{40}\z/i', $sourceRef) !== 1) {
            $errors[] = 'SOURCE_CODE_REF must be the exact deployed 40-character commit SHA.';
        }

        foreach (['url' => 'SOURCE_CODE_URL', 'license_url' => 'SOURCE_LICENSE_URL'] as $key => $variable) {
            $url = config("deployment.source.{$key}");

            if (! $this->isHttpsUrl($url) || ! str_contains((string) $url, $sourceRef)) {
                $errors[] = "{$variable} must be an HTTPS link containing SOURCE_CODE_REF.";
            }
        }

        return $errors;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isPathInside(string $path, string $directory): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $directory = $this->normalizeAbsolutePath($directory);

        if ($path === null || $directory === null || $path === $directory) {
            return false;
        }

        if (preg_match('/\A[A-Z]:\//i', $directory) === 1) {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }

        return str_starts_with($path, rtrim($directory, '/').'/');
    }

    private function normalizeAbsolutePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('/\A([A-Za-z]:)\/(.*)\z/', $path, $matches) === 1) {
            $prefix = strtoupper($matches[1]).'/';
            $path = $matches[2];
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        } else {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }

    private function hasStrongApplicationKey(mixed $configuredKey, mixed $cipher): bool
    {
        if (! is_string($configuredKey) || ! is_string($cipher)) {
            return false;
        }

        $key = str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), strict: true)
            : $configuredKey;

        return is_string($key)
            && Encrypter::supported($key, $cipher)
            && strlen(count_chars($key, 3)) >= 16;
    }

    private function isCronExpression(mixed $expression): bool
    {
        return is_string($expression)
            && CronExpression::isValidExpression($expression);
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }
}
