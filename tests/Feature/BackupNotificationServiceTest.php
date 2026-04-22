<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupNotificationService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;

class BackupNotificationServiceTest extends TestCase
{
    public function test_completed_notification_is_sent_to_configured_email(): void
    {
        $sent = [];
        $mailer = new class($sent)
        {
            public function __construct(public array &$sent)
            {
            }

            public function raw(string $body, callable $callback): void
            {
                $message = new class
                {
                    public ?string $to = null;
                    public ?string $subject = null;

                    public function to(string $email): self
                    {
                        $this->to = $email;

                        return $this;
                    }

                    public function subject(string $subject): self
                    {
                        $this->subject = $subject;

                        return $this;
                    }
                };

                $callback($message);

                $this->sent[] = [
                    'to' => $message->to,
                    'subject' => $message->subject,
                    'body' => $body,
                ];
            }
        };

        $this->app->instance('mailer', $mailer);
        $this->app->make(SettingsService::class)->set('notification_email', 'ops@example.com');

        $this->app->make(BackupNotificationService::class)->sendCompleted([
            'run_id' => 42,
            'status' => 'completed',
            'mode' => 'full',
            'driver' => 'full',
            'format' => 'sql',
            'table_count' => 2,
            'disk' => 'local',
            'path' => 'backups/database',
            'started_at' => '2026-04-22 10:00:00',
            'finished_at' => '2026-04-22 10:05:00',
        ]);

        $this->assertCount(1, $sent);
        $this->assertSame('ops@example.com', $sent[0]['to']);
        $this->assertStringContainsString('Backup completed', $sent[0]['subject']);
        $this->assertStringContainsString('Run ID: 42', $sent[0]['body']);
    }
}
