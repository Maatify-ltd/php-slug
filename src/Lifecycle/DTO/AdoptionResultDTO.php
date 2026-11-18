<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;

/** Immutable result of adopting a current, historical, or alias claim. */
final readonly class AdoptionResultDTO implements JsonSerializable
{
    /** Validates adoption metadata and ownership of the adopted claim and event. */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ?BindingDTO $before,
        public BindingDTO $after,
        public RegistryClaimDTO $adoptedClaim,
        public HistoryEventDTO $historyEvent,
    ) {
        [$eventType, $role] = match ($operationType) {
            OperationTypeEnum::ADOPT_CURRENT => [HistoryEventTypeEnum::ADOPTED_CURRENT, RegistryRoleEnum::CURRENT_CANONICAL],
            OperationTypeEnum::ADOPT_HISTORICAL => [HistoryEventTypeEnum::ADOPTED_HISTORICAL, RegistryRoleEnum::HISTORICAL_CANONICAL],
            OperationTypeEnum::ADOPT_ALIAS => [HistoryEventTypeEnum::ADOPTED_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS],
            default => throw new SlugInvalidArgumentException('Adoption result requires an adoption operation.'),
        };
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        $after->assertResultEvidence($before, [$historyEvent]);
        if (! $after->identity->equals($adoptedClaim->binding)) {
            throw new SlugInvalidArgumentException('The adopted claim must belong to the result Binding.');
        }
        if (
            $adoptedClaim->role !== $role || $historyEvent->eventType !== $eventType
            || $historyEvent->claimRoleSnapshot !== $role || $historyEvent->slugSnapshot?->value !== $adoptedClaim->slug->value
        ) {
            throw new SlugInvalidArgumentException('The adopted claim and history event must match the adoption operation.');
        }
    }

    /** Returns the same result with replay status adjusted by the persistence boundary. */
    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->before,
            $this->after,
            $this->adoptedClaim,
            $this->historyEvent,
        );
    }

    /**
     * Returns the operation, before/after state, adopted claim, and history event.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'operation_type' => $this->operationType->value,
            'operation_key' => $this->operationKey,
            'replayed' => $this->replayed,
            'before' => $this->before,
            'after' => $this->after,
            'adopted_claim' => $this->adoptedClaim,
            'history_event' => $this->historyEvent,
        ];
    }
}
