@foreach ($fields as $key => $value)
    @php
        $path = $prefix === '' ? $key : $prefix . '.' . $key;
        $normalizedSegments = array_map(
            static fn ($segment) => trim((string) $segment, " \t\n\r\0\x0B`'\""),
            explode('.', $path)
        );
        $normalizedPath = implode('.', $normalizedSegments);
        $normalizedKey = trim((string) $key, " \t\n\r\0\x0B`'\"");
        $id = 'setting-' . str_replace(['.', '[', ']'], '-', $path);
        $name = $buildFieldName($path);
        $label = $fieldLabels[$normalizedPath] ?? $formatLabel($normalizedKey);
        $selected = old($path, $value);
        $options = $fieldOptions[$path] ?? null;
        $arrayValue = is_array($selected) ? $selected : (is_array($value) ? $value : []);
        $fieldType = $fieldTypes[$normalizedPath] ?? null;
        $visibilityRules = $fieldVisibilityRules[$normalizedPath] ?? null;
        $visibilityAttribute = $visibilityRules !== null
            ? json_encode($visibilityRules, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)
            : null;
        $dateValue = null;

        if ($fieldType === 'date' && $selected !== null && $selected !== '') {
            try {
                $dateValue = \Illuminate\Support\Carbon::parse((string) $selected)->format('Y-m-d');
            } catch (\Throwable $exception) {
                $dateValue = (string) $selected;
            }
        }
    @endphp

    @if (is_array($value) && ! $isLeafArray($value))
        <div class="settings-subgroup">
            <h3>{{ $label }}</h3>
            <div class="settings-panel">
                @include('smart-backup::partials.settings-fields', [
                    'fields' => $value,
                    'prefix' => $path,
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
        </div>
    @else
        <div
            class="settings-field"
            data-field-path="{{ $normalizedPath }}"
            @if ($visibilityAttribute !== null)
                data-visibility-rules="{{ $visibilityAttribute }}"
            @endif
        >
            {{ $label }}

            @if (isset($fieldHelp[$path]))
                <span class="field-note">{{ $fieldHelp[$path] }}</span>
            @elseif (is_array($value))
                <span class="field-note">Enter one value per line.</span>
            @endif

            @if ($fieldType === 'boolean_radio')
                <div class="boolean-choices">
                    <label class="boolean-choice" for="{{ $id }}-yes">
                        <input
                            id="{{ $id }}-yes"
                            type="radio"
                            name="{{ $name }}"
                            value="1"
                            data-setting-path="{{ $normalizedPath }}"
                            {{ (string) old($path, $value ? '1' : '0') === '1' ? 'checked' : '' }}
                        >
                        <span>Yes</span>
                    </label>

                    <label class="boolean-choice" for="{{ $id }}-no">
                        <input
                            id="{{ $id }}-no"
                            type="radio"
                            name="{{ $name }}"
                            value="0"
                            data-setting-path="{{ $normalizedPath }}"
                            {{ (string) old($path, $value ? '1' : '0') === '0' ? 'checked' : '' }}
                        >
                        <span>No</span>
                    </label>
                </div>
            @elseif (is_bool($value))
                <input type="hidden" name="{{ $name }}" value="0">
                <div class="checkbox-field">
                    <input
                        id="{{ $id }}"
                        type="checkbox"
                        name="{{ $name }}"
                        value="1"
                        data-setting-path="{{ $normalizedPath }}"
                        {{ old($path, $value) ? 'checked' : '' }}
                    >
                    <span class="muted">{{ old($path, $value) ? 'Enabled' : 'Disabled' }}</span>
                </div>
            @elseif ($options !== null)
                <select id="{{ $id }}" name="{{ $name }}" data-setting-path="{{ $normalizedPath }}">
                    @foreach ($options as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" {{ (string) $selected === (string) $optionValue ? 'selected' : '' }}>
                            {{ $optionLabel }}
                        </option>
                    @endforeach
                </select>
            @elseif ($fieldType === 'date')
                <input id="{{ $id }}" type="date" name="{{ $name }}" value="{{ $dateValue }}" data-setting-path="{{ $normalizedPath }}">
            @elseif ($fieldType === 'time')
                <input id="{{ $id }}" type="time" name="{{ $name }}" value="{{ $selected ?? '' }}" data-setting-path="{{ $normalizedPath }}">
            @elseif ($fieldType === 'decimal_number')
                <input id="{{ $id }}" type="number" step="any" name="{{ $name }}" value="{{ $selected }}" data-setting-path="{{ $normalizedPath }}">
            @elseif ($fieldType === 'number_0_59')
                <input id="{{ $id }}" type="number" min="0" max="59" name="{{ $name }}" value="{{ $selected }}" data-setting-path="{{ $normalizedPath }}">
            @elseif (is_array($value))
                <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ max(3, count($arrayValue) + 1) }}" data-setting-path="{{ $normalizedPath }}">{{ implode(PHP_EOL, $arrayValue) }}</textarea>
            @elseif (is_int($value))
                <input id="{{ $id }}" type="number" name="{{ $name }}" value="{{ $selected }}" data-setting-path="{{ $normalizedPath }}">
            @elseif (is_float($value))
                <input id="{{ $id }}" type="number" step="any" name="{{ $name }}" value="{{ $selected }}" data-setting-path="{{ $normalizedPath }}">
            @else
                <input id="{{ $id }}" type="text" name="{{ $name }}" value="{{ $selected ?? '' }}" data-setting-path="{{ $normalizedPath }}">
            @endif

            @error($path)
                <span class="error-text">{{ $message }}</span>
            @enderror
            </div>
    @endif
@endforeach
