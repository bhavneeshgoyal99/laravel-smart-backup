@extends('smart-backup::layouts.app')

@php
    $completed = $backups->where('status', 'completed')->count();
    $failed = $backups->where('status', 'failed')->count();
    $routePrefix = config('backup.ui.name_prefix', 'smart-backup.');
    $restoreRuns = $backups
        ->filter(fn ($backup) => ($backup['status'] ?? null) === 'completed' && count($backup['tables'] ?? []) > 0)
        ->map(fn ($backup) => [
            'id' => $backup['id'],
            'label' => sprintf(
                '#%s | %s | %s',
                $backup['id'],
                ucfirst((string) ($backup['type'] ?? 'backup')),
                $backup['started_at'] ?? 'n/a'
            ),
            'disk' => $backup['disk'] ?? null,
            'tables' => collect($backup['tables'] ?? [])->map(fn ($table) => [
                'table_name' => $table['table_name'] ?? 'n/a',
                'file_path' => $table['file_path'] ?? '',
            ])->values()->all(),
        ])
        ->values();
@endphp

@section('content')
    <style>
        .files-button {
            min-width: 92px;
        }

        .restore-button {
            min-width: 92px;
        }

        .files-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(18, 32, 47, 0.44);
            z-index: 40;
        }

        .files-modal.open {
            display: flex;
        }

        .files-modal-card {
            width: min(760px, 100%);
            max-height: min(80vh, 720px);
            overflow: auto;
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(216, 225, 232, 0.95);
            border-radius: 24px;
            box-shadow: 0 28px 60px rgba(18, 32, 47, 0.18);
            padding: 22px;
        }

        .files-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .files-modal-head h3,
        .files-modal-head p {
            margin: 0;
        }

        .files-list {
            display: grid;
            gap: 12px;
        }

        .files-item {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #f8fafb;
        }

        .files-item strong {
            display: block;
            margin-bottom: 4px;
        }

        .files-item code {
            display: block;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.88rem;
        }

        .modal-note {
            margin: 0 0 16px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .restore-picker-note {
            margin-top: -4px;
        }

        .table-preview {
            min-height: 132px;
            resize: vertical;
        }

    </style>

    <div class="hero">
        <div class="card">
            <p class="muted">Backup Operations</p>
            <h1>Keep database snapshots close and recovery even closer.</h1>
            <p class="muted">Run full or incremental backups, review recent runs, and restore a file without leaving this lightweight dashboard.</p>

            <div class="stats">
                <div class="stat">
                    <strong>{{ $backups->count() }}</strong>
                    <span class="muted">Recent runs</span>
                </div>
                <div class="stat">
                    <strong>{{ $completed }}</strong>
                    <span class="muted">Completed</span>
                </div>
                <div class="stat">
                    <strong>{{ $failed }}</strong>
                    <span class="muted">Failed</span>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Run Backup</h2>
            <form method="POST" action="{{ route($routePrefix . 'backups.run') }}" class="stack">
                @csrf
                <label>
                    Mode
                    <select name="mode">
                        <option value="full" {{ ($config['mode'] ?? 'full') === 'full' ? 'selected' : '' }}>Full</option>
                        <option value="incremental" {{ ($config['mode'] ?? 'full') === 'incremental' ? 'selected' : '' }}>Incremental</option>
                    </select>
                </label>

                <label>
                    Format
                    <select name="format">
                        <option value="sql" {{ ($config['format'] ?? 'sql') === 'sql' ? 'selected' : '' }}>SQL</option>
                        <option value="json" {{ ($config['format'] ?? 'sql') === 'json' ? 'selected' : '' }}>JSON</option>
                        <option value="csv" {{ ($config['format'] ?? 'sql') === 'csv' ? 'selected' : '' }}>CSV</option>
                    </select>
                </label>

                <label>
                    Tables
                    <textarea name="tables_text" class="table-preview" placeholder="users&#10;orders">{{ implode("\n", (array) ($config['tables_include'] ?? [])) }}</textarea>
                    @if (!empty($config['tables_include']))
                        <span class="field-note">Included tables from Settings are prefilled here. You can add or remove table names before running the backup.</span>
                    @else
                        <span class="muted">Leave this empty to consider all tables except those excluded in Settings.</span>
                    @endif
                </label>

                <button type="submit" class="button primary">Run Backup</button>
            </form>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Restore Individual Table</h2>
            <p class="muted">Select a backup run, then choose the exact table file you want to restore back into the database.</p>
            <form method="POST" action="{{ route($routePrefix . 'backups.restore') }}" class="stack" data-restore-picker-form>
                @csrf
                <label>
                    Backup Run
                    <select data-restore-run-picker>
                        <option value="">Select a backup run</option>
                        @foreach ($restoreRuns as $restoreRun)
                            <option value="{{ $restoreRun['id'] }}">{{ $restoreRun['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Backup File
                    <select name="file" data-restore-file-picker required>
                        <option value="">Select a backup file</option>
                    </select>
                    <span class="field-note restore-picker-note">Choose a run first, then pick an individual file from that backup.</span>
                </label>

                <input type="hidden" name="disk" value="" data-restore-disk-input>

                <label>
                    Table Override
                    <input type="text" name="table" placeholder="Optional table name">
                </label>

                <label>
                    Password
                    <input type="password" name="password" placeholder="Optional restore password">
                </label>

                <button type="submit" class="button">Restore Backup</button>
            </form>
        </div>

        <div class="card">
            <h2>Storage Snapshot</h2>
            <div class="meta-list">
                <div class="meta-row">
                    <span class="muted">Disk</span>
                    <strong>{{ $config['disk'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Path</span>
                    <strong>{{ $config['path'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Default mode</span>
                    <strong>{{ $config['mode'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Default format</span>
                    <strong>{{ $config['format'] }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Backup List</h2>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Status</th>
                <th>Format</th>
                <th>Started</th>
                <th>Location</th>
                <th>Files</th>
                <th>Restore</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($backups as $backup)
                @php
                    $disk = isset($backup['disk']) && $backup['disk'] !== ''
                        ? (string) $backup['disk']
                        : null;
                    $basePath = isset($backup['base_path']) && $backup['base_path'] !== ''
                        ? trim((string) $backup['base_path'], '/')
                        : null;
                    $location = $disk !== null && $basePath !== null
                        ? sprintf('(%s) %s', $disk, $basePath)
                        : ($disk !== null ? sprintf('(%s)', $disk) : ($basePath ?? 'n/a'));
                @endphp
                <tr>
                    <td>#{{ $backup['id'] }}</td>
                    <td>{{ $backup['type'] }}</td>
                    <td>
                        <span class="badge {{ $backup['status'] === 'completed' ? 'success' : ($backup['status'] === 'failed' ? 'failed' : '') }}">
                            {{ $backup['status'] }}
                        </span>
                    </td>
                    <td>{{ $backup['format'] ?? 'n/a' }}</td>
                    <td>{{ $backup['started_at'] ?? 'n/a' }}</td>
                    <td><code>{{ $location }}</code></td>
                    <td>
                        @if (count($backup['tables']) === 0)
                            <span class="muted">No files tracked</span>
                        @else
                            <button
                                type="button"
                                class="button files-button"
                                data-files-open="backup-files-{{ $backup['id'] }}"
                            >
                                View ({{ count($backup['tables']) }})
                            </button>
                        @endif
                    </td>
                    <td>
                        @if ($backup['status'] === 'failed')
                            <span class="muted">Unavailable</span>
                        @elseif (count($backup['tables']) === 0)
                            <span class="muted">n/a</span>
                        @else
                            <button
                                type="button"
                                class="button restore-button"
                                data-restore-open
                                data-restore-action="{{ route($routePrefix . 'backups.restore-run', $backup['id']) }}"
                                data-restore-run="#{{ $backup['id'] }}"
                                data-restore-count="{{ count($backup['tables']) }}"
                            >
                                Restore
                            </button>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route($routePrefix . 'backups.destroy', $backup['id']) }}" class="inline" onsubmit="return confirm('Delete this backup and its tracked files?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="muted">No backup runs have been recorded yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @foreach ($backups as $backup)
        @if (count($backup['tables']) > 0)
            <div class="files-modal" id="backup-files-{{ $backup['id'] }}" aria-hidden="true">
                <div class="files-modal-card">
                    <div class="files-modal-head">
                        <div>
                            <p class="muted">Backup Files</p>
                            <h3>Run #{{ $backup['id'] }}</h3>
                        </div>
                        <button type="button" class="button" data-files-close>Close</button>
                    </div>

                    <div class="files-list">
                        @foreach ($backup['tables'] as $table)
                            <div class="files-item">
                                <strong>{{ $table['table_name'] }}</strong>
                                <code>{{ $table['file_path'] }}</code>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    <div class="files-modal" id="backup-restore-modal" aria-hidden="true">
        <div class="files-modal-card">
            <div class="files-modal-head">
                <div>
                    <p class="muted">Restore Backup</p>
                    <h3 data-restore-title>Restore Run</h3>
                </div>
                <button type="button" class="button" data-restore-close>Close</button>
            </div>

            <p class="muted modal-note" data-restore-description></p>

            <form method="POST" action="" class="stack" data-restore-form>
                @csrf

                @if (!empty($config['restore_password_required']))
                    <label>
                        Restore Password
                        <input type="password" name="password" placeholder="Enter restore password" required>
                    </label>
                @endif

                <div class="modal-actions">
                    <button type="button" class="button" data-restore-close>Cancel</button>
                    <button type="submit" class="button primary">Confirm Restore</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const restoreRuns = @json($restoreRuns);
            const openButtons = Array.from(document.querySelectorAll('[data-files-open]'));
            const modals = Array.from(document.querySelectorAll('.files-modal'));
            const restoreButtons = Array.from(document.querySelectorAll('[data-restore-open]'));
            const restoreModal = document.getElementById('backup-restore-modal');
            const restoreForm = document.querySelector('[data-restore-form]');
            const restoreTitle = document.querySelector('[data-restore-title]');
            const restoreDescription = document.querySelector('[data-restore-description]');
            const restoreRunPicker = document.querySelector('[data-restore-run-picker]');
            const restoreFilePicker = document.querySelector('[data-restore-file-picker]');
            const restoreDiskInput = document.querySelector('[data-restore-disk-input]');

            const closeModal = function (modal) {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            };

            const openModal = function (modal) {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
            };

            const updateRestoreFiles = function (runId) {
                if (!restoreFilePicker || !restoreDiskInput) {
                    return;
                }

                restoreFilePicker.innerHTML = '<option value="">Select a backup file</option>';
                restoreDiskInput.value = '';

                const selectedRun = restoreRuns.find(function (run) {
                    return String(run.id) === String(runId);
                });

                if (!selectedRun) {
                    return;
                }

                restoreDiskInput.value = selectedRun.disk ?? '';

                selectedRun.tables.forEach(function (table) {
                    if (!table.file_path) {
                        return;
                    }

                    const option = document.createElement('option');
                    option.value = table.file_path;
                    option.textContent = table.table_name + ' | ' + table.file_path;
                    restoreFilePicker.appendChild(option);
                });
            };

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const modal = document.getElementById(button.getAttribute('data-files-open'));

                    if (modal) {
                        openModal(modal);
                    }
                });
            });

            if (restoreRunPicker) {
                restoreRunPicker.addEventListener('change', function () {
                    updateRestoreFiles(restoreRunPicker.value);
                });
            }

            modals.forEach(function (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal || event.target.hasAttribute('data-files-close')) {
                        closeModal(modal);
                    }
                });
            });

            restoreButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!restoreModal || !restoreForm || !restoreTitle || !restoreDescription) {
                        return;
                    }

                    restoreForm.setAttribute('action', button.getAttribute('data-restore-action'));
                    restoreTitle.textContent = 'Restore Run ' + button.getAttribute('data-restore-run');
                    restoreDescription.textContent = 'This will restore ' + button.getAttribute('data-restore-count') + ' tracked file(s) back into the configured database.';
                    openModal(restoreModal);
                });
            });

            if (restoreModal) {
                restoreModal.addEventListener('click', function (event) {
                    if (event.target === restoreModal || event.target.hasAttribute('data-restore-close')) {
                        closeModal(restoreModal);
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                modals.forEach(function (modal) {
                    if (modal.classList.contains('open')) {
                        closeModal(modal);
                    }
                });
            });

            if (restoreRunPicker && restoreRunPicker.value) {
                updateRestoreFiles(restoreRunPicker.value);
            }
        });
    </script>
@endsection
