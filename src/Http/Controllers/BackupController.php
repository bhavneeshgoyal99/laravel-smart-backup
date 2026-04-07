<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Http\Controllers;

use BhavneeshGoyal\LaravelSmartBackup\Services\BackupManager;
use BhavneeshGoyal\LaravelSmartBackup\Services\BackupHistoryService;
use BhavneeshGoyal\LaravelSmartBackup\Services\RestoreService;
use BhavneeshGoyal\LaravelSmartBackup\Services\SettingsService;
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
        protected RestoreService $restoreService,
        protected BackupHistoryService $history,
        protected Config $config,
        protected SettingsService $settings
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
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string'],
        ]);

        $result = $this->backupManager->run($data);

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

        $result = $this->restoreService->restore($data);

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

    public function settings(): View
    {
        return view('smart-backup::settings', [
            'settings' => $this->settings->all(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        foreach ($this->settings->sanitizeInput($request->except(['_token', '_method'])) as $key => $value) {
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
