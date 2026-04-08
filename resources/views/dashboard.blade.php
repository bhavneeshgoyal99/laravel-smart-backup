@extends('smart-backup::layouts.app')

@php
    $completed = $backups->where('status', 'completed')->count();
    $failed = $backups->where('status', 'failed')->count();
    $routePrefix = config('backup.ui.name_prefix', 'smart-backup.');
@endphp

@section('content')
    <style>
        .files-button {
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
                    </select>
                </label>

                <label>
                    Tables
                    <input type="text" name="tables[]" placeholder="users, orders">
                </label>

                <button type="submit" class="button primary">Run Backup</button>
            </form>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Restore Backup</h2>
            <form method="POST" action="{{ route($routePrefix . 'backups.restore') }}" class="stack">
                @csrf
                <label>
                    Backup File
                    <input type="text" name="file" placeholder="backups/database/full/2026/04/07/users-20260407_010000.sql" required>
                </label>

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
                <th>Files</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($backups as $backup)
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
                        <form method="POST" action="{{ route($routePrefix . 'backups.destroy', $backup['id']) }}" class="inline" onsubmit="return confirm('Delete this backup and its tracked files?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="muted">No backup runs have been recorded yet.</td>
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const openButtons = Array.from(document.querySelectorAll('[data-files-open]'));
            const modals = Array.from(document.querySelectorAll('.files-modal'));

            const closeModal = function (modal) {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            };

            const openModal = function (modal) {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
            };

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const modal = document.getElementById(button.getAttribute('data-files-open'));

                    if (modal) {
                        openModal(modal);
                    }
                });
            });

            modals.forEach(function (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal || event.target.hasAttribute('data-files-close')) {
                        closeModal(modal);
                    }
                });
            });

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
        });
    </script>
@endsection
