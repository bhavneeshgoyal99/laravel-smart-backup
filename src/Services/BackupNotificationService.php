<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use Throwable;

class BackupNotificationService
{
    public function __construct(
        protected Container $container,
        protected Config $config,
        protected LoggerInterface $logger,
        protected SettingsService $settings
    ) {
    }

    public function sendCompleted(array $metadata): void
    {
        $email = $this->notificationEmail();

        if ($email === null) {
            return;
        }

        $subject = sprintf(
            'Backup completed: %s (%d table%s)',
            $metadata['mode'] ?? 'unknown',
            (int) ($metadata['table_count'] ?? 0),
            ((int) ($metadata['table_count'] ?? 0)) === 1 ? '' : 's'
        );

        $body = implode(PHP_EOL, [
            'A smart backup finished successfully.',
            '',
            'Run ID: ' . ($metadata['run_id'] ?? 'n/a'),
            'Status: ' . ($metadata['status'] ?? 'completed'),
            'Mode: ' . ($metadata['mode'] ?? 'n/a'),
            'Format: ' . ($metadata['format'] ?? 'n/a'),
            'Table Count: ' . (string) ($metadata['table_count'] ?? 0),
            'Disk: ' . ($metadata['disk'] ?? 'n/a'),
            'Path: ' . ($metadata['path'] ?? 'n/a'),
            'Started At: ' . ($metadata['started_at'] ?? 'n/a'),
            'Finished At: ' . ($metadata['finished_at'] ?? 'n/a'),
        ]);

        $this->send($email, $subject, $body, $metadata);
    }

    public function sendFailed(array $metadata, Throwable $exception): void
    {
        $email = $this->notificationEmail();

        if ($email === null) {
            return;
        }

        $subject = sprintf('Backup failed: %s', $metadata['mode'] ?? 'unknown');
        $body = implode(PHP_EOL, [
            'A smart backup failed.',
            '',
            'Run ID: ' . ($metadata['run_id'] ?? 'n/a'),
            'Status: ' . ($metadata['status'] ?? 'failed'),
            'Mode: ' . ($metadata['mode'] ?? 'n/a'),
            'Format: ' . ($metadata['format'] ?? 'n/a'),
            'Started At: ' . ($metadata['started_at'] ?? 'n/a'),
            'Finished At: ' . ($metadata['finished_at'] ?? 'n/a'),
            'Error: ' . $exception->getMessage(),
        ]);

        $this->send($email, $subject, $body, $metadata);
    }

    protected function send(string $email, string $subject, string $body, array $metadata): void
    {
        if (! $this->container->bound('mailer')) {
            $this->logger->warning('Backup notification email skipped because mailer is not bound.', [
                'run_id' => $metadata['run_id'] ?? null,
                'notification_email' => $email,
            ]);

            return;
        }

        $fromAddress = $this->config->get('mail.from.address');
        $fromName = $this->config->get('mail.from.name', $this->config->get('app.name'));

        if (! is_string($fromAddress) || trim($fromAddress) === '') {
            $this->logger->warning('Backup notification email skipped because mail.from.address is not configured.', [
                'run_id' => $metadata['run_id'] ?? null,
                'notification_email' => $email,
            ]);

            return;
        }

        try {
            $this->container->make('mailer')->raw($body, function ($message) use ($email, $subject, $fromAddress, $fromName): void {
                $message->from((string) $fromAddress, is_string($fromName) ? $fromName : null)
                    ->to($email)
                    ->subject($subject);
            });
        } catch (Throwable $exception) {
            $this->logger->error('Backup notification email failed.', [
                'run_id' => $metadata['run_id'] ?? null,
                'notification_email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function notificationEmail(): ?string
    {
        $email = $this->settings->get('notification_email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }
}
