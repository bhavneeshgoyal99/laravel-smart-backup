@php($routePrefix = config('backup.ui.name_prefix', 'smart-backup.'))
@extends('smart-backup::layouts.app')

@php
    $completed = $backups->where('status', 'completed')->count();
    $failed = $backups->where('status', 'failed')->count();
@endphp

@section('content')
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
                            @foreach ($backup['tables'] as $table)
                                <div>{{ $table['table_name'] }}<br><span class="muted">{{ $table['file_path'] }}</span></div>
                            @endforeach
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
@endsection
