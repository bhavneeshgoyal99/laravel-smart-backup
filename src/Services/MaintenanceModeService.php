<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

class MaintenanceModeService
{
    public function __construct(
        protected Config $config,
        protected Kernel $kernel,
        protected Application $application,
        protected SettingsService $settings
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(
            'maintenance.enabled',
            (bool) $this->config->get('backup.maintenance.enabled', false)
        );
    }

    public function enable(): bool
    {
        if ($this->application->isDownForMaintenance()) {
            return false;
        }

        $this->kernel->call('down');

        return true;
    }

    public function disable(): void
    {
        $this->kernel->call('up');
    }

    public function runSafely(callable $callback): mixed
    {
        $enabledByService = false;

        try {
            if ($this->isEnabled()) {
                $enabledByService = $this->enable();
            }

            return $callback($enabledByService);
        } finally {
            if ($enabledByService) {
                $this->disable();
            }
        }
    }

}
