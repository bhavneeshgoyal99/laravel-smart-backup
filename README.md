# Laravel Smart Backup

Lightweight Laravel package for full and incremental database backups with chunked processing, restore commands, scheduling support, and an optional Blade dashboard.

## Features

- Full table backups
- Incremental backups using `created_at`, `updated_at`, and `deleted_at`
- Chunked processing for large tables
- Laravel filesystem storage
- SQL and JSON export
- Restore from SQL, JSON, and JSONL backup files
- Scheduled backups with frequency, time, and timezone support
- Optional maintenance mode during backup and restore runs
- Optional Blade dashboard with backup list, run, restore, and settings screens
- Package migrations and config publishing

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require bhavneeshgoyal/laravel-smart-backup
```

Publish config and package assets if needed:

```bash
php artisan vendor:publish --tag=smart-backup-config
php artisan vendor:publish --tag=smart-backup-migrations
php artisan vendor:publish --tag=smart-backup-views
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

The package config lives at `config/backup.php`.

Main options:

- `mode`: `full` or `incremental`
- `format`: `sql`, `json`, `csv`
- `chunk_size`
- `tables.include`
- `tables.exclude`
- `storage.disk`
- `storage.path`
- `schedule.*`
- `maintenance.*`
- `restore.*`
- `ui.*`

Example:

```php
return [
    'mode' => 'full',
    'format' => 'sql',
    'chunk_size' => 1000,

    'tables' => [
        'include' => [],
        'exclude' => ['migrations', 'jobs', 'failed_jobs'],
    ],

    'storage' => [
        'disk' => 'local',
        'path' => 'backups/database',
    ],
];
```

## Running Backups

Run a backup manually:

```bash
php artisan backup:run
```

With runtime options:

```bash
php artisan backup:run --mode=incremental --format=json --tables=users --tables=orders
```

## Restoring Backups

Restore a backup file:

```bash
php artisan backup:restore backups/database/full/2026/04/07/users-20260407_010000.sql
```

Restore a specific table:

```bash
php artisan backup:restore backups/database/full/2026/04/07/users-20260407_010000.sql --table=users
```

With restore password:

```bash
php artisan backup:restore backups/database/full/2026/04/07/users-20260407_010000.sql --password=secret
```

## Scheduling

Enable scheduled backups in `config/backup.php`:

```php
'schedule' => [
    'enabled' => true,
    'frequency' => 'daily',
    'time' => '02:00',
    'timezone' => 'Asia/Kolkata',
],
```

Supported frequencies:

- `hourly`
- `daily`
- `weekly`
- `monthly`

The package registers itself with Laravel's scheduler when scheduling is enabled.

## Maintenance Mode

When enabled, the package will automatically run Laravel maintenance mode
before backup and restore operations, then bring the application back up
after the operation completes.

```php
'maintenance' => [
    'enabled' => true,
],
```

## Dashboard

Enable the Blade dashboard:

```php
'ui' => [
    'enabled' => true,
    'prefix' => 'smart-backup',
    'middleware' => explode(',', env('SMART_BACKUP_UI_MIDDLEWARE', 'web,auth')),
    'name_prefix' => env('SMART_BACKUP_UI_NAME_PREFIX', 'smart-backup.'),
],
```

Dashboard includes:

- Backup list
- Run backup form
- Restore backup form
- Settings page

## Storage Structure

Backups are stored in structured folders by mode and date.

Examples:

```text
backups/database/full/2026/04/07/users-20260407_010000.sql
backups/database/incremental/2026/04/07/orders-20260407_010000.jsonl
```

## Package Migrations

The package ships with migrations for backup metadata tables:

- `smart_backup_runs`
- `smart_backup_tables`

They are loaded automatically by the package and can also be published.

## Testing

The package includes a basic Orchestra Testbench setup with feature coverage for:

- provider bootstrapping
- core backup execution
- metadata persistence

Run tests with:

```bash
vendor/bin/phpunit
```

## Current Status

This package is now in much better shape for a first stable release.

Before tagging `v1.0.0`, I still recommend one final verification pass:

- Run the Testbench suite in a clean environment with dependencies installed
- Verify install and publish flow in a fresh Laravel app
- Decide whether CSV should be fully implemented or removed from the public surface for `v1`

## Release Recommendation

Recommended release path:

1. Run `composer install`
2. Run `vendor/bin/phpunit`
3. Smoke-test the package in a fresh Laravel app
4. If that passes, tag `v1.0.0`

If you want, the next step can be a final release-prep pass where I:
- trim compatibility files
- reduce the public surface to only supported formats
- prepare a release checklist or changelog
