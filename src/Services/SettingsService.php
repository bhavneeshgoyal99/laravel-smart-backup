<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;

class SettingsService
{
    public function __construct(
        protected Config $config,
        protected DatabaseManager $database
    ) {
    }

    public function get(string $key, $default = null)
    {
        $key = $this->normalizeKey($key);
        $default = func_num_args() === 1
            ? $this->config->get('backup.' . $key)
            : $default;

        if (! $this->tableExists()) {
            return $default;
        }

        $setting = $this->database->table('smart_backup_settings')->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return $this->restoreValue($setting->value, $default);
    }

    public function set(string $key, $value): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $key = $this->normalizeKey($key);
        $now = now();
        $query = $this->database->table('smart_backup_settings')->where('key', $key);
        $payload = [
            'value' => $this->storeValue($value),
            'updated_at' => $now,
        ];

        if ($query->exists()) {
            $query->update($payload);

            return;
        }

        $this->database->table('smart_backup_settings')->insert([
            'key' => $key,
            'value' => $payload['value'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function all(): array
    {
        $defaults = $this->defaults();

        if (! $this->tableExists()) {
            return $defaults;
        }

        $stored = $this->database->table('smart_backup_settings')->pluck('value', 'key')->toArray();
        $stored = $this->normalizeStoredSettings($stored);
        $flatDefaults = $this->leafDefaults();
        $flatSettings = [];

        foreach ($flatDefaults as $key => $default) {
            $flatSettings[$key] = array_key_exists($key, $stored)
                ? $this->restoreValue($stored[$key], $default)
                : $default;
        }

        return Arr::undot($flatSettings);
    }

    public function defaults(): array
    {
        $defaults = (array) $this->config->get('backup', []);

        unset($defaults['drivers'], $defaults['ui']);

        return $defaults;
    }

    public function leafDefaults(): array
    {
        return $this->flattenSettings($this->defaults());
    }

    public function sanitizeInput(array $input): array
    {
        $input = $this->normalizeInputKeys($input);
        $defaults = $this->defaults();
        $values = [];

        foreach ($this->leafDefaults() as $path => $default) {
            $values[$path] = $this->coerceInputValue(
                Arr::get($input, $path),
                $default
            );
        }

        return $values;
    }

    public function delete(string $key): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $this->database->table('smart_backup_settings')->where('key', $this->normalizeKey($key))->delete();
    }

    protected function tableExists(): bool
    {
        return $this->database->getSchemaBuilder()->hasTable('smart_backup_settings');
    }

    protected function flattenSettings(array $settings, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($settings as $key => $value) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            $path = $this->normalizeKey($path);

            if (is_array($value) && ! $this->isLeafArray($value)) {
                $flattened += $this->flattenSettings($value, $path);
                continue;
            }

            $flattened[$path] = $value;
        }

        return $flattened;
    }

    protected function coerceInputValue(mixed $value, mixed $default): mixed
    {
        if (is_array($default) && $this->isLeafArray($default)) {
            if (is_array($value)) {
                return array_values(array_filter(array_map(
                    static fn ($item) => trim((string) $item),
                    $value
                ), static fn (string $item) => $item !== ''));
            }

            if (! is_string($value)) {
                return [];
            }

            $parts = preg_split('/[\r\n,]+/', $value) ?: [];

            return array_values(array_filter(array_map(
                static fn (string $item) => trim($item),
                $parts
            ), static fn (string $item) => $item !== ''));
        }

        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($default)) {
            return is_numeric($value) ? (int) $value : $default;
        }

        if (is_float($default)) {
            return is_numeric($value) ? (float) $value : $default;
        }

        if ($default === null) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                return null;
            }

            return (string) $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value ?? '';
        }

        return $default;
    }

    protected function restoreValue(mixed $value, mixed $default): mixed
    {
        if (is_array($default) && $this->isLeafArray($default)) {
            if (is_array($value)) {
                return $value;
            }

            if ($value === null || $value === '') {
                return [];
            }

            $decoded = json_decode((string) $value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [(string) $value];
        }

        if (is_bool($default)) {
            if (is_bool($value)) {
                return $value;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($default)) {
            return is_numeric($value) ? (int) $value : $default;
        }

        if (is_float($default)) {
            return is_numeric($value) ? (float) $value : $default;
        }

        if ($default === null) {
            return $value === '' ? null : $value;
        }

        return $value;
    }

    protected function storeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }

    protected function isLeafArray(array $value): bool
    {
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
    }

    protected function normalizeStoredSettings(array $settings): array
    {
        $normalized = [];

        foreach ($settings as $key => $value) {
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        return $normalized;
    }

    protected function normalizeInputKeys(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            $normalizedKey = is_string($key)
                ? $this->normalizeKey($key)
                : $key;

            $normalized[$normalizedKey] = is_array($value)
                ? $this->normalizeInputKeys($value)
                : $value;
        }

        return $normalized;
    }

    protected function normalizeKey(string $key): string
    {
        $segments = explode('.', $key);

        $segments = array_map(static function (string $segment): string {
            return trim($segment, " \t\n\r\0\x0B`'\"");
        }, $segments);

        return implode('.', $segments);
    }
}
