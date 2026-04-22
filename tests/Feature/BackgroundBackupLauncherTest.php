<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Tests\Feature;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackgroundBackupLauncher;
use BhavneeshGoyal\LaravelSmartBackup\Tests\TestCase;
use Psr\Log\NullLogger;

class BackgroundBackupLauncherTest extends TestCase
{
    public function test_it_uses_the_current_binary_when_it_is_a_cli_php_executable(): void
    {
        $launcher = new class($this->app, new NullLogger()) extends BackgroundBackupLauncher
        {
            public function commandForTest(string $artisan, array $options = []): string
            {
                return $this->buildShellCommand($artisan, $options);
            }

            protected function currentPhpBinary(): string
            {
                return '/custom/bin/php8.4';
            }

            protected function isUsablePhpBinary(string $binary): bool
            {
                return $binary === '/custom/bin/php8.4';
            }
        };

        $command = $launcher->commandForTest('/var/www/html/project/artisan', ['mode' => 'full']);

        $this->assertStringStartsWith("'/custom/bin/php8.4' '/var/www/html/project/artisan' backup:run", $command);
    }

    public function test_it_skips_php_fpm_and_falls_back_to_a_cli_php_binary(): void
    {
        $launcher = new class($this->app, new NullLogger()) extends BackgroundBackupLauncher
        {
            public function commandForTest(string $artisan, array $options = []): string
            {
                return $this->buildShellCommand($artisan, $options);
            }

            protected function phpBinaryCandidates(): array
            {
                return [
                    '/usr/sbin/php-fpm8.4',
                    '/usr/bin/php8.4',
                    'php',
                ];
            }

            protected function isUsablePhpBinary(string $binary): bool
            {
                return $binary === '/usr/bin/php8.4' || $binary === 'php';
            }
        };

        $command = $launcher->commandForTest('/var/www/html/project/artisan', ['mode' => 'full']);

        $this->assertStringStartsWith("'/usr/bin/php8.4' '/var/www/html/project/artisan' backup:run", $command);
        $this->assertStringNotContainsString('php-fpm8.4', $command);
    }

    public function test_it_falls_back_to_php_from_path_when_no_absolute_cli_binary_is_available(): void
    {
        $launcher = new class($this->app, new NullLogger()) extends BackgroundBackupLauncher
        {
            public function commandForTest(string $artisan, array $options = []): string
            {
                return $this->buildShellCommand($artisan, $options);
            }

            protected function phpBinaryCandidates(): array
            {
                return [
                    '/usr/sbin/php-fpm8.4',
                    'php',
                ];
            }

            protected function isUsablePhpBinary(string $binary): bool
            {
                return $binary === 'php';
            }
        };

        $command = $launcher->commandForTest('/var/www/html/project/artisan', ['mode' => 'full']);

        $this->assertStringStartsWith("'php' '/var/www/html/project/artisan' backup:run", $command);
    }
}
