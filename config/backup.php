<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Set this to null to use Laravel's default database connection.
    |
    */
    'connection' => env('SMART_BACKUP_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Backup Mode
    |--------------------------------------------------------------------------
    |
    | Supported modes:
    | - full
    | - incremental
    |
    */
    'mode' => env('SMART_BACKUP_MODE', 'full'),

    /*
    |--------------------------------------------------------------------------
    | Output Format
    |--------------------------------------------------------------------------
    |
    | Supported formats:
    | - sql
    | - json
    | - csv
    |
    */
    'format' => env('SMART_BACKUP_FORMAT', 'sql'),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Number of rows processed per chunk when backing up large tables.
    |
    */
    'chunk_size' => (int) env('SMART_BACKUP_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Incremental Backup
    |--------------------------------------------------------------------------
    |
    | These settings are used when the package runs in incremental mode.
    | "last_backup_at" acts as the lower bound for change detection.
    |
    */
    'incremental' => [
        'last_backup_at' => env('SMART_BACKUP_LAST_BACKUP_AT'),
        'columns' => [
            'created_at',
            'updated_at',
            'deleted_at',
        ],
        'missing_timestamps' => env('SMART_BACKUP_INCREMENTAL_MISSING_TIMESTAMPS', 'full'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | If "include" is empty, all tables are considered except those listed
    | in "exclude".
    |
    */
    'tables' => [
        'include' => [],
        'exclude' => [
            'migrations',
            'jobs',
            'failed_jobs',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Defines where backup files should be stored.
    |
    */
    'storage' => [
        'disk' => env('SMART_BACKUP_DISK', 'local'),
        'path' => env('SMART_BACKUP_PATH', 'backups/database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Supported frequency values can map cleanly to Laravel scheduler methods:
    | - hourly
    | - daily
    | - weekly
    | - monthly
    |
    | "time" is mainly used for daily, weekly, and monthly schedules.
    |
    */
    'schedule' => [
        'enabled' => (bool) env('SMART_BACKUP_SCHEDULE_ENABLED', false),
        'frequency' => env('SMART_BACKUP_SCHEDULE_FREQUENCY', 'daily'),
        'hourly_minute' => (int) env('SMART_BACKUP_SCHEDULE_HOURLY_MINUTE', 0),
        'time' => env('SMART_BACKUP_SCHEDULE_TIME', '02:00'),
        'day_of_week' => (int) env('SMART_BACKUP_SCHEDULE_DAY_OF_WEEK', 0),
        'day_of_month' => (int) env('SMART_BACKUP_SCHEDULE_DAY_OF_MONTH', 1),
        'timezone' => env('SMART_BACKUP_SCHEDULE_TIMEZONE', config('app.timezone')),
        'mode' => env('SMART_BACKUP_SCHEDULE_MODE'),
        'format' => env('SMART_BACKUP_SCHEDULE_FORMAT'),
        'driver' => env('SMART_BACKUP_SCHEDULE_DRIVER'),
        'tables' => [],
        'without_overlapping' => (bool) env('SMART_BACKUP_SCHEDULE_WITHOUT_OVERLAPPING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will put the application into maintenance
    | mode before backup and restore operations, then bring it back up once
    | the operation has completed.
    |
    */
    'maintenance' => [
        'enabled' => (bool) env('SMART_BACKUP_MAINTENANCE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    |
    | Configure restore-specific behavior, including optional password
    | protection for restore commands.
    |
    */
    'restore' => [
        'disk' => env('SMART_BACKUP_RESTORE_DISK', env('SMART_BACKUP_DISK', 'local')),
        'password' => env('SMART_BACKUP_RESTORE_PASSWORD'),
        'disable_foreign_key_constraints' => (bool) env('SMART_BACKUP_RESTORE_DISABLE_FOREIGN_KEYS', true),
        'insert_batch_size' => (int) env('SMART_BACKUP_RESTORE_INSERT_BATCH_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience
    |--------------------------------------------------------------------------
    |
    | Retry handling for failed table backups and restore/backup recovery.
    |
    */
    'resilience' => [
        'backup_retry_attempts' => (int) env('SMART_BACKUP_RETRY_ATTEMPTS', 1),
        'retry_sleep_microseconds' => (int) env('SMART_BACKUP_RETRY_SLEEP_MICROSECONDS', 250000),
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Routes
    |--------------------------------------------------------------------------
    |
    | These routes can back a simple dashboard or JSON-driven frontend.
    | Prefix and middleware are configurable to fit the host application.
    |
    */
    'ui' => [
        'enabled' => (bool) env('SMART_BACKUP_UI_ENABLED', false),
        'prefix' => env('SMART_BACKUP_UI_PREFIX', 'smart-backup'),
        'middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env('SMART_BACKUP_UI_MIDDLEWARE', 'web'))))),
        'name_prefix' => env('SMART_BACKUP_UI_NAME_PREFIX', 'smart-backup.'),
        'dispatch_after_response' => (bool) env('SMART_BACKUP_UI_DISPATCH_AFTER_RESPONSE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Drivers may provide format-specific or destination-specific behavior.
    |
    */
    'drivers' => [
        'default' => env('SMART_BACKUP_DRIVER', 'full'),
        'full' => [
            'class' => \BhavneeshGoyal\LaravelSmartBackup\Drivers\FullBackupDriver::class,
        ],
        'incremental' => [
            'class' => \BhavneeshGoyal\LaravelSmartBackup\Drivers\IncrementalBackupDriver::class,
        ],
        'local' => [
            'class' => \BhavneeshGoyal\LaravelSmartBackup\Drivers\LocalBackupDriver::class,
        ],
    ],
];
