<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Commands;

use BhavneeshGoyal\LaravelSmartBackup\Services\RestoreService;
use Illuminate\Console\Command;
use Throwable;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {file : Backup file path or disk-relative path}
        {--table= : Restore only a specific table}
        {--disk= : Storage disk to read the backup file from}
        {--password= : Optional restore password}';

    protected $description = 'Restore a backup file into the configured database connection.';

    public function handle(RestoreService $restoreService): int
    {
        try {
            $result = $restoreService->restore([
                'file' => $this->argument('file'),
                'table' => $this->option('table'),
                'disk' => $this->option('disk'),
                'password' => $this->option('password'),
            ], function (string $event, array $payload): void {
                if ($event === 'starting') {
                    $this->components->info(sprintf('Restoring backup file [%s].', $payload['file']));

                    if (! empty($payload['table'])) {
                        $this->line(sprintf('Table filter: %s', $payload['table']));
                    }

                    return;
                }

                if ($event === 'row.restored' && (($payload['rows'] ?? 0) % 500) === 0) {
                    $this->line(sprintf(
                        'Restored %d row(s) into [%s].',
                        $payload['rows'],
                        $payload['table'] ?? 'unknown'
                    ));

                    return;
                }

                if ($event === 'statement.restored' && (($payload['statements'] ?? 0) % 500) === 0) {
                    $this->line(sprintf(
                        'Executed %d statement(s)%s.',
                        $payload['statements'],
                        isset($payload['table']) && $payload['table'] !== null ? ' for [' . $payload['table'] . ']' : ''
                    ));
                }
            });
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Restore %s. %d row(s), %d statement(s).',
            $result['status'],
            $result['rows'],
            $result['statements']
        ));

        if (! empty($result['restored_tables'])) {
            $this->line('Tables: ' . implode(', ', $result['restored_tables']));
        }

        return self::SUCCESS;
    }
}
