<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BackupMetadataService
{
    public function __construct(protected DatabaseManager $database)
    {
    }

    public function startRun(array $metadata): int
    {
        return (int) $this->database->table('smart_backup_runs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'type' => $metadata['mode'],
            'status' => $metadata['status'],
            'format' => $metadata['format'] ?? null,
            'disk' => $metadata['disk'] ?? null,
            'base_path' => $metadata['path'] ?? null,
            'started_at' => $metadata['started_at'] ?? now()->toDateTimeString(),
            'meta' => json_encode([
                'driver' => $metadata['driver'] ?? null,
                'chunk_size' => $metadata['chunk_size'] ?? null,
                'connection' => $metadata['connection'] ?? null,
                'maintenance_mode' => $metadata['maintenance_mode'] ?? null,
                'selected_tables' => $metadata['selected_tables'] ?? [],
                'retry_attempts' => $metadata['retry_attempts'] ?? 1,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordTableStart(int $runId, string $table, string $mode): int
    {
        return (int) $this->database->table('smart_backup_tables')->insertGetId([
            'backup_run_id' => $runId,
            'table_name' => $table,
            'type' => $mode,
            'status' => 'running',
            'file_path' => '',
            'rows' => 0,
            'chunks' => 0,
            'last_backup_at' => null,
            'meta' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finalizeTable(int $tableRecordId, array $result, Carbon|string|null $lastBackupAt = null): void
    {
        $timestamp = $this->normalizeTimestamp($lastBackupAt ?? $result['backup_started_at'] ?? null);

        $this->database->table('smart_backup_tables')
            ->where('id', $tableRecordId)
            ->update([
                'type' => $result['effective_mode'] ?? $result['mode'] ?? 'full',
                'status' => $result['status'] ?? 'completed',
                'file_path' => $result['path'] ?? '',
                'rows' => (int) ($result['rows'] ?? 0),
                'chunks' => (int) ($result['chunks'] ?? 0),
                'last_backup_at' => $timestamp,
                'meta' => json_encode($result, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function failTable(int $tableRecordId, \Throwable $exception): void
    {
        $this->database->table('smart_backup_tables')
            ->where('id', $tableRecordId)
            ->update([
                'status' => 'failed',
                'meta' => json_encode([
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function finalizeRun(int $runId, array $metadata): void
    {
        $this->database->table('smart_backup_runs')
            ->where('id', $runId)
            ->update([
                'status' => $metadata['status'],
                'finished_at' => $metadata['finished_at'] ?? now()->toDateTimeString(),
                'error_message' => $metadata['error'] ?? null,
                'meta' => json_encode([
                    'driver' => $metadata['driver'] ?? null,
                    'chunk_size' => $metadata['chunk_size'] ?? null,
                    'connection' => $metadata['connection'] ?? null,
                    'maintenance_mode' => $metadata['maintenance_mode'] ?? null,
                    'selected_tables' => $metadata['selected_tables'] ?? [],
                    'table_count' => $metadata['table_count'] ?? 0,
                    'tables' => $metadata['tables'] ?? [],
                    'retry_attempts' => $metadata['retry_attempts'] ?? 1,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function lastSuccessfulTableBackupAt(string $table): ?string
    {
        $value = $this->database->table('smart_backup_tables')
            ->where('table_name', $table)
            ->where('status', 'completed')
            ->whereNotNull('last_backup_at')
            ->orderByDesc('last_backup_at')
            ->value('last_backup_at');

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return is_string($value) ? $value : null;
    }

    protected function normalizeTimestamp(Carbon|string|null $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }
}
