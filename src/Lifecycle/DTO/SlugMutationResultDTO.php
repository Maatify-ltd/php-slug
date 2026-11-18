<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Immutable result of one binding or alias mutation and its persisted history. */
final readonly class SlugMutationResultDTO implements JsonSerializable
{
    /**
     * Validates operation metadata, state consistency, and ordered history events.
     *
     * @param list<RegistryClaimDTO> $affectedClaims
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ?BindingDTO $before,
        public BindingDTO $after,
        /** @var list<RegistryClaimDTO> */
        public array $affectedClaims,
        public ?Slug $previousSlug,
        public ?Slug $currentSlug,
        public ChangeTypeEnum $changeType,
        public int $revision,
        /** @var list<HistoryEventDTO> */
        public array $historyEvents,
    ) {
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        if ($revision < 0) {
            throw new SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($revision !== $after->state->revision || $currentSlug?->value !== $after->state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Mutation result revision and current slug must match the after state.');
        }
        $this->assertAffectedClaims($affectedClaims, $after);
        // The enclosing transfer carries the out event after its nested replacement.
        $trailingEvents = $operationType === OperationTypeEnum::ATOMIC_TRANSFER ? 1 : 0;
        $after->assertResultEvidence($before, $historyEvents, $trailingEvents);
        $this->assertOutcome();
    }

    /** Matches the operation to its outcome and verifies the recorded state change. */
    private function assertOutcome(): void
    {
        $allowed = match ($this->operationType) {
            OperationTypeEnum::ASSIGN_EXACT, OperationTypeEnum::ASSIGN_GENERATED => [ChangeTypeEnum::ASSIGNED],
            OperationTypeEnum::CHANGE_EXACT, OperationTypeEnum::CHANGE_GENERATED, OperationTypeEnum::ATOMIC_TRANSFER => [ChangeTypeEnum::CHANGED, ChangeTypeEnum::RESTORED],
            OperationTypeEnum::RESTORE_HISTORICAL => [ChangeTypeEnum::RESTORED],
            OperationTypeEnum::DEACTIVATE => [ChangeTypeEnum::DEACTIVATED],
            OperationTypeEnum::REACTIVATE => [ChangeTypeEnum::REACTIVATED],
            OperationTypeEnum::RELEASE_CLAIM => [ChangeTypeEnum::RELEASED],
            OperationTypeEnum::RELEASE_ALL => [ChangeTypeEnum::RELEASED_ALL],
            OperationTypeEnum::ADD_ALIAS => [ChangeTypeEnum::ALIAS_ADDED],
            OperationTypeEnum::RETIRE_ALIAS => [ChangeTypeEnum::ALIAS_RETIRED],
            OperationTypeEnum::REACTIVATE_ALIAS => [ChangeTypeEnum::ALIAS_REACTIVATED],
            OperationTypeEnum::PROMOTE_ALIAS => [ChangeTypeEnum::ALIAS_PROMOTED],
            default => [],
        };
        if (! in_array($this->changeType, $allowed, true)) {
            throw new SlugInvalidArgumentException('Mutation outcome must match its operation.');
        }

        if ($this->historyEvents === []) {
            $this->assertNoOp();
            return;
        }

        if ($this->revision - ($this->before?->state->revision ?? 0) !== 1) {
            throw new SlugInvalidArgumentException('A recorded mutation must advance the Binding revision once.');
        }
        $this->assertStateChange();
        $this->assertHistoryOutcome($this->historyEvents[0]);
    }

    /** Requires a live and unchanged Binding for the operations that allow a no-op. */
    private function assertNoOp(): void
    {
        $before = $this->before;
        if (
            ! in_array($this->operationType, [OperationTypeEnum::CHANGE_EXACT, OperationTypeEnum::CHANGE_GENERATED, OperationTypeEnum::ADD_ALIAS], true)
            || ! in_array($this->changeType, [ChangeTypeEnum::CHANGED, ChangeTypeEnum::ALIAS_ADDED], true)
            || $before === null || $before->currentClaim?->matches($this->after->currentClaim) !== true
            || $before->state->jsonSerialize() !== $this->after->state->jsonSerialize()
            || $before->createdAt != $this->after->createdAt || $before->updatedAt != $this->after->updatedAt
            || $this->previousSlug?->value !== $this->currentSlug?->value
        ) {
            throw new SlugInvalidArgumentException('An empty mutation history requires a supported no-op with an unchanged Binding.');
        }
    }

    /** Verifies whether the mutation may create, remove, keep, or replace the current claim. */
    private function assertStateChange(): void
    {
        $beforeStatus = $this->before?->state->status;
        $afterStatus = $this->after->state->status;
        $beforeClaim = $this->before?->currentClaim;
        $sameCurrent = $beforeClaim?->matches($this->after->currentClaim) === true;
        $valid = match ($this->changeType) {
            ChangeTypeEnum::ASSIGNED => ($beforeStatus === null || $beforeStatus === BindingStatusEnum::RELEASED)
                && $afterStatus === BindingStatusEnum::ACTIVE && $this->previousSlug === null,
            ChangeTypeEnum::CHANGED, ChangeTypeEnum::RESTORED, ChangeTypeEnum::ALIAS_PROMOTED
                => $beforeClaim !== null && $beforeStatus === $afterStatus
                && $beforeClaim->id !== $this->after->currentClaim?->id
                && $this->previousSlug?->value === $beforeClaim->slug->value,
            ChangeTypeEnum::DEACTIVATED => $beforeStatus === BindingStatusEnum::ACTIVE && $afterStatus === BindingStatusEnum::INACTIVE && $sameCurrent,
            ChangeTypeEnum::REACTIVATED => $beforeStatus === BindingStatusEnum::INACTIVE && $afterStatus === BindingStatusEnum::ACTIVE && $sameCurrent,
            ChangeTypeEnum::RELEASED_ALL => $beforeClaim !== null && $afterStatus === BindingStatusEnum::RELEASED
                && $this->affectedClaims === [] && $this->previousSlug?->value === $beforeClaim->slug->value,
            ChangeTypeEnum::RELEASED, ChangeTypeEnum::ALIAS_ADDED, ChangeTypeEnum::ALIAS_RETIRED, ChangeTypeEnum::ALIAS_REACTIVATED
                => $beforeStatus === $afterStatus && $sameCurrent,
        };
        if (! $valid) {
            throw new SlugInvalidArgumentException('Mutation Binding states must match the recorded outcome.');
        }
    }

    /** Checks the event type and the claim evidence recorded for this mutation. */
    private function assertHistoryOutcome(HistoryEventDTO $event): void
    {
        $expected = match ($this->changeType) {
            ChangeTypeEnum::RELEASED => HistoryEventTypeEnum::OWNERSHIP_RELEASED,
            ChangeTypeEnum::RELEASED_ALL => HistoryEventTypeEnum::OWNERSHIP_RELEASED_ALL,
            default => HistoryEventTypeEnum::from($this->changeType->value),
        };
        if ($event->eventType !== $expected) {
            throw new SlugInvalidArgumentException('Mutation history must match its outcome.');
        }
        if ($expected === HistoryEventTypeEnum::OWNERSHIP_RELEASED_ALL) {
            $this->assertReleaseAllHistory();
            return;
        }
        if (count($this->historyEvents) !== 1) {
            throw new SlugInvalidArgumentException('Mutation must record exactly one history event.');
        }

        $valid = match ($this->changeType) {
            ChangeTypeEnum::ASSIGNED => $event->slugSnapshot?->value === $this->currentSlug?->value
                && $event->claimRoleSnapshot === RegistryRoleEnum::CURRENT_CANONICAL && $event->previousSlugSnapshot === null,
            ChangeTypeEnum::CHANGED, ChangeTypeEnum::RESTORED, ChangeTypeEnum::ALIAS_PROMOTED => $event->slugSnapshot?->value === $this->currentSlug?->value
                && $event->claimRoleSnapshot === RegistryRoleEnum::CURRENT_CANONICAL
                && $event->previousSlugSnapshot?->value === $this->previousSlug?->value
                && $event->previousClaimRoleSnapshot === RegistryRoleEnum::CURRENT_CANONICAL,
            ChangeTypeEnum::RELEASED => $event->slugSnapshot?->value === $this->previousSlug?->value
                && $event->claimRoleSnapshot !== RegistryRoleEnum::CURRENT_CANONICAL,
            ChangeTypeEnum::ALIAS_ADDED, ChangeTypeEnum::ALIAS_REACTIVATED => $event->claimRoleSnapshot === RegistryRoleEnum::ACTIVE_ALIAS
                && $this->containsHistoryClaim($event),
            ChangeTypeEnum::ALIAS_RETIRED => $event->claimRoleSnapshot === RegistryRoleEnum::RETIRED_ALIAS
                && $this->containsHistoryClaim($event),
            default => true,
        };
        if (! $valid) {
            throw new SlugInvalidArgumentException('Mutation history claim evidence must match its outcome.');
        }
    }

    /** Matches alias history to the stored slug bytes and role in the result's claim list. */
    private function containsHistoryClaim(HistoryEventDTO $event): bool
    {
        return array_any(
            $this->affectedClaims,
            static fn(RegistryClaimDTO $claim): bool => $claim->role === $event->claimRoleSnapshot
                && $claim->slug->value === $event->slugSnapshot?->value,
        );
    }

    /** Requires the release marker followed by the released claims, including the old current claim. */
    private function assertReleaseAllHistory(): void
    {
        $releasedCurrent = false;
        foreach (array_slice($this->historyEvents, 1) as $event) {
            if ($event->eventType !== HistoryEventTypeEnum::OWNERSHIP_RELEASED) {
                throw new SlugInvalidArgumentException('Release-all history must follow its marker with ownership releases.');
            }
            if ($event->claimRoleSnapshot === RegistryRoleEnum::CURRENT_CANONICAL) {
                if ($releasedCurrent || $event->slugSnapshot?->value !== $this->previousSlug?->value) {
                    throw new SlugInvalidArgumentException('Release-all history must identify the one former current claim.');
                }
                $releasedCurrent = true;
            }
        }
        if (! $releasedCurrent) {
            throw new SlugInvalidArgumentException('Release-all history must include the former current claim.');
        }
    }

    /**
     * Checks ownership, ordering, and unique Registry keys, excluding a released slug from the final claims.
     * A current claim in the after state must be present by exact value.
     *
     * @param array<int, mixed> $items
     */
    private function assertAffectedClaims(array $items, BindingDTO $after): void
    {
        if (! array_is_list($items)) {
            throw new SlugInvalidArgumentException('affectedClaims must be a list.');
        }
        $previous = null;
        $foundCurrent = false;
        $seenSlugs = [];
        $releasedKey = $this->changeType === ChangeTypeEnum::RELEASED && $this->previousSlug !== null
            ? RegistryClaimDTO::scopeSlugKey($this->previousSlug) : null;
        foreach ($items as $item) {
            if (! $item instanceof RegistryClaimDTO || ! $after->identity->equals($item->binding)) {
                throw new SlugInvalidArgumentException('affectedClaims must contain only Registry claims owned by the result Binding.');
            }
            if ($previous !== null && $item->id <= $previous) {
                throw new SlugInvalidArgumentException('affectedClaims must be strictly ordered by id.');
            }
            $previous = $item->id;
            $key = RegistryClaimDTO::scopeSlugKey($item->slug);
            if (isset($seenSlugs[$key]) || $key === $releasedKey) {
                throw new SlugInvalidArgumentException('The claim list cannot contain duplicate Registry slugs or the released slug.');
            }
            $seenSlugs[$key] = true;
            if ($item->role === RegistryRoleEnum::CURRENT_CANONICAL) {
                if ($foundCurrent || ! $item->matches($after->currentClaim)) {
                    throw new SlugInvalidArgumentException('The claim list must agree with the after state on its single current claim.');
                }
                $foundCurrent = true;
            }
        }
        if ($after->currentClaim !== null && ! $foundCurrent) {
            throw new SlugInvalidArgumentException('The claim list must include the current claim from the after state.');
        }
    }

    /** Returns the same result with the replay flag supplied by idempotency handling. */
    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->before,
            $this->after,
            $this->affectedClaims,
            $this->previousSlug,
            $this->currentSlug,
            $this->changeType,
            $this->revision,
            $this->historyEvents,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'operation_type' => $this->operationType->value,
            'operation_key' => $this->operationKey,
            'replayed' => $this->replayed,
            'before' => $this->before,
            'after' => $this->after,
            'affected_claims' => $this->affectedClaims,
            'previous_slug' => $this->previousSlug?->value,
            'current_slug' => $this->currentSlug?->value,
            'change_type' => $this->changeType->value,
            'revision' => $this->revision,
            'history_events' => $this->historyEvents,
        ];
    }
}
