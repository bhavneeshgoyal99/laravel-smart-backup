<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

class BackupManager
{
    public function __construct(protected BackupService $backupService)
    {
    }

    public function run(array $options = [], ?callable $progressCallback = null): array
    {
        return $this->backupService->run($options, $progressCallback);
    }
}
