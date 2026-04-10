<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupService;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;

class BackupServiceTest extends TestCase
{
    public function test_full_backup_creates_file_and_persists_metadata(): void
    {
        DB::table('users')->insert([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app['config']->set('backup.mode', 'full');
        $this->app['config']->set('backup.format', 'sql');
        $this->app['config']->set('backup.storage.disk', 'local');
        $this->app['config']->set('backup.tables.include', ['users']);

        $result = $this->app->make(BackupService::class)->run();

        $this->assertSame('completed', $result['status']);
        $this->assertCount(1, $result['tables']);

        $run = DB::table('smart_backup_runs')->first();
        $table = DB::table('smart_backup_tables')->first();

        $this->assertNotNull($run);
        $this->assertNotNull($table);
        $this->assertSame('completed', $run->status);
        $this->assertSame('users', $table->table_name);
        $this->assertSame('completed', $table->status);
        $this->assertNotEmpty($table->file_path);

        $root = $this->app['config']->get('filesystems.disks.local.root');

        $this->assertTrue(File::exists($root . '/' . $table->file_path));
    }

    public function test_full_csv_backup_creates_csv_file_and_metadata(): void
    {
        DB::table('users')->insert([
            'name' => 'Abigail Otwell',
            'email' => 'abigail@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app['config']->set('backup.mode', 'full');
        $this->app['config']->set('backup.format', 'csv');
        $this->app['config']->set('backup.storage.disk', 'local');
        $this->app['config']->set('backup.tables.include', ['users']);

        $result = $this->app->make(BackupService::class)->run();

        $this->assertSame('completed', $result['status']);
        $this->assertSame('csv', $result['tables'][0]['format']);

        $table = DB::table('smart_backup_tables')->latest('id')->first();
        $root = $this->app['config']->get('filesystems.disks.local.root');
        $path = $root . '/' . $table->file_path;

        $this->assertStringEndsWith('.csv', $table->file_path);
        $this->assertTrue(File::exists($path));
        $this->assertStringContainsString('name,email,created_at,updated_at', File::get($path));
    }

    public function test_backup_toggles_maintenance_mode_when_enabled(): void
    {
        DB::table('users')->insert([
            'name' => 'Maintenance Test',
            'email' => 'maintenance@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('call')->once()->with('down');
        $kernel->shouldReceive('call')->once()->with('up');
        $this->app->instance(Kernel::class, $kernel);

        $this->app['config']->set('backup.mode', 'full');
        $this->app['config']->set('backup.format', 'sql');
        $this->app['config']->set('backup.storage.disk', 'local');
        $this->app['config']->set('backup.tables.include', ['users']);
        $this->app['config']->set('backup.maintenance.enabled', true);

        $result = $this->app->make(BackupService::class)->run();

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['maintenance_mode']['enabled']);
    }
}
