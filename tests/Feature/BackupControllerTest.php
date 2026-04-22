<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupService;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackgroundBackupLauncher;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;
use Mockery;

class BackupControllerTest extends TestCase
{
    public function test_web_backup_requests_start_a_background_process(): void
    {
        $this->app['config']->set('backup.ui.enabled', true);
        $this->app['config']->set('backup.ui.dispatch_after_response', true);

        $launcher = Mockery::mock(BackgroundBackupLauncher::class);
        $launcher->shouldReceive('dispatch')
            ->once()
            ->with(['mode' => 'full', 'tables' => ['users']]);
        $this->app->instance(BackgroundBackupLauncher::class, $launcher);

        $backupService = Mockery::mock(BackupService::class);
        $manager = new class($backupService) extends BackupManager
        {
            public int $calls = 0;

            public array $options = [];

            public function run(array $options = [], ?callable $progressCallback = null): array
            {
                $this->calls++;
                $this->options = $options;

                return [
                    'status' => 'completed',
                    'table_count' => 1,
                ];
            }
        };
        $this->app->instance(BackupManager::class, $manager);

        $response = $this->post('/smart-backup/backups/run', [
            'mode' => 'full',
            'tables' => ['users'],
        ]);

        $response->assertRedirect('/smart-backup/backups');
        $response->assertSessionHas('status', 'Backup started in the background.');
        $this->assertSame(0, $manager->calls);
    }
}
