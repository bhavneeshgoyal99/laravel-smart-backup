<?php

namespace BhavneeshGoyal\LaravelSmartBackup;

use BhavneeshGoyal\LaravelSmartBackup\Commands\BackupRunCommand;
use BhavneeshGoyal\LaravelSmartBackup\Commands\RestoreBackupCommand;
use BhavneeshGoyal\LaravelSmartBackup\Drivers\DriverManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupHistoryService;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupMetadataService;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupService;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupStorageService;
use BhavneeshGoyal\LaravelSmartBackup\Services\MaintenanceModeService;
use BhavneeshGoyal\LaravelSmartBackup\Services\RestoreService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SchedulerService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use BhavneeshGoyal\LaravelSmartBackup\Services\TableSelectionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/backup.php', 'backup');

        $this->app->singleton(DriverManager::class, function ($app) {
            return new DriverManager($app);
        });

        $this->app->singleton(TableSelectionService::class, function ($app) {
            return new TableSelectionService($app['db']);
        });

        $this->app->singleton(BackupStorageService::class, function ($app) {
            return new BackupStorageService(
                $app['config'],
                $app['filesystem']
            );
        });

        $this->app->singleton(SettingsService::class, function ($app) {
            return new SettingsService($app['config'], $app['db']);
        });

        $this->app->singleton(BackupHistoryService::class, function ($app) {
            return new BackupHistoryService(
                $app['db'],
                $app->make(BackupStorageService::class),
                $app['config'],
                $app->make(SettingsService::class)
            );
        });

        $this->app->singleton(BackupMetadataService::class, function ($app) {
            return new BackupMetadataService($app['db']);
        });

        $this->app->singleton(MaintenanceModeService::class, function ($app) {
            return new MaintenanceModeService(
                $app['config'],
                $app->make(\Illuminate\Contracts\Console\Kernel::class),
                $app
            );
        });

        $this->app->singleton(BackupService::class, function ($app) {
            return new BackupService(
                $app['config'],
                $app->make(DriverManager::class),
                $app->make(TableSelectionService::class),
                $app->make(MaintenanceModeService::class),
                $app->make(BackupMetadataService::class),
                $app['log'],
                $app->make(SettingsService::class)
            );
        });

        $this->app->singleton(BackupManager::class, function ($app) {
            return new BackupManager($app->make(BackupService::class));
        });

        $this->app->singleton(RestoreService::class, function ($app) {
            return new RestoreService(
                $app['config'],
                $app['db'],
                $app['filesystem']
            );
        });

        $this->app->singleton(SchedulerService::class, function ($app) {
            return new SchedulerService(
                $app['config'],
                $app->make(SettingsService::class)
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'smart-backup');

        $this->publishes([
            __DIR__ . '/../config/backup.php' => config_path('backup.php'),
        ], 'smart-backup-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'smart-backup-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/smart-backup'),
        ], 'smart-backup-views');

        $this->registerUiRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupRunCommand::class,
                RestoreBackupCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->app->make(SchedulerService::class)->register($schedule);
        });
    }

    protected function registerUiRoutes(): void
    {
        if (! $this->app['config']->get('backup.ui.enabled', false)) {
            return;
        }

        Route::middleware((array) $this->app['config']->get('backup.ui.middleware', ['web']))
            ->prefix((string) $this->app['config']->get('backup.ui.prefix', 'smart-backup'))
            ->as((string) $this->app['config']->get('backup.ui.name_prefix', 'smart-backup.'))
            ->group(__DIR__ . '/../routes/web.php');
    }
}
