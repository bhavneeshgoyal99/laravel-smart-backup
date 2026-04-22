<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

class BackgroundBackupLauncher
{
    public function __construct(protected Container $container)
    {
    }

    public function dispatch(array $options = []): void
    {
        $workingDirectory = $this->basePath();
        $artisan = $workingDirectory . DIRECTORY_SEPARATOR . 'artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException(sprintf('Unable to find artisan at [%s].', $artisan));
        }

        $command = $this->buildShellCommand($artisan, $options);

        if (DIRECTORY_SEPARATOR === '\\') {
            $process = @popen($command, 'r');

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start the backup command in the background.');
            }

            pclose($process);

            return;
        }

        $process = @proc_open(
            ['/bin/sh', '-c', $command],
            [
                ['file', '/dev/null', 'r'],
                ['file', '/dev/null', 'a'],
                ['file', '/dev/null', 'a'],
            ],
            $pipes,
            $workingDirectory
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the backup command in the background.');
        }

        proc_close($process);
    }

    protected function buildShellCommand(string $artisan, array $options): string
    {
        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($artisan),
            'backup:run',
        ];

        foreach ($this->commandOptions($options) as $option) {
            $parts[] = $option;
        }

        $command = implode(' ', $parts);

        if (DIRECTORY_SEPARATOR === '\\') {
            return 'start /B "" ' . $command;
        }

        return $command . ' > /dev/null 2>&1 &';
    }

    protected function commandOptions(array $options): array
    {
        $arguments = [];

        foreach (['mode', 'format', 'driver'] as $option) {
            $value = $options[$option] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $arguments[] = sprintf('--%s=%s', $option, escapeshellarg($value));
            }
        }

        foreach ((array) ($options['tables'] ?? []) as $table) {
            $table = trim((string) $table);

            if ($table !== '') {
                $arguments[] = sprintf('--tables=%s', escapeshellarg($table));
            }
        }

        return $arguments;
    }

    protected function basePath(): string
    {
        if (method_exists($this->container, 'basePath')) {
            return $this->container->basePath();
        }

        return getcwd() ?: '.';
    }
}
