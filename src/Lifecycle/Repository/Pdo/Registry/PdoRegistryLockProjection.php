<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Registry;

use PDO;
use PDOStatement;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;

/** Maintains and reads the physical lock partitions after Scope and Binding locks. */
final class PdoRegistryLockProjection
{
    private const KEY_COLUMNS = 'scope_id, binding_partition, key_kind, slug';

    /** @var array<int, PDOStatement> */
    private array $boundaryLookups = [];

    /** @var array<int, PDOStatement> */
    private array $boundaryInserts = [];

    private ?PDOStatement $singleCandidateLookup = null;

    private ?PDOStatement $bindingLookup = null;

    /** Uses the lifecycle transaction's PDO. */
    public function __construct(private readonly PDO $pdo) {}

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
     * Driving the join from ordered candidates keeps each lookup on the full
     * unique partition key. MySQL reports every matching request ordinal, including
     * byte-distinct requests that compare equal under the Registry collation.
     *
     * @param non-empty-list<string> $slugs
     * @return list<array{id: int, scope_id: int, binding_id: int, slug: string, requested_slug: string}>
     */
    public function lockExactKeys(int $scopeId, array $slugs, int $bindingPartition = 0): array
    {
        $this->ensureBoundaries($scopeId, $bindingPartition);
        $rows = [];
        $statements = [];
        foreach (array_chunk($slugs, 100) as $chunk) {
            $size = count($chunk);
            $statement = $size === 1
                ? ($this->singleCandidateLookup ??= $this->prepareCandidateLookup(1))
                : ($statements[$size] ??= $this->prepareCandidateLookup($size));
            $statement->execute([...$chunk, $scopeId, $bindingPartition]);
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

    /** Builds a native prepared lookup with one derived row per candidate. */
    private function prepareCandidateLookup(int $size): PDOStatement
    {
        $candidates = [];
        for ($index = 0; $index < $size; $index++) {
            $candidates[] = 'SELECT ' . $index . ' AS candidate_index, CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin AS slug';
        }
        return $this->prepare(
            'SELECT c.candidate_index, k.registry_id, k.owner_binding_id, k.slug FROM (' . implode(' UNION ALL ', $candidates) . ') c '
            . 'STRAIGHT_JOIN maa_slug_registry_lock_keys k FORCE INDEX (uk_registry_lock_partition) '
            . 'ON k.scope_id = ? AND k.scope_end = 0 AND k.binding_partition = ? AND k.key_kind = 1 AND k.slug = c.slug '
            . 'FOR UPDATE',
        );
    }

    /**
     * Locks the current claim keys within one Binding partition.
     *
     * @return list<array{id: int, scope_id: int, binding_id: int, slug: string}>
     */
    public function lockBinding(int $scopeId, int $bindingId): array
    {
        $this->ensureBoundaries($scopeId, $bindingId);
        $statement = $this->bindingLookup ??= $this->prepare(
            'SELECT registry_id, owner_binding_id, slug FROM maa_slug_registry_lock_keys FORCE INDEX (uk_registry_lock_partition) '
            . 'WHERE scope_id = ? AND scope_end = 0 AND binding_partition = ? AND key_kind = 1 ORDER BY slug FOR UPDATE',
        );
        $statement->execute([$scopeId, $bindingId]);
        return $this->readKeys($scopeId, $statement);
    }

    /** Records both physical keys after inserting the authoritative claim. */
    public function insertClaimKeys(int $scopeId, int $bindingId, string $slug, int $registryId): void
    {
        $this->ensureBoundaries($scopeId, $bindingId, includeOccupancy: true);
        $statement = $this->prepare(
            'INSERT INTO maa_slug_registry_lock_keys (' . self::KEY_COLUMNS . ', registry_id, owner_binding_id) '
            . 'VALUES (?, 0, 1, ?, ?, ?), (?, ?, 1, ?, ?, ?)',
        );
        $statement->execute([$scopeId, $slug, $registryId, $bindingId, $scopeId, $bindingId, $slug, $registryId, $bindingId]);
    }

    /** Removes both claim keys through exact partition-key deletes. */
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

    /** Removes the now-empty Binding partition by exact partition keys. */
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
    private function ensureBoundaries(int $scopeId, int $bindingPartition, bool $includeOccupancy = false): void
    {
        if ($scopeId < 1 || $bindingPartition < 0) {
            throw new SlugPersistenceInvariantException('Registry lock partition identity is invalid.');
        }
        $boundaries = [];
        if ($includeOccupancy && $bindingPartition !== 0) {
            $boundaries[] = [$scopeId, 0, 0, 0];
            $boundaries[] = [$scopeId, 0, 0, 2];
        }
        $boundaries[] = [$scopeId, 0, $bindingPartition, 0];
        $boundaries[] = [$scopeId, 0, $bindingPartition, 2];
        $boundaries[] = [$scopeId, 1, 0, 2];

        // Keep each locking read on the full unique key. A range lookup or
        // secondary-key upsert can take unnecessary next-key locks.
        $size = count($boundaries);
        $lookup = $this->boundaryLookups[$size] ??= $this->prepare(
            implode(' UNION ALL ', array_fill(
                0,
                $size,
                "(SELECT ? AS boundary_index FROM maa_slug_registry_lock_keys FORCE INDEX (uk_registry_lock_partition) "
                . "WHERE scope_id = ? AND scope_end = ? AND binding_partition = ? AND key_kind = ? AND slug = '' FOR UPDATE)",
            )),
        );
        $parameters = [];
        foreach ($boundaries as $index => $boundary) {
            array_push($parameters, $index, ...$boundary);
        }
        $lookup->execute($parameters);
        $missing = $boundaries;
        /** @var list<array<string, mixed>> $found */
        $found = $lookup->fetchAll(PDO::FETCH_ASSOC);
        foreach ($found as $row) {
            unset($missing[PdoRowHydrator::nonNegativeInt($row, 'boundary_index')]);
        }
        if ($missing === []) {
            return;
        }

        // Scope and Binding locks serialize creation of these missing anchors.
        $size = count($missing);
        $insert = $this->boundaryInserts[$size] ??= $this->prepare(
            'INSERT INTO maa_slug_registry_lock_keys (scope_id, scope_end, binding_partition, key_kind, slug) VALUES '
            . implode(', ', array_fill(0, $size, "(?, ?, ?, ?, '')")),
        );
        $insert->execute(array_merge(...array_values($missing)));
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
