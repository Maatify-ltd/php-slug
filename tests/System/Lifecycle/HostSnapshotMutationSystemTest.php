<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptCurrentCommand;
use Maatify\Slug\Lifecycle\Command\AdoptHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostSnapshotMutationSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('newBindingMutationModes')]
    public function testMutationSeesNewBindingAndClaimsInExistingHostSnapshot(string $operation): void
    {
        $host = $this->engine($this->pdo);
        $writer = $this->engine($this->newTestConnection());
        $identity = $this->identity('source');
        $audit = new AuditContextDTO();
        $host->ensureScope($identity->scopeProfile);
        $this->startSnapshot();
        try {
            $writer->assignExact(new AssignExactCommand($identity, 'initial', null, $audit));
            $revision = 1;
            if ($operation === 'restore') {
                $writer->changeExact(new ChangeExactCommand($identity, 'latest', 1, $audit));
                $revision = 2;
            }
            self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM maa_slug_bindings'));

            $result = match ($operation) {
                'exact' => $host->changeExact(new ChangeExactCommand($identity, 'after-snapshot', $revision, $audit)),
                'alias' => $host->addAlias(new AddAliasCommand($identity, 'after-snapshot', $revision, $audit)),
                'restore' => $host->restoreHistorical(new RestoreHistoricalCommand($identity, 'initial', $revision, $audit)),
                'adopt-alias' => $host->adoptAlias(new AdoptAliasCommand($identity, 'after-snapshot', null, $revision, $audit)),
                'adopt-historical' => $host->adoptHistorical(new AdoptHistoricalCommand($identity, 'after-snapshot', null, $revision, $audit)),
                default => throw new \LogicException('Unknown mutation fixture.'),
            };

            self::assertNotNull($result->before);
            self::assertSame($revision, $result->before->state->revision);
            self::assertSame($revision + 1, $result->after->state->revision);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame($operation === 'restore' ? 2 : 1, $this->countRows('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    /** @return array<string, array{string}> */
    public static function newBindingMutationModes(): array
    {
        return [
            'exact change' => ['exact'],
            'alias addition' => ['alias'],
            'historical restoration' => ['restore'],
            'alias adoption' => ['adopt-alias'],
            'historical adoption' => ['adopt-historical'],
        ];
    }

    #[DataProvider('hostBindingWriteModes')]
    public function testGeneratedChangeUsesWriterRevisionAndCurrentClaim(bool $writesBindingFirst): void
    {
        $host = $this->engine($this->pdo);
        $writer = $this->engine($this->newTestConnection());
        $identity = $this->identity('source');
        $audit = new AuditContextDTO();
        $host->assignExact(new AssignExactCommand($identity, 'initial', null, $audit));
        $this->startSnapshot();
        try {
            $writer->changeExact(new ChangeExactCommand($identity, 'latest', 1, $audit));
            $expectedRevision = 2;
            if ($writesBindingFirst) {
                $host->deactivate(new DeactivateBindingCommand($identity, $expectedRevision, $audit));
                $expectedRevision++;
            }

            $result = $host->changeGenerated(new ChangeGeneratedCommand($identity, 'Final', $expectedRevision, $audit));

            self::assertSame('latest', $result->before?->currentClaim?->slug->value);
            self::assertSame('final', $result->after->currentClaim?->slug->value);
            self::assertSame($expectedRevision + 1, $result->after->state->revision);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    /** @return array<string, array{bool}> */
    public static function hostBindingWriteModes(): array
    {
        return ['writer revision' => [false], 'Host writes Binding first' => [true]];
    }

    public function testAtomicTransferSeesParticipantsAndAliasCommittedAfterHostSnapshot(): void
    {
        $host = $this->engine($this->pdo);
        $writer = $this->engine($this->newTestConnection());
        $source = $this->identity('source');
        $target = $this->identity('target');
        $audit = new AuditContextDTO();
        $host->ensureScope($source->scopeProfile);
        $this->startSnapshot();
        try {
            $writer->assignExact(new AssignExactCommand($source, 'source-current', null, $audit));
            $writer->addAlias(new AddAliasCommand($source, 'shared-alias', 1, $audit));
            $writer->assignExact(new AssignExactCommand($target, 'target-current', null, $audit));
            self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM maa_slug_bindings'));

            $result = $host->atomicTransfer(new AtomicTransferCommand($source, $target, 'shared-alias', null, 2, 1, $audit));

            self::assertSame(3, $result->sourceRevision);
            self::assertSame(2, $result->targetRevision);
            self::assertSame('shared-alias', $result->transferredClaim->slug->value);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM maa_slug_registry r JOIN maa_slug_bindings b ON b.id = r.binding_id WHERE r.slug = 'shared-alias' AND b.entity_key = 'source'"));
    }

    #[DataProvider('modesForReleasedClaim')]
    public function testAssignmentAndCurrentAdoptionSeeClaimReleasedAfterHostSnapshot(string $operation): void
    {
        $host = $this->engine($this->pdo);
        $writer = $this->engine($this->newTestConnection());
        $occupant = $this->identity('occupant');
        $recipient = $this->identity('recipient');
        $audit = new AuditContextDTO();
        $host->assignExact(new AssignExactCommand($occupant, 'now-free', null, $audit));
        if ($operation === 'adopt') {
            $host->assignExact(new AssignExactCommand($recipient, 'recipient-current', null, $audit));
        }
        $this->startSnapshot();
        try {
            $writer->releaseAllOwnership(new ReleaseAllOwnershipCommand($occupant, 1, $audit));
            if ($operation === 'adopt') {
                $writer->releaseAllOwnership(new ReleaseAllOwnershipCommand($recipient, 1, $audit));
            }
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM maa_slug_registry WHERE slug = 'now-free'"));

            $result = match ($operation) {
                'exact' => $host->assignExact(new AssignExactCommand($recipient, 'now-free', null, $audit)),
                'generated' => $host->assignGenerated(new AssignGeneratedCommand($recipient, 'Now Free', null, $audit)),
                'adopt' => $host->adoptCurrent(new AdoptCurrentCommand($recipient, 'now-free', null, 2, $audit)),
                default => throw new \LogicException('Unknown assignment fixture.'),
            };

            self::assertSame('now-free', $result->after->currentClaim?->slug->value);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    /** @return array<string, array{string}> */
    public static function modesForReleasedClaim(): array
    {
        return ['exact assignment' => ['exact'], 'generated assignment' => ['generated'], 'current adoption' => ['adopt']];
    }

    #[DataProvider('adjacentScopeSlugs')]
    public function testMutationDoesNotBlockAssignmentInAdjacentScope(string $candidate): void
    {
        $otherPdo = $this->newTestConnection();
        $host = $this->engine($this->pdo);
        $other = $this->engine($otherPdo);
        $source = $this->identity('source', 'a-lock-scope');
        $recipient = $this->identity('recipient', 'b-lock-scope');
        $audit = new AuditContextDTO();
        $host->assignExact(new AssignExactCommand($source, 'base', null, $audit));
        for ($index = 1; $index <= 10; $index++) {
            $sentinel = $this->identity('sentinel-' . $index, 'a-lock-scope');
            $host->assignExact(new AssignExactCommand($sentinel, 'z-sentinel-' . $index, null, $audit));
        }
        $host->assignExact(new AssignExactCommand($this->identity('sentinel', 'b-lock-scope'), 'zzz', null, $audit));
        $host->assignExact(new AssignExactCommand($recipient, 'seed', null, $audit));
        $host->releaseAllOwnership(new ReleaseAllOwnershipCommand($recipient, 1, $audit));

        // Prime the observer's schema probes before measuring Registry locks.
        $other->ensureScope($recipient->scopeProfile);
        $otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $moved = $host->changeExact(new ChangeExactCommand($source, $candidate, 1, $audit));
            self::assertSame($candidate, $moved->after->currentClaim?->slug->value);
            self::assertTrue($this->pdo->inTransaction());

            $assigned = $other->assignExact(new AssignExactCommand($recipient, 'aaa', 2, $audit));

            self::assertSame('aaa', $assigned->after->currentClaim?->slug->value);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        $rolledBackClaim = $this->pdo->prepare('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = ?');
        self::assertNotFalse($rolledBackClaim);
        $rolledBackClaim->execute([$candidate]);
        self::assertSame(0, (int) $rolledBackClaim->fetchColumn());
        $rolledBackKey = $this->pdo->prepare('SELECT COUNT(*) FROM maa_slug_registry_lock_keys WHERE slug = ?');
        self::assertNotFalse($rolledBackKey);
        $rolledBackKey->execute([$candidate]);
        self::assertSame(0, (int) $rolledBackKey->fetchColumn());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM maa_slug_registry WHERE slug = 'aaa'"));
    }

    /** @return array<string, array{string}> */
    public static function adjacentScopeSlugs(): array
    {
        return ['interior candidate' => ['mine'], 'candidate beyond partition tail' => ['zzzzzz']];
    }

    public function testFirstAssignmentInEmptyScopeDoesNotBlockAdjacentScope(): void
    {
        $otherPdo = $this->newTestConnection();
        $host = $this->engine($this->pdo);
        $other = $this->engine($otherPdo);
        $source = $this->identity('first-binding', 'a-lock-scope');
        $recipient = $this->identity('recipient', 'b-lock-scope');
        $audit = new AuditContextDTO();
        $host->ensureScope($source->scopeProfile);
        $host->assignExact(new AssignExactCommand($this->identity('sentinel', 'b-lock-scope'), 'zzz', null, $audit));
        $host->assignExact(new AssignExactCommand($recipient, 'seed', null, $audit));
        $host->releaseAllOwnership(new ReleaseAllOwnershipCommand($recipient, 1, $audit));

        // Prime the observer's schema probes before measuring Registry locks.
        $other->ensureScope($recipient->scopeProfile);
        $otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $assignedFirst = $host->assignExact(new AssignExactCommand($source, 'zzzzzz', null, $audit));
            self::assertSame('zzzzzz', $assignedFirst->after->currentClaim?->slug->value);
            $assignedOther = $other->assignExact(new AssignExactCommand($recipient, 'aaa', 2, $audit));
            self::assertSame('aaa', $assignedOther->after->currentClaim?->slug->value);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM maa_slug_registry_lock_keys WHERE slug = 'zzzzzz'"));
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM maa_slug_registry_lock_keys WHERE slug = 'aaa'"));
    }

    public function testPurgingLastBindingPartitionPreservesScopeBoundariesAndHostRollback(): void
    {
        $host = $this->engine($this->pdo);
        $purged = $this->identity('last-binding', 'a-lock-scope');
        $audit = new AuditContextDTO();
        $host->assignExact(new AssignExactCommand($purged, 'seed-a', null, $audit));
        $released = $host->releaseAllOwnership(new ReleaseAllOwnershipCommand($purged, 1, $audit));
        $partitionCount = 'SELECT COUNT(*) FROM maa_slug_registry_lock_keys WHERE binding_partition = ' . $released->after->id;
        $scopeEnds = 'SELECT COUNT(*) FROM maa_slug_registry_lock_keys WHERE scope_end = 1';

        $this->startSnapshot();
        try {
            $host->purgeBinding(new PurgeBindingCommand($purged, 2));
            self::assertSame(0, $this->countRows($partitionCount));
            self::assertSame(1, $this->countRows($scopeEnds));
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->rollBackHost();
        }
        self::assertSame(2, $this->countRows($partitionCount));
        self::assertSame(1, $this->countRows($scopeEnds));
    }

    private function startSnapshot(): void
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM maa_slug_scopes'));
    }

    private function rollBackHost(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function countRows(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function engine(PDO $pdo): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());

        return SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $key, string $namespace = 'host-snapshot'): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $key),
        );
    }
}
