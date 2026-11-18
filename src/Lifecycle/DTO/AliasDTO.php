<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;

/** Immutable read model for an alias claim and its resolution state. */
final readonly class AliasDTO implements JsonSerializable
{
    /** Requires an alias role, resolution enabled only for active aliases, and a non-negative revision. */
    public function __construct(
        public RegistryClaimDTO $claim,
        public bool $resolvableAsAlias,
        public int $bindingRevision,
    ) {
        if ($bindingRevision < 0) {
            throw new SlugInvalidArgumentException('bindingRevision must be non-negative.');
        }
        if (! in_array($claim->role, [RegistryRoleEnum::ACTIVE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS], true)) {
            throw new SlugInvalidArgumentException('Alias snapshots require an active or retired alias claim.');
        }
        if ($resolvableAsAlias !== ($claim->role === RegistryRoleEnum::ACTIVE_ALIAS)) {
            throw new SlugInvalidArgumentException('Alias resolution state must match the claim role.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'claim' => $this->claim,
            'resolvable_as_alias' => $this->resolvableAsAlias,
            'binding_revision' => $this->bindingRevision,
        ];
    }
}
