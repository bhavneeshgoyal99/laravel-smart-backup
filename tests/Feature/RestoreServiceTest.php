<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\RestoreService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;

class RestoreServiceTest extends TestCase
{
    public function test_restore_toggles_maintenance_mode_when_enabled(): void
    {
        $root = $this->app['config']->get('filesystems.disks.local.root');
        $path = 'backups/database/full/2026/04/10/users.sql';

        File::ensureDirectoryExists(dirname($root . '/' . $path));
        File::put($root . '/' . $path, <<<'SQL'
INSERT INTO "users" ("name", "email", "created_at", "updated_at") VALUES ('Restored User', 'restored@example.com', '2026-04-10 00:00:00', '2026-04-10 00:00:00');
SQL);

        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('call')->once()->with('down');
        $kernel->shouldReceive('call')->once()->with('up');
        $this->app->instance(Kernel::class, $kernel);

        $this->app['config']->set('backup.maintenance.enabled', true);

        $result = $this->app->make(RestoreService::class)->restore([
            'file' => $path,
            'disk' => 'local',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['maintenance_mode']['enabled']);
        $this->assertDatabaseHas('users', [
            'email' => 'restored@example.com',
        ]);
    }

    public function test_restore_falls_back_to_default_database_connection_when_backup_connection_is_invalid(): void
    {
        $root = $this->app['config']->get('filesystems.disks.local.root');
        $path = 'backups/database/full/2026/04/10/users-invalid-connection.sql';

        File::ensureDirectoryExists(dirname($root . '/' . $path));
        File::put($root . '/' . $path, <<<'SQL'
INSERT INTO "users" ("name", "email", "created_at", "updated_at") VALUES ('Fallback User', 'fallback@example.com', '2026-04-10 00:00:00', '2026-04-10 00:00:00');
SQL);

        $this->app['config']->set('backup.connection', 'sql');

        $result = $this->app->make(RestoreService::class)->restore([
            'file' => $path,
            'disk' => 'local',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('testing', $result['connection']);
        $this->assertDatabaseHas('users', [
            'email' => 'fallback@example.com',
        ]);
    }

    public function test_restore_uses_saved_setting_for_foreign_key_constraint_toggle(): void
    {
        $root = $this->app['config']->get('filesystems.disks.local.root');
        $path = 'backups/database/full/2026/04/10/users-settings-foreign-keys.sql';

        File::ensureDirectoryExists(dirname($root . '/' . $path));
        File::put($root . '/' . $path, <<<'SQL'
INSERT INTO "users" ("name", "email", "created_at", "updated_at") VALUES ('FK Setting User', 'fk-setting@example.com', '2026-04-10 00:00:00', '2026-04-10 00:00:00');
SQL);

        $this->app['config']->set('backup.restore.disable_foreign_key_constraints', false);
        $this->app->make(SettingsService::class)->set('restore.disable_foreign_key_constraints', true);

        $result = $this->app->make(RestoreService::class)->restore([
            'file' => $path,
            'disk' => 'local',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['foreign_key_constraints_disabled'] ?? false);
        $this->assertDatabaseHas('users', [
            'email' => 'fk-setting@example.com',
        ]);
    }

    public function test_restore_uses_saved_setting_for_insert_batch_size(): void
    {
        $root = $this->app['config']->get('filesystems.disks.local.root');
        $path = 'backups/database/full/2026/04/10/users-settings-batch.json';

        File::ensureDirectoryExists(dirname($root . '/' . $path));
        File::put($root . '/' . $path, <<<'JSON'
[
{"name":"Batch One","email":"batch-one@example.com","created_at":"2026-04-10 00:00:00","updated_at":"2026-04-10 00:00:00"},
{"name":"Batch Two","email":"batch-two@example.com","created_at":"2026-04-10 00:00:00","updated_at":"2026-04-10 00:00:00"}
]
JSON);

        $this->app['config']->set('backup.restore.insert_batch_size', 500);
        $this->app->make(SettingsService::class)->set('restore.insert_batch_size', 1);

        $result = $this->app->make(RestoreService::class)->restore([
            'file' => $path,
            'disk' => 'local',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['rows']);
        $this->assertSame(2, $result['statements']);
        $this->assertDatabaseHas('users', [
            'email' => 'batch-one@example.com',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'batch-two@example.com',
        ]);
    }
}
