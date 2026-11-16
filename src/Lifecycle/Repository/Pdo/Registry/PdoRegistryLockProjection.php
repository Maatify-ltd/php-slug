<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Registry;

use PDO;
use PDOStatement;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;

/** Maintains and reads the physical lock partitions after Scope and Binding locks. */
final readonly class PdoRegistryLockProjection
{
    private const KEY_COLUMNS = 'scope_id, binding_partition, key_kind, slug';

    /** Uses the lifecycle transaction's PDO. */
    public function __construct(private PDO $pdo) {}

    /** Resolves the Scope of the already locked Binding using its exact primary key. */
    public function currentBindingScope(int $bindingId): ?int
    {
        $statement = $this->prepare('SELECT scope_id FROM maa_slug_bindings WHERE id = ? LOCK IN SHARE MODE');
        $statement->execute([$bindingId]);
        /** @var array<string, mixed>|false $rawRow */
        $rawRow = $statement->fetch(PDO::FETCH_ASSOC);
        $row = PdoRowHydrator::one($rawRow);
        return $row === null ? null : PdoRowHydrator::nonNegativeInt($row, 'scope_id');
    }

    /**
     * Locks exact current slug keys within one Scope or Binding partition.
     *
     * Each point lookup uses the indexed MySQL collation and retains the
     * candidate ordinal. Distinct candidate bytes can match the same record.
     *
     * @param non-empty-list<string> $slugs
     * @return list<array{id: int, scope_id: int, binding_id: int, slug: string, requested_slug: string}>
     */
    public function lockExactKeys(int $scopeId, array $slugs, int $bindingPartition = 0): array
    {
        $this->ensureBoundaries($scopeId, $bindingPartition);
        $rows = [];
        foreach (array_chunk($slugs, 100) as $chunk) {
            $pointLookup = '(SELECT ? AS candidate_index, registry_id, owner_binding_id, slug '
                . 'FROM maa_slug_registry_lock_keys FORCE INDEX (PRIMARY) '
                . 'WHERE scope_id = ? AND scope_end = 0 AND binding_partition = ? AND key_kind = 1 AND slug = ? FOR UPDATE)';
            $statement = $this->prepare(implode(' UNION ALL ', array_fill(0, count($chunk), $pointLookup)));
            $parameters = [];
            foreach ($chunk as $index => $slug) {
                array_push($parameters, $index, $scopeId, $bindingPartition, $slug);
            }
            $statement->execute($parameters);
            /** @var list<array<string, mixed>> $rawRows */
            $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach (PdoRowHydrator::many($rawRows) as $row) {
                $index = PdoRowHydrator::nonNegativeInt($row, 'candidate_index');
                $requested = $chunk[$index] ?? throw new SlugPersistenceInvariantException('Registry lock key has an unknown candidate index.');
                $rows[] = $this->keyFromRow($scopeId, $row) + ['requested_slug' => $requested];
            }
        }
        return $rows;
    }

    /**
     * Locks the current claim keys within one Binding partition.
     *
     * @return list<array{id: int, scope_id: int, binding_id: int, slug: string}>
     */
    public function lockBinding(int $scopeId, int $bindingId): array
    {
        $this->ensureBoundaries($scopeId, $bindingId);
        $statement = $this->prepare(
            'SELECT registry_id, owner_binding_id, slug FROM maa_slug_registry_lock_keys FORCE INDEX (PRIMARY) '
            . 'WHERE scope_id = ? AND scope_end = 0 AND binding_partition = ? AND key_kind = 1 ORDER BY slug FOR UPDATE',
        );
        $statement->execute([$scopeId, $bindingId]);
        return $this->readKeys($scopeId, $statement);
    }

    /** Records both physical keys after inserting the authoritative claim. */
    public function insertClaimKeys(int $scopeId, int $bindingId, string $slug, int $registryId): void
    {
        $this->ensureBoundaries($scopeId, 0);
        $this->ensureBoundaries($scopeId, $bindingId);
        $statement = $this->prepare(
            'INSERT INTO maa_slug_registry_lock_keys (' . self::KEY_COLUMNS . ', registry_id, owner_binding_id) '
            . 'VALUES (?, 0, 1, ?, ?, ?), (?, ?, 1, ?, ?, ?)',
        );
        $statement->execute([$scopeId, $slug, $registryId, $bindingId, $scopeId, $bindingId, $slug, $registryId, $bindingId]);
    }

    /** Removes both claim keys through exact clustered-key deletes. */
    public function deleteClaimKeys(int $scopeId, int $bindingId, string $slug, int $registryId): void
    {
        $statement = $this->prepare(
            'DELETE FROM maa_slug_registry_lock_keys '
            . 'WHERE scope_id = ? AND scope_end = 0 AND binding_partition = ? AND key_kind = 1 AND slug = ? AND registry_id = ?',
        );
        foreach ([0, $bindingId] as $partition) {
            $statement->execute([$scopeId, $partition, $slug, $registryId]);
            if ($statement->rowCount() !== 1) {
                throw new SlugPersistenceInvariantException('Registry lock key is missing or has an unexpected owner.');
            }
        }
    }

    /** Removes the now-empty Binding partition by exact clustered keys. */
    public function deleteBindingBoundaries(int $scopeId, int $bindingId): void
    {
        $statement = $this->prepare(
            'DELETE FROM maa_slug_registry_lock_keys WHERE scope_id = ? AND scope_end = 0 AND binding_partition = ? AND key_kind = ? AND slug = \'\'',
        );
        foreach ([0, 2] as $kind) {
            $statement->execute([$scopeId, $bindingId, $kind]);
        }
    }

    /** Reads current claim rows by the primary IDs returned by the lock projection.
     *
     * @param list<array{id: int, scope_id: int, binding_id: int, slug: string}> $keys
     * @return list<array<string, mixed>>
     */
    public function readCurrentClaims(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($keys, 100) as $chunk) {
            $slots = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $this->prepare(
                'SELECT STRAIGHT_JOIN r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
                . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
                . 'FROM maa_slug_registry r FORCE INDEX (PRIMARY) '
                . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
                . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
                . 'WHERE r.id IN (' . $slots . ') ORDER BY r.id LOCK IN SHARE MODE',
            );
            $statement->execute(array_column($chunk, 'id'));
            /** @var list<array<string, mixed>> $rawRows */
            $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $byId = [];
            foreach (PdoRowHydrator::many($rawRows) as $row) {
                $byId[PdoRowHydrator::nonNegativeInt($row, 'id')] = $row;
            }
            foreach ($chunk as $key) {
                $row = $byId[$key['id']] ?? null;
                if ($row === null || PdoRowHydrator::nonNegativeInt($row, 'scope_id') !== $key['scope_id']
                    || PdoRowHydrator::nonNegativeInt($row, 'binding_id') !== $key['binding_id']
                    || PdoRowHydrator::string($row, 'slug') !== $key['slug']) {
                    throw new SlugPersistenceInvariantException('Registry lock key does not match its claim.');
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Creates boundary records before the partition can see a missing-key lock.
     * The permanent Scope separator stays in place even after its last Binding
     * partition is purged.
     */
    private function ensureBoundaries(int $scopeId, int $bindingPartition): void
    {
        if ($scopeId < 1 || $bindingPartition < 0) {
            throw new SlugPersistenceInvariantException('Registry lock partition identity is invalid.');
        }
        $statement = $this->prepare(
            'INSERT INTO maa_slug_registry_lock_keys (scope_id, scope_end, binding_partition, key_kind, slug) '
            . "VALUES (?, 0, ?, 0, ''), (?, 0, ?, 2, ''), (?, 1, 0, 2, '') "
            . 'ON DUPLICATE KEY UPDATE registry_id = NULL',
        );
        $statement->execute([$scopeId, $bindingPartition, $scopeId, $bindingPartition, $scopeId]);
    }

    /** @return list<array{id: int, scope_id: int, binding_id: int, slug: string}> */
    private function readKeys(int $scopeId, PDOStatement $statement): array
    {
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->keyFromRow($scopeId, $row), PdoRowHydrator::many($rawRows));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, scope_id: int, binding_id: int, slug: string}
     */
    private function keyFromRow(int $scopeId, array $row): array
    {
        return [
            'id' => PdoRowHydrator::nonNegativeInt($row, 'registry_id'),
            'scope_id' => $scopeId,
            'binding_id' => PdoRowHydrator::nonNegativeInt($row, 'owner_binding_id'),
            'slug' => PdoRowHydrator::string($row, 'slug'),
        ];
    }

    /** Prepares an internal projection statement or fails closed. */
    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry lock projection statement.');
        }
        return $statement;
    }
}
