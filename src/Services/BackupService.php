<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use BhavneeshGoyal\LaravelSmartBackup\Drivers\DriverManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use Throwable;

class BackupService
{
    public function __construct(
        protected Config $config,
        protected DriverManager $driverManager,
        protected TableSelectionService $tableSelectionService,
        protected MaintenanceModeService $maintenanceModeService,
        protected BackupMetadataService $metadataService,
        protected LoggerInterface $logger,
        protected SettingsService $settings
    ) {
    }

    public function run(array $options = [], ?callable $progressCallback = null): array
    {
        $startedAt = now();
        $mode = $this->resolveMode($options['mode'] ?? null);
        $resolvedDriver = $this->driverManager->driver($options['driver'] ?? $this->resolveDriverName($mode));
        $tables = $this->tableSelectionService->resolve(
            $this->settings->get('connection'),
            $this->resolveIncludedTables($options),
            (array) $this->settings->get('tables.exclude', [])
        );
        $format = $this->resolveFormat($options['format'] ?? null);

        if ($mode === 'incremental' && $format !== 'json') {
            $format = 'json';
        }

        $metadata = [
            'status' => 'running',
            'mode' => $mode,
            'driver' => $resolvedDriver->name(),
            'format' => $format,
            'disk' => $this->settings->get('storage.disk'),
            'path' => $this->settings->get('storage.path'),
            'chunk_size' => (int) $this->settings->get('chunk_size', 1000),
            'connection' => $this->settings->get('connection'),
            'started_at' => $startedAt->toDateTimeString(),
            'selected_tables' => $tables,
            'maintenance_mode' => [
                'policy' => $this->settings->get('maintenance.policy'),
                'enabled' => false,
            ],
            'retry_attempts' => $this->retryAttempts(),
            'tables' => [],
        ];
        $runId = $this->metadataService->startRun($metadata);
        $metadata['run_id'] = $runId;

        $this->logger->info('Starting smart backup run.', [
            'mode' => $metadata['mode'],
            'driver' => $metadata['driver'],
            'format' => $metadata['format'],
            'table_count' => count($tables),
        ]);

        if (is_callable($progressCallback)) {
            $progressCallback('starting', [
                'total_tables' => count($tables),
                'tables' => $tables,
                'metadata' => $metadata,
            ]);
        }

        try {
            $this->maintenanceModeService->runSafely($mode, function (bool $maintenanceEnabled) use (
                $tables,
                $mode,
                $runId,
                $resolvedDriver,
                $startedAt,
                $progressCallback,
                &$metadata
            ) {
                $metadata['maintenance_mode']['enabled'] = $maintenanceEnabled;

                if ($maintenanceEnabled) {
                    $this->logger->info('Application entered maintenance mode for backup.');
                }

                foreach ($tables as $table) {
                    $tableRecordId = $this->metadataService->recordTableStart($runId, $table, $mode);

                    $this->logger->info('Backing up table.', [
                        'table' => $table,
                        'mode' => $mode,
                        'driver' => $metadata['driver'],
                    ]);

                    if (is_callable($progressCallback)) {
                        $progressCallback('table.starting', [
                            'table' => $table,
                            'metadata' => $metadata,
                        ]);
                    }

                    try {
                        $tableMetadata = $this->runTableBackupWithRetry($resolvedDriver, $table, $mode, [
                            'format' => $metadata['format'],
                            'disk' => $metadata['disk'],
                            'path' => $metadata['path'],
                            'chunk_size' => $metadata['chunk_size'],
                            'started_at' => $startedAt->toDateTimeString(),
                            'connection' => $metadata['connection'],
                            'last_backup_at' => $this->resolveLastBackupAt($table),
                        ], $progressCallback);
                    } catch (Throwable $exception) {
                        $this->metadataService->failTable($tableRecordId, $exception);

                        throw $exception;
                    }

                    $metadata['tables'][] = array_merge([
                        'table' => $table,
                        'status' => $tableMetadata['status'] ?? 'completed',
                    ], $tableMetadata);
                    $this->metadataService->finalizeTable(
                        $tableRecordId,
                        $tableMetadata,
                        $tableMetadata['last_backup_at'] ?? $startedAt
                    );

                    if (is_callable($progressCallback)) {
                        $progressCallback('table.completed', [
                            'table' => $table,
                            'result' => $tableMetadata,
                            'completed_tables' => count($metadata['tables']),
                            'total_tables' => count($tables),
                        ]);
                    }
                }

                $metadata['status'] = 'completed';
            });
        } catch (Throwable $exception) {
            $metadata['status'] = 'failed';
            $metadata['error'] = $exception->getMessage();

            $this->logger->error('Smart backup run failed.', [
                'message' => $exception->getMessage(),
            ]);

            if (is_callable($progressCallback)) {
                $progressCallback('failed', [
                    'error' => $exception->getMessage(),
                    'metadata' => $metadata,
                ]);
            }

            throw $exception;
        } finally {
            if ($metadata['status'] === 'completed' && $mode === 'incremental') {
                $this->settings->set('incremental.last_backup_at', $startedAt->toDateTimeString());
                $metadata['incremental_last_backup_at_updated_to'] = $startedAt->toDateTimeString();
            }

            $metadata['finished_at'] = now()->toDateTimeString();
            $metadata['table_count'] = count($metadata['tables']);
            $this->metadataService->finalizeRun($runId, $metadata);

            $this->logger->info('Smart backup run finished.', [
                'status' => $metadata['status'],
                'table_count' => $metadata['table_count'],
                'maintenance_mode' => $metadata['maintenance_mode']['enabled'],
            ]);

            if (is_callable($progressCallback)) {
                $progressCallback('finished', [
                    'metadata' => $metadata,
                ]);
            }
        }

        return $metadata;
    }

