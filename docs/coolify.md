# Coolify deployment

This repository deploys through Coolify's Docker Compose build pack. One container runs Apache, exactly one database queue worker, and exactly one scheduler against a named persistent SQLite volume. Do not scale the service above one replica or enable rolling deployments.

## Create the resource

1. Create a Docker Compose application from this Git repository and select `compose.coolify.yaml`.
2. Assign the public HTTPS domain to the `signup` service on port 80.
3. Set every variable marked required by the Compose file. Generate `APP_KEY` with `php artisan key:generate --show`; never reuse the local key.
4. Set `SOURCE_CODE_REF` to the deployed 40-character commit SHA and point `SOURCE_CODE_URL` and `SOURCE_LICENSE_URL` at that exact revision.
5. Deploy. The entrypoint validates production configuration and runs migrations before starting the web, queue, and scheduler processes.

The named `signup-data` volume stores the SQLite database, scheduler heartbeat, restore evidence, and the default local backup directory. Coolify application volumes are not database-backup resources: configure an encrypted off-host backup using the selected `BACKUP_AGE_RECIPIENT`, schedule, retention, and destination before treating the deployment as production-ready.

## Verify

After the scheduler has written a heartbeat and a real encrypted backup has been restored and documented at `BACKUP_RESTORE_EVIDENCE_PATH`, open a terminal for the `signup` service and run:

```sh
php artisan app:production-check
php artisan app:production-smoke
php artisan queue:failed
```

The health endpoint is `/up`. See [operations.md](operations.md) for mail DNS, backups, restore evidence, queue recovery, logs, and alerts.
