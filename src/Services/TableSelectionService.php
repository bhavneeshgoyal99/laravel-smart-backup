<?php

namespace BhavneeshGoyal\LaravelSmartBackup\Services;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

class TableSelectionService
{
    public function __construct(protected DatabaseManager $database)
    {
    }

    public function all(?string $connectionName): array
    {
        $schema = $this->database->connection($connectionName)->getSchemaBuilder();

        if (! method_exists($schema, 'getTableListing')) {
            throw new InvalidArgumentException('Automatic table discovery is not supported by the current Laravel/database combination.');
        }

        return $this->normalize($schema->getTableListing());
    }

    public function resolve(?string $connectionName, array $include = [], array $exclude = []): array
    {
        if ($include !== []) {
            return $this->normalize(array_diff($include, $exclude));
        }

        $schema = $this->database->connection($connectionName)->getSchemaBuilder();

        if (! method_exists($schema, 'getTableListing')) {
            throw new InvalidArgumentException('Automatic table discovery is not supported by the current Laravel/database combination. Configure backup.tables.include explicitly.');
        }

        return $this->normalize(array_diff($schema->getTableListing(), $exclude));
    }

    protected function normalize(array $tables): array
    {
        $tables = array_map(static fn ($table) => (string) $table, $tables);
        $tables = array_filter($tables, static fn ($table) => $table !== '');
        $tables = array_values(array_unique($tables));

        sort($tables);

        return $tables;
    }
}
