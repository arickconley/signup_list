# Operations

## Production configuration and smoke check

Set the production values in `.env` (HTTPS `APP_URL`, secure cookies, generated `APP_KEY`, mounted `PERSISTENT_DISK_PATH`, and exactly one web, queue, and scheduler process). Choose the mail provider, sender domain/DNS records, smoke destination, disposable-domain blocklist source/cadence, encrypted backup destination/recipient/schedule/retention, and restore-evidence path. These are human deployment decisions; this repository supplies validation only and never performs DNS, provisioning, or deployment.

Validate without changing application data:

```sh
php artisan app:production-check
php artisan app:production-smoke
```

The smoke check verifies the live HTTPS response discloses the exact deployed source ref, AGPL license, and no-warranty notice; writes a disposable SQLite probe, reopens the connection, verifies the probe, and removes it; queues a mail test; checks the scheduler heartbeat; and validates fresh operator-written encrypted restore evidence. It does not send mail synchronously, mutate domain data, restore backups, or select providers.

Backups must be encrypted with the human-selected recipient, retained for the configured period, and periodically restored into an isolated location. Record the restore timestamp, backup name, SHA-256, encryption status, and SQLite integrity result at `BACKUP_RESTORE_EVIDENCE_PATH` for the smoke check. Configure provider DNS (SPF, DKIM, DMARC) and verify the selected sender domain before enabling production mail.

Production supports one application instance and one persistent SQLite database. The web process, scheduler, and queue worker must mount the same database file. Keep these defaults unless the deployment architecture changes:

```dotenv
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Do not run this SQLite deployment across multiple application instances or ephemeral disks.

## Scheduler and cleanup

Run exactly one scheduler process under the process supervisor:

```sh
php artisan schedule:work
```

A cron alternative is `php artisan schedule:run` every minute. Cleanup runs daily at 02:00 in the application timezone, uses the database cache lock for `onOneServer`, and prevents overlapping runs.

The scheduled `app:scheduler-heartbeat` command runs every minute and atomically records its timestamp at `SCHEDULER_HEARTBEAT_PATH`. The production smoke check requires that recorded timestamp to be recent; a touched or malformed file is not sufficient evidence.

Manual cleanup is safe and rerunnable:

```sh
php artisan app:cleanup-expired --limit=500
```

The limit applies independently to access challenges, pending Account Associations, and sessions. The default is 500 and the hard maximum is 1,000. Repeat the command until its counts are zero when clearing a backlog. If a stale scheduler mutex remains after confirming no cleanup is running, use `php artisan schedule:clear-cache`.

Retention policy:

- Account access codes and links are removed after their 15-minute expiration.
- Database sessions are removed at the configured inactivity lifetime (`SESSION_LIFETIME`).
- Pending Account Associations are retained for 30 days, then only the provisional association is removed.
- A completed Signup, its snapshots, and its Option Claims are never removed because Account verification remained pending.

## Queue worker

Run one database queue worker under the process supervisor:

```sh
php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=60 --max-time=3600
```

The 60-second worker timeout stays below the database queue's 90-second `retry_after`. Restart workers during deploys with `php artisan queue:restart`.

Inspect and recover failed jobs deliberately:

```sh
php artisan queue:failed
php artisan queue:retry <uuid>
php artisan queue:forget <uuid>
```

Fix the underlying mail, database, or application failure before retrying. Prefer targeted UUID retries over retrying every failed job.

## Logs and alerts

Application logs use stable event names with structured context:

| Event | Level | Meaning |
| --- | --- | --- |
| `maintenance.cleanup_completed` | info | Per-data-set cleanup counts and applied limit |
| `queue.job_failed` | error | A queued job exhausted attempts |
| `mail.job_failed` | error | A queued Mailable or queued Notification failed |
| `mail.dispatch_failed` | error | A Signup access message could not be queued |
| `sqlite.lock_retry` | warning | A bounded SQLite lock retry will occur |
| `sqlite.lock_failed` | error | SQLite stayed locked through all retries |
| `signup.throttled` | warning | A Signup submission or duplicate access message was rate-limited |
| `signup.owner_removal` | info | Owner removal type and affected Signup/Option Claim volume |

Alert immediately on `queue.job_failed`, `mail.job_failed`, or `sqlite.lock_failed`. Investigate repeated `sqlite.lock_retry` events (for example, five in five minutes), sustained `signup.throttled` spikes, unusual Owner-removal volume, cleanup failures, or cleanup counts repeatedly reaching the configured limit. Avoid alerting on a single normal throttle or removal event.
