<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers;

use BhavneeshGoyal\LaravelSmartBackup\Drivers\Concerns\InteractsWithDatabaseTables;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupStorageService;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class FullBackupDriver implements BackupDriver
{
    use InteractsWithDatabaseTables;

    public function __construct(
        protected Config $config,
        protected DatabaseManager $database,
        protected BackupStorageService $storage
    ) {
    }

    public function name(): string
    {
        return 'full';
    }

    public function backupTable(string $table, string $mode, array $context = []): array
    {
        if ($mode !== 'full') {
            throw new InvalidArgumentException('FullBackupDriver only supports full mode.');
        }

        $format = (string) ($context['format'] ?? $this->config->get('backup.format', 'sql'));

        if (! in_array($format, ['sql', 'json', 'csv'], true)) {
            throw new InvalidArgumentException(sprintf(
                'FullBackupDriver only supports [sql, json, csv] export. [%s] given.',
                $format
            ));
        }

        $connectionName = $context['connection'] ?? $this->config->get('backup.connection');
        $disk = (string) ($context['disk'] ?? $this->config->get('backup.storage.disk', 'local'));
        $basePath = (string) ($context['path'] ?? $this->config->get('backup.storage.path', 'backups/database'));
        $chunkSize = max(1, (int) ($context['chunk_size'] ?? $this->config->get('backup.chunk_size', 1000)));
        $startedAt = $this->resolveTimestamp($context['started_at'] ?? null) ?? now();

        $connection = $this->database->connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $columns = $schema->getColumnListing($table);
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
                fputcsv($stream, $columns);
            }

            $query = $connection->table($table);

            if ($primaryKey !== null) {
                $query->orderBy($primaryKey)->chunkById($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $columns,
                    $format,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;
                    $preparedRows = [];

                    foreach ($rows as $row) {
                        $preparedRows[] = (array) $row;
                    }

                    $this->writeChunk($stream, $table, $columns, $preparedRows, $format, $writtenRows > 0);
                    $writtenRows += count($preparedRows);
                }, $primaryKey);
            } else {
                $orderColumn = Arr::first($columns);

                if ($orderColumn !== null) {
                    $query->orderBy($orderColumn);
                }

                $query->chunk($chunkSize, function ($rows) use (
                    $stream,
                    $table,
                    $columns,
                    $format,
                    &$writtenRows,
                    &$chunkCount
                ) {
                    $chunkCount++;
                    $preparedRows = [];

                    foreach ($rows as $row) {
                        $preparedRows[] = (array) $row;
                    }

                    $this->writeChunk($stream, $table, $columns, $preparedRows, $format, $writtenRows > 0);
                    $writtenRows += count($preparedRows);
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
            'columns' => $columns,
            'backup_started_at' => $startedAt->toDateTimeString(),
            'rows' => $writtenRows,
            'chunks' => $chunkCount,
            'status' => 'completed',
        ];
    }

    protected function writeChunk($stream, string $table, array $columns, array $rows, string $format, bool $prependSeparator): void
    {
        if ($format === 'json') {
            foreach ($rows as $index => $row) {
                if ($prependSeparator || $index > 0) {
                    fwrite($stream, ",\n");
                }

                fwrite($stream, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return;
        }

        if ($format === 'csv') {
            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    static fn (string $column) => $row[$column] ?? null,
                    $columns
                ));
            }

            return;
        }

        fwrite($stream, $this->buildInsertStatement($table, $columns, $rows));
    }

    protected function buildInsertStatement(string $table, array $columns, array $rows): string
    {
        $wrappedColumns = array_map(
            static fn (string $column) => '`' . str_replace('`', '``', $column) . '`',
            $columns
        );

        $values = array_map(function (array $row) use ($columns): string {
            $serialized = array_map(function (string $column) use ($row): string {
                return $this->sqlValue($row[$column] ?? null);
            }, $columns);

            return '(' . implode(', ', $serialized) . ')';
        }, $rows);

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES %s;\n",
            str_replace('`', '``', $table),
            implode(', ', $wrappedColumns),
            implode(",\n", $values)
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
