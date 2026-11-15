<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;

/** @internal Immutable internal result for one ownership claim attempt. */
final readonly class OwnershipClaimResultDTO implements JsonSerializable
{
    /** Stores the claim outcome and the registry record selected by the coordinator. */
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $attempts,
    ) {}

    /**
     * Returns the binding, claim, and attempt count for serialization.
     *
     * @return array{binding: BindingDTO, claim: RegistryClaimDTO, attempts: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'binding' => $this->binding,
            'claim' => $this->claim,
            'attempts' => $this->attempts,
        ];
    }
}
