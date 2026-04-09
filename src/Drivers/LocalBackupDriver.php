<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers;

use Illuminate\Contracts\Config\Repository as Config;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupStorageService;

class LocalBackupDriver implements BackupDriver
{
    public function __construct(
        protected Config $config,
        protected BackupStorageService $storage
    )
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function backupTable(string $table, string $mode, array $context = []): array
    {
        $startedAt = $context['started_at'] ?? now()->toDateTimeString();
        $format = $context['format'] ?? $this->config->get('backup.format', 'sql');
        $extension = in_array($format, ['sql', 'json', 'csv'], true) ? $format : 'sql';

        return [
            'table' => $table,
            'mode' => $mode,
            'driver' => $this->name(),
            'format' => $format,
            'disk' => $context['disk'] ?? $this->config->get('backup.storage.disk'),
            'path' => $this->storage->tableBackupPath(
                $mode,
                $table,
                $extension,
                \Illuminate\Support\Carbon::parse($startedAt),
                $context['path'] ?? $this->config->get('backup.storage.path')
            ),
            'chunk_size' => $context['chunk_size'] ?? $this->config->get('backup.chunk_size'),
            'status' => 'pending',
            'started_at' => $startedAt,
        ];
    }
}
