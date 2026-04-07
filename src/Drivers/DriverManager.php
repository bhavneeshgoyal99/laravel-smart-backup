<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class DriverManager
{
    public function __construct(protected Container $container)
    {
    }

    public function driver(?string $name = null): BackupDriver
    {
        $name ??= $this->container['config']->get('backup.drivers.default', 'local');

        $class = $this->container['config']->get("backup.drivers.{$name}.class");

        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException(sprintf('Backup driver [%s] is not configured.', $name));
        }

        $driver = $this->container->make($class);

        if (! $driver instanceof BackupDriver) {
            throw new InvalidArgumentException(sprintf('Backup driver [%s] must implement [%s].', $name, BackupDriver::class));
        }

        return $driver;
    }
}
