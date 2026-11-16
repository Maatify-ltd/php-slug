<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Replay;

use PDO;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PHPUnit\Framework\Attributes\DataProvider;

final class SlugHostSnapshotReplayTest extends MySqlIntegrationTestCase
{
    /** @param 'mutation'|'transfer'|'adoption'|'transition' $path */
    #[DataProvider('operations')]
    public function testReplayUsesCommittedOperationAfterHostSnapshot(string $path): void
    {
        $writer = $this->engine($this->pdo);
        $hostPdo = $this->newTestConnection();
        $host = $this->engine($hostPdo);
        $source = new BindingIdentityDTO($this->scope('replay-source'), new EntityReference('item', 'source'));
        $target = new BindingIdentityDTO($source->scopeProfile, new EntityReference('item', 'target'));
        $audit = new AuditContextDTO(idempotencyKey: 'stale-snapshot-replay');
        $slug = 'operation-slug';
        if ($path !== 'mutation') {
            $writer->assignExact(new AssignExactCommand($source, 'seed-source', null, new AuditContextDTO()));
        }
        if ($path === 'transfer') {
            $writer->addAlias(new AddAliasCommand($source, $slug, 1, new AuditContextDTO()));
            $writer->assignExact(new AssignExactCommand($target, 'seed-target', null, new AuditContextDTO()));
        }
        $transitionTarget = $this->scope('replay-target');
        $execute = match ($path) {
            'mutation' => static fn(SlugEngine $engine, bool $changed) => $engine->assignExact(new AssignExactCommand($source, $changed ? 'changed-request' : $slug, null, $audit)),
            'transfer' => static fn(SlugEngine $engine, bool $changed) => $engine->atomicTransfer(new AtomicTransferCommand($source, $target, $slug, null, $changed ? 3 : 2, 1, $audit)),
            'adoption' => static fn(SlugEngine $engine, bool $changed) => $engine->adoptAlias(new AdoptAliasCommand($source, $changed ? 'changed-request' : $slug, null, 1, $audit)),
            'transition' => static fn(SlugEngine $engine, bool $changed) => $engine->transitionScope(new TransitionScopeCommand(
                $source,
                $transitionTarget,
                ScopeTransitionModeEnum::PARALLEL,
                new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, $changed ? 'changed-request' : $slug),
                1,
                $audit,
            )),
        };

        $hostPdo->exec('CREATE TEMPORARY TABLE slug_replay_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $hostPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $hostPdo->beginTransaction();
        try {
            self::assertSame('0', $this->scalar($hostPdo, 'SELECT COUNT(*) FROM maa_slug_operations'));
            $hostPdo->exec('INSERT INTO slug_replay_marker VALUES (1)');

            $original = $execute($writer, false);
            self::assertFalse($original->replayed);
            $revision = match ($path) {
                'mutation', 'transition' => 1,
                'adoption' => 2,
                'transfer' => 3,
            };
            $writer->changeExact(new ChangeExactCommand($source, 'later-slug', $revision, new AuditContextDTO()));
            self::assertSame('0', $this->scalar($hostPdo, 'SELECT COUNT(*) FROM maa_slug_operations'));

            $replay = $execute($host, false);
            self::assertTrue($replay->replayed);
            self::assertSame(json_encode($original->withReplayed(true), JSON_THROW_ON_ERROR), json_encode($replay, JSON_THROW_ON_ERROR));
            try {
                $execute($host, true);
                self::fail('A different request must not replay with an existing idempotency key.');
            } catch (SlugIdempotencyConflictException) {
                self::assertTrue($hostPdo->inTransaction());
            }
            self::assertSame('REPEATABLE-READ', $this->scalar($hostPdo, 'SELECT @@SESSION.transaction_isolation'));
            self::assertSame('1', $this->scalar($hostPdo, 'SELECT COUNT(*) FROM slug_replay_marker'));
        } finally {
            if ($hostPdo->inTransaction()) {
                $hostPdo->rollBack();
            }
        }

        self::assertSame('0', $this->scalar($hostPdo, 'SELECT COUNT(*) FROM slug_replay_marker'));
        self::assertSame('1', $this->scalar($this->pdo, 'SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame('later-slug', $writer->getCurrent(new CurrentSlugCriteria($source))?->claim->slug->value);
    }

    /** @return iterable<string, array{'mutation'|'transfer'|'adoption'|'transition'}> */
    public static function operations(): iterable
    {
        foreach (['mutation', 'transfer', 'adoption', 'transition'] as $path) {
            yield $path => [$path];
        }
    }

    private function scope(string $namespace): ScopeProfileRequestDTO
    {
        return new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1'));
    }

    private function engine(PDO $pdo): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());

        return SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertTrue(is_int($value) || is_string($value));

        return (string) $value;
    }
}
