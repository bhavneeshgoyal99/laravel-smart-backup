<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\SchedulerService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class SchedulerServiceTest extends TestCase
{
    public function test_register_skips_event_when_schedule_is_disabled(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->app->make(SettingsService::class)->set('schedule.enabled', false);
        $this->app->make(SchedulerService::class)->register($schedule);

        $this->assertCount(0, $schedule->events());
    }

    public function test_register_builds_weekly_backup_event_from_saved_settings(): void
    {
        $settings = $this->app->make(SettingsService::class);
        $schedule = $this->app->make(Schedule::class);

        $settings->set('schedule.enabled', true);
        $settings->set('schedule.frequency', 'weekly');
        $settings->set('schedule.time', '03:45');
        $settings->set('schedule.day_of_week', 2);
        $settings->set('schedule.timezone', 'Asia/Kolkata');
        $settings->set('schedule.mode', 'incremental');
        $settings->set('schedule.format', 'json');
        $settings->set('schedule.tables', ['users', 'failed_jobs']);
        $settings->set('schedule.without_overlapping', true);

        $this->app->make(SchedulerService::class)->register($schedule);

        $events = $schedule->events();

        $this->assertCount(1, $events);

        $event = $events[0];

        $this->assertSame('45 3 * * 2', $this->readProperty($event, 'expression'));
        $this->assertSame('Asia/Kolkata', $this->readProperty($event, 'timezone'));

        $command = (string) $this->readProperty($event, 'command');

        $this->assertStringContainsString('backup:run', $command);
        $this->assertStringContainsString('--mode=incremental', $command);
        $this->assertStringContainsString('--format=json', $command);
        $this->assertStringContainsString('--tables=users', $command);
        $this->assertStringContainsString('--tables=failed_jobs', $command);
    }

    public function test_register_builds_hourly_backup_event_from_hourly_minute_setting(): void
    {
        $settings = $this->app->make(SettingsService::class);
        $schedule = $this->app->make(Schedule::class);

        $settings->set('schedule.enabled', true);
        $settings->set('schedule.frequency', 'hourly');
        $settings->set('schedule.hourly_minute', 17);

        $this->app->make(SchedulerService::class)->register($schedule);

        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertSame('17 * * * *', $this->readProperty($events[0], 'expression'));
    }

    private function readProperty(object $target, string $property): mixed
    {
        $reflection = new \ReflectionProperty($target, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($target);
    }
}
