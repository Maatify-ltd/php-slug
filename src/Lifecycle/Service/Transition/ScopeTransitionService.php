<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Transition;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotStorage;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Lifecycle\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugReservedException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\Repository\History\HistoryEventDraft;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryBindingRecord;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryClaimRecord;
use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;
use Maatify\Slug\Lifecycle\Repository\History\HistoryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationPersistenceInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Transaction\TransactionCoordinatorInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationParticipant;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationRecord;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationReservation;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Service\Reserved\ReservationEvaluator;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;

/** Persists MOVE and PARALLEL transitions between distinct slug scopes. */
final readonly class ScopeTransitionService
{
    private ReservationEvaluator $reservations;

    /** Wires profile, persistence, transaction, reservation, and candidate boundaries. */
    public function __construct(
        private SlugProfileRegistryInterface $profiles,
        private RegistryRepositoryInterface $registry,
        private HistoryRepositoryInterface $history,
        private OperationPersistenceInterface $operations,
        private TransactionCoordinatorInterface $transactions,
        private CapabilityGuardInterface $capabilities,
        private ClockInterface $clock,
        ReservedSlugPolicyInterface $reservedPolicy,
        private GeneratedCandidateSequence $candidates,
        private OperationFingerprintMapper $fingerprints,
        private OperationKeyFactory $operationKeys,
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    /**
     * Creates an independent target Binding and current claim in a distinct
     * target Scope under one transaction. EXACT uses one canonical target
     * candidate and GENERATED uses ordered candidates; the target Binding must
     * be absent on first execution, with an idempotent replay able to return
     * the stored result.
     *
     * MOVE marks the source Binding INACTIVE but retains its current claim and
     * source Scope identity; PARALLEL leaves the source Binding unchanged.
     * Source CAS, profile, occupancy, reservation, and transition-state rules
     * are enforced.
     */
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        $targetProfile = $this->profiles->get($command->targetScope->expectedProfileKey);
        if ($this->sameScope($command->source->scopeProfile, $command->targetScope)) {
            throw new SlugInvalidArgumentException('Transition source and target Scope identities must differ.');
        }

        $targetCandidates = $this->candidateList($targetProfile, $command);
        $fingerprint = $this->fingerprint($command, $targetCandidates[0] ?? null);
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($command, $targetCandidates, $fingerprint): ScopeTransitionResultDTO {
            $sourceKey = $this->scopeKey($command->source->scopeProfile);
            $targetKey = $this->scopeKey($command->targetScope);
            $scopeRequests = [$command->source->scopeProfile, $command->targetScope];
            usort($scopeRequests, fn(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): int => strcmp($this->scopeKey($left), $this->scopeKey($right)));
            $lockedScopes = [];
            foreach ($scopeRequests as $scopeRequest) {
                $key = $this->scopeKey($scopeRequest);
                if ($key === $targetKey) {
                    $this->registry->ensureScope($scopeRequest->scope, $scopeRequest->expectedProfileKey);
                    $lockedScopes[$key] = $this->registry->lockScope($scopeRequest->scope, $scopeRequest->expectedProfileKey);
                    continue;
                }

                $scope = $this->registry->findScope($scopeRequest->scope, $scopeRequest->expectedProfileKey, true);
                if ($scope === null) {
                    throw new SlugNotFoundException('The transition source Scope was not found.');
                }
                $lockedScopes[$key] = $scope;
            }
            $sourceScope = $lockedScopes[$sourceKey];
            $targetScope = $lockedScopes[$targetKey];

            $bindingScopes = [$sourceScope, $targetScope];
            usort($bindingScopes, static fn($left, $right): int => $left->id <=> $right->id);

            $sourceRecord = null;
            $targetRecord = null;
            $targetWasPresent = false;
            $targetCreated = false;
            foreach ($bindingScopes as $scope) {
                $record = $this->registry->findBindingRecord($scope, $command->source->entity, true);
                if ($scope->id === $sourceScope->id) {
                    if ($record === null) {
                        throw new SlugNotFoundException('The transition source Binding was not found.');
                    }
                    $sourceRecord = $record;
                    continue;
                }

                $targetWasPresent = $record !== null;
                $targetRecord = $record ?? $this->registry->lockOrCreateBindingRecord($scope, $command->source->entity);
                $targetCreated = $targetRecord->createdInCurrentTransaction;
            }
            if ($sourceRecord === null || $targetRecord === null) {
                throw new SlugPersistenceInvariantException('Transition Binding lock plan did not produce both participants.');
            }
            $targetIdentity = new BindingIdentityDTO($command->targetScope, $command->source->entity);

            $existing = $this->existingOperation([$sourceRecord->id, $targetRecord->id], $command->audit);
            if ($targetWasPresent && $existing === null) {
                throw new SlugRevisionConflictException('The transition target Binding must be absent at first execution.');
            }
            if (! $targetWasPresent && ! $targetCreated && $existing === null) {
                throw new SlugRevisionConflictException('The transition target Binding was created by another operation.');
            }

            $reservation = $this->reserve(
                $command->audit,
                $fingerprint,
                [$sourceRecord->id, $targetRecord->id],
                $existing,
            );
            if ($reservation !== null && ! $reservation->created) {
                return $this->decodeReplay($reservation, $command->mode);
            }

            $this->assertRevision($sourceRecord, $command->sourceExpectedRevision);
            if (! in_array($sourceRecord->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true) || $sourceRecord->currentRegistryId === null) {
                throw new SlugAssignmentNotPermittedException('Transition source must be ACTIVE or INACTIVE and have a current claim.');
            }
            if (! $targetCreated) {
                throw new SlugRevisionConflictException('The transition target Binding must be absent at first execution.');
            }

            $targetKeys = array_map(
                static fn(Slug $slug): array => ['scope_id' => $targetScope->id, 'slug' => $slug->value],
                $targetCandidates,
            );
            // Current source hydration locks its Registry claims in stored order.
            // The other claim group belongs to a different Scope, so order the
            // two groups by Scope id after both Binding rows are locked.
            if ($sourceScope->id < $targetScope->id) {
                $sourceBefore = $this->requiredBinding($command->source);
                $occupiedTargetKeys = $this->registry->lockOccupiedClaimKeys($targetKeys);
            } else {
                $occupiedTargetKeys = $this->registry->lockOccupiedClaimKeys($targetKeys);
                $sourceBefore = $this->requiredBinding($command->source);
            }
            if ($sourceBefore->currentClaim === null) {
                throw new SlugPersistenceInvariantException('The locked transition source has no current claim.');
            }
            $targetClaim = $this->selectTargetClaim(
                $targetScope->id,
                $targetRecord,
                array_column($occupiedTargetKeys, 'slug'),
                $targetCandidates,
                $command,
            );

            $sourceEvents = [];
            if ($command->mode->value === 'MOVE') {
                $sourceEvents[] = $this->claimEvent(
                    HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT,
                    $command->source,
                    $sourceBefore->currentClaim->slug,
                    $command->targetScope,
                    $command->source->entity,
                    $reservation,
                    $command->audit,
                );
            }
            $targetEvents = [$this->claimEvent(
                HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN,
                $targetIdentity,
                $targetClaim->toDto($this->profiles)->slug,
                $command->source->scopeProfile,
                $command->source->entity,
                $reservation,
                $command->audit,
            )];
            /** @var list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO> $sourceHistory */
            $sourceHistory = $this->appendEvents($sourceBefore->id, $sourceBefore->state->historySequence, $sourceEvents);
            /** @var list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO> $targetHistory */
            $targetHistory = $this->appendEvents($targetRecord->id, 0, $targetEvents);

            $targetClaimId = $targetClaim->id;
            $this->registry->mutateBinding($targetRecord->id, $targetRecord->revision, BindingStatusEnum::ACTIVE, $targetClaimId, 1);
            if ($command->mode->value === 'MOVE') {
                $this->registry->mutateBinding(
                    $sourceBefore->id,
                    $sourceBefore->state->revision,
                    BindingStatusEnum::INACTIVE,
                    $sourceBefore->currentClaim->id,
                    $sourceBefore->state->historySequence + 1,
                );
            }

            $sourceAfter = $command->mode->value === 'MOVE' ? $this->requiredBinding($command->source) : $sourceBefore;
            $targetAfter = $this->requiredBinding($targetIdentity);
            $sourceParticipant = new BindingStateResultDTO(
                $sourceBefore,
                $sourceAfter,
                $command->mode->value === 'MOVE',
                $sourceAfter->state->revision,
                $sourceHistory,
            );
            $targetParticipant = new BindingStateResultDTO(null, $targetAfter, true, $targetAfter->state->revision, $targetHistory);
            $result = new ScopeTransitionResultDTO(
                OperationTypeEnum::TRANSITION_SCOPE,
                $this->operationKey($reservation),
                false,
                $command->mode,
                true,
                $sourceParticipant,
                $targetParticipant,
                $sourceBefore,
                $sourceAfter,
                null,
                $targetAfter,
                $sourceBefore->currentClaim->slug,
                $targetClaim->toDto($this->profiles)->slug,
                $sourceAfter->state->revision,
                $targetAfter->state->revision,
                array_merge($sourceHistory, $targetHistory),
            );
            if ($reservation !== null) {
                $snapshot = ResultSnapshotEncoder::encode($result);
                $this->operations->commitSnapshot(
                    $reservation->operation->id,
                    new ResultSnapshotMetadata('transition', 1, OperationTypeEnum::TRANSITION_SCOPE, $reservation->operation->operationKey, $snapshot),
                    $this->profiles,
                );
            }

            return $result;
        });
    }

    /** @return list<Slug> */
    private function candidateList(SlugProfileInterface $profile, TransitionScopeCommand $command): array
    {
        if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
            return [$profile->canonicalizeClaim($command->targetClaimIntent->value)->slug];
        }

        return $this->candidates->fromSource($profile, $command->targetClaimIntent->value);
    }

    /**
     * @param list<string> $occupiedSlugs
     * @param list<Slug> $candidates
     */
    private function selectTargetClaim(
        int $scopeId,
        RegistryBindingRecord $target,
        array $occupiedSlugs,
        array $candidates,
        TransitionScopeCommand $command,
    ): RegistryClaimRecord {
        foreach ($candidates as $candidate) {
            if (in_array($candidate->value, $occupiedSlugs, true)) {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    if ($this->reservations->isReserved($command->targetScope->scope, $candidate)) {
                        throw new SlugReservedException('The exact transition target claim is reserved.');
                    }
                    throw new SlugAlreadyClaimedException('The exact transition target claim is already owned.');
                }
                continue;
            }
            if ($this->reservations->isReserved($command->targetScope->scope, $candidate)) {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    throw new SlugReservedException('The exact transition target claim is reserved.');
                }
                continue;
            }
            $claim = $this->registry->insertClaim($scopeId, $target->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
            if ($claim !== null) {
                return $claim;
            }
            {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    throw new SlugAlreadyClaimedException('The exact transition target claim was claimed concurrently.');
                }
            }
        }

        throw new SlugAllocationExhaustedException('All transition target candidates are unavailable.');
    }

    /** Loads the binding required to complete a scope transition. */
    private function requiredBinding(BindingIdentityDTO $identity): BindingDTO
    {
        $binding = $this->registry->binding($identity, true);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Transition Binding disappeared during mutation.');
        }
        return $binding;
    }

    /** Enforces optimistic-concurrency revision matching for the source binding. */
    private function assertRevision(RegistryBindingRecord $binding, int $expectedRevision): void
    {
        if ($binding->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('The transition source revision does not match.');
        }
    }

    /** Creates the source history draft linking a transition to its target. */
    private function claimEvent(
        HistoryEventTypeEnum $type,
        BindingIdentityDTO $identity,
        Slug $slug,
        ScopeProfileRequestDTO $relatedScope,
        \Maatify\Slug\Lifecycle\ValueObject\EntityReference $relatedEntity,
        ?OperationReservation $reservation,
        AuditContextDTO $audit,
    ): HistoryEventDraft {
        return new HistoryEventDraft(
            0,
            0,
            $type,
            $identity->scopeProfile->scope,
            $identity->entity,
            $slug,
            null,
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            $relatedScope->scope,
            $relatedEntity,
            $reservation?->operation->id,
            $reservation?->operation->operationKey,
            $audit,
            $this->clock->now(),
        );
    }

    /**
     * @param list<HistoryEventDraft> $drafts
     * @return list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO>
     */
    private function appendEvents(int $bindingId, int $startingSequence, array $drafts): array
    {
        $events = [];
        foreach ($drafts as $index => $draft) {
            $events[] = $this->history->append(new HistoryEventDraft(
                $bindingId,
                $startingSequence + $index + 1,
                $draft->eventType,
                $draft->scopeSnapshot,
                $draft->entitySnapshot,
                $draft->slugSnapshot,
                $draft->previousSlugSnapshot,
                $draft->claimRoleSnapshot,
                $draft->previousClaimRoleSnapshot,
                $draft->relatedScope,
                $draft->relatedEntity,
                $draft->operationId,
                $draft->operationKey,
                $draft->audit,
                $draft->occurredAt,
                $draft->originalOccurredAt,
            ));
        }
        return $events;
    }

    /** Returns the operation key associated with an optional transition reservation. */
    private function operationKey(?OperationReservation $reservation): ?string
    {
        return $reservation === null ? null : $reservation->operation->operationKey;
    }

    /** @param list<int> $bindingIds */
    private function existingOperation(array $bindingIds, AuditContextDTO $audit): ?OperationRecord
    {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        foreach ($bindingIds as $bindingId) {
            $existing = $this->operations->findByParticipant($bindingId, $audit->idempotencyKey, true);
            if ($existing !== null) {
                return $existing;
            }
        }
        return null;
    }

    /** @param list<int> $bindingIds */
    private function reserve(
        AuditContextDTO $audit,
        string $fingerprint,
        array $bindingIds,
        ?OperationRecord $existing,
    ): ?OperationReservation {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        $key = $existing === null ? $this->operationKeys->create('Unable to allocate a transition operation key.') : $existing->operationKey;
        $participants = [
            new OperationParticipant($bindingIds[0], $audit->idempotencyKey, 'SOURCE'),
            new OperationParticipant($bindingIds[1], $audit->idempotencyKey, 'TARGET'),
        ];
        return $this->operations->reserve($key, OperationTypeEnum::TRANSITION_SCOPE, $fingerprint, 'transition', 1, $participants);
    }

    /** Decodes the locked reservation and verifies the mode of the original request. */
    private function decodeReplay(OperationReservation $reservation, ScopeTransitionModeEnum $expectedMode): ScopeTransitionResultDTO
    {
        $result = ResultSnapshotStorage::decodeOperation($reservation->operation, $this->profiles, $reservation->participants);
        if (! $result instanceof ScopeTransitionResultDTO) {
            throw new SlugPersistenceInvariantException('Transition operation contains a non-transition snapshot.');
        }
        if ($result->mode !== $expectedMode) {
            throw new SlugPersistenceInvariantException('Invalid result snapshot. The transition mode must match the request.');
        }

        return $result;
    }

    /** Builds the canonical request fingerprint for a scope transition. */
    private function fingerprint(TransitionScopeCommand $command, ?Slug $exactCandidate): string
    {
        $intentValue = $command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT
            ? $exactCandidate?->value
            : $command->targetClaimIntent->value;
        $payload = [
            'contract_version' => 1,
            'operation_type' => OperationTypeEnum::TRANSITION_SCOPE->value,
            'source_scope' => $this->scopeArray($command->source->scopeProfile),
            'source_binding' => $this->entityArray($command->source),
            'target_scope' => $this->scopeArray($command->targetScope),
            'target_binding' => null,
            'claim_intent' => [
                'mode' => $command->targetClaimIntent->mode->value,
                'value' => $intentValue,
            ],
            'source_replacement_intent' => null,
            'transition_mode' => $command->mode->value,
            'expected_revision' => null,
            'source_expected_revision' => $command->sourceExpectedRevision,
            'target_expected_revision' => null,
            'original_occurred_at' => null,
        ];
        return $this->fingerprints->fingerprint($payload, 'Transition fingerprint encoding failed.');
    }

    /** @return array{namespace: string, locale_key: ?string, context_key: ?string, profile_key: string} */
    private function scopeArray(ScopeProfileRequestDTO $request): array
    {
        return [
            'namespace' => $request->scope->namespace,
            'locale_key' => $request->scope->localeKey,
            'context_key' => $request->scope->contextKey,
            'profile_key' => $request->expectedProfileKey->value,
        ];
    }

    /** @return array{entity_type: string, entity_key: string} */
    private function entityArray(BindingIdentityDTO $identity): array
    {
        return ['entity_type' => $identity->entity->entityType, 'entity_key' => $identity->entity->entityKey];
    }

    /** Compares scope dimensions without comparing profile configuration. */
    private function sameScope(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): bool
    {
        return $left->scope->namespace === $right->scope->namespace
            && $left->scope->localeKey === $right->scope->localeKey
            && $left->scope->contextKey === $right->scope->contextKey;
    }

    /** Builds the stable lock key for a scope identity. */
    private function scopeKey(ScopeProfileRequestDTO $request): string
    {
        return $request->scope->namespace . "\0" . ($request->scope->localeKey ?? '') . "\0" . ($request->scope->contextKey ?? '');
    }
}
