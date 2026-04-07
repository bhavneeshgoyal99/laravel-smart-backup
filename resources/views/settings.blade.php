@extends('smart-backup::layouts.app')

@php
    $routePrefix = config('backup.ui.name_prefix', 'smart-backup.');

    $isLeafArray = function (array $value): bool {
        if ($value === []) {
            return true;
        }

        if (! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    };

    $formatLabel = fn (string $value): string => ucwords(str_replace('_', ' ', $value));

    $buildFieldName = function (string $path): string {
        $segments = explode('.', $path);
        $name = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= '[' . $segment . ']';
        }

        return $name;
    };

    $fieldOptions = [
        'mode' => [
            'full' => 'Full',
            'incremental' => 'Incremental',
        ],
        'format' => [
            'sql' => 'SQL',
            'json' => 'JSON',
            'csv' => 'CSV',
        ],
        'incremental.missing_timestamps' => [
            'full' => 'Fallback To Full Backup',
            'skip' => 'Skip Table',
        ],
        'schedule.frequency' => [
            'hourly' => 'Hourly',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
        ],
        'schedule.mode' => [
            '' => 'Use Default',
            'full' => 'Full',
            'incremental' => 'Incremental',
        ],
        'schedule.format' => [
            '' => 'Use Default',
            'sql' => 'SQL',
            'json' => 'JSON',
            'csv' => 'CSV',
        ],
        'maintenance.policy' => [
            'always_off' => 'Always Off',
            'full_only' => 'Full Only',
            'always_on' => 'Always On',
        ],
        'drivers.default' => [
            'full' => 'Full',
            'incremental' => 'Incremental',
            'local' => 'Local',
        ],
    ];

    $fieldHelp = [
        'incremental.columns' => 'Enter one column name per line.',
        'tables.include' => 'Leave empty to include every table except those in the exclude list.',
        'tables.exclude' => 'Enter one table name per line.',
        'schedule.tables' => 'Optional table subset for scheduled backups. Use one table name per line.',
        'ui.middleware' => 'One middleware entry per line.',
    ];

    $generalSettings = [];
    $groupedSettings = [];

    foreach ($settings as $key => $value) {
        if (is_array($value) && ! $isLeafArray($value)) {
            $groupedSettings[$key] = $value;
            continue;
        }

        $generalSettings[$key] = $value;
    }

    $leafCount = 0;
    $countLeaves = function (array $values) use (&$countLeaves, $isLeafArray, &$leafCount): void {
        foreach ($values as $value) {
            if (is_array($value) && ! $isLeafArray($value)) {
                $countLeaves($value);
                continue;
            }

            $leafCount++;
        }
    };

    $countLeaves($settings);
@endphp

@section('content')
    <style>
        .settings-form {
            display: grid;
            gap: 24px;
        }

        .settings-grid {
            align-items: start;
        }

        .settings-panel {
            display: grid;
            gap: 16px;
        }

        .settings-subgroup {
            padding-top: 14px;
            border-top: 1px dashed var(--line);
        }

        .settings-subgroup:first-child {
            padding-top: 0;
            border-top: 0;
        }

        .settings-subgroup h3 {
            margin-bottom: 12px;
        }

        .checkbox-field {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 46px;
        }

        .checkbox-field input {
            width: auto;
        }

        .field-note,
        .error-text {
            font-size: 0.85rem;
        }

        .field-note {
            color: var(--muted);
        }

        .error-text {
            color: var(--danger);
        }

        .settings-panel > .card {
            width: 100%;
        }
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @if (session('status'))
        <div class="flash success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route($routePrefix . 'settings.update') }}" class="settings-form">
        @csrf

        <div class="hero">
            <div class="card">
                <p class="muted">Package Settings</p>
                <h1>Manage the full backup configuration.</h1>
                {{-- <p class="muted">This form now follows the same config tree as the old read-only view, so every visible setting can be edited and stored in the database.</p> --}}
            </div>

            <div class="card">
                <h2>Coverage</h2>
                <div class="meta-list">
                    <div class="meta-row">
                        <span class="muted">Top-level groups</span>
                        <strong>{{ count($groupedSettings) + (count($generalSettings) > 0 ? 1 : 0) }}</strong>
                    </div>
                    <div class="meta-row">
                        <span class="muted">Config fields</span>
                        <strong>{{ $leafCount }}</strong>
                    </div>
                    <div class="meta-row">
                        <span class="muted">Storage mode</span>
                        <strong>Database-backed</strong>
                    </div>
                </div>
            </div>
        </div>

        @if (count($generalSettings) > 0)
            <div class="card">
                <h2>General</h2>
                <div class="settings-panel">
                    @include('smart-backup::partials.settings-fields', [
                        'fields' => $generalSettings,
                        'prefix' => '',
                        'isLeafArray' => $isLeafArray,
                        'formatLabel' => $formatLabel,
                        'buildFieldName' => $buildFieldName,
                        'fieldOptions' => $fieldOptions,
                        'fieldHelp' => $fieldHelp,
                    ])
                </div>
            </div>
        @endif

        <div class="settings-panel">
            @foreach ($groupedSettings as $group => $values)
                <div class="card">
                    <h2>{{ $formatLabel($group) }}</h2>
                    <div class="settings-panel">
                        @include('smart-backup::partials.settings-fields', [
                            'fields' => $values,
                            'prefix' => $group,
                            'isLeafArray' => $isLeafArray,
                            'formatLabel' => $formatLabel,
                            'buildFieldName' => $buildFieldName,
                            'fieldOptions' => $fieldOptions,
                            'fieldHelp' => $fieldHelp,
                        ])
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <h2>Save Changes</h2>
            <p class="muted">Submitting this form writes every config leaf shown above to the `smart_backup_settings` table.</p>
            <button type="submit" class="button primary">Save Settings</button>
        </div>
    </form>
@endsection
