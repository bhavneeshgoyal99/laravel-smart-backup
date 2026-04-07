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
                ])
            </div>
        </div>
    @else
        <label for="{{ $id }}">
            {{ $label }}

            @if (isset($fieldHelp[$path]))
                <span class="field-note">{{ $fieldHelp[$path] }}</span>
            @elseif (is_array($value))
                <span class="field-note">Enter one value per line.</span>
            @endif

            @if (is_bool($value))
                <input type="hidden" name="{{ $name }}" value="0">
                <div class="checkbox-field">
                    <input
                        id="{{ $id }}"
                        type="checkbox"
                        name="{{ $name }}"
                        value="1"
                        {{ old($path, $value) ? 'checked' : '' }}
                    >
                    <span class="muted">{{ old($path, $value) ? 'Enabled' : 'Disabled' }}</span>
                </div>
            @elseif ($options !== null)
                <select id="{{ $id }}" name="{{ $name }}">
                    @foreach ($options as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" {{ (string) $selected === (string) $optionValue ? 'selected' : '' }}>
                            {{ $optionLabel }}
                        </option>
                    @endforeach
                </select>
            @elseif (is_array($value))
                <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ max(3, count($arrayValue) + 1) }}">{{ implode(PHP_EOL, $arrayValue) }}</textarea>
            @elseif (is_int($value))
                <input id="{{ $id }}" type="number" name="{{ $name }}" value="{{ $selected }}">
            @elseif (is_float($value))
                <input id="{{ $id }}" type="number" step="any" name="{{ $name }}" value="{{ $selected }}">
            @else
                <input id="{{ $id }}" type="text" name="{{ $name }}" value="{{ $selected ?? '' }}">
            @endif

            @error($path)
                <span class="error-text">{{ $message }}</span>
            @enderror
        </label>
    @endif
@endforeach
