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
        'connection' => collect(config('database.connections', []))
            ->keys()
            ->mapWithKeys(fn (string $connection) => [$connection => $connection])
            ->all(),
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
        'schedule.timezone' => collect(\DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone) => [$timezone => $timezone])
            ->all(),
        'schedule.mode' => [
            'full' => 'Full',
            'incremental' => 'Incremental',
        ],
        'schedule.format' => [
            'sql' => 'SQL',
            'json' => 'JSON',
            'csv' => 'CSV',
        ],
        'maintenance.policy' => [
            'always_off' => 'Always Off',
            'full_only' => 'Full Only',
            'always_on' => 'Always On',
        ],
        'storage.disk' => collect(config('filesystems.disks', []))
            ->keys()
            ->mapWithKeys(fn (string $disk) => [$disk => $disk])
            ->all(),
        'restore.disk' => collect(config('filesystems.disks', []))
            ->keys()
            ->mapWithKeys(fn (string $disk) => [$disk => $disk])
            ->all(),
    ];

    $fieldHelp = [
        'incremental.columns' => 'Enter one column name per line.',
        'tables.include' => 'Leave empty to include every table except those in the exclude list.',
        'tables.exclude' => 'Enter one table name per line.',
        'schedule.tables' => 'Optional table subset for scheduled backups. Use one table name per line.',
    ];

    $fieldLabels = [
        'tables.include' => 'Include',
        'tables.exclude' => 'Exclude',
        'restore.disable_foreign_key_constraints' => 'Temporarily Disable Foreign Key Constraints During Restore',
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

    $tabs = [];

    if (count($generalSettings) > 0) {
        $tabs['general'] = [
            'label' => 'General',
            'fields' => $generalSettings,
            'prefix' => '',
        ];
    }

    foreach ($groupedSettings as $group => $values) {
        $tabs[$group] = [
            'label' => $formatLabel($group),
            'fields' => $values,
            'prefix' => $group,
        ];
    }

    $initialTab = array_key_first($tabs) ?? 'general';
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

        .settings-shell {
            display: grid;
            gap: 22px;
        }

        .settings-hero {
            grid-template-columns: 1fr;
        }

        .settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 4px;
        }

        .settings-tab {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.72);
            color: var(--muted);
            padding: 10px 14px;
            border-radius: 999px;
            cursor: pointer;
            font: inherit;
            transition: background 120ms ease, color 120ms ease, border-color 120ms ease;
        }

        .settings-tab.active {
            background: var(--ink);
            color: #fff;
            border-color: var(--ink);
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .tab-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .tab-panel-header p {
            margin: 0;
        }

        .settings-panel {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .settings-subgroup {
            padding-top: 14px;
            border-top: 1px dashed var(--line);
            grid-column: 1 / -1;
        }

        .settings-subgroup:first-child {
            padding-top: 0;
            border-top: 0;
        }

        .settings-subgroup h3 {
            margin-bottom: 12px;
        }

        .settings-panel > label {
            min-width: 0;
        }

        .settings-subgroup .settings-panel {
            grid-template-columns: 1fr;
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
            gap: 24px;
            grid-template-columns: 1fr !important;
        }

        @media (max-width: 768px) {
            .tab-panel-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .settings-panel {
                grid-template-columns: 1fr;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <form method="POST" action="{{ route($routePrefix . 'settings.update') }}" class="settings-form">
        @csrf
        <input type="hidden" name="active_tab" value="{{ $initialTab }}" data-active-tab-input>

        <div class="card">
            <p class="muted">Package Settings</p>
            <h1>Manage the full backup configuration.</h1>
        </div>

        <div class="card settings-shell">
            <div class="settings-tabs" role="tablist" aria-label="Settings Sections">
                @foreach ($tabs as $tabKey => $tab)
                    <button
                        type="button"
                        class="settings-tab {{ $tabKey === $initialTab ? 'active' : '' }}"
                        data-tab-target="{{ $tabKey }}"
                        role="tab"
                        aria-selected="{{ $tabKey === $initialTab ? 'true' : 'false' }}"
                    >
                        {{ $tab['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="settings-grid">
                @foreach ($tabs as $tabKey => $tab)
                    <section
                        class="tab-panel {{ $tabKey === $initialTab ? 'active' : '' }}"
                        data-tab-panel="{{ $tabKey }}"
                        role="tabpanel"
                    >
                        <div class="tab-panel-header">
                            <div>
                                <h2>{{ $tab['label'] }}</h2>
                                <p class="muted">Update the {{ strtolower($tab['label']) }} settings and save when you are ready.</p>
                            </div>
                        </div>

                        <div class="settings-panel">
                            @include('smart-backup::partials.settings-fields', [
                                'fields' => $tab['fields'],
                                'prefix' => $tab['prefix'],
                                'isLeafArray' => $isLeafArray,
                                'formatLabel' => $formatLabel,
                                'buildFieldName' => $buildFieldName,
                                'fieldOptions' => $fieldOptions,
                                'fieldHelp' => $fieldHelp,
                                'fieldLabels' => $fieldLabels,
                            ])
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2>Save Changes</h2>
            <button type="submit" class="button primary">Save Settings</button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = Array.from(document.querySelectorAll('[data-tab-target]'));
            const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
            const activeTabInput = document.querySelector('[data-active-tab-input]');
            const storageKey = 'smart-backup-settings-active-tab';

            if (tabs.length === 0 || panels.length === 0) {
                return;
            }

            const activateTab = function (target) {
                tabs.forEach(function (tab) {
                    const isActive = tab.getAttribute('data-tab-target') === target;
                    tab.classList.toggle('active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                panels.forEach(function (panel) {
                    panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target);
                });

                 if (activeTabInput) {
                    activeTabInput.value = target;
                }

                window.localStorage.setItem(storageKey, target);
            };

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activateTab(tab.getAttribute('data-tab-target'));
                });
            });

            const savedTab = window.localStorage.getItem(storageKey);
            const defaultTab = @json($initialTab);
            const targetTab = tabs.some(function (tab) {
                return tab.getAttribute('data-tab-target') === savedTab;
            }) ? savedTab : defaultTab;

            activateTab(targetTab);
        });
    </script>
@endsection
