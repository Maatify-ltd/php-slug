<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Support;

use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;

/** Adapts the PDO guard for services that require mutation capabilities. */
final readonly class PdoMutationCapabilityGuard implements CapabilityGuardInterface
{
    public function __construct(private PdoCapabilityGuard $capabilities) {}

    public function assertInstalledSchemaSupported(): void
    {
        $this->capabilities->assertMutationSupported();
    }
}
