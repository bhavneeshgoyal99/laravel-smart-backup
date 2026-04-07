<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

class MaintenanceModeService
{
    public function __construct(
        protected Config $config,
        protected Kernel $kernel,
        protected Application $application
    ) {
    }

    public function shouldEnableForMode(string $mode): bool
    {
        return match ($this->resolvePolicy()) {
            'always_on' => true,
            'full_only' => $mode === 'full',
            'always_off' => false,
            default => false,
        };
    }

    public function enable(): bool
    {
        if ($this->application->isDownForMaintenance()) {
            return false;
        }

        $this->kernel->call('down', array_filter([
            '--secret' => $this->config->get('backup.maintenance.secret'),
            '--retry' => $this->config->get('backup.maintenance.retry'),
            '--refresh' => $this->config->get('backup.maintenance.refresh'),
        ], static fn ($value) => $value !== null && $value !== ''));

        return true;
    }

    public function disable(): void
    {
        if (! $this->application->isDownForMaintenance()) {
            return;
        }

        $this->kernel->call('up');
    }

    public function runSafely(string $mode, callable $callback): mixed
    {
        $enabledByService = false;

        try {
            if ($this->shouldEnableForMode($mode)) {
                $enabledByService = $this->enable();
            }

            return $callback($enabledByService);
        } finally {
            if ($enabledByService) {
                $this->disable();
            }
        }
    }

    protected function resolvePolicy(): string
    {
        $policy = $this->config->get('backup.maintenance.policy');

        if (! is_string($policy) || $policy === '') {
            return $this->config->get('backup.maintenance.enabled', false) ? 'always_on' : 'always_off';
        }

        if (! in_array($policy, ['always_off', 'full_only', 'always_on'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported maintenance policy [%s].',
                $policy
            ));
        }

        return $policy;
    }
}
