<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Closure;
use JsonSerializable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Management\Criteria\AliasCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadCapabilitySystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('readPaths')]
    public function testFreshQueriesWorkWithARealSelectOnlyAccount(string $path, bool $buffered): void
    {
        [$writer, $identity] = $this->seed();
        $expected = json_encode($this->read($writer, $identity, $path), JSON_THROW_ON_ERROR);
        $pdo = $this->readOnlyConnection();
        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        try {
            $pdo->exec('UPDATE maa_slug_scopes SET id = id WHERE 1 = 0');
            self::fail('The dedicated reader account must not have UPDATE permission.');
        } catch (PDOException $exception) {
            self::assertSame(1142, $exception->errorInfo[1] ?? null);
        }
        $reader = $this->engine($pdo);
        self::assertSame($expected, json_encode($this->read($reader, $identity, $path), JSON_THROW_ON_ERROR));
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('hostModes')]
    public function testFreshAndWarmReadersKeepTheHostReadOnlyTransaction(bool $warm, bool $buffered): void
    {
        [$writer, $identity] = $this->seed();
        $pdo = $this->newTestConnection();
        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        $reader = $this->engine($pdo);
        if ($warm) {
            $reader->getCurrent(new CurrentSlugCriteria($identity));
        }
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            foreach (self::paths() as $path) {
                self::assertSame(json_encode($this->read($writer, $identity, $path), JSON_THROW_ON_ERROR), json_encode($this->read($reader, $identity, $path), JSON_THROW_ON_ERROR));
                self::assertTrue($pdo->inTransaction());
            }
            try {
                $pdo->exec('UPDATE maa_slug_scopes SET id = id WHERE 1 = 0');
                self::fail('The Host transaction must remain READ ONLY.');
            } catch (PDOException $exception) {
                self::assertSame(1792, $exception->errorInfo[1] ?? null);
            }
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testAReadDoesNotCacheAwayTheFirstMutationProbeOrHostRollback(): void
    {
        [, $identity] = $this->seed();
        $pdo = $this->newTestConnection();
        $probes = 0;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ReadProbeTraceStatement::class, [static function (array $params) use (&$probes): void {
            $namespace = $params['namespace'] ?? null;
            if (is_string($namespace) && str_starts_with($namespace, '__cap_')) {
                $probes++;
            }
        }]]);
        $reader = $this->engine($pdo);
        $pdo->exec('CREATE TEMPORARY TABLE read_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO read_host_marker VALUES (1)');
        $newIdentity = new BindingIdentityDTO($identity->scopeProfile, new EntityReference('article', 'new'));
        try {
            $reader->getCurrent(new CurrentSlugCriteria($identity));
            self::assertSame(0, $probes, 'A public read must not execute the DML capability probes.');
            $reader->assignExact(new AssignExactCommand($newIdentity, 'new', null, new AuditContextDTO()));
            self::assertSame(2, $probes, 'The first mutation must still execute its insert and duplicate probes.');
            $reader->changeExact(new ChangeExactCommand($newIdentity, 'changed', 1, new AuditContextDTO()));
            self::assertSame(2, $probes);
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
        $marker = $pdo->query('SELECT COUNT(*) FROM read_host_marker');
        self::assertNotFalse($marker);
        self::assertSame('0', (string) $marker->fetchColumn());
        self::assertNull($this->engine($this->newTestConnection())->getCurrent(new CurrentSlugCriteria($newIdentity)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function readPaths(): iterable
    {
        foreach (self::paths() as $path) {
            yield $path . '-buffered' => [$path, true];
            yield $path . '-unbuffered' => [$path, false];
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function hostModes(): iterable
    {
        foreach ([false, true] as $warm) {
            foreach ([false, true] as $buffered) {
                yield (int) $warm . '-' . (int) $buffered => [$warm, $buffered];
            }
        }
    }

    /** @return list<string> */
    private static function paths(): array
    {
        return ['current', 'resolve', 'availability', 'binding', 'scope', 'summary', 'scopes', 'aliases', 'history', 'registry', 'bindings', 'registry-search', 'history-search'];
    }

    /** Executes only documented public query contracts. */
    private function read(SlugEngine $engine, BindingIdentityDTO $identity, string $path): ?JsonSerializable
    {
        $request = $identity->scopeProfile;
        $page = new PageRequest(page: 1, perPage: 25);
        return match ($path) {
            'current' => $engine->getCurrent(new CurrentSlugCriteria($identity)),
            'resolve' => $engine->resolve(new ResolutionCriteria($request, 'owned')),
            'availability' => $engine->checkAvailability(new AvailabilityCriteria($request, 'owned', $identity)),
            'binding' => $engine->getBinding(new BindingCriteria($identity)),
            'scope' => $engine->inspectScope(new ScopeCriteria($request)),
            'summary' => $engine->getScopeOperationalSummary(new ScopeCriteria($request)),
            'scopes' => $engine->searchScopes(new ScopeSearchCriteria($page)),
            'aliases' => $engine->listAliases(new AliasCriteria($identity, $page)),
            'history' => $engine->getHistory(new HistoryCriteria($identity, $page)),
            'registry' => $engine->inspectRegistry(new RegistryCriteria($request, $page)),
            'bindings' => $engine->searchBindings(new BindingSearchCriteria($request, $page)),
            'registry-search' => $engine->searchRegistry(new RegistrySearchCriteria($request, $page)),
            'history-search' => $engine->searchHistory(new HistorySearchCriteria($page, $request)),
            default => throw new RuntimeException('Unknown query path.'),
        };
    }

    /** @return array{SlugEngine, BindingIdentityDTO} */
    private function seed(): array
    {
        $engine = $this->engine($this->pdo);
        $identity = new BindingIdentityDTO(new ScopeProfileRequestDTO(new SlugScope('read-permissions', null, null), new SlugProfileKey('ascii-v1')), new EntityReference('article', 'original'));
        $engine->assignExact(new AssignExactCommand($identity, 'owned', null, new AuditContextDTO()));
        return [$engine, $identity];
    }

    /** Builds the production facade without warming or mutating its guard. */
    private function engine(PDO $pdo): SlugEngine
    {
        return SlugEngineFactory::create($pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
    }

    /** Connects using the SELECT-only fixture provisioned by the canonical Compose lifecycle. */
    private function readOnlyConnection(): PDO
    {
        $values = [];
        foreach (['SLUG_TEST_DB_HOST', 'SLUG_TEST_DB_PORT', 'SLUG_TEST_DB_NAME', 'SLUG_TEST_DB_READER_USER', 'SLUG_TEST_DB_READER_PASSWORD'] as $name) {
            $value = getenv($name);
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('The reader fixture environment is incomplete: ' . $name);
            }
            $values[$name] = $value;
        }
        return new PDO('mysql:host=' . $values['SLUG_TEST_DB_HOST'] . ';port=' . $values['SLUG_TEST_DB_PORT'] . ';dbname=' . $values['SLUG_TEST_DB_NAME'] . ';charset=utf8mb4', $values['SLUG_TEST_DB_READER_USER'], $values['SLUG_TEST_DB_READER_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    }
}

/** Records prepared probe attempts, including expected duplicate-key failures. */
final class ReadProbeTraceStatement extends PDOStatement
{
    /** @param Closure(array<array-key, mixed>): void $trace */
    protected function __construct(private readonly Closure $trace) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        ($this->trace)($params ?? []);
        return parent::execute($params);
    }
}
