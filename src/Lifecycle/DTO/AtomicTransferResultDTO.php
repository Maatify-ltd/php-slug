<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonException;
use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;

/** Immutable result of an atomic ownership transfer across two bindings. */
final readonly class AtomicTransferResultDTO implements JsonSerializable
{
    /**
     * Validates transfer metadata, participant history ordering, and replacement invariants.
     *
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public BindingStateResultDTO $sourceResult,
        public BindingStateResultDTO $targetResult,
        public RegistryClaimDTO $transferredClaim,
        public ?SlugMutationResultDTO $sourceReplacementResult,
        public int $sourceRevision,
        public int $targetRevision,
        public array $historyEvents,
    ) {
        if ($operationType !== OperationTypeEnum::ATOMIC_TRANSFER) {
            throw new SlugInvalidArgumentException('Transfer result requires ATOMIC_TRANSFER.');
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
        if ($sourceRevision !== $sourceResult->revision || $targetRevision !== $targetResult->revision) {
            throw new SlugInvalidArgumentException('Transfer revisions must match the participant after states.');
        }
        if (! $targetResult->after->identity->equals($transferredClaim->binding)) {
            throw new SlugInvalidArgumentException('The transferred claim must belong to the target Binding.');
        }
        self::participantHistory($historyEvents, [$sourceResult->after->id, $targetResult->after->id], 'historyEvents');
        $transfersCurrent = $transferredClaim->role === RegistryRoleEnum::CURRENT_CANONICAL;
        self::assertParticipantStates($sourceResult, $targetResult, $transferredClaim, $transfersCurrent);
        if ($transfersCurrent !== ($sourceReplacementResult !== null)) {
            throw new SlugInvalidArgumentException('Current transfers require a source replacement result.');
        }
        self::assertCompleteTransferHistory($sourceResult, $targetResult, $historyEvents, $transferredClaim);
        if ($sourceReplacementResult !== null && $sourceReplacementResult->operationType !== OperationTypeEnum::ATOMIC_TRANSFER) {
            throw new SlugInvalidArgumentException('Transfer replacement must use ATOMIC_TRANSFER.');
        }
        if ($sourceReplacementResult !== null && ($sourceReplacementResult->operationKey !== $operationKey || $sourceReplacementResult->replayed !== $replayed)) {
            throw new SlugInvalidArgumentException('Transfer replacement metadata must match the outer transfer.');
        }
        if ($sourceReplacementResult !== null) {
            self::assertFinalReplacement($sourceResult, $sourceReplacementResult, $transferredClaim);
        }
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

    /** Returns the same transfer result with replay metadata applied to all nested results. */
    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->sourceResult,
            $this->targetResult,
            $this->transferredClaim,
            $this->sourceReplacementResult?->withReplayed($replayed),
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
            'source_result' => $this->sourceResult,
            'target_result' => $this->targetResult,
            'transferred_claim' => $this->transferredClaim,
            'source_replacement_result' => $this->sourceReplacementResult,
            'source_revision' => $this->sourceRevision,
            'target_revision' => $this->targetRevision,
            'history_events' => $this->historyEvents,
        ];
    }

    /** Verifies the transfer effect on both participants' current claims and statuses. */
    private static function assertParticipantStates(
        BindingStateResultDTO $source,
        BindingStateResultDTO $target,
        RegistryClaimDTO $transferred,
        bool $transfersCurrent,
    ): void {
        $sourceIdentity = $source->after->identity;
        $targetIdentity = $target->after->identity;
        if (
            $source->after->id === $target->after->id || $sourceIdentity->sharesEntity($targetIdentity)
            || ! $sourceIdentity->sharesScope($targetIdentity)
            || $sourceIdentity->scopeProfile->expectedProfileKey->value !== $targetIdentity->scopeProfile->expectedProfileKey->value
        ) {
            throw new SlugInvalidArgumentException('Transfer requires distinct Bindings in the same Scope and profile.');
        }

        $sourceBefore = $source->before;
        $targetBefore = $target->before;
        if (
            $sourceBefore?->currentClaim === null || $targetBefore === null
            || ! $source->mutated || ! $target->mutated
            || $source->after->state->status !== $sourceBefore->state->status
        ) {
            throw new SlugInvalidArgumentException('Transfer requires two mutated participants with existing before states and a source that retains its status.');
        }

        $valid = $transfersCurrent
            ? $sourceBefore->currentClaim->slug->value === $transferred->slug->value
                && $targetBefore->state->status === BindingStatusEnum::RELEASED
                && $target->after->state->status === BindingStatusEnum::ACTIVE
                && $transferred->matches($target->after->currentClaim)
            : $sourceBefore->currentClaim->matches($source->after->currentClaim)
                && $targetBefore->state->status === $target->after->state->status
                && $targetBefore->currentClaim?->matches($target->after->currentClaim) === true
                && ! $transferred->sharesKeyWithinScope($sourceBefore->currentClaim)
                && ! $transferred->sharesKeyWithinScope($targetBefore->currentClaim);
        if (! $valid) {
            throw new SlugInvalidArgumentException('Transfer participant states must match the role of the transferred claim.');
        }
    }

    /** @param list<HistoryEventDTO> $historyEvents */
    private static function assertCompleteTransferHistory(
        BindingStateResultDTO $sourceResult,
        BindingStateResultDTO $targetResult,
        array $historyEvents,
        RegistryClaimDTO $transferredClaim,
    ): void {
        $sourceEvents = $sourceResult->historyEvents;
        $targetEvents = $targetResult->historyEvents;
        if (! self::sameHistory($historyEvents, array_merge($sourceEvents, $targetEvents))) {
            throw new SlugInvalidArgumentException('Transfer history must contain complete source and target evidence.');
        }

        $sourceCount = $transferredClaim->role === RegistryRoleEnum::CURRENT_CANONICAL ? 2 : 1;
        if (count($sourceEvents) !== $sourceCount || count($targetEvents) !== 1) {
            throw new SlugInvalidArgumentException('Transfer must contain its replacement evidence where applicable, one source out event, and one target in event.');
        }
        $sourceOut = $sourceEvents[$sourceCount - 1];
        $targetIn = $targetEvents[0];
        if (
            $sourceOut->eventType->value !== 'OWNERSHIP_TRANSFERRED_OUT'
            || $targetIn->eventType->value !== 'OWNERSHIP_TRANSFERRED_IN'
            || $sourceOut->bindingId !== $sourceResult->after->id
            || $targetIn->bindingId !== $targetResult->after->id
            || $sourceOut->slugSnapshot?->value !== $transferredClaim->slug->value
            || $targetIn->slugSnapshot?->value !== $transferredClaim->slug->value
            || $sourceOut->claimRoleSnapshot !== $transferredClaim->role
            || $targetIn->claimRoleSnapshot !== $transferredClaim->role
            || ! $sourceOut->identifiesCounterparty($targetResult->after->identity)
            || ! $targetIn->identifiesCounterparty($sourceResult->after->identity)
        ) {
            throw new SlugInvalidArgumentException('Transfer events must match the transferred claim and identify the counterpart Binding.');
        }
    }

    /** Ensures a current-transfer replacement reflects the final source state. */
    private static function assertFinalReplacement(
        BindingStateResultDTO $sourceResult,
        SlugMutationResultDTO $replacement,
        RegistryClaimDTO $transferredClaim,
    ): void {
        if ($sourceResult->after->state->status === BindingStatusEnum::RELEASED) {
            throw new SlugInvalidArgumentException('Current transfer source result cannot be RELEASED after replacement.');
        }
        if ($replacement->revision !== $sourceResult->revision) {
            throw new SlugInvalidArgumentException('Replacement revision must equal the final source revision.');
        }
        if (array_any($replacement->affectedClaims, $transferredClaim->sharesKeyWithinScope(...))) {
            throw new SlugInvalidArgumentException('Final source replacement must remove the transferred claim.');
        }
        if (! self::sameJson($replacement->before, $sourceResult->before) || ! self::sameJson($replacement->after, $sourceResult->after)) {
            throw new SlugInvalidArgumentException('Replacement before and after must be the final source evidence.');
        }
        if ($replacement->historyEvents === []) {
            throw new SlugInvalidArgumentException('Replacement result requires replacement history.');
        }
        foreach ($replacement->historyEvents as $event) {
            if (! in_array($event->eventType->value, ['CHANGED', 'RESTORED'], true) || $event->bindingId !== $sourceResult->after->id) {
                throw new SlugInvalidArgumentException('Replacement history must contain replacement events only.');
            }
        }
        if (! self::historyPrefix($sourceResult->historyEvents, $replacement->historyEvents)) {
            throw new SlugInvalidArgumentException('Replacement history must precede transfer-out history.');
        }
    }

    /**
     * @param list<HistoryEventDTO> $left
     * @param list<HistoryEventDTO> $right
     */
    private static function sameHistory(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $event) {
            if (! self::sameJson($event, $right[$index])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<HistoryEventDTO> $history
     * @param list<HistoryEventDTO> $prefix
     */
    private static function historyPrefix(array $history, array $prefix): bool
    {
        if (count($prefix) > count($history)) {
            return false;
        }
        foreach ($prefix as $index => $event) {
            if (! self::sameJson($history[$index], $event)) {
                return false;
            }
        }
        return true;
    }

    /** Compares serialized DTO representations without depending on object identity. */
    private static function sameJson(mixed $left, mixed $right): bool
    {
        try {
            return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
    }
}
