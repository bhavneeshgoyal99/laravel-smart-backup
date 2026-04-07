<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers;

use BhavneeshGoyal\LaravelSmartBackup\Drivers\Concerns\InteractsWithDatabaseTables;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupStorageService;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

class IncrementalBackupDriver implements BackupDriver
{
    use InteractsWithDatabaseTables;

    public function __construct(
        protected Config $config,
        protected DatabaseManager $database,
        protected BackupStorageService $storage,
        protected Container $container
    ) {
    }

    public function name(): string
    {
        return 'incremental';
    }

    public function backupTable(string $table, string $mode, array $context = []): array
    {
        if ($mode !== 'incremental') {
            throw new InvalidArgumentException('IncrementalBackupDriver only supports incremental mode.');
        }

        $connectionName = $context['connection'] ?? $this->config->get('backup.connection');
        $disk = (string) ($context['disk'] ?? $this->config->get('backup.storage.disk', 'local'));
        $basePath = (string) ($context['path'] ?? $this->config->get('backup.storage.path', 'backups/database'));
        $chunkSize = max(1, (int) ($context['chunk_size'] ?? $this->config->get('backup.chunk_size', 1000)));
        $startedAt = $this->resolveTimestamp($context['started_at'] ?? null) ?? now();
        $lastBackupAt = $this->resolveTimestamp(
            $context['last_backup_at'] ?? $this->config->get('backup.incremental.last_backup_at')
        );

        if (! $lastBackupAt instanceof Carbon) {
            throw new InvalidArgumentException('Incremental backups require a valid last backup timestamp.');
        }

        $connection = $this->database->connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $columns = $schema->getColumnListing($table);
        $timestampColumns = $this->resolveTimestampColumns($columns);

        if ($timestampColumns === []) {
            return $this->handleMissingTimestampColumns($table, $context);
        }

        $primaryKey = $this->resolvePrimaryKey($schema, $table);
        $relativePath = $this->storage->tableBackupPath($mode, $table, 'jsonl', $startedAt, $basePath);
        $temporaryFile = $this->storage->createTemporaryStream();
        $stream = $temporaryFile['stream'];
        $writtenRows = 0;
        $chunkCount = 0;

        try {
            $query = $connection->table($table);
            $this->applyChangeWindow($query, $timestampColumns, $lastBackupAt, $startedAt);

            if ($primaryKey !== null) {
                $query->orderBy($primaryKey)->chunkById($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $timestampColumns,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;

                    foreach ($rows as $row) {
                        $payload = $this->buildRowPayload($table, (array) $row, $timestampColumns);
                        fwrite($stream, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                        $writtenRows++;
                    }
                }, $primaryKey);
            } else {
                $query->orderBy(Arr::first($timestampColumns))->chunk($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $timestampColumns,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;

                    foreach ($rows as $row) {
                        $payload = $this->buildRowPayload($table, (array) $row, $timestampColumns);
                        fwrite($stream, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                        $writtenRows++;
                    }
                });
            }

            $this->storage->writeStream($relativePath, $stream, $disk);
        } finally {
            $this->storage->cleanupTemporaryStream($temporaryFile);
        }

        return [
            'table' => $table,
            'mode' => $mode,
            'driver' => $this->name(),
            'disk' => $disk,
            'path' => $relativePath,
            'chunk_size' => $chunkSize,
            'primary_key' => $primaryKey,
            'timestamp_columns' => $timestampColumns,
            'last_backup_at' => $lastBackupAt->toDateTimeString(),
            'backup_started_at' => $startedAt->toDateTimeString(),
            'rows' => $writtenRows,
            'chunks' => $chunkCount,
            'status' => 'completed',
        ];
    }

    protected function resolveTimestampColumns(array $columns): array
    {
        $configured = (array) $this->config->get('backup.incremental.columns', []);

        return array_values(array_filter($configured, static fn (string $column) => in_array($column, $columns, true)));
    }

    protected function handleMissingTimestampColumns(string $table, array $context): array
    {
        $policy = (string) $this->config->get('backup.incremental.missing_timestamps', 'full');

        if ($policy === 'skip') {
            return [
                'table' => $table,
                'mode' => 'incremental',
                'driver' => $this->name(),
                'status' => 'skipped',
                'reason' => 'missing_timestamp_columns',
                'rows' => 0,
                'chunks' => 0,
            ];
        }

        if ($policy === 'full') {
            /** @var FullBackupDriver $driver */
            $driver = $this->container->make(FullBackupDriver::class);
            $result = $driver->backupTable($table, 'full', $context);
            $result['mode'] = 'incremental';
            $result['effective_mode'] = 'full';
            $result['fallback_reason'] = 'missing_timestamp_columns';

            return $result;
        }

        throw new RuntimeException(sprintf(
            'Table [%s] does not contain any configured incremental timestamp columns.',
            $table
        ));
    }
    protected function applyChangeWindow($query, array $timestampColumns, Carbon $lastBackupAt, Carbon $startedAt): void
    {
        $query->where(function ($builder) use ($timestampColumns, $lastBackupAt, $startedAt) {
            foreach ($timestampColumns as $column) {
                $builder->orWhere(function ($nested) use ($column, $lastBackupAt, $startedAt) {
                    $nested
                        ->whereNotNull($column)
                        ->where($column, '>', $lastBackupAt->toDateTimeString())
                        ->where($column, '<=', $startedAt->toDateTimeString());
                });
            }
        });
    }

    protected function buildRowPayload(string $table, array $row, array $timestampColumns): array
    {
        $changeColumn = null;
        $changeTimestamp = null;

        foreach ($timestampColumns as $column) {
            if (! empty($row[$column])) {
                $current = Carbon::parse((string) $row[$column]);

                if (! $changeTimestamp instanceof Carbon || $current->greaterThan($changeTimestamp)) {
                    $changeColumn = $column;
                    $changeTimestamp = $current;
                }
            }
        }

        return [
            'table' => $table,
            'operation' => $changeColumn === 'deleted_at' ? 'deleted' : 'upsert',
            'change_column' => $changeColumn,
            'change_timestamp' => $changeTimestamp?->toDateTimeString(),
            'data' => $row,
        ];
    }
}
