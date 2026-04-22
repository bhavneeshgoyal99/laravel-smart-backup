<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class BackgroundBackupLauncher
{
    public function __construct(
        protected Container $container,
        protected LoggerInterface $logger
    ) {
    }

    public function dispatch(array $options = []): void
    {
        $workingDirectory = $this->basePath();
        $artisan = $workingDirectory . DIRECTORY_SEPARATOR . 'artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException(sprintf('Unable to find artisan at [%s].', $artisan));
        }

        $command = $this->buildShellCommand($artisan, $options);

        $this->logger->info('Starting background smart backup process.', [
            'command' => $command,
            'options' => $options,
        ]);

        try {
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
        } catch (Throwable $exception) {
            $this->logger->error('Failed to start background smart backup process.', [
                'command' => $command,
                'options' => $options,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    protected function buildShellCommand(string $artisan, array $options): string
    {
        $parts = [
            escapeshellarg($this->resolvePhpBinary()),
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

    protected function resolvePhpBinary(): string
    {
        foreach ($this->phpBinaryCandidates() as $candidate) {
            if ($this->isUsablePhpBinary($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * @return list<string>
     */
    protected function phpBinaryCandidates(): array
    {
        $candidates = [
            $this->currentPhpBinary(),
        ];

        if (defined('PHP_BINDIR')) {
            $bindir = rtrim((string) PHP_BINDIR, DIRECTORY_SEPARATOR);

            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php';
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . sprintf('php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . sprintf('php%d', PHP_MAJOR_VERSION);
        }

        foreach ([
            '/usr/bin/php',
            '/usr/local/bin/php',
            sprintf('/usr/bin/php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            sprintf('/usr/local/bin/php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            sprintf('/opt/homebrew/bin/php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            sprintf('/opt/homebrew/bin/php%d', PHP_MAJOR_VERSION),
        ] as $candidate) {
            $candidates[] = $candidate;
        }

        $pathBinary = $this->phpBinaryFromPath();

        if (is_string($pathBinary) && $pathBinary !== '') {
            $candidates[] = $pathBinary;
        }

        $candidates[] = 'php';

        return array_values(array_unique(array_filter($candidates, static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '')));
    }

    protected function currentPhpBinary(): string
    {
        return PHP_BINARY;
    }

    protected function phpBinaryFromPath(): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $resolved = @shell_exec('command -v php 2>/dev/null');

        if (! is_string($resolved)) {
            return null;
        }

        $resolved = trim($resolved);

        return $resolved !== '' ? $resolved : null;
    }

    protected function isUsablePhpBinary(string $binary): bool
    {
        if (! $this->isCliPhpBinaryName($binary)) {
            return false;
        }

        if (! str_contains($binary, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $binary)) {
            return true;
        }

        return is_file($binary) && is_executable($binary);
    }

    protected function isCliPhpBinaryName(string $binary): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $binary)));

        return (bool) preg_match('/^php(?:\d+(?:\.\d+)?)?(?:\.exe)?$/', $name);
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
