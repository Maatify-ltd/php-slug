<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable read model pairing a binding with its current registry claim. */
final readonly class CurrentSlugDTO implements JsonSerializable
{
    /** Rejects a revision mismatch or a claim that differs from the Binding's current canonical claim snapshot. */
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $revision,
    ) {
        if ($revision < 0) {
            throw new SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($revision !== $binding->state->revision) {
            throw new SlugInvalidArgumentException('Current slug revision must match binding revision.');
        }
        if ($claim->slug->value !== $binding->state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Current slug claim must match binding current slug.');
        }

        if (! $claim->matches($binding->currentClaim)) {
            throw new SlugInvalidArgumentException("The claim must match the binding's current canonical claim.");
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'binding' => $this->binding,
            'claim' => $this->claim,
            'revision' => $this->revision,
        ];
    }
}
