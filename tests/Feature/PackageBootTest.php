<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\BackupServiceProvider;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;

class PackageBootTest extends TestCase
{
    public function test_package_provider_registers_core_services(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(BackupServiceProvider::class));
        $this->assertTrue($this->app->bound(BackupManager::class));
        $this->assertArrayHasKey('backup', $this->app['config']->all());
    }
}
