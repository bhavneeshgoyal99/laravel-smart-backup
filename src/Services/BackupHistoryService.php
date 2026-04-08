<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class BackupHistoryService
{
    public function __construct(
        protected DatabaseManager $database,
        protected BackupStorageService $storage,
        protected Config $config,
        protected SettingsService $settings
    ) {
    }

    public function listRuns(int $limit = 25): Collection
    {
        $runs = $this->database->table('smart_backup_runs')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        $tableEntries = $this->database->table('smart_backup_tables')
            ->whereIn('backup_run_id', $runs->pluck('id')->all())
            ->orderBy('table_name')
            ->get()
            ->groupBy('backup_run_id');

        return $runs->map(function ($run) use ($tableEntries) {
            return [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'type' => $run->type,
                'status' => $run->status,
                'format' => $run->format,
                'disk' => $run->disk,
                'base_path' => $run->base_path,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'tables' => ($tableEntries->get($run->id) ?? collect())->map(function ($table) {
                    return [
                        'id' => $table->id,
                        'table_name' => $table->table_name,
                        'type' => $table->type,
                        'status' => $table->status,
                        'file_path' => $table->file_path,
                        'rows' => $table->rows,
                        'chunks' => $table->chunks,
                        'last_backup_at' => $table->last_backup_at,
                    ];
                })->values(),
            ];
        })->values();
    }

    public function findRun(int|string $runId): ?object
    {
        return $this->database->table('smart_backup_runs')->where('id', $runId)->first();
    }

    public function runTables(int|string $runId): Collection
    {
        return $this->database->table('smart_backup_tables')
            ->where('backup_run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(function ($table) {
                return [
                    'id' => $table->id,
                    'table_name' => $table->table_name,
                    'type' => $table->type,
                    'status' => $table->status,
                    'file_path' => $table->file_path,
                    'rows' => $table->rows,
                    'chunks' => $table->chunks,
                    'last_backup_at' => $table->last_backup_at,
                ];
            })
            ->values();
    }

    public function deleteRun(int|string $runId): bool
    {
        $run = $this->findRun($runId);

        if ($run === null) {
            return false;
        }

        $disk = $this->storage->disk(is_string($run->disk) && $run->disk !== '' ? $run->disk : null);
        $filePaths = $this->database->table('smart_backup_tables')
            ->where('backup_run_id', $run->id)
            ->pluck('file_path');

        foreach ($filePaths as $filePath) {
            if (! is_string($filePath) || $filePath === '') {
                continue;
            }

            if ($disk->exists($filePath)) {
                $disk->delete($filePath);
            }
        }

        $this->database->transaction(function () use ($run): void {
            $this->database->table('smart_backup_tables')->where('backup_run_id', $run->id)->delete();
            $this->database->table('smart_backup_runs')->where('id', $run->id)->delete();
        });

        return true;
    }

    public function dashboardConfig(): array
    {
        return [
            'mode' => $this->settings->get('mode'),
            'format' => $this->settings->get('format'),
            'disk' => $this->settings->get('storage.disk'),
            'path' => $this->settings->get('storage.path'),
            'chunk_size' => $this->settings->get('chunk_size'),
            'schedule' => $this->settings->get('schedule'),
            'maintenance' => $this->settings->get('maintenance'),
            'restore_password_required' => filled($this->settings->get('restore.password')),
            'ui' => $this->settings->get('ui'),
        ];
    }
}
