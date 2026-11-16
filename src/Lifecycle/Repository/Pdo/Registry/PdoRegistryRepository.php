<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Registry;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoDuplicateClassifier;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryBindingRecord;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryClaimRecord;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Scope\ScopePersistenceInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/**
 * Internal direct-PDO persistence for live Registry ownership.
 *
 * This repository deliberately exposes no public package contract. Lifecycle
 * code owns the public boundary and supplies its own transaction boundary.
 */
final readonly class PdoRegistryRepository implements RegistryRepositoryInterface
{
    private PdoCapabilityGuard $capabilities;

    /** Injects PDO, profile/scope dependencies, clock, and capability checks. */
    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
        private ScopePersistenceInterface $scopes,
        private ClockInterface $clock,
        ?PdoCapabilityGuard $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
    }

    /** Verifies the installed schema before registry access. */
    public function assertInstalledSchemaSupported(): void
    {
        $this->capabilities->assertInstalledSchemaSupported();
    }

    /** Ensures a scope/profile row and returns its immutable snapshot. */
    public function ensureScope(SlugScope $scope, SlugProfileKey $profileKey): ScopeDTO
    {
        return $this->scopes->ensureScope(new ScopeProfileRequestDTO($scope, $profileKey));
    }

    /** Finds a scope/profile row, optionally with a row lock. */
    public function findScope(SlugScope $scope, SlugProfileKey $expectedProfileKey, bool $forUpdate = false): ?ScopeDTO
    {
        $this->profiles->get($expectedProfileKey);
        $row = $this->findScopeRow($scope, $forUpdate);
        if ($row === null) {
            return null;
        }
        if (PdoRowHydrator::string($row, 'profile_key') !== $expectedProfileKey->value) {
            throw new SlugScopeProfileMismatchException('The existing Scope uses a different slug profile.');
        }

        return $this->scopeFromRow($row);
    }

    /** Returns a required scope under a row lock and enforces profile identity. */
    public function lockScope(SlugScope $scope, SlugProfileKey $expectedProfileKey): ScopeDTO
    {
        $result = $this->findScope($scope, $expectedProfileKey, true);
        if ($result === null) {
            throw new SlugPersistenceInvariantException('Scope disappeared before Registry mutation.');
        }

        return $result;
    }

    /** Locks or creates the binding row for an entity in the supplied scope. */
    public function lockOrCreateBinding(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord
    {
        $record = $this->lockOrCreateBindingRecord($scope, $entity);

        return $this->validatedBindingRecord($scope, $entity, $record->createdInCurrentTransaction);
    }

    /**
     * Lock or create a Binding row without hydrating or locking Registry claims.
     *
     * Transition and adoption use this primitive to finish the global Binding
     * phase before constructing and acquiring their Registry lock plan.
     */
    public function lockOrCreateBindingRecord(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord
    {
        $row = $this->findBindingRow($scope->id, $entity, true);
        if ($row !== null) {
            return $this->bindingRecordFromRow($row, false);
        }

        $now = $this->timestamp();
        $created = false;
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO maa_slug_bindings '
                . '(scope_id, entity_type, entity_key, current_registry_id, status, revision, history_sequence, created_at, updated_at) '
                . 'VALUES (:scope_id, :entity_type, :entity_key, NULL, :status, :revision, :history_sequence, :created_at, :updated_at)',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the Registry Binding insert.');
            }
            $statement->execute([
                'scope_id' => $scope->id,
                'entity_type' => $entity->entityType,
                'entity_key' => $entity->entityKey,
                'status' => BindingStatusEnum::RELEASED->value,
                'revision' => 0,
                'history_sequence' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created = true;
        } catch (PDOException $exception) {
            if (! PdoDuplicateClassifier::matches($exception, 'uk_binding_identity')) {
                throw $exception;
            }
        }

        $row = $this->findBindingRow($scope->id, $entity, true);
        if ($row === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during Registry bootstrap.');
        }

        return $this->bindingRecordFromRow($row, $created);
    }

    /**
     * Read and lock an existing Binding row without hydrating Registry claims.
     *
     * Lifecycle transfer uses this primitive to complete every Binding lock
     * before it starts the separate Registry claim-lock phase.
     */
    public function findBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $forUpdate = false): ?RegistryBindingRecord
    {
        $row = $this->findBindingRow($scope->id, $entity, $forUpdate);
        if ($row === null) {
            return null;
        }
        return $this->bindingRecordFromRow($row, false);
    }

    /** Finds a claim by scope and canonical slug, optionally with a row lock. */
    public function findClaimByScopeSlug(int $scopeId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord
    {
        return $this->findClaim('r.scope_id = :scope_id AND r.slug = :slug', [
            'scope_id' => $scopeId,
            'slug' => $slug->value,
        ], $forUpdate);
    }

    /** Finds a claim by binding and canonical slug, optionally with a row lock. */
    public function findClaimByBindingSlug(int $bindingId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord
    {
        return $this->findClaim('r.binding_id = :binding_id AND r.slug = :slug', [
            'binding_id' => $bindingId,
            'slug' => $slug->value,
        ], $forUpdate);
    }

    /**
     * Returns all claims for one binding in stable registry order.
     *
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForBinding(int $bindingId, bool $forUpdate = false): array
    {
        if ($bindingId < 0) {
            throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
        }
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.binding_id = :binding_id ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim list lookup.');
        }
        $statement->execute(['binding_id' => $bindingId]);
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = PdoRowHydrator::many($rawRows);

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $rows);
    }

    /**
     * @param list<int> $bindingIds
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForBindings(array $bindingIds, bool $forUpdate = false): array
    {
        if ($bindingIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach ($bindingIds as $index => $bindingId) {
            if ($bindingId < 0) {
                throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
            }
            $parameter = 'binding_id_' . $index;
            $placeholder = ':' . $parameter;
            $placeholders[] = $placeholder;
            $parameters[$parameter] = $bindingId;
        }
        if (count(array_unique($bindingIds)) !== count($bindingIds)) {
            throw new SlugPersistenceInvariantException('Binding ids must be distinct for a multi-binding claim lock.');
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.binding_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the multi-binding Registry claim list lookup.');
        }
        $statement->execute($parameters);
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = PdoRowHydrator::many($rawRows);

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $rows);
    }

    /**
     * Lock the existing Registry rows for an exact, deterministic set of
     * (scope_id, slug) keys. The unique Registry key resolves insert races
     * when a requested key is absent.
     *
     * @param list<array{scope_id: int, slug: string}> $keys
     * @return list<array{scope_id: int, slug: string}>
     */
    public function lockOccupiedClaimKeys(array $keys): array
    {
        return array_map(static fn(array $row): array => [
            'scope_id' => $row['scope_id'],
            'slug' => $row['slug'],
        ], $this->lockRegistryKeyRows($keys));
    }

    /**
     * @param list<array{scope_id: int, slug: string}> $keys
     * @return list<array{id: int, scope_id: int, binding_id: int, slug: string}>
     */
    private function lockRegistryKeyRows(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if ($key['scope_id'] < 0 || $key['slug'] === '') {
                throw new SlugPersistenceInvariantException('Registry lock key is invalid.');
            }
            $normalized[$key['scope_id'] . "\0" . $key['slug']] = $key;
        }
        $normalized = array_values($normalized);
        usort($normalized, static fn(array $left, array $right): int => $left['scope_id'] <=> $right['scope_id'] ?: strcmp($left['slug'], $right['slug']));

        $predicates = [];
        $parameters = [];
        foreach ($normalized as $index => $key) {
            $scopeParameter = 'lock_scope_' . $index;
            $slugParameter = 'lock_slug_' . $index;
            $predicates[] = '(r.scope_id = :' . $scopeParameter . ' AND r.slug = :' . $slugParameter . ')';
            $parameters[$scopeParameter] = $key['scope_id'];
            $parameters[$slugParameter] = $key['slug'];
        }

        // This phase locks Registry rows only. A join to the owner Binding
        // could introduce a Binding lock after the Binding lock phase.
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug FROM maa_slug_registry r FORCE INDEX (uk_registry_scope_slug) WHERE ' . implode(' OR ', $predicates)
            . ' ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC FOR UPDATE',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the exact Registry lock plan lookup.');
        }
        $statement->execute($parameters);
        return $this->registryKeyRows($statement);
    }

    /**
     * Locks current participant claims by Binding index, then exact candidate
     * keys by Scope/slug. The preceding Scope lock serializes writers within
     * this Scope before either Registry phase.
     *
     * @param list<int> $bindingIds
     * @param non-empty-list<string> $candidateSlugs
     * @return list<array{id: int, binding_id: int, slug: string}>
     */
    public function lockClaimKeysForMutation(int $scopeId, array $bindingIds, array $candidateSlugs): array
    {
        $rows = [];
        if ($bindingIds !== []) {
            $bindingIds = array_values(array_unique($bindingIds));
            sort($bindingIds, SORT_NUMERIC);
            $statement = $this->pdo->prepare(
                'SELECT r.id, r.scope_id, r.binding_id, r.slug FROM maa_slug_registry r FORCE INDEX (uk_registry_binding_slug) '
                . 'WHERE r.binding_id = :binding_id AND r.scope_id = :scope_id '
                . 'ORDER BY r.slug ASC, r.id ASC FOR UPDATE',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the participant Registry lock plan.');
            }
            foreach ($bindingIds as $bindingId) {
                $statement->execute(['binding_id' => $bindingId, 'scope_id' => $scopeId]);
                foreach ($this->registryKeyRows($statement) as $row) {
                    $rows[$row['id']] = $row;
                }
                $statement->closeCursor();
            }
        }

        $keys = array_map(
            static fn(string $slug): array => ['scope_id' => $scopeId, 'slug' => $slug],
            $candidateSlugs,
        );
        foreach ($this->lockRegistryKeyRows($keys) as $row) {
            $rows[$row['id']] = $row;
        }
        uasort($rows, static fn(array $left, array $right): int => strcmp($left['slug'], $right['slug']) ?: $left['id'] <=> $right['id']);

        return array_values(array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'binding_id' => $row['binding_id'],
            'slug' => $row['slug'],
        ], $rows));
    }

    /** @return list<array{id: int, scope_id: int, binding_id: int, slug: string}> */
    private function registryKeyRows(\PDOStatement $statement): array
    {
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'id' => PdoRowHydrator::nonNegativeInt($row, 'id'),
            'scope_id' => PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            'binding_id' => PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
            'slug' => PdoRowHydrator::string($row, 'slug'),
        ], PdoRowHydrator::many($rawRows));
    }

    /** Inserts a claim and returns its raw row, or null when a uniqueness race loses. */
    public function insertClaim(int $scopeId, int $bindingId, Slug $slug, RegistryRoleEnum $role): ?RegistryClaimRecord
    {
        $now = $this->timestamp();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO maa_slug_registry '
                . '(scope_id, binding_id, slug, claim_role, claimed_at, updated_at) '
                . 'VALUES (:scope_id, :binding_id, :slug, :claim_role, :claimed_at, :updated_at)',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim insert.');
            }
            $statement->execute([
                'scope_id' => $scopeId,
                'binding_id' => $bindingId,
                'slug' => $slug->value,
                'claim_role' => $role->value,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (PdoDuplicateClassifier::matches($exception, 'uk_registry_scope_slug')) {
                return null;
            }
            throw $exception;
        }

        $id = filter_var($this->pdo->lastInsertId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SlugPersistenceInvariantException('Registry claim insert did not return a valid id.');
        }
        // The write flow already holds the Scope, Binding, and inserted Registry
        // row. Read their joined projection from current state under Host snapshots.
        $claim = $this->findClaimById($id, true);
        if ($claim === null) {
            throw new SlugPersistenceInvariantException('Inserted Registry claim cannot be read back.');
        }

        return $claim;
    }

    /** Marks a claim current while applying the expected binding revision. */
    public function activateCurrent(int $bindingId, int $expectedRevision, int $registryId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_bindings '
            . 'SET current_registry_id = :current_registry_id, status = :status, revision = revision + 1, updated_at = :updated_at '
            . 'WHERE id = :binding_id AND revision = :expected_revision',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the current Binding update.');
        }
        $statement->execute([
            'current_registry_id' => $registryId,
            'status' => BindingStatusEnum::ACTIVE->value,
            'updated_at' => $this->timestamp(),
            'binding_id' => $bindingId,
            'expected_revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugRevisionConflictException('Binding revision changed before current ownership activation.');
        }
    }

    /** Updates binding state and counters under optimistic revision control. */
    public function mutateBinding(
        int $bindingId,
        int $expectedRevision,
        BindingStatusEnum $status,
        ?int $currentRegistryId,
        int $historySequence,
    ): void {
        if ($bindingId < 0 || $expectedRevision < 0 || $historySequence < 0) {
            throw new SlugPersistenceInvariantException('Binding mutation identity is invalid.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_bindings SET current_registry_id = :current_registry_id, status = :status, '
            . 'revision = revision + 1, history_sequence = :history_sequence, updated_at = :updated_at '
            . 'WHERE id = :binding_id AND revision = :expected_revision',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Binding lifecycle update.');
        }
        $statement->execute([
            'current_registry_id' => $currentRegistryId,
            'status' => $status->value,
            'history_sequence' => $historySequence,
            'updated_at' => $this->timestamp(),
            'binding_id' => $bindingId,
            'expected_revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugRevisionConflictException('Binding revision changed before lifecycle mutation.');
        }
    }

    /** Updates a claim role and returns the refreshed raw record. */
    public function updateClaimRole(int $registryId, RegistryRoleEnum $role): RegistryClaimRecord
    {
        if ($registryId < 0) {
            throw new SlugPersistenceInvariantException('Registry id cannot be negative.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_registry SET claim_role = :claim_role, updated_at = :updated_at WHERE id = :registry_id',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry role update.');
        }
        $statement->execute([
            'claim_role' => $role->value,
            'updated_at' => $this->timestamp(),
            'registry_id' => $registryId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugPersistenceInvariantException('Registry role update did not affect exactly one claim.');
        }
        $claim = $this->findClaimById($registryId, true);
        if ($claim === null) {
            throw new SlugPersistenceInvariantException('Updated Registry claim cannot be read back.');
        }

        return $claim;
    }

    /** Deletes one registry claim after lifecycle authorization. */
    public function deleteClaim(int $registryId): void
    {
        if ($registryId < 0) {
            throw new SlugPersistenceInvariantException('Registry id cannot be negative.');
        }
        $statement = $this->pdo->prepare('DELETE FROM maa_slug_registry WHERE id = :registry_id');
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim delete.');
        }
        $statement->execute(['registry_id' => $registryId]);
        if ($statement->rowCount() !== 1) {
            throw new SlugPersistenceInvariantException('Registry claim delete did not affect exactly one claim.');
        }
    }

    /** Hydrates a public binding snapshot by identity, optionally under lock. */
    public function binding(BindingIdentityDTO $identity, bool $forUpdate = false): ?BindingDTO
    {
        return $this->scopes->findBinding($identity->scopeProfile, $identity->entity, $forUpdate);
    }

    /** Loads a registry claim by id through the shared claim projection. */
    private function findClaimById(int $id, bool $forUpdate): ?RegistryClaimRecord
    {
        return $this->findClaim('r.id = :registry_id', ['registry_id' => $id], $forUpdate);
    }

    /** @param array<string, int|string> $parameters */
    private function findClaim(string $predicate, array $parameters, bool $forUpdate): ?RegistryClaimRecord
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE ' . $predicate . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim lookup.');
        }
        $statement->execute($parameters);
        /** @var array<string, mixed>|false $rawRow */
        $rawRow = $statement->fetch(PDO::FETCH_ASSOC);
        $row = PdoRowHydrator::one($rawRow);

        return $row === null ? null : $this->claimFromRow($row);
    }

    /** @return array<string, mixed>|null */
    private function findScopeRow(SlugScope $scope, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, namespace, locale_key, context_key, profile_key, created_at, updated_at '
            . 'FROM maa_slug_scopes '
            . 'WHERE namespace = :namespace AND locale_key = :locale_key AND context_key = :context_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry Scope lookup.');
        }
        $statement->execute([
            'namespace' => $scope->namespace,
            'locale_key' => $scope->localeKey ?? '',
            'context_key' => $scope->contextKey ?? '',
        ]);

        /** @var array<string, mixed>|false $rawRow */
        $rawRow = $statement->fetch(PDO::FETCH_ASSOC);
        return PdoRowHydrator::one($rawRow);
    }

    /** @return array<string, mixed>|null */
    private function findBindingRow(int $scopeId, EntityReference $entity, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, scope_id, current_registry_id, status, revision '
            . 'FROM maa_slug_bindings '
            . 'WHERE scope_id = :scope_id AND entity_type = :entity_type AND entity_key = :entity_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry Binding lookup.');
        }
        $statement->execute([
            'scope_id' => $scopeId,
            'entity_type' => $entity->entityType,
            'entity_key' => $entity->entityKey,
        ]);

        /** @var array<string, mixed>|false $rawRow */
        $rawRow = $statement->fetch(PDO::FETCH_ASSOC);
        return PdoRowHydrator::one($rawRow);
    }

    /** Re-reads a binding after mutation to validate its persisted lifecycle state. */
    private function validatedBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $created): RegistryBindingRecord
    {
        $binding = $this->scopes->findBinding(
            new ScopeProfileRequestDTO($scope->scope, $scope->profileKey),
            $entity,
            true,
        );
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during invariant validation.');
        }

        return new RegistryBindingRecord(
            $binding->id,
            $scope->id,
            $binding->state->status,
            $binding->currentClaim?->id,
            $binding->state->revision,
            $created,
        );
    }

    /** @param array<string, mixed> $row */
    private function bindingRecordFromRow(array $row, bool $created): RegistryBindingRecord
    {
        $status = BindingStatusEnum::tryFrom(PdoRowHydrator::string($row, 'status'));
        if ($status === null) {
            throw new SlugPersistenceInvariantException('Binding contains an unknown status.');
        }

        return new RegistryBindingRecord(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            $status,
            $this->nullableNonNegativeInt($row, 'current_registry_id'),
            PdoRowHydrator::nonNegativeInt($row, 'revision'),
            $created,
        );
    }

    /** @param array<string, mixed> $row */
    private function nullableNonNegativeInt(array $row, string $key): ?int
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return PdoRowHydrator::nonNegativeInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function claimFromRow(array $row): RegistryClaimRecord
    {
        $role = RegistryRoleEnum::tryFrom(PdoRowHydrator::string($row, 'claim_role'));
        if ($role === null) {
            throw new SlugPersistenceInvariantException('Registry contains an unknown claim role.');
        }

        return new RegistryClaimRecord(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
            PdoRowHydrator::string($row, 'namespace'),
            PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
            PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            PdoRowHydrator::string($row, 'profile_key'),
            PdoRowHydrator::string($row, 'entity_type'),
            PdoRowHydrator::string($row, 'entity_key'),
            PdoRowHydrator::string($row, 'slug'),
            $role,
            $this->date(PdoRowHydrator::string($row, 'claimed_at'), 'registry.claimed_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'registry.updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function scopeFromRow(array $row): ScopeDTO
    {
        return new ScopeDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            new SlugScope(
                PdoRowHydrator::string($row, 'namespace'),
                PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
                PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            ),
            new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key')),
            $this->date(PdoRowHydrator::string($row, 'created_at'), 'scope.created_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'scope.updated_at'),
        );
    }

    /** Formats the injected clock value for registry persistence. */
    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** Parses a UTC timestamp with microsecond precision from a registry row. */
    private function date(string $value, string $field): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) !== 1) {
            throw new SlugPersistenceInvariantException(sprintf('%s must contain six fractional-second digits.', $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new SlugPersistenceInvariantException(sprintf('%s is not a valid UTC timestamp.', $field));
        }

        return $date;
    }
}
