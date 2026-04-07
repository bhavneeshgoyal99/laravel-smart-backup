<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class RestoreService
{
    public function __construct(
        protected Config $config,
        protected DatabaseManager $database,
        protected FilesystemManager $filesystem
    ) {
    }

    public function restore(array $options, ?callable $progressCallback = null): array
    {
        $path = $options['file'] ?? null;

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException('A backup file path is required.');
        }

        $this->guardPassword($options['password'] ?? null);

        $connectionName = $this->config->get('backup.connection');
        $table = $this->normalizeTable($options['table'] ?? null);
        $stream = $this->openReadStream($path, $options['disk'] ?? null);
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $metadata = [
            'status' => 'running',
            'file' => $path,
            'disk' => $options['disk'] ?? $this->config->get('backup.restore.disk'),
            'connection' => $connectionName,
            'table' => $table,
            'format' => $extension,
            'rows' => 0,
            'statements' => 0,
        ];

        if (is_callable($progressCallback)) {
            $progressCallback('starting', $metadata);
        }

        $connection = $this->database->connection($connectionName);

        try {
            if ($this->shouldDisableForeignKeyConstraints()) {
                $connection->getSchemaBuilder()->disableForeignKeyConstraints();
                $metadata['foreign_key_constraints_disabled'] = true;
            }

            if ($extension === 'sql') {
                $metadata = array_merge($metadata, $this->restoreSql($connection, $stream, $table, $progressCallback));
            } elseif ($extension === 'csv') {
                $metadata = array_merge($metadata, $this->restoreCsv(
                    $connection,
                    $stream,
                    $table,
                    $path,
                    $progressCallback
                ));
            } elseif (in_array($extension, ['json', 'jsonl'], true)) {
                $metadata = array_merge($metadata, $this->restoreJson(
                    $connection,
                    $stream,
                    $extension,
                    $table,
                    $path,
                    $progressCallback
                ));
            } else {
                throw new InvalidArgumentException(sprintf('Unsupported restore format [%s].', $extension));
            }

            $metadata['status'] = 'completed';
        } finally {
            if (($metadata['foreign_key_constraints_disabled'] ?? false) === true) {
                $connection->getSchemaBuilder()->enableForeignKeyConstraints();
            }

            fclose($stream);

            if (is_callable($progressCallback)) {
                $progressCallback('finished', $metadata);
            }
        }

        return $metadata;
    }

    protected function restoreSql($connection, $stream, ?string $table, ?callable $progressCallback): array
    {
        $rows = 0;
        $statements = 0;
        $restoredTables = [];
        $buffer = '';

        while (($line = fgets($stream)) !== false) {
            $buffer .= $line;

            if (! str_ends_with(rtrim($line), ';')) {
                continue;
            }

            $statement = trim($buffer);
            $buffer = '';

            if ($statement === '') {
                continue;
            }

            $statementTable = $this->extractTableFromSqlStatement($statement);

            if ($table !== null && $statementTable !== null && $statementTable !== $table) {
                continue;
            }

            $connection->unprepared($statement);
            $statements++;

            if (Str::startsWith(Str::upper($statement), 'INSERT INTO')) {
                $rows++;
            }

            if ($statementTable !== null) {
                $restoredTables[$statementTable] = true;
            }

            if (is_callable($progressCallback)) {
                $progressCallback('statement.restored', [
                    'table' => $statementTable,
                    'statements' => $statements,
                    'rows' => $rows,
                ]);
            }
        }

        return [
            'rows' => $rows,
            'statements' => $statements,
            'restored_tables' => array_keys($restoredTables),
        ];
    }

    protected function restoreJson(
        $connection,
        $stream,
        string $extension,
        ?string $table,
        string $path,
        ?callable $progressCallback
    ): array
    {
        $rows = 0;
        $statements = 0;
        $restoredTables = [];

        if ($extension === 'json') {
            $targetTable = $table ?? $this->inferTableFromPath($path);

            if ($targetTable === null) {
                throw new RuntimeException('Unable to determine the target table for the JSON restore.');
            }

            $batch = [];

            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '' || $line === '[' || $line === ']') {
                    continue;
                }

                $line = rtrim($line, ',');
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($row)) {
                    continue;
                }

                $batch[] = $row;

                if (count($batch) < $this->restoreInsertBatchSize()) {
                    continue;
                }

                $connection->table($targetTable)->insert($batch);
                $rows += count($batch);
                $statements++;
                $restoredTables[$targetTable] = true;
                $batch = [];

                if (is_callable($progressCallback)) {
                    $progressCallback('row.restored', [
                        'table' => $targetTable,
                        'rows' => $rows,
                        'statements' => $statements,
                    ]);
                }
            }

            if ($batch !== []) {
                $targetTable = $table ?? $this->inferTableFromPath($path);
                $connection->table($targetTable)->insert($batch);
                $rows += count($batch);
                $statements++;
                $restoredTables[$targetTable] = true;

                if (is_callable($progressCallback)) {
                    $progressCallback('row.restored', [
                        'table' => $targetTable,
                        'rows' => $rows,
                        'statements' => $statements,
                    ]);
                }
            }

            return [
                'rows' => $rows,
                'statements' => $statements,
                'restored_tables' => array_keys($restoredTables),
            ];
        }

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $targetTable = $table ?? Arr::get($payload, 'table');
            $row = Arr::get($payload, 'data');

            if (! is_string($targetTable) || $targetTable === '' || ! is_array($row)) {
                throw new RuntimeException('Invalid incremental backup row payload encountered during restore.');
            }

            $operation = Arr::get($payload, 'operation', 'upsert');

            if ($operation === 'deleted') {
                $this->restoreDeletedRow($connection, $targetTable, $row);
            } else {
                $connection->table($targetTable)->insert($row);
            }

            $rows++;
            $statements++;
            $restoredTables[$targetTable] = true;

            if (is_callable($progressCallback)) {
                $progressCallback('row.restored', [
                    'table' => $targetTable,
                    'rows' => $rows,
                    'statements' => $statements,
                ]);
            }
        }

        return [
            'rows' => $rows,
            'statements' => $statements,
            'restored_tables' => array_keys($restoredTables),
        ];
    }

    protected function restoreCsv(
        $connection,
        $stream,
        ?string $table,
        string $path,
        ?callable $progressCallback
    ): array {
        $targetTable = $table ?? $this->inferTableFromPath($path);

        if ($targetTable === null) {
            throw new RuntimeException('Unable to determine the target table for the CSV restore.');
        }

        $headers = fgetcsv($stream);

        if (! is_array($headers) || $headers === []) {
            return [
                'rows' => 0,
                'statements' => 0,
                'restored_tables' => [],
            ];
        }

        $rows = 0;
        $statements = 0;
        $batch = [];

        while (($data = fgetcsv($stream)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                if (! is_string($header) || $header === '') {
                    continue;
                }

                $value = $data[$index] ?? null;
                $row[$header] = $value === '' ? null : $value;
            }

            if ($row === []) {
                continue;
            }

            $batch[] = $row;

            if (count($batch) < $this->restoreInsertBatchSize()) {
                continue;
            }

            $connection->table($targetTable)->insert($batch);
            $rows += count($batch);
            $statements++;
            $batch = [];

            if (is_callable($progressCallback)) {
                $progressCallback('row.restored', [
                    'table' => $targetTable,
                    'rows' => $rows,
                    'statements' => $statements,
                ]);
            }
        }

        if ($batch !== []) {
            $connection->table($targetTable)->insert($batch);
            $rows += count($batch);
            $statements++;

            if (is_callable($progressCallback)) {
                $progressCallback('row.restored', [
                    'table' => $targetTable,
                    'rows' => $rows,
                    'statements' => $statements,
                ]);
            }
        }

        return [
            'rows' => $rows,
            'statements' => $statements,
            'restored_tables' => [$targetTable],
        ];
    }

    protected function restoreDeletedRow($connection, string $table, array $row): void
    {
        if (array_key_exists('id', $row)) {
            $connection->table($table)->where('id', $row['id'])->delete();

            return;
        }

        $connection->table($table)->insert($row);
    }

    protected function openReadStream(string $path, ?string $disk = null)
    {
        if (is_file($path)) {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new RuntimeException(sprintf('Unable to open backup file [%s].', $path));
            }

            return $stream;
        }

        $disk = $disk ?? $this->config->get('backup.restore.disk');
        $stream = $this->filesystem->disk($disk)->readStream($path);

        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to open backup file [%s] from disk [%s].', $path, $disk));
        }

        return $stream;
    }

    protected function extractTableFromSqlStatement(string $statement): ?string
    {
        if (preg_match('/^\s*INSERT\s+INTO\s+`?([^`\s(]+)`?/i', $statement, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    protected function inferTableFromPath(string $path): ?string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        if (! is_string($filename) || $filename === '') {
            return null;
        }

        if (preg_match('/^(.*)-\d{8}_\d{6}$/', $filename, $matches) === 1) {
            return $matches[1] !== '' ? $matches[1] : null;
        }

        return $filename;
    }

    protected function normalizeTable(mixed $table): ?string
    {
        if (! is_string($table) || trim($table) === '') {
            return null;
        }

        return trim($table);
    }

    protected function guardPassword(mixed $providedPassword): void
    {
        $configuredPassword = $this->config->get('backup.restore.password');

        if (! is_string($configuredPassword) || $configuredPassword === '') {
            return;
        }

        if (! is_string($providedPassword) || ! hash_equals($configuredPassword, $providedPassword)) {
            throw new InvalidArgumentException('Invalid restore password.');
        }
    }

    protected function shouldDisableForeignKeyConstraints(): bool
    {
        return (bool) $this->config->get('backup.restore.disable_foreign_key_constraints', true);
    }

    protected function restoreInsertBatchSize(): int
    {
        return max(1, (int) $this->config->get('backup.restore.insert_batch_size', 500));
    }
}
