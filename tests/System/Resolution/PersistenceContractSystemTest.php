<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises the persisted contract from the public facade with deliberately incompatible fixtures. */
final class PersistenceContractSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('cacheStates')]
    public function testClientCharsetIsRejectedBeforeWritingAndHostCanRollBack(bool $warm): void
    {
        $engine = $this->engine();
        $identity = $this->identity('café');
        if ($warm) {
            $engine->ensureScope($identity->scopeProfile);
        }
        $this->beginHostWork();
        try {
            $this->pdo->exec('SET SESSION character_set_client = latin1');
            try {
                $engine->assignExact(new AssignExactCommand($identity, 'hello-entity', null, new AuditContextDTO()));
                self::fail('An incompatible client charset was admitted.');
            } catch (SlugRuntimeCompatibilityException $exception) {
                self::assertStringContainsString('character_set_client', $exception->getMessage());
                self::assertSame('latin1', $this->scalar('SELECT @@SESSION.character_set_client'));
                $this->assertHostWorkIsIntact();
                self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM maa_slug_bindings'));
            }
        } finally {
            $this->pdo->exec('SET SESSION character_set_client = utf8mb4');
            $this->pdo->rollBack();
        }
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM contract_host_marker'));
        $created = $engine->assignExact(new AssignExactCommand($identity, 'hello-entity', null, new AuditContextDTO()));
        self::assertSame('café', $created->after->identity->entity->entityKey);
        self::assertSame('hello-entity', $engine->getCurrent(new CurrentSlugCriteria($identity))?->claim->slug->value);
    }

    #[DataProvider('cacheStates')]
    public function testResultsCharsetIsRejectedOnReadWithoutChangingHostSession(bool $warm): void
    {
        $identity = $this->identity('reader');
        $criteria = new CurrentSlugCriteria($identity);
        $this->engine()->assignExact(new AssignExactCommand($identity, 'مرحبا', null, new AuditContextDTO()));
        $reader = $this->engine();
        if ($warm) {
            self::assertSame('مرحبا', $reader->getCurrent($criteria)?->claim->slug->value);
        }
        $this->beginHostWork();
        try {
            $this->pdo->exec('SET SESSION character_set_results = latin1');
            try {
                $reader->getCurrent($criteria);
                self::fail('An incompatible results charset was admitted.');
            } catch (SlugRuntimeCompatibilityException $exception) {
                self::assertStringContainsString('character_set_results', $exception->getMessage());
                self::assertSame('latin1', $this->scalar('SELECT @@SESSION.character_set_results'));
                $this->assertHostWorkIsIntact();
            }
            $this->pdo->exec('SET SESSION character_set_results = NULL');
            self::assertSame('مرحبا', $reader->getCurrent($criteria)?->claim->slug->value);
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM contract_host_marker'));
    }

    /** @return iterable<string, array{bool}> */
    public static function cacheStates(): iterable
    {
        yield 'cold' => [false];
        yield 'warm' => [true];
    }

    #[DataProvider('cacheStates')]
    public function testDisabledAutocommitRequiresAnActiveHostTransaction(bool $warm): void
    {
        $engine = $this->engine();
        $identity = $this->identity('autocommit');
        $command = new AssignExactCommand($identity, 'hello', null, new AuditContextDTO());
        if ($warm) {
            $engine->ensureScope($identity->scopeProfile);
        }
        $this->pdo->exec('CREATE TEMPORARY TABLE contract_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('SET SESSION autocommit = 0');
        try {
            self::assertFalse($this->pdo->inTransaction());
            try {
                $engine->assignExact($command);
                self::fail('The mutation ran with autocommit disabled and no active Host transaction.');
            } catch (SlugRuntimeCompatibilityException $exception) {
                self::assertStringContainsString('autocommit', $exception->getMessage());
                self::assertFalse($this->pdo->inTransaction());
            }
            self::assertTrue($this->pdo->beginTransaction());
            $this->pdo->exec('INSERT INTO contract_host_marker VALUES (1)');
            self::assertSame('hello', $engine->assignExact($command)->currentSlug?->value);
            $this->assertHostWorkIsIntact();
            $this->pdo->rollBack();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET SESSION autocommit = 1');
        }
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM contract_host_marker'));
        self::assertNull($engine->getCurrent(new CurrentSlugCriteria($identity)));
    }

    #[DataProvider('nonNaturalCaseModes')]
    public function testNonNaturalColumnCaseFailsWithAnActionableCompatibilityError(int $mode): void
    {
        $this->pdo->setAttribute(PDO::ATTR_CASE, $mode);
        $this->expectException(SlugRuntimeCompatibilityException::class);
        $this->expectExceptionMessage('PDO::CASE_NATURAL');
        $this->engine()->getCurrent(new CurrentSlugCriteria($this->identity('reader')));
    }

    /** @return iterable<string, array{int}> */
    public static function nonNaturalCaseModes(): iterable
    {
        yield 'lower' => [PDO::CASE_LOWER];
        yield 'upper' => [PDO::CASE_UPPER];
    }

    #[DataProvider('databaseSwitchPaths')]
    public function testWarmEngineChecksTheSelectedDatabaseWithoutTakingOverHostWork(bool $write): void
    {
        $engine = $this->engine();
        $identity = $this->identity('existing');
        $criteria = new CurrentSlugCriteria($identity);
        $engine->assignExact(new AssignExactCommand($identity, 'hello', null, new AuditContextDTO()));
        $original = $this->scalar('SELECT DATABASE()');
        $this->beginHostWork();
        try {
            $this->pdo->exec('USE information_schema');
            try {
                if ($write) {
                    $engine->ensureScope($identity->scopeProfile);
                } else {
                    $engine->getCurrent($criteria);
                }
                self::fail('The engine reused schema verification from a different database.');
            } catch (SlugRuntimeCompatibilityException $exception) {
                self::assertStringContainsString('table set', $exception->getMessage());
                self::assertSame('information_schema', $this->scalar('SELECT DATABASE()'));
            } finally {
                $this->pdo->exec(sprintf('USE `%s`', str_replace('`', '``', $original)));
            }
            $this->assertHostWorkIsIntact();
            self::assertSame('hello', $engine->getCurrent($criteria)?->claim->slug->value);
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM contract_host_marker'));
    }

    /** @return iterable<string, array{bool}> */
    public static function databaseSwitchPaths(): iterable
    {
        yield 'read' => [false];
        yield 'write' => [true];
    }

    /** @param list<string> $ddl */
    #[DataProvider('schemaChanges')]
    public function testSchemaVerifierAndFirstWriteRejectPhysicalDrift(array $ddl, string $message): void
    {
        foreach ($ddl as $statement) {
            $this->pdo->exec($statement);
        }
        try {
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();
            self::fail('Schema verification admitted an incompatible definition.');
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        $this->beginHostWork();
        try {
            $this->engine()->assignExact(new AssignExactCommand($this->identity('writer'), 'hello', null, new AuditContextDTO(idempotencyKey: 'physical-drift')));
            self::fail('The first write admitted an incompatible schema.');
        } catch (SlugRuntimeCompatibilityException $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(SlugPersistenceInvariantException::class, $cause);
            self::assertStringContainsString($message, $cause->getMessage());
            $this->assertHostWorkIsIntact();
            self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM maa_slug_bindings'));
        } finally {
            $this->pdo->rollBack();
        }
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function schemaChanges(): iterable
    {
        yield 'non-unique Registry index' => [
            ['ALTER TABLE maa_slug_registry DROP INDEX uk_registry_scope_slug, ADD INDEX uk_registry_scope_slug (scope_id, slug)'],
            'uk_registry_scope_slug',
        ];
        yield 'wrong Registry index column order' => [
            ['ALTER TABLE maa_slug_registry DROP INDEX uk_registry_scope_slug, ADD UNIQUE INDEX uk_registry_scope_slug (slug, scope_id)'],
            'uk_registry_scope_slug',
        ];
        $changeForeignKey = [
            'ALTER TABLE maa_slug_history DROP FOREIGN KEY fk_history_operation',
            'ALTER TABLE maa_slug_history ADD CONSTRAINT fk_history_operation FOREIGN KEY (operation_id) REFERENCES maa_slug_operations (id) ON DELETE CASCADE',
        ];
        yield 'foreign key with an incompatible delete rule' => [$changeForeignKey, 'fk_history_operation'];
        yield 'missing required result snapshot' => [
            ['ALTER TABLE maa_slug_operations DROP COLUMN result_snapshot'],
            'result_snapshot',
        ];
        yield 'unexpected column without an insert default' => [
            ["ALTER TABLE maa_slug_operations ADD COLUMN required_marker INT NOT NULL COMMENT 'Required fixture value without a default'"],
            'required_marker',
        ];
    }

    public function testExtraColumnsWithOptionalInsertValuesRemainCompatible(): void
    {
        $this->pdo->exec("ALTER TABLE maa_slug_operations ADD COLUMN optional_marker INT NULL COMMENT 'Optional fixture value'");
        $this->pdo->exec("ALTER TABLE maa_slug_operations ADD COLUMN defaulted_marker INT NOT NULL DEFAULT 7 COMMENT 'Defaulted fixture value'");
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
        $command = new AssignExactCommand($this->identity('compatible'), 'hello', null, new AuditContextDTO(idempotencyKey: 'extra-columns'));

        self::assertSame('hello', $this->engine()->assignExact($command)->currentSlug?->value);
        self::assertSame('7', $this->scalar('SELECT defaulted_marker FROM maa_slug_operations'));
    }

    public function testSearchBindingsRejectsCurrentClaimOwnedByAnotherBinding(): void
    {
        $engine = $this->engine();
        $first = $this->identity('one');
        $firstResult = $engine->assignExact(new AssignExactCommand($first, 'first-book', null, new AuditContextDTO()));
        $secondResult = $engine->assignExact(new AssignExactCommand($this->identity('two'), 'second-book', null, new AuditContextDTO()));
        self::assertNotNull($secondResult->after->currentClaim);
        $update = $this->pdo->prepare('UPDATE maa_slug_bindings SET current_registry_id = :claim WHERE id = :binding');
        $update->execute(['claim' => $secondResult->after->currentClaim->id, 'binding' => $firstResult->after->id]);
        $this->beginHostWork();
        try {
            $criteria = new BindingSearchCriteria($first->scopeProfile, new PageRequest(1, 20));
            $engine->searchBindings($criteria);
            self::fail('Management returned a foreign current claim.');
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertStringContainsString('current pointer', $exception->getMessage());
            $this->assertHostWorkIsIntact();
        } finally {
            $this->pdo->rollBack();
        }
    }

    #[DataProvider('invalidBindingStates')]
    public function testSearchBindingsRejectsInvalidRegistryState(string $change, string $message): void
    {
        $engine = $this->engine();
        $identity = $this->identity('search-owner');
        $binding = $engine->assignExact(new AssignExactCommand($identity, 'first-book', null, new AuditContextDTO()))->after;
        $criteria = new BindingSearchCriteria($identity->scopeProfile, new PageRequest(1, 20));
        self::assertCount(1, $engine->searchBindings($criteria)->data);
        $this->beginHostWork();
        try {
            $update = $this->pdo->prepare($change);
            $update->execute(['binding' => $binding->id]);
            try {
                $engine->searchBindings($criteria);
                self::fail('Management returned a Binding with inconsistent Registry state.');
            } catch (SlugPersistenceInvariantException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
                $this->assertHostWorkIsIntact();
            }
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame('first-book', $engine->searchBindings($criteria)->data[0]->currentClaim?->slug->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBindingStates(): iterable
    {
        yield 'released with live claims' => [
            "UPDATE maa_slug_bindings SET status = 'RELEASED', current_registry_id = NULL WHERE id = :binding",
            'Released Binding',
        ];
        yield 'active with two current claims' => [
            'INSERT INTO maa_slug_registry (scope_id, binding_id, slug, claim_role, claimed_at, updated_at) '
            . "SELECT scope_id, binding_id, 'second-book', 'CURRENT_CANONICAL', claimed_at, updated_at "
            . 'FROM maa_slug_registry WHERE binding_id = :binding',
            'current pointer',
        ];
    }

    #[DataProvider('inconsistentReplayFields')]
    public function testReplayRejectsAnInconsistentMutationSnapshot(string $field): void
    {
        $engine = $this->engine();
        $command = new AssignExactCommand($this->identity('replay-owner'), 'replay-book', null, new AuditContextDTO(idempotencyKey: 'binding-owner'));
        $normal = $engine->assignExact($command);
        self::assertTrue($engine->assignExact($command)->replayed);
        $read = $this->pdo->prepare('SELECT result_snapshot FROM maa_slug_operations WHERE operation_key = :operation');
        $read->execute(['operation' => $normal->operationKey]);
        $snapshotJson = $read->fetchColumn();
        self::assertIsString($snapshotJson);
        /** @var array{result: array{revision: int, current_slug: string, after: array{current_claim: array{binding: array{entity: array{entity_key: string}}}}}} $snapshot */
        $snapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        match ($field) {
            'owner' => $snapshot['result']['after']['current_claim']['binding']['entity']['entity_key'] = 'wrong-owner',
            'revision' => $snapshot['result']['revision'] += 10,
            'slug' => $snapshot['result']['current_slug'] = 'another-book',
            default => throw new \LogicException('Unknown replay fixture field.'),
        };
        $update = $this->pdo->prepare('UPDATE maa_slug_operations SET result_snapshot = :snapshot WHERE operation_key = :operation');
        $update->execute(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'operation' => $normal->operationKey]);

        $this->beginHostWork();
        try {
            $engine->assignExact($command);
            self::fail('Replay admitted an inconsistent mutation snapshot.');
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertStringContainsString('Invalid result snapshot', $exception->getMessage());
            $this->assertHostWorkIsIntact();
            self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM maa_slug_history'));
        } finally {
            $this->pdo->rollBack();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inconsistentReplayFields(): iterable
    {
        yield 'foreign current claim owner' => ['owner'];
        yield 'revision disagrees with the after state' => ['revision'];
        yield 'current slug disagrees with the after state' => ['slug'];
    }

    public function testReplayRejectsAnOtherwiseValidSnapshotFromAnotherParticipant(): void
    {
        $engine = $this->engine();
        $first = new AssignExactCommand($this->identity('first'), 'first-slug', null, new AuditContextDTO(idempotencyKey: 'first-operation'));
        $second = new AssignExactCommand($this->identity('second'), 'second-slug', null, new AuditContextDTO(idempotencyKey: 'second-operation'));
        $firstResult = $engine->assignExact($first);
        $secondResult = $engine->assignExact($second);
        self::assertNotNull($firstResult->operationKey);
        self::assertNotNull($secondResult->operationKey);
        $read = $this->pdo->prepare('SELECT result_snapshot FROM maa_slug_operations WHERE operation_key = :operation');
        $read->execute(['operation' => $secondResult->operationKey]);
        $snapshot = $read->fetchColumn();
        self::assertIsString($snapshot);
        $read->closeCursor();
        $update = $this->pdo->prepare('UPDATE maa_slug_operations SET result_snapshot = :snapshot WHERE operation_key = :operation');
        $update->execute([
            'snapshot' => str_replace($secondResult->operationKey, $firstResult->operationKey, $snapshot),
            'operation' => $firstResult->operationKey,
        ]);
        $this->beginHostWork();
        try {
            $engine->assignExact($first);
            self::fail('Replay returned a snapshot for another participant.');
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertStringContainsString('participants', $exception->getMessage());
            $this->assertHostWorkIsIntact();
            self::assertSame('2', $this->scalar('SELECT COUNT(*) FROM maa_slug_history'));
        } finally {
            $this->pdo->rollBack();
        }
    }

    /** Starts a Host transaction with pending work unrelated to the library. */
    private function beginHostWork(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE contract_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO contract_host_marker VALUES (1)');
    }

    /** Verifies that Slug did not finish the Host transaction or roll back its pending work. */
    private function assertHostWorkIsIntact(): void
    {
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM contract_host_marker'));
    }

    private function scalar(string $sql): string
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('contract', null, null), new SlugProfileKey('unicode-v1')),
            new EntityReference('record', $entityKey),
        );
    }

    private function engine(): SlugEngine
    {
        return SlugEngineFactory::create($this->pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
    }
}
