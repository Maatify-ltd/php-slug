<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Closure;
use PDO;
use PDOStatement;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class AvailabilityObservationSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('interleavings')]
    public function testAvailabilityKeepsOneObservationAcrossAConcurrentMutation(string $mutation, bool $hostTransaction, bool $buffered): void
    {
        $profiles = SlugProfileRegistryFactory::createBuiltIn();
        $writer = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $request = new ScopeProfileRequestDTO(new SlugScope('availability-observation', null, null), new SlugProfileKey('ascii-v1'));
        $owner = new BindingIdentityDTO($request, new EntityReference('article', 'original'));
        $target = new BindingIdentityDTO($request, new EntityReference('article', 'replacement'));
        $audit = new AuditContextDTO();
        $original = $writer->assignExact(new AssignExactCommand($owner, 'owned', null, $audit));
        if ($mutation === 'transfer') {
            $writer->assignExact(new AssignExactCommand($target, 'seed', null, $audit));
            $writer->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, $audit));
        }
        $readerPdo = $this->newTestConnection();
        $readerPdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        $readerPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($hostTransaction) {
            $readerPdo->exec('CREATE TEMPORARY TABLE availability_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
            $readerPdo->beginTransaction();
            $readerPdo->exec('INSERT INTO availability_host_marker VALUES (1)');
        }
        $written = false;
        $afterRead = static function (string $sql) use (&$written, $writer, $owner, $target, $audit, $mutation): void {
            if ($written || (! str_contains($sql, 'm.slug = :slug') && ! str_contains($sql, 'r.scope_id = :scope_id AND r.slug = :slug'))) {
                return;
            }
            $written = true;
            if ($mutation === 'transfer') {
                $writer->atomicTransfer(new AtomicTransferCommand($owner, $target, 'owned', new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'new-current'), 1, 2, $audit));
                return;
            }
            $writer->releaseAllOwnership(new ReleaseAllOwnershipCommand($owner, 1, $audit));
            if ($mutation === 'purge') {
                $writer->purgeBinding(new PurgeBindingCommand($owner, 2));
            } else {
                $writer->assignExact(new AssignExactCommand($target, 'owned', null, $audit));
            }
        };
        $readerPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AvailabilityAfterReadStatement::class, [$afterRead]]);
        $reader = SlugEngineFactory::create($readerPdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        try {
            $result = $reader->checkAvailability(new AvailabilityCriteria($request, 'owned', $owner));
            self::assertTrue($written);
            self::assertSame(AvailabilityStatusEnum::OWNED_BY_SAME_BINDING, $result->status);
            $observedOwner = $result->owner;
            self::assertNotNull($observedOwner);
            self::assertSame($original->after->id, $observedOwner->id);
            self::assertSame('owned', $observedOwner->currentClaim?->slug->value);
            self::assertSame(1, $observedOwner->state->revision);
            self::assertSame($hostTransaction, $readerPdo->inTransaction());
            $next = $reader->checkAvailability(new AvailabilityCriteria($request, 'owned', $owner));
            self::assertSame($mutation === 'purge' ? AvailabilityStatusEnum::AVAILABLE : AvailabilityStatusEnum::OWNED_BY_OTHER_BINDING, $next->status);
        } finally {
            if ($readerPdo->inTransaction()) {
                $readerPdo->rollBack();
            }
        }
        if ($hostTransaction) {
            $marker = $readerPdo->query('SELECT COUNT(*) FROM availability_host_marker');
            self::assertNotFalse($marker);
            self::assertSame('0', (string) $marker->fetchColumn());
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function interleavings(): iterable
    {
        foreach (['purge', 'reuse', 'transfer'] as $mutation) {
            foreach ([false, true] as $hostTransaction) {
                foreach ([false, true] as $buffered) {
                    yield $mutation . '-' . (int) $hostTransaction . '-' . (int) $buffered => [$mutation, $hostTransaction, $buffered];
                }
            }
        }
    }

    public function testAvailabilityStillRejectsRealOwnerCorruption(): void
    {
        $engine = SlugEngineFactory::create($this->pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
        $request = new ScopeProfileRequestDTO(new SlugScope('corrupt-availability', null, null), new SlugProfileKey('ascii-v1'));
        $identity = new BindingIdentityDTO($request, new EntityReference('article', 'one'));
        $engine->assignExact(new AssignExactCommand($identity, 'owned', null, new AuditContextDTO()));
        $this->pdo->exec('UPDATE maa_slug_bindings SET current_registry_id = NULL');
        $this->expectException(SlugPersistenceInvariantException::class);
        $engine->checkAvailability(new AvailabilityCriteria($request, 'owned'));
    }

    public function testAvailabilityChecksTheScopeProfileWithoutAMatchingClaim(): void
    {
        $engine = SlugEngineFactory::create($this->pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
        $scope = new SlugScope('availability-profile', null, null);
        $engine->ensureScope(new ScopeProfileRequestDTO($scope, new SlugProfileKey('ascii-v1')));
        $this->expectException(SlugScopeProfileMismatchException::class);
        $engine->checkAvailability(new AvailabilityCriteria(new ScopeProfileRequestDTO($scope, new SlugProfileKey('unicode-v1')), 'missing'));
    }
}

/** Commits another real connection's mutation after the observation executes. */
final class AvailabilityAfterReadStatement extends PDOStatement
{
    /** @param Closure(string): void $afterRead */
    protected function __construct(private readonly Closure $afterRead) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        ($this->afterRead)($this->queryString);
        return $result;
    }
}
