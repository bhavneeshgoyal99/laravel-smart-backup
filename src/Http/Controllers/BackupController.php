<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Http\Controllers;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupHistoryService;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackgroundBackupLauncher;
use BhavneeshGoyal\LaravelSmartBackup\Services\RestoreService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
use BhavneeshGoyal\LaravelSmartBackup\Services\TableSelectionService;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Throwable;

class BackupController extends Controller
{
    public function __construct(
        protected BackupManager $backupManager,
        protected BackgroundBackupLauncher $backgroundBackupLauncher,
        protected RestoreService $restoreService,
        protected BackupHistoryService $history,
        protected Config $config,
        protected SettingsService $settings,
        protected TableSelectionService $tableSelection
    ) {
    }

    public function index(Request $request): JsonResponse|View
    {
        $limit = max(1, min((int) $request->integer('limit', 25), 100));
        $payload = $this->history->listRuns($limit);

        if (! $request->expectsJson()) {
            return view('smart-backup::dashboard', [
                'backups' => $payload,
                'config' => $this->history->dashboardConfig(),
            ]);
        }

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function run(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'in:full,incremental'],
            'format' => ['nullable', 'in:sql,json,csv'],
            'driver' => ['nullable', 'string'],
            'tables_text' => ['nullable', 'string'],
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string'],
        ]);

        if (array_key_exists('tables_text', $data)) {
            $data['tables'] = collect(preg_split('/\r\n|\r|\n/', (string) $data['tables_text']))
                ->map(static fn (string $table): string => trim($table))
                ->filter()
                ->values()
                ->all();

            unset($data['tables_text']);
        }

        if ($this->shouldRunInBackground()) {
            try {
                $this->backgroundBackupLauncher->dispatch($data);
            } catch (Throwable $exception) {
                if (! $request->expectsJson()) {
                    return redirect()
                        ->route($this->routeName('backups.index'))
                        ->with('error', $exception->getMessage());
                }

                return response()->json([
                    'message' => $exception->getMessage(),
                ], 500);
            }

            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('status', 'Backup started in the background.');
            }

            return response()->json([
                'message' => 'Backup started in the background.',
                'status' => 'accepted',
            ], 202);
        }

        try {
            $result = $this->backupManager->run($data);
        } catch (Throwable $exception) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', $exception->getMessage());
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        if (! $request->expectsJson()) {
            return redirect()
                ->route($this->routeName('backups.index'))
                ->with('status', 'Backup run completed successfully.');
        }

        return response()->json([
            'message' => 'Backup run completed.',
            'data' => $result,
        ]);
    }

    public function restore(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'string'],
            'table' => ['nullable', 'string'],
            'disk' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->restoreService->restore($data);
        } catch (Throwable $exception) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', $exception->getMessage());
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (! $request->expectsJson()) {
            return redirect()
                ->route($this->routeName('backups.index'))
                ->with('status', 'Restore completed successfully.');
        }

        return response()->json([
            'message' => 'Restore completed.',
            'data' => $result,
        ]);
    }

    public function restoreRun(Request $request, int|string $run): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string'],
        ]);

        $backupRun = $this->history->findRun($run);

        if ($backupRun === null) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', 'Backup run not found.');
            }

            return response()->json([
                'message' => 'Backup run not found.',
            ], 404);
        }

        $files = $this->history->runTables($run)->filter(
            static fn (array $table) => is_string($table['file_path'] ?? null) && $table['file_path'] !== ''
        );

        if ($files->isEmpty()) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', 'No tracked files were found for this backup run.');
            }

            return response()->json([
                'message' => 'No tracked files were found for this backup run.',
            ], 422);
        }

        $results = [];

        try {
            foreach ($files as $file) {
                $results[] = $this->restoreService->restore([
                    'file' => $file['file_path'],
                    'table' => $file['table_name'],
                    'disk' => $backupRun->disk,
                    'password' => $data['password'] ?? null,
                ]);
            }
        } catch (Throwable $exception) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', $exception->getMessage());
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        if (! $request->expectsJson()) {
            return redirect()
                ->route($this->routeName('backups.index'))
                ->with('status', sprintf('Restore completed successfully for %d file(s).', count($results)));
        }

        return response()->json([
            'message' => 'Restore completed.',
            'data' => $results,
        ]);
    }

    public function destroy(Request $request, int|string $run): JsonResponse|RedirectResponse
    {
        if ($this->history->findRun($run) === null) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', 'Backup run not found.');
            }

            return response()->json([
                'message' => 'Backup run not found.',
            ], 404);
        }

        try {
            $this->history->deleteRun($run);
        } catch (Throwable $exception) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route($this->routeName('backups.index'))
                    ->with('error', $exception->getMessage());
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        if (! $request->expectsJson()) {
            return redirect()
                ->route($this->routeName('backups.index'))
                ->with('status', 'Backup deleted successfully.');
        }

        return response()->json([
            'message' => 'Backup deleted successfully.',
        ]);
    }

    protected function shouldRunInBackground(): bool
    {
        return (bool) $this->config->get('backup.ui.dispatch_after_response', true);
    }

    public function settings(): View
    {
        $settings = $this->settings->all();
        $availableTables = [];

        $settings['schedule'] = array_merge([
            'enabled' => false,
            'frequency' => 'daily',
            'hourly_minute' => 0,
            'time' => '02:00',
            'day_of_week' => 0,
            'day_of_month' => 1,
            'timezone' => config('app.timezone'),
            'mode' => null,
            'format' => null,
            'tables' => [],
            'without_overlapping' => true,
        ], (array) ($settings['schedule'] ?? []));

        if (isset($settings['resilience']['retry_sleep_microseconds'])) {
            $seconds = ((int) $settings['resilience']['retry_sleep_microseconds']) / 1000000;
            $settings['resilience']['retry_sleep_microseconds'] = fmod($seconds, 1.0) === 0.0
                ? (int) $seconds
                : $seconds;
        }

        try {
            $availableTables = $this->tableSelection->all($this->settings->get('connection'));
        } catch (Throwable) {
            $availableTables = [];
        }

        return view('smart-backup::settings', [
            'settings' => $settings,
            'availableTables' => $availableTables,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $request->validate([
            'schedule.enabled' => ['nullable', 'boolean'],
            'schedule.frequency' => ['nullable', 'in:hourly,daily,weekly,monthly'],
            'schedule.hourly_minute' => ['nullable', 'integer', 'between:0,59'],
            'schedule.time' => ['nullable', 'date_format:H:i'],
            'schedule.day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'schedule.day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'schedule.timezone' => ['nullable', 'timezone'],
            'schedule.mode' => ['nullable', 'in:full,incremental'],
            'schedule.format' => ['nullable', 'in:sql,json,csv'],
            'schedule.tables' => ['nullable'],
            'schedule.without_overlapping' => ['nullable', 'boolean'],
        ]);

        $settings = $this->settings->sanitizeInput($request->except(['_token', '_method']));

        $retrySleepSeconds = $request->input('resilience.retry_sleep_microseconds');

        if ($retrySleepSeconds !== null && $retrySleepSeconds !== '' && is_numeric($retrySleepSeconds)) {
            $settings['resilience.retry_sleep_microseconds'] = max(
                0,
                (int) round(((float) $retrySleepSeconds) * 1000000)
            );
        }

        if ($request->has('schedule.hourly_minute')) {
            $settings['schedule.hourly_minute'] = (int) $request->input('schedule.hourly_minute', 0);
        }

        foreach ($settings as $key => $value) {
            $this->settings->set($key, $value);
        }

        return redirect()
            ->route($this->routeName('settings'))
            ->with('status', 'Settings updated successfully.');
    }

    protected function routeName(string $suffix): string
    {
        return (string) $this->config->get('backup.ui.name_prefix', 'smart-backup.') . $suffix;
    }
}
