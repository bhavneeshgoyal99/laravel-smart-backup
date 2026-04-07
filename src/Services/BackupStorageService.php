<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use RuntimeException;

class BackupStorageService
{
    public function __construct(
        protected Config $config,
        protected FilesystemManager $filesystem
    ) {
    }

    public function disk(?string $disk = null): FilesystemAdapter
    {
        return $this->filesystem->disk($disk ?? $this->defaultDisk());
    }

    public function defaultDisk(): string
    {
        return (string) $this->config->get('backup.storage.disk', 'local');
    }

    public function basePath(?string $path = null): string
    {
        return trim((string) ($path ?? $this->config->get('backup.storage.path', 'backups/database')), '/');
    }

    public function tableBackupPath(
        string $mode,
        string $table,
        string $extension,
        Carbon $startedAt,
        ?string $basePath = null
    ): string {
        return sprintf(
            '%s/%s/%s/%s.%s',
            $this->basePath($basePath),
            $mode,
            $startedAt->format('Y/m/d'),
            $table . '-' . $startedAt->format('Ymd_His'),
            ltrim($extension, '.')
        );
    }

    public function writeStream(string $path, $stream, ?string $disk = null): void
    {
        $filesystem = $this->disk($disk);

        if (! is_resource($stream)) {
            throw new RuntimeException('A valid stream resource is required for backup storage.');
        }

        rewind($stream);

        if ($filesystem->writeStream($path, $stream) === false) {
            throw new RuntimeException(sprintf(
                'Failed to write backup stream to [%s] on disk [%s].',
                $path,
                $disk ?? $this->defaultDisk()
            ));
        }
    }

    public function createTemporaryStream(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'smart-backup-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary backup file.');
        }

        $stream = fopen($path, 'w+b');

        if ($stream === false) {
            @unlink($path);

            throw new RuntimeException('Unable to open a temporary backup stream.');
        }

        return [
            'path' => $path,
            'stream' => $stream,
        ];
    }

    public function cleanupTemporaryStream(array $temporaryFile): void
    {
        $stream = $temporaryFile['stream'] ?? null;
        $path = $temporaryFile['path'] ?? null;

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
}
