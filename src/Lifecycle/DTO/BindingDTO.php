<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable binding snapshot with identity, lifecycle state, and current claim. */
final readonly class BindingDTO implements JsonSerializable
{
    /** Requires a live Binding's current canonical claim to match its identity and current slug. */
    public function __construct(
        public int $id,
        public BindingIdentityDTO $identity,
        public BindingStateDTO $state,
        public ?RegistryClaimDTO $currentClaim,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($id < 0) {
            throw new SlugInvalidArgumentException('id must be non-negative.');
        }
        if ($state->status === BindingStatusEnum::RELEASED && $currentClaim !== null) {
            throw new SlugInvalidArgumentException('Released binding cannot have a current claim.');
        }
        if ($state->status !== BindingStatusEnum::RELEASED && $currentClaim === null) {
            throw new SlugInvalidArgumentException('Active or inactive binding requires a current claim.');
        }
        if ($currentClaim !== null && $currentClaim->slug->value !== $state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Binding current claim must match current slug.');
        }
        if ($currentClaim !== null && ($currentClaim->role !== RegistryRoleEnum::CURRENT_CANONICAL || ! $identity->equals($currentClaim->binding))) {
            throw new SlugInvalidArgumentException('Binding current claim must be canonical and owned by that Binding.');
        }
    }

    /**
     * Validates an earlier state and ordered history against this Binding.
     *
     * @param array<int, mixed> $historyEvents
     * @internal
     */
    public function assertResultEvidence(?self $before, array $historyEvents): void
    {
        if ($before !== null && ($before->id !== $this->id || ! $this->identity->equals($before->identity))) {
            throw new SlugInvalidArgumentException('Result before and after states must refer to the same Binding.');
        }
        if (! array_is_list($historyEvents)) {
            throw new SlugInvalidArgumentException('historyEvents must be a list.');
        }
        $scope = $this->identity->scopeProfile->scope->jsonSerialize();
        $entity = $this->identity->entity->jsonSerialize();
        $previous = null;
        foreach ($historyEvents as $event) {
            if (! $event instanceof HistoryEventDTO) {
                throw new SlugInvalidArgumentException('historyEvents contains an invalid history event.');
            }
            if (
                $event->bindingId !== $this->id
                || $event->scopeSnapshot->jsonSerialize() !== $scope
                || $event->entitySnapshot->jsonSerialize() !== $entity
            ) {
                throw new SlugInvalidArgumentException('History events must refer to the result Binding.');
            }
            if ($previous !== null && [$event->sequenceNo, $event->id] <= [$previous->sequenceNo, $previous->id]) {
                throw new SlugInvalidArgumentException('historyEvents must be ordered by sequence and id.');
            }
            $previous = $event;
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'identity' => $this->identity,
            'state' => $this->state,
            'current_claim' => $this->currentClaim,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'updated_at' => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
