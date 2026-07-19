<?php

return [
    'https' => [
        'termination' => env('HTTPS_TERMINATION'),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        ))),
    ],

    'persistent_disk_path' => env('PERSISTENT_DISK_PATH'),

    'processes' => [
        'web' => (int) env('WEB_INSTANCES', 1),
        'queue' => (int) env('QUEUE_WORKERS', 1),
        'scheduler' => (int) env('SCHEDULER_PROCESSES', 1),
    ],

    'scheduler' => [
        'heartbeat_path' => env('SCHEDULER_HEARTBEAT_PATH'),
        'heartbeat_max_age_minutes' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_MINUTES', 0),
    ],

    'mail' => [
        'provider' => env('MAIL_PROVIDER'),
        'sender_domain' => env('MAIL_SENDER_DOMAIN'),
        'smoke_to' => env('SMOKE_TEST_EMAIL'),
    ],

    'backup' => [
        'destination' => env('BACKUP_DESTINATION'),
        'age_recipient' => env('BACKUP_AGE_RECIPIENT'),
        'schedule' => env('BACKUP_SCHEDULE'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 0),
        'restore_evidence_path' => env('BACKUP_RESTORE_EVIDENCE_PATH'),
        'restore_max_age_days' => (int) env('BACKUP_RESTORE_MAX_AGE_DAYS', 0),
    ],

    'disposable_email_domains' => [
        'source_url' => env('DISPOSABLE_EMAIL_BLOCKLIST_SOURCE_URL'),
        'update_schedule' => env('DISPOSABLE_EMAIL_BLOCKLIST_UPDATE_SCHEDULE'),
    ],

    'source' => [
        'ref' => env('SOURCE_CODE_REF'),
        'url' => env('SOURCE_CODE_URL'),
        'license_url' => env('SOURCE_LICENSE_URL'),
    ],
];
