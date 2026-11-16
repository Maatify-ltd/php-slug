<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Closure;
use PDO;
use PDOStatement;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class SlugReadSnapshotSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('readPaths')]
    public function testReadRetainsOneObservationWhenAnotherConnectionChangesTheSlug(string $path, bool $hostTransaction): void
    {
        $writer = $this->engine($this->pdo);
        $identity = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('read-snapshot', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'observed'),
        );
        $writer->assignExact(new AssignExactCommand($identity, 'old-slug', null, new AuditContextDTO()));
        $readerPdo = $this->newTestConnection();
        $readerPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($hostTransaction) {
            $readerPdo->exec('CREATE TEMPORARY TABLE slug_read_snapshot_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
            $readerPdo->beginTransaction();
            $readerPdo->exec('INSERT INTO slug_read_snapshot_marker VALUES (1)');
        }
        $written = false;
        $afterRead = static function (string $sql) use ($writer, $identity, &$written): void {
            if ($written || ! str_contains($sql, 'maa_slug_bindings b')) {
                return;
            }
            $written = true;
            $writer->changeExact(new ChangeExactCommand($identity, 'new-slug', 1, new AuditContextDTO()));
        };
        $readerPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [WriteAfterReadStatement::class, [$afterRead]]);
        $reader = $this->engine($readerPdo);

        try {
            if ($path === 'binding') {
                $binding = $reader->getBinding(new BindingCriteria($identity));
                self::assertSame('old-slug', $binding?->currentClaim?->slug->value);
                self::assertSame(1, $binding->state->revision);
            } elseif ($path === 'current') {
                $current = $reader->getCurrent(new CurrentSlugCriteria($identity));
                self::assertSame('old-slug', $current?->claim->slug->value);
                self::assertSame(1, $current->revision);
            } else {
                $resolved = $reader->resolve(new ResolutionCriteria($identity->scopeProfile, 'old-slug'));
                self::assertSame(MatchKindEnum::CURRENT, $resolved->matchKind);
                self::assertSame('old-slug', $resolved->matchedSlug?->value);
                self::assertSame('old-slug', $resolved->currentSlug?->value);
                self::assertSame(1, $resolved->bindingRevision);
            }
            self::assertTrue($written, 'Writer must commit after a real reader statement executes.');
            self::assertSame($hostTransaction, $readerPdo->inTransaction());
            $isolation = $readerPdo->query('SELECT @@SESSION.transaction_isolation');
            self::assertNotFalse($isolation);
            self::assertSame('READ-COMMITTED', $isolation->fetchColumn());
            $after = $reader->getCurrent(new CurrentSlugCriteria($identity));
            self::assertSame('new-slug', $after?->claim->slug->value);
            self::assertSame(2, $after->revision);
        } finally {
            if ($readerPdo->inTransaction()) {
                $readerPdo->rollBack();
            }
        }
        if ($hostTransaction) {
            $marker = $readerPdo->query('SELECT COUNT(*) FROM slug_read_snapshot_marker');
            self::assertNotFalse($marker);
            self::assertSame('0', (string) $marker->fetchColumn());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function readPaths(): iterable
    {
        foreach (['binding', 'current', 'resolve'] as $path) {
            yield $path . '-autocommit' => [$path, false];
            yield $path . '-host-read-committed' => [$path, true];
        }
    }

    public function testResolveRejectsScopeProfileMismatchWithoutMatchingClaim(): void
    {
        $engine = $this->engine($this->pdo);
        $scope = new SlugScope('profile-check', null, null);
        $engine->ensureScope(new ScopeProfileRequestDTO($scope, new SlugProfileKey('ascii-v1')));

        $this->expectException(SlugScopeProfileMismatchException::class);
        $engine->resolve(new ResolutionCriteria(new ScopeProfileRequestDTO($scope, new SlugProfileKey('unicode-v1')), 'never-assigned'));
    }

    public function testResolveStillRejectsAnActiveBindingWithoutCurrentPointer(): void
    {
        $engine = $this->engine($this->pdo);
        $request = new ScopeProfileRequestDTO(new SlugScope('corrupt-pointer', null, null), new SlugProfileKey('ascii-v1'));
        $identity = new BindingIdentityDTO($request, new EntityReference('product', 'corrupt'));
        $engine->assignExact(new AssignExactCommand($identity, 'persisted-slug', null, new AuditContextDTO()));
        $this->pdo->exec('UPDATE maa_slug_bindings SET current_registry_id = NULL');

        $this->expectException(SlugPersistenceInvariantException::class);
        $this->expectExceptionMessage('Active or inactive Binding has an invalid current pointer.');
        $engine->resolve(new ResolutionCriteria($request, 'persisted-slug'));
    }

    private function engine(PDO $pdo): SlugEngine
    {
        return SlugEngineFactory::create($pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
    }
}

/** Runs a second real connection's write after the first connection executes a SELECT. */
final class WriteAfterReadStatement extends PDOStatement
{
    /** @param Closure(string): void $afterRead */
    protected function __construct(private readonly Closure $afterRead) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $executed = parent::execute($params);
        ($this->afterRead)($this->queryString);

        return $executed;
    }
}
