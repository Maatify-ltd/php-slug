<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Registry;

use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** @internal Reads the match and its hydrated owner from the same persisted observation. */
interface RegistryResolutionReaderInterface
{
    /** @return array{claim: RegistryClaimDTO, owner: BindingDTO}|null */
    public function findResolution(ScopeProfileRequestDTO $request, Slug $slug): ?array;
}
