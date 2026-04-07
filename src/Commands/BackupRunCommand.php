<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Commands;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--mode= : Backup mode (full or incremental)}
        {--tables=* : Limit the run to one or more tables}
        {--format= : Export format (sql, json, csv)}
        {--driver= : Explicit backup driver override}';

    protected $description = 'Run a database backup using the configured smart backup package.';

    public function handle(BackupManager $manager): int
    {
        $progressBar = null;

        $result = $manager->run([
            'mode' => $this->option('mode'),
            'tables' => $this->option('tables'),
            'format' => $this->option('format'),
            'driver' => $this->option('driver'),
        ], function (string $event, array $payload) use (&$progressBar): void {
            if ($event === 'starting') {
                $totalTables = $payload['total_tables'];
                $this->components->info(sprintf('Preparing backup for %d table(s).', $totalTables));
                $progressBar = $this->output->createProgressBar($totalTables);
                $progressBar->start();

                return;
            }

            if ($event === 'table.completed' && $progressBar !== null) {
                $progressBar->advance();
                $result = $payload['result'];
                $this->newLine();
                $this->line(sprintf(
                    'Table [%s] completed using %s (%d row(s), %d chunk(s)).',
                    $payload['table'],
                    $result['driver'] ?? 'driver',
                    $result['rows'] ?? 0,
                    $result['chunks'] ?? 0
                ));

                return;
            }

            if ($event === 'finished' && $progressBar !== null) {
                $progressBar->finish();
                $this->newLine(2);
            }
        });

        $this->components->info(sprintf(
            'Smart backup %s with %d table(s).',
            $result['status'],
            $result['table_count']
        ));
        $this->line(sprintf('Mode: %s', $result['mode']));
        $this->line(sprintf('Driver: %s', $result['driver']));
        $this->line(sprintf('Disk: %s', $result['disk']));
        $this->line(sprintf('Path: %s', $result['path']));
        $this->line(sprintf('Format: %s', $result['format']));
        $this->line(sprintf('Chunk Size: %s', $result['chunk_size']));

        foreach ($result['tables'] as $table) {
            $this->line(sprintf(
                'Table [%s]: %s (%d row(s), %d chunk(s))',
                $table['table'],
                $table['status'],
                $table['rows'] ?? 0,
                $table['chunks'] ?? 0
            ));
        }

        return self::SUCCESS;
    }
}
