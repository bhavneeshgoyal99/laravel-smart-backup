<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers;

interface BackupDriver
{
    public function name(): string;

    public function backupTable(string $table, string $mode, array $context = []): array;
}
