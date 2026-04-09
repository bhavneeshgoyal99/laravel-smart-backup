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

        $format = (string) ($context['format'] ?? $this->config->get('backup.format', 'json'));

        if (! in_array($format, ['sql', 'json', 'csv'], true)) {
            throw new InvalidArgumentException(sprintf(
                'IncrementalBackupDriver only supports [sql, json, csv] export. [%s] given.',
                $format
            ));
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
        $relativePath = $this->storage->tableBackupPath($mode, $table, $format, $startedAt, $basePath);
        $temporaryFile = $this->storage->createTemporaryStream();
        $stream = $temporaryFile['stream'];
        $writtenRows = 0;
        $chunkCount = 0;

        try {
            if ($format === 'json') {
                fwrite($stream, "[\n");
            } elseif ($format === 'csv') {
                fputcsv($stream, array_merge(['__operation', '__change_column', '__change_timestamp'], $columns));
            }

            $query = $connection->table($table);
            $this->applyChangeWindow($query, $timestampColumns, $lastBackupAt, $startedAt);

            if ($primaryKey !== null) {
                $query->orderBy($primaryKey)->chunkById($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $columns,
                    $format,
                    $primaryKey,
                    $timestampColumns,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;
                    $payloads = [];

                    foreach ($rows as $row) {
                        $payloads[] = $this->buildRowPayload($table, (array) $row, $timestampColumns);
                    }

                    $this->writeChunk(
                        $stream,
                        $table,
                        $columns,
                        $payloads,
                        $format,
                        $primaryKey,
                        $writtenRows > 0
                    );
                    $writtenRows += count($payloads);
                }, $primaryKey);
            } else {
                $query->orderBy(Arr::first($timestampColumns))->chunk($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $columns,
                    $format,
                    $primaryKey,
                    $timestampColumns,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;
                    $payloads = [];

                    foreach ($rows as $row) {
                        $payloads[] = $this->buildRowPayload($table, (array) $row, $timestampColumns);
                    }

                    $this->writeChunk(
                        $stream,
                        $table,
                        $columns,
                        $payloads,
                        $format,
                        $primaryKey,
                        $writtenRows > 0
                    );
                    $writtenRows += count($payloads);
                });
            }

            if ($format === 'json') {
                fwrite($stream, "]\n");
            }

            $this->storage->writeStream($relativePath, $stream, $disk);
        } finally {
            $this->storage->cleanupTemporaryStream($temporaryFile);
        }

        return [
            'table' => $table,
            'mode' => $mode,
            'driver' => $this->name(),
            'format' => $format,
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

    protected function writeChunk(
        $stream,
        string $table,
        array $columns,
        array $payloads,
        string $format,
        ?string $primaryKey,
        bool $prependSeparator
    ): void {
        if ($format === 'json') {
            foreach ($payloads as $index => $payload) {
                if ($prependSeparator || $index > 0) {
                    fwrite($stream, ",\n");
                }

                fwrite($stream, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return;
        }

        if ($format === 'csv') {
            foreach ($payloads as $payload) {
                $row = $payload['data'] ?? [];

                fputcsv($stream, array_merge(
                    [
                        $payload['operation'] ?? 'upsert',
                        $payload['change_column'] ?? null,
                        $payload['change_timestamp'] ?? null,
                    ],
                    array_map(
                    static fn (string $column) => $row[$column] ?? null,
                    $columns
                    )
                ));
            }

            return;
        }

        foreach ($payloads as $payload) {
            fwrite($stream, $this->buildSqlStatement(
                $table,
                $payload,
                $columns,
                $primaryKey
            ));
        }
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

    protected function buildSqlStatement(string $table, array $payload, array $columns, ?string $primaryKey): string
    {
        $row = $payload['data'] ?? [];
        $operation = $payload['operation'] ?? 'upsert';

        if ($operation === 'deleted') {
            return $this->buildDeleteStatement($table, $row, $columns, $primaryKey);
        }

        return $this->buildInsertStatement($table, $row, $columns);
    }

    protected function buildInsertStatement(string $table, array $row, array $columns): string
    {
        $wrappedColumns = array_map(
            static fn (string $column) => '`' . str_replace('`', '``', $column) . '`',
            $columns
        );

        $values = array_map(function (string $column) use ($row): string {
            return $this->sqlValue($row[$column] ?? null);
        }, $columns);

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s);\n",
            str_replace('`', '``', $table),
            implode(', ', $wrappedColumns),
            implode(', ', $values)
        );
    }

    protected function buildDeleteStatement(string $table, array $row, array $columns, ?string $primaryKey): string
    {
        $matchColumns = $primaryKey !== null && array_key_exists($primaryKey, $row)
            ? [$primaryKey]
            : $columns;

        $conditions = array_map(function (string $column) use ($row): string {
            $wrappedColumn = '`' . str_replace('`', '``', $column) . '`';
            $value = $row[$column] ?? null;

            if ($value === null) {
                return sprintf('%s IS NULL', $wrappedColumn);
            }

            return sprintf('%s = %s', $wrappedColumn, $this->sqlValue($value));
        }, $matchColumns);

        return sprintf(
            "DELETE FROM `%s` WHERE %s;\n",
            str_replace('`', '``', $table),
            implode(' AND ', $conditions)
        );
    }

    protected function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
