<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Schema;

use PDO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;

/** Verifies required package tables, columns, indexes, and foreign keys. */
final readonly class PdoSchemaVerifier
{
    /**
     * Required indexes from Schema 001 and 002, with columns in key order. Schema 003 has its own lock-specific checks.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array REQUIRED_INDEXES = [
        'maa_slug_scopes' => [
            'PRIMARY' => ['id'],
            'uk_scope_identity' => ['namespace', 'locale_key', 'context_key'],
            'ix_scope_profile' => ['profile_key'],
        ],
        'maa_slug_bindings' => [
            'PRIMARY' => ['id'],
            'uk_binding_identity' => ['scope_id', 'entity_type', 'entity_key'],
            'uk_binding_id_scope' => ['id', 'scope_id'],
            'ix_binding_current_registry' => ['current_registry_id'],
            'ix_binding_status' => ['status'],
            'ix_binding_scope_status_id' => ['scope_id', 'status', 'id'],
        ],
        'maa_slug_operations' => [
            'PRIMARY' => ['id'],
            'uk_operation_key' => ['operation_key'],
            'ix_operation_type_created_id' => ['operation_type', 'created_at', 'id'],
        ],
        'maa_slug_operation_bindings' => [
            'PRIMARY' => ['id'],
            'uk_operation_binding_idempotency' => ['binding_id', 'idempotency_key'],
            'uk_operation_binding_pair' => ['operation_id', 'binding_id'],
            'uk_operation_binding_role' => ['operation_id', 'participant_role'],
            'ix_operation_binding_role' => ['operation_id', 'participant_role'],
        ],
        'maa_slug_registry' => [
            'PRIMARY' => ['id'],
            'uk_registry_scope_slug' => ['scope_id', 'slug'],
            'uk_registry_binding_slug' => ['binding_id', 'slug'],
            'ix_registry_binding_role' => ['binding_id', 'claim_role'],
            'ix_registry_scope_role_id' => ['scope_id', 'claim_role', 'id'],
        ],
        'maa_slug_history' => [
            'PRIMARY' => ['id'],
            'uk_history_binding_sequence' => ['binding_id', 'sequence_no'],
            'ix_history_binding_occurred_id' => ['binding_id', 'occurred_at', 'id'],
            'ix_history_event_occurred_id' => ['event_type', 'occurred_at', 'id'],
            'ix_history_occurred_id' => ['occurred_at', 'id'],
            'ix_history_scope_occurred_id' => ['scope_namespace_snapshot', 'scope_locale_snapshot', 'scope_context_snapshot', 'occurred_at', 'id'],
        ],
    ];

    /**
     * Required foreign keys with source table, target table, source columns, and target columns in key order.
     *
     * @var array<string, array{string, string, list<string>, list<string>}>
     */
    private const array REQUIRED_FOREIGN_KEYS = [
        'fk_binding_scope' => ['maa_slug_bindings', 'maa_slug_scopes', ['scope_id'], ['id']],
        'fk_operation_binding_operation' => ['maa_slug_operation_bindings', 'maa_slug_operations', ['operation_id'], ['id']],
        'fk_operation_binding_binding' => ['maa_slug_operation_bindings', 'maa_slug_bindings', ['binding_id'], ['id']],
        'fk_registry_scope' => ['maa_slug_registry', 'maa_slug_scopes', ['scope_id'], ['id']],
        'fk_registry_binding_scope' => ['maa_slug_registry', 'maa_slug_bindings', ['binding_id', 'scope_id'], ['id', 'scope_id']],
        'fk_history_binding' => ['maa_slug_history', 'maa_slug_bindings', ['binding_id'], ['id']],
        'fk_history_operation' => ['maa_slug_history', 'maa_slug_operations', ['operation_id'], ['id']],
    ];

    /**
     * Required columns from the installed schema with their normalized type, nullability, and identity rule.
     *
     * @var array<string, array<string, string>>
     */
    private const array REQUIRED_COLUMNS = [
        'maa_slug_scopes' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'namespace' => 'varchar(63) NOT NULL',
            'locale_key' => 'varchar(191) NOT NULL',
            'context_key' => 'varchar(191) NOT NULL',
            'profile_key' => 'varchar(63) NOT NULL',
            'created_at' => 'datetime(6) NOT NULL',
            'updated_at' => 'datetime(6) NOT NULL',
        ],
        'maa_slug_bindings' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'scope_id' => 'bigint unsigned NOT NULL',
            'entity_type' => 'varchar(63) NOT NULL',
            'entity_key' => 'varchar(191) NOT NULL',
            'current_registry_id' => 'bigint unsigned NULL',
            'status' => 'varchar(16) NOT NULL',
            'revision' => 'bigint unsigned NOT NULL',
            'history_sequence' => 'bigint unsigned NOT NULL',
            'created_at' => 'datetime(6) NOT NULL',
            'updated_at' => 'datetime(6) NOT NULL',
        ],
        'maa_slug_operations' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'operation_key' => 'char(32) NOT NULL',
            'operation_type' => 'varchar(48) NOT NULL',
            'request_fingerprint' => 'char(64) NOT NULL',
            'result_type' => 'varchar(48) NOT NULL',
            'result_schema_version' => 'smallint unsigned NOT NULL',
            'result_snapshot' => 'longtext NULL',
            'status' => 'varchar(16) NOT NULL',
            'created_at' => 'datetime(6) NOT NULL',
            'completed_at' => 'datetime(6) NULL',
        ],
        'maa_slug_operation_bindings' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'idempotency_key' => 'varchar(191) NOT NULL',
            'operation_id' => 'bigint unsigned NOT NULL',
            'binding_id' => 'bigint unsigned NOT NULL',
            'participant_role' => 'varchar(16) NOT NULL',
        ],
        'maa_slug_registry' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'scope_id' => 'bigint unsigned NOT NULL',
            'binding_id' => 'bigint unsigned NOT NULL',
            'slug' => 'varchar(160) NOT NULL',
            'claim_role' => 'varchar(24) NOT NULL',
            'claimed_at' => 'datetime(6) NOT NULL',
            'updated_at' => 'datetime(6) NOT NULL',
        ],
        'maa_slug_history' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'binding_id' => 'bigint unsigned NOT NULL',
            'sequence_no' => 'bigint unsigned NOT NULL',
            'event_type' => 'varchar(32) NOT NULL',
            'scope_namespace_snapshot' => 'varchar(63) NOT NULL',
            'scope_locale_snapshot' => 'varchar(191) NOT NULL',
            'scope_context_snapshot' => 'varchar(191) NOT NULL',
            'entity_type_snapshot' => 'varchar(63) NOT NULL',
            'entity_key_snapshot' => 'varchar(191) NOT NULL',
            'slug_snapshot' => 'varchar(160) NULL',
            'previous_slug_snapshot' => 'varchar(160) NULL',
            'claim_role_snapshot' => 'varchar(24) NULL',
            'previous_claim_role_snapshot' => 'varchar(24) NULL',
            'related_namespace' => 'varchar(63) NULL',
            'related_locale' => 'varchar(191) NULL',
            'related_context' => 'varchar(191) NULL',
            'related_entity_type' => 'varchar(63) NULL',
            'related_entity_key' => 'varchar(191) NULL',
            'operation_id' => 'bigint unsigned NULL',
            'operation_key' => 'char(32) NULL',
            'actor_key' => 'varchar(191) NULL',
            'reason' => 'varchar(500) NULL',
            'correlation_key' => 'varchar(191) NULL',
            'occurred_at' => 'datetime(6) NOT NULL',
            'original_occurred_at' => 'datetime(6) NULL',
        ],
        'maa_slug_registry_lock_keys' => [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'scope_id' => 'bigint unsigned NOT NULL',
            'scope_end' => 'tinyint unsigned NOT NULL',
            'binding_partition' => 'bigint unsigned NOT NULL',
            'key_kind' => 'tinyint unsigned NOT NULL',
            'slug' => 'varchar(160) NOT NULL',
            'registry_id' => 'bigint unsigned NULL',
            'owner_binding_id' => 'bigint unsigned NULL',
        ],
    ];

    /** Stores the connection whose installed schema is being checked. */
    public function __construct(private PDO $pdo) {}

    /** Fails closed when the installed schema does not match the package contract. */
    public function assertInstalled(): void
    {
        $expected = [
            'maa_slug_scopes',
            'maa_slug_bindings',
            'maa_slug_operations',
            'maa_slug_operation_bindings',
            'maa_slug_registry',
            'maa_slug_registry_lock_keys',
            'maa_slug_history',
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_' ORDER BY TABLE_NAME",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect the package schema.');
        }
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = PdoRowHydrator::many($rawRows);
        $names = array_map(static fn(array $row): string => PdoRowHydrator::string($row, 'TABLE_NAME'), $rows);
        sort($names);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($names !== $sortedExpected) {
            throw new SlugPersistenceInvariantException('Package schema table set is not exact.');
        }
        foreach ($rows as $row) {
            if (PdoRowHydrator::string($row, 'ENGINE') !== 'InnoDB' || PdoRowHydrator::string($row, 'TABLE_COLLATION') !== 'utf8mb4_bin') {
                throw new SlugPersistenceInvariantException('Package schema does not satisfy exact InnoDB/utf8mb4 semantics.');
            }
        }

        $this->assertRegistryLockProjection();
        $this->assertWriteStructure(checkComments: true);
    }

    /** Checks the required columns, indexes and foreign keys before the first mutation on this engine. */
    public function assertMutationStructure(): void
    {
        $this->assertWriteStructure(checkComments: false);
    }

    /** Keeps schema documentation checks out of runtime admission while checking the same physical structure. */
    private function assertWriteStructure(bool $checkComments): void
    {
        $this->assertRequiredIndexes();
        $this->assertRequiredForeignKeys();
        $this->assertColumnContract($checkComments);
    }

    /** Verifies the physical lock order and the completed projection backfill. */
    public function assertRegistryLockProjection(): void
    {
        $statement = $this->pdo->query(
            "SHOW INDEX FROM maa_slug_registry_lock_keys WHERE Key_name IN ('PRIMARY', 'uk_registry_lock_partition')",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect the Registry lock projection indexes.');
        }

        $indexes = [];
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach (PdoRowHydrator::many($rawRows) as $row) {
            if (($row['Visible'] ?? null) === 'NO' || ($row['Ignored'] ?? null) === 'YES') {
                throw new SlugPersistenceInvariantException('Registry lock projection requires visible, usable indexes.');
            }
            if (PdoRowHydrator::nonNegativeInt($row, 'Non_unique') !== 0
                || $row['Sub_part'] !== null
                || $row['Collation'] !== 'A') {
                throw new SlugPersistenceInvariantException('Registry lock projection requires full ascending unique keys.');
            }

            $name = PdoRowHydrator::string($row, 'Key_name');
            $position = PdoRowHydrator::nonNegativeInt($row, 'Seq_in_index');
            $indexes[$name][$position] = PdoRowHydrator::string($row, 'Column_name');
        }
        foreach ($indexes as &$columns) {
            ksort($columns);
        }
        unset($columns);
        if (($indexes['PRIMARY'] ?? []) !== [1 => 'id']
            || ($indexes['uk_registry_lock_partition'] ?? []) !== [1 => 'scope_id', 2 => 'scope_end', 3 => 'binding_partition', 4 => 'key_kind', 5 => 'slug']) {
            throw new SlugPersistenceInvariantException('Registry lock projection requires the id primary key and the ordered unique partition index.');
        }

        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'maa_slug_registry_lock_keys' AND ("
            . "(COLUMN_NAME = 'id' AND DATA_TYPE = 'bigint' AND COLUMN_TYPE LIKE '% unsigned' AND EXTRA = 'auto_increment') "
            . "OR (COLUMN_NAME = 'slug' AND COLLATION_NAME = 'utf8mb4_bin'))",
        );
        if ($columns === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect the Registry lock projection columns.');
        }
        $matchingColumns = $columns->fetchAll(PDO::FETCH_COLUMN);
        if (! in_array('id', $matchingColumns, true)) {
            throw new SlugPersistenceInvariantException('Registry lock projection requires an auto-increment unsigned bigint identity.');
        }
        if (! in_array('slug', $matchingColumns, true)) {
            throw new SlugPersistenceInvariantException('Registry lock projection slug column has unexpected collation. Expected utf8mb4_bin.');
        }

        $marker = $this->pdo->query(
            "SELECT 1 FROM maa_slug_registry_lock_keys FORCE INDEX (uk_registry_lock_partition) WHERE scope_id = 0 AND scope_end = 0 AND binding_partition = 0 AND key_kind = 2 AND slug = '' "
            . 'AND registry_id IS NULL AND owner_binding_id IS NULL',
        );
        if ($marker === false || (int) $marker->fetchColumn() !== 1) {
            throw new SlugPersistenceInvariantException('Registry lock projection backfill has not been completed.');
        }
    }

    /** Verifies the unique and lookup indexes required by lifecycle invariants. */
    private function assertRequiredIndexes(): void
    {
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART, COLLATION FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_' "
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package indexes.');
        }

        $actual = [];
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach (PdoRowHydrator::many($rawRows) as $row) {
            $table = PdoRowHydrator::string($row, 'TABLE_NAME');
            $index = PdoRowHydrator::string($row, 'INDEX_NAME');
            if (! isset(self::REQUIRED_INDEXES[$table][$index])) {
                continue;
            }
            $unique = $index === 'PRIMARY' || str_starts_with($index, 'uk_');
            if (
                PdoRowHydrator::nonNegativeInt($row, 'NON_UNIQUE') !== (int) !$unique
                || $row['SUB_PART'] !== null
                || $row['COLLATION'] !== 'A'
            ) {
                throw new SlugPersistenceInvariantException(sprintf('Required package index %s.%s has incompatible key semantics.', $table, $index));
            }
            $actual[$table . '.' . $index][] = PdoRowHydrator::string($row, 'COLUMN_NAME');
        }

        foreach (self::REQUIRED_INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $columns) {
                if (($actual[$table . '.' . $index] ?? null) !== $columns) {
                    throw new SlugPersistenceInvariantException(sprintf('Required package index %s.%s is missing or has incompatible columns.', $table, $index));
                }
            }
        }
    }

    /** Verifies each required foreign key's columns, local target and restrictive update and delete rules. */
    private function assertRequiredForeignKeys(): void
    {
        $statement = $this->pdo->query(
            'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, '
            . 'r.DELETE_RULE, r.UPDATE_RULE, CAST(k.REFERENCED_TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) AS LOCAL_REFERENCE '
            . 'FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
            . "WHERE k.TABLE_SCHEMA = DATABASE() AND LEFT(CAST(k.TABLE_NAME AS BINARY), 9) = 'maa_slug_' "
            . 'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package foreign keys.');
        }

        $actual = [];
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach (PdoRowHydrator::many($rawRows) as $row) {
            $name = PdoRowHydrator::string($row, 'CONSTRAINT_NAME');
            $required = self::REQUIRED_FOREIGN_KEYS[$name] ?? null;
            if ($required === null) {
                continue;
            }
            if (
                $row['TABLE_NAME'] !== $required[0]
                || $row['REFERENCED_TABLE_NAME'] !== $required[1]
                || PdoRowHydrator::nonNegativeInt($row, 'LOCAL_REFERENCE') !== 1
                || ! in_array($row['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true)
                || ! in_array($row['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true)
            ) {
                throw new SlugPersistenceInvariantException(sprintf('Required package foreign key %s has incompatible rules or target.', $name));
            }
            $actual[$name][] = [
                PdoRowHydrator::string($row, 'COLUMN_NAME'),
                PdoRowHydrator::string($row, 'REFERENCED_COLUMN_NAME'),
            ];
        }

        foreach (self::REQUIRED_FOREIGN_KEYS as $name => [, , $columns, $referenced]) {
            $pairs = $actual[$name] ?? [];
            if (array_column($pairs, 0) !== $columns || array_column($pairs, 1) !== $referenced) {
                throw new SlugPersistenceInvariantException(sprintf('Required package foreign key %s is missing or has incompatible columns.', $name));
            }
        }
    }

    /** Verifies required column names and SQL definitions for the installed schema. */
    private function assertColumnContract(bool $checkComments): void
    {
        $asciiColumns = [
            'maa_slug_scopes.namespace',
            'maa_slug_scopes.profile_key',
            'maa_slug_bindings.status',
            'maa_slug_operations.operation_key',
            'maa_slug_operations.operation_type',
            'maa_slug_operations.request_fingerprint',
            'maa_slug_operations.result_type',
            'maa_slug_operations.status',
            'maa_slug_operation_bindings.participant_role',
            'maa_slug_registry.claim_role',
            'maa_slug_history.event_type',
            'maa_slug_history.scope_namespace_snapshot',
            'maa_slug_history.claim_role_snapshot',
            'maa_slug_history.previous_claim_role_snapshot',
            'maa_slug_history.related_namespace',
            'maa_slug_history.operation_key',
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, DATETIME_PRECISION, COLLATION_NAME, EXTRA, COLUMN_COMMENT, COLUMN_DEFAULT "
            . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_'",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package columns.');
        }
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach (PdoRowHydrator::many($rawRows) as $row) {
            $table = PdoRowHydrator::string($row, 'TABLE_NAME');
            $column = PdoRowHydrator::string($row, 'COLUMN_NAME');
            $dataType = strtolower(PdoRowHydrator::string($row, 'DATA_TYPE'));
            $comment = $row['COLUMN_COMMENT'] ?? null;
            if ($checkComments && (! is_string($comment) || mb_strlen(trim($comment), 'UTF-8') < 8)) {
                throw new SlugPersistenceInvariantException(sprintf('Column %s.%s must have a meaningful schema comment.', $table, $column));
            }
            if ($dataType === 'json' || str_contains(strtolower(PdoRowHydrator::string($row, 'EXTRA')), 'generated')) {
                throw new SlugPersistenceInvariantException('Package schema must not depend on native JSON or generated columns.');
            }
            if (in_array($dataType, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                $collation = $row['COLLATION_NAME'] ?? null;
                $expectedCollation = in_array($table . '.' . $column, $asciiColumns, true) ? 'ascii_bin' : 'utf8mb4_bin';
                if ($collation !== $expectedCollation) {
                    throw new SlugPersistenceInvariantException(sprintf('Column %s.%s has unexpected collation.', $table, $column));
                }
            }
            if ($dataType === 'datetime' && PdoRowHydrator::nonNegativeInt($row, 'DATETIME_PRECISION') !== 6) {
                throw new SlugPersistenceInvariantException('Package timestamps must use DATETIME(6).');
            }
            $required = self::REQUIRED_COLUMNS[$table][$column] ?? null;
            if ($required !== null) {
                // MariaDB exposes integer display widths that do not affect the column's value domain.
                $type = PdoRowHydrator::string($row, 'COLUMN_TYPE');
                $type = preg_replace('/\A(bigint|int|smallint|tinyint)\(\d+\)/', '$1', strtolower($type));
                $definition = $type . ($row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL');
                if (PdoRowHydrator::string($row, 'EXTRA') === 'auto_increment') {
                    $definition .= ' AUTO_INCREMENT';
                }
                if ($definition !== $required) {
                    throw new SlugPersistenceInvariantException(sprintf('Required package column %s.%s has an incompatible definition.', $table, $column));
                }
                $seen[$table][$column] = true;
            } elseif (
                isset(self::REQUIRED_COLUMNS[$table])
                && $row['IS_NULLABLE'] === 'NO'
                && $row['COLUMN_DEFAULT'] === null
                && PdoRowHydrator::string($row, 'EXTRA') !== 'auto_increment'
            ) {
                throw new SlugPersistenceInvariantException(sprintf('Additional package column %s.%s requires an insert value.', $table, $column));
            }
        }

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $_definition) {
                if (! isset($seen[$table][$column])) {
                    throw new SlugPersistenceInvariantException(sprintf('Required package column %s.%s is missing.', $table, $column));
                }
            }
        }
    }
}
