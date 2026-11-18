<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Immutable result of a scope transition with participant state and ordered history. */
final readonly class ScopeTransitionResultDTO implements JsonSerializable
{
    /**
     * Validates transition metadata, revisions, and ordered participant history.
     *
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ScopeTransitionModeEnum $mode,
        public bool $targetCreated,
        public BindingStateResultDTO $sourceResult,
        public BindingStateResultDTO $targetResult,
        public ?BindingDTO $sourceBefore,
        public BindingDTO $sourceAfter,
        public ?BindingDTO $targetBefore,
        public BindingDTO $targetAfter,
        public ?Slug $sourceClaim,
        public ?Slug $targetClaim,
        public int $sourceRevision,
        public int $targetRevision,
        public array $historyEvents,
    ) {
        if ($operationType !== OperationTypeEnum::TRANSITION_SCOPE) {
            throw new SlugInvalidArgumentException('Transition result requires TRANSITION_SCOPE.');
        }
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        if ($sourceRevision < 0) {
            throw new SlugInvalidArgumentException('sourceRevision must be non-negative.');
        }
        if ($targetRevision < 0) {
            throw new SlugInvalidArgumentException('targetRevision must be non-negative.');
        }
        if (
            $sourceRevision !== $sourceAfter->state->revision || $sourceRevision !== $sourceResult->revision
            || $targetRevision !== $targetAfter->state->revision || $targetRevision !== $targetResult->revision
        ) {
            throw new SlugInvalidArgumentException('Transition revisions must match the participants and their after states.');
        }
        if (
            $sourceBefore?->currentClaim === null || ! $targetCreated
            || $targetBefore !== null || $targetAfter->state->status !== BindingStatusEnum::ACTIVE
        ) {
            throw new SlugInvalidArgumentException('A transition requires a source with a current claim and a newly created active target.');
        }
        if (
            $sourceAfter->id === $targetAfter->id || $sourceAfter->identity->sharesScope($targetAfter->identity)
            || ! $sourceAfter->identity->sharesEntity($targetAfter->identity)
        ) {
            throw new SlugInvalidArgumentException('A transition must carry the same entity to a different Scope and Binding.');
        }
        self::participantHistory($historyEvents, [$sourceAfter->id, $targetAfter->id], 'historyEvents');
        if ($sourceClaim?->value !== $sourceBefore->state->currentSlug?->value || $targetClaim?->value !== $targetAfter->state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Transition claims must match the source before and target after states.');
        }
        $outer = [$sourceBefore, $sourceAfter, $targetBefore, $targetAfter, $historyEvents];
        $participants = [
            $sourceResult->before,
            $sourceResult->after,
            $targetResult->before,
            $targetResult->after,
            [...$sourceResult->historyEvents, ...$targetResult->historyEvents],
        ];
        $flags = JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($outer !== $participants && json_encode($outer, $flags) !== json_encode($participants, $flags)) {
            throw new SlugInvalidArgumentException('Transition state and history must agree with the participant results.');
        }

        if (! self::recordsTransitionClaim($targetResult, HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN, $targetClaim, $sourceAfter->identity)) {
            throw new SlugInvalidArgumentException('The transition target must record its activation and identify the source.');
        }
        if ($mode === ScopeTransitionModeEnum::MOVE) {
            if (
                $sourceAfter->state->status !== BindingStatusEnum::INACTIVE
                || ! $sourceBefore->currentClaim->matches($sourceAfter->currentClaim)
                || ! self::recordsTransitionClaim($sourceResult, HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT, $sourceClaim, $targetAfter->identity)
            ) {
                throw new SlugInvalidArgumentException('A move must retain the source claim and record the deactivation and destination.');
            }
        } elseif ($sourceResult->mutated) {
            throw new SlugInvalidArgumentException('A parallel transition must leave the source unchanged.');
        }
    }

    /** Checks the canonical claim event and its counterpart for a participant changed by the transition. */
    private static function recordsTransitionClaim(
        BindingStateResultDTO $participant,
        HistoryEventTypeEnum $eventType,
        ?Slug $claim,
        BindingIdentityDTO $counterparty,
    ): bool {
        $event = $participant->historyEvents[0] ?? null;

        return $participant->mutated && count($participant->historyEvents) === 1
            && $event?->eventType === $eventType
            && $event->claimRoleSnapshot === RegistryRoleEnum::CURRENT_CANONICAL
            && $event->slugSnapshot?->value === $claim?->value
            && $event->identifiesCounterparty($counterparty);
    }

    /**
     * @param array<int, mixed> $items
     * @param list<int> $bindingIds
     */
    private static function participantHistory(array $items, array $bindingIds, string $field): void
    {
        if (! array_is_list($items)) {
            throw new SlugInvalidArgumentException(sprintf('%s must be a list.', $field));
        }
        $lastParticipant = -1;
        $lastSequence = [];
        $lastId = [];
        foreach ($items as $item) {
            if (! $item instanceof HistoryEventDTO) {
                throw new SlugInvalidArgumentException(sprintf('%s contains an invalid history event.', $field));
            }
            $participant = array_search($item->bindingId, $bindingIds, true);
            if ($participant === false || $participant < $lastParticipant) {
                throw new SlugInvalidArgumentException(sprintf('%s has invalid participant ordering.', $field));
            }
            if ($participant > $lastParticipant) {
                $lastParticipant = $participant;
            }
            if (isset($lastSequence[$item->bindingId]) && ($item->sequenceNo < $lastSequence[$item->bindingId] || ($item->sequenceNo === $lastSequence[$item->bindingId] && $item->id <= $lastId[$item->bindingId]))) {
                throw new SlugInvalidArgumentException(sprintf('%s must be ordered within each participant.', $field));
            }
            $lastSequence[$item->bindingId] = $item->sequenceNo;
            $lastId[$item->bindingId] = $item->id;
        }
    }

    /** Returns the same transition result with replay metadata applied. */
    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->mode,
            $this->targetCreated,
            $this->sourceResult,
            $this->targetResult,
            $this->sourceBefore,
            $this->sourceAfter,
            $this->targetBefore,
            $this->targetAfter,
            $this->sourceClaim,
            $this->targetClaim,
            $this->sourceRevision,
            $this->targetRevision,
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
            'mode' => $this->mode->value,
            'target_created' => $this->targetCreated,
            'source_result' => $this->sourceResult,
            'target_result' => $this->targetResult,
            'source_before' => $this->sourceBefore,
            'source_after' => $this->sourceAfter,
            'target_before' => $this->targetBefore,
            'target_after' => $this->targetAfter,
            'source_claim' => $this->sourceClaim?->value,
            'target_claim' => $this->targetClaim?->value,
            'source_revision' => $this->sourceRevision,
            'target_revision' => $this->targetRevision,
            'history_events' => $this->historyEvents,
        ];
    }
}
