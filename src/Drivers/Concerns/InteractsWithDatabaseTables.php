<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Drivers\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait InteractsWithDatabaseTables
{
    protected function resolvePrimaryKey(object $schema, string $table): ?string
    {
        if (! method_exists($schema, 'getIndexes')) {
            return null;
        }

        foreach ((array) $schema->getIndexes($table) as $index) {
            $isPrimary = (bool) Arr::get($index, 'primary', false)
                || Str::lower((string) Arr::get($index, 'name', '')) === 'primary';

            if (! $isPrimary) {
                continue;
            }

            $columns = Arr::get($index, 'columns', []);

            if (is_array($columns) && count($columns) === 1) {
                return (string) Arr::first($columns);
            }
        }

        return null;
    }

    protected function resolveTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
