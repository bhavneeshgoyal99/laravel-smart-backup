<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

class SchedulerService
{
    public function __construct(
        protected Config $config,
        protected SettingsService $settings
    ) {
    }

    public function register(Schedule $schedule): void
    {
        if (! (bool) $this->settings->get('schedule.enabled', false)) {
            return;
        }

        $event = $schedule->command('backup:run', $this->buildCommandOptions());

        $this->applyFrequency($event);
        $this->applyTimezone($event);

        if ((bool) $this->settings->get('schedule.without_overlapping', true)) {
            $event->withoutOverlapping();
        }
    }

    protected function buildCommandOptions(): array
    {
        $options = [];

        foreach (['mode', 'format', 'driver'] as $option) {
            $value = $this->settings->get("schedule.{$option}");

            if (is_string($value) && $value !== '') {
                $options["--{$option}"] = $value;
            }
        }

        $tables = array_values(array_filter(
            (array) $this->settings->get('schedule.tables', []),
            static fn ($table) => is_string($table) && $table !== ''
        ));

        if ($tables !== []) {
            $options['--tables'] = $tables;
        }

        return $options;
    }

    protected function applyFrequency(Event $event): void
    {
        $frequency = (string) $this->settings->get('schedule.frequency', 'daily');

        match ($frequency) {
            'hourly' => $event->hourlyAt($this->resolveHourlyMinute()),
            'weekly' => $event->weeklyOn(
                (int) $this->settings->get('schedule.day_of_week', 0),
                $this->scheduledTime()
            ),
            'monthly' => $event->monthlyOn(
                (int) $this->settings->get('schedule.day_of_month', 1),
                $this->scheduledTime()
            ),
            'daily' => $event->dailyAt($this->scheduledTime()),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported backup schedule frequency [%s].',
                $frequency
            )),
        };
    }

    protected function applyTimezone(Event $event): void
    {
        $timezone = $this->settings->get('schedule.timezone');

        if (is_string($timezone) && $timezone !== '') {
            $event->timezone($timezone);
        }
    }

    protected function scheduledTime(): string
    {
        return $this->normalizeTime((string) $this->settings->get('schedule.time', '02:00'));
    }

    protected function resolveHourlyMinute(): int
    {
        $minute = $this->settings->get('schedule.hourly_minute');

        if (is_numeric($minute)) {
            return $this->normalizeMinute((int) $minute);
        }

        return $this->extractMinute($this->scheduledTime());
    }

    protected function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Backup schedule time [%s] must use the HH:MM format.',
                $time
            ));
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException(sprintf(
                'Backup schedule time [%s] is not a valid 24-hour time.',
                $time
            ));
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    protected function extractMinute(string $time): int
    {
        [, $minute] = array_map('intval', explode(':', $time));

        return $this->normalizeMinute($minute);
    }

    protected function normalizeMinute(int $minute): int
    {
        if ($minute < 0 || $minute > 59) {
            throw new InvalidArgumentException(sprintf(
                'Backup schedule minute [%s] must be between 0 and 59.',
                $minute
            ));
        }

        return $minute;
    }
}
