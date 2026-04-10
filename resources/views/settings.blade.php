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
        'schedule.hourly_minute' => 'Minute within the hour for hourly runs. Use a value from 0 to 59.',
        'schedule.time' => 'Run time used for daily, weekly, and monthly schedules.',
        'schedule.day_of_week' => 'Used only when the frequency is set to weekly. Sunday = 0.',
        'schedule.day_of_month' => 'Used only when the frequency is set to monthly.',
        'schedule.tables' => 'Use one table name per line. If left empty, scheduled backups use the included tables from Settings.',
    ];

    $fieldLabels = [
        'tables.include' => 'Include',
        'tables.exclude' => 'Exclude',
        'schedule.hourly_minute' => 'Hourly Minute',
        'schedule.time' => 'Time',
        'restore.disable_foreign_key_constraints' => 'Temporarily Disable Foreign Key Constraints During Restore',
        'resilience.retry_sleep_microseconds' => 'Retry Sleep Seconds',
    ];

    $fieldTypes = [
        'schedule.enabled' => 'boolean_radio',
        'schedule.without_overlapping' => 'boolean_radio',
        'restore.disable_foreign_key_constraints' => 'boolean_radio',
        'maintenance.enabled' => 'boolean_radio',
        'schedule.hourly_minute' => 'number_0_59',
        'schedule.time' => 'time',
        'resilience.retry_sleep_microseconds' => 'decimal_number',
        'incremental.last_backup_at' => 'date',
    ];

    $fieldVisibilityRules = [
        'schedule.frequency' => ['schedule.enabled' => ['1']],
        'schedule.hourly_minute' => [
            'schedule.enabled' => ['1'],
            'schedule.frequency' => ['hourly'],
        ],
        'schedule.time' => [
            'schedule.enabled' => ['1'],
            'schedule.frequency' => ['daily', 'weekly', 'monthly'],
        ],
        'schedule.timezone' => ['schedule.enabled' => ['1']],
        'schedule.mode' => ['schedule.enabled' => ['1']],
        'schedule.format' => ['schedule.enabled' => ['1']],
        'schedule.tables' => ['schedule.enabled' => ['1']],
        'schedule.without_overlapping' => ['schedule.enabled' => ['1']],
        'schedule.day_of_week' => [
            'schedule.enabled' => ['1'],
            'schedule.frequency' => ['weekly'],
        ],
        'schedule.day_of_month' => [
            'schedule.enabled' => ['1'],
            'schedule.frequency' => ['monthly'],
        ],
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

    $tableInclude = array_values(array_unique(array_map('strval', (array) ($settings['tables']['include'] ?? []))));
    $tableExclude = array_values(array_unique(array_map('strval', (array) ($settings['tables']['exclude'] ?? []))));
    $availableTables = array_values(array_unique(array_map('strval', $availableTables ?? [])));
    $scheduleTables = array_values(array_filter(array_map('strval', (array) ($settings['schedule']['tables'] ?? []))));

    if ($scheduleTables === []) {
        $scheduleTables = $tableInclude;
        $settings['schedule']['tables'] = $scheduleTables;
    }

    $tableIncludedOptions = $tableInclude !== []
        ? array_values(array_unique(array_diff($tableInclude, $tableExclude)))
        : array_values(array_unique(array_diff($availableTables, $tableExclude)));

    $tableIncludedOptions = array_values(array_unique(array_merge(
        $tableIncludedOptions,
        array_diff($availableTables, $tableExclude, $tableIncludedOptions)
    )));

    sort($tableIncludedOptions);
    sort($tableExclude);

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

        .settings-save {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .settings-save h2,
        .settings-save p {
            margin: 0;
        }

        .settings-save-copy {
            display: grid;
            gap: 6px;
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

        .table-transfer {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            gap: 18px;
            align-items: center;
        }

        .table-transfer-column {
            display: grid;
            gap: 10px;
        }

        .table-transfer-column h3,
        .table-transfer-column p {
            margin: 0;
        }

        .table-transfer select {
            min-height: 320px;
        }

        .table-transfer-actions {
            display: grid;
            gap: 10px;
        }

        .table-transfer-actions .button {
            min-width: 52px;
            min-height: 44px;
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1;
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

        .boolean-choices {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            min-height: 46px;
            align-items: center;
        }

        .boolean-choice {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.94rem;
        }

        .boolean-choice input {
            width: auto;
            margin: 0;
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

        textarea {
            resize: none;
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
            .settings-save {
                flex-direction: column;
                align-items: stretch;
            }

            .tab-panel-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .settings-panel {
                grid-template-columns: 1fr;
            }

            .table-transfer {
                grid-template-columns: 1fr;
            }

            .table-transfer-actions {
                grid-auto-flow: column;
                justify-content: start;
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

                        @if ($tabKey === 'tables')
                            <div class="table-transfer">
                                <div class="table-transfer-column">
                                    <h3>Included Tables</h3>
                                    <p class="muted">These tables will be selected for backup.</p>
                                    <select multiple data-table-include name="tables[include][]">
                                        @foreach ($tableIncludedOptions as $tableName)
                                            <option value="{{ $tableName }}" selected>{{ $tableName }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="table-transfer-actions">
                                    <button type="button" class="button" data-move-to-exclude>&rarr;</button>
                                    <button type="button" class="button" data-move-to-include>&larr;</button>
                                </div>

                                <div class="table-transfer-column">
                                    <h3>Excluded Tables</h3>
                                    <p class="muted">Move tables here to skip them during backup.</p>
                                    <select multiple data-table-exclude name="tables[exclude][]">
                                        @foreach ($tableExclude as $tableName)
                                            <option value="{{ $tableName }}" selected>{{ $tableName }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @else
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
                                    'fieldTypes' => $fieldTypes,
                                    'fieldVisibilityRules' => $fieldVisibilityRules,
                                ])
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>

        <div class="card">
            <div class="settings-save">
                <div class="settings-save-copy">
                    <h2>Save Changes</h2>
                    <p class="muted">Save the currently edited configuration to the database.</p>
                </div>
                <button type="submit" class="button primary">Save Settings</button>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = Array.from(document.querySelectorAll('[data-tab-target]'));
            const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
            const activeTabInput = document.querySelector('[data-active-tab-input]');
            const storageKey = 'smart-backup-settings-active-tab';
            const settingsForm = document.querySelector('.settings-form');
            const includeSelect = document.querySelector('[data-table-include]');
            const excludeSelect = document.querySelector('[data-table-exclude]');
            const moveToExcludeButton = document.querySelector('[data-move-to-exclude]');
            const moveToIncludeButton = document.querySelector('[data-move-to-include]');
            const visibilityFields = Array.from(document.querySelectorAll('[data-visibility-rules]'));

            if (tabs.length === 0 || panels.length === 0) {
                return;
            }

            const readControlValue = function (path) {
                const control = document.querySelector('[data-setting-path="' + path + '"]');

                if (!control) {
                    return null;
                }

                if (control.matches('[type="radio"]')) {
                    const checked = document.querySelector('[data-setting-path="' + path + '"]:checked');

                    return checked ? checked.value : null;
                }

                if (control.matches('[type="checkbox"]')) {
                    return control.checked ? '1' : '0';
                }

                return control.value;
            };

            const updateScheduleVisibility = function () {
                visibilityFields.forEach(function (field) {
                    const rawRules = field.getAttribute('data-visibility-rules');

                    if (!rawRules) {
                        field.hidden = false;
                        return;
                    }

                    let isVisible = true;

                    try {
                        const rules = JSON.parse(rawRules);

                        isVisible = Object.keys(rules).every(function (path) {
                            return rules[path].includes(readControlValue(path));
                        });
                    } catch (error) {
                        isVisible = true;
                    }

                    field.hidden = !isVisible;
                });
            };

            const moveSelectedOptions = function (fromSelect, toSelect) {
                if (!fromSelect || !toSelect) {
                    return;
                }

                const selectedOptions = Array.from(fromSelect.selectedOptions);

                selectedOptions.forEach(function (option) {
                    option.selected = false;
                    toSelect.appendChild(option);
                });

                [fromSelect, toSelect].forEach(function (select) {
                    Array.from(select.options)
                        .sort(function (left, right) {
                            return left.text.localeCompare(right.text);
                        })
                        .forEach(function (option) {
                            select.appendChild(option);
                            option.selected = true;
                        });
                });
            };

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

            document.querySelectorAll('[data-setting-path]').forEach(function (control) {
                control.addEventListener('change', updateScheduleVisibility);
            });

            if (moveToExcludeButton) {
                moveToExcludeButton.addEventListener('click', function () {
                    moveSelectedOptions(includeSelect, excludeSelect);
                });
            }

            if (moveToIncludeButton) {
                moveToIncludeButton.addEventListener('click', function () {
                    moveSelectedOptions(excludeSelect, includeSelect);
                });
            }

            if (settingsForm) {
                settingsForm.addEventListener('submit', function () {
                    [includeSelect, excludeSelect].forEach(function (select) {
                        if (!select) {
                            return;
                        }

                        Array.from(select.options).forEach(function (option) {
                            option.selected = true;
                        });
                    });
                });
            }

            const savedTab = window.localStorage.getItem(storageKey);
            const defaultTab = @json($initialTab);
            const targetTab = tabs.some(function (tab) {
                return tab.getAttribute('data-tab-target') === savedTab;
            }) ? savedTab : defaultTab;

            activateTab(targetTab);
            updateScheduleVisibility();
        });
    </script>
@endsection