    protected function resolveMode(?string $mode = null): string
    {
        $mode = $mode ?? (string) $this->settings->get('mode', 'full');

        if (! in_array($mode, ['full', 'incremental'], true)) {
            return 'full';
        }

        return $mode;
    }

    protected function resolveFormat(?string $format = null): string
    {
        $format = $format ?? (string) $this->settings->get('format', 'sql');

        if (! in_array($format, ['sql', 'json', 'csv'], true)) {
            return 'sql';
        }

        return $format;
    }

    protected function resolveIncludedTables(array $options): array
    {
        $tables = $options['tables'] ?? null;

        if (! is_array($tables) || $tables === []) {
            return (array) $this->settings->get('tables.include', []);
        }

        $normalized = [];

        foreach ($tables as $tableSet) {
            foreach (explode(',', (string) $tableSet) as $table) {
                $table = trim($table);

                if ($table !== '') {
                    $normalized[] = $table;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function resolveDriverName(string $mode): ?string
    {
        return $mode === 'incremental' ? 'incremental' : 'full';
    }

    protected function runTableBackupWithRetry(
        object $driver,
        string $table,
        string $mode,
        array $context,
        ?callable $progressCallback = null
    ): array {
        $attempts = $this->retryAttempts();
        $sleepMicroseconds = (int) $this->config->get('backup.resilience.retry_sleep_microseconds', 250000);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $driver->backupTable($table, $mode, $context);
                $result['attempt'] = $attempt;

                return $result;
            } catch (Throwable $exception) {
                $lastException = $exception;

                $this->logger->warning('Table backup attempt failed.', [
                    'table' => $table,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'message' => $exception->getMessage(),
                ]);

                if (is_callable($progressCallback)) {
                    $progressCallback('table.retrying', [
                        'table' => $table,
                        'attempt' => $attempt,
                        'max_attempts' => $attempts,
                        'error' => $exception->getMessage(),
                    ]);
                }

                if ($attempt < $attempts && $sleepMicroseconds > 0) {
                    usleep($sleepMicroseconds);
                }
            }
        }

        throw $lastException ?? new \RuntimeException(sprintf('Backup failed for table [%s].', $table));
    }

    protected function retryAttempts(): int
    {
        return max(1, (int) $this->config->get('backup.resilience.backup_retry_attempts', 1));
    }

    protected function resolveLastBackupAt(string $table): ?string
    {
        $configured = $this->settings->get('incremental.last_backup_at');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return $this->metadataService->lastSuccessfulTableBackupAt($table);
    }
}
