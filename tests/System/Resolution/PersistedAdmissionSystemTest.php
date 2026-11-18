<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateAliasCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseClaimCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises PDO admission and missing-target behavior through the public facade on both engines. */
final class PersistedAdmissionSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('missingBindingCases')]
    public function testAbsentTargetsAreNotFoundAndLeaveHostWorkIntact(string $operation, bool $scopeExists): void
    {
        $engine = $this->engine();
        $identity = $this->identity();
        $target = new BindingIdentityDTO($identity->scopeProfile, new EntityReference('record', 'target'));
        $audit = new AuditContextDTO();
        if ($scopeExists) {
            $engine->ensureScope($identity->scopeProfile);
        }

        $this->pdo->exec('CREATE TEMPORARY TABLE admission_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('INSERT INTO admission_host_marker VALUES (1)');
            try {
                match ($operation) {
                    'changeExact' => $engine->changeExact(new ChangeExactCommand($identity, 'candidate', 0, $audit)),
                    'changeGenerated' => $engine->changeGenerated(new ChangeGeneratedCommand($identity, 'candidate', 0, $audit)),
                    'restoreHistorical' => $engine->restoreHistorical(new RestoreHistoricalCommand($identity, 'candidate', 0, $audit)),
                    'releaseClaim' => $engine->releaseClaim(new ReleaseClaimCommand($identity, 'candidate', 0, $audit)),
                    'addAlias' => $engine->addAlias(new AddAliasCommand($identity, 'candidate', 0, $audit)),
                    'retireAlias' => $engine->retireAlias(new RetireAliasCommand($identity, 'candidate', 0, $audit)),
                    'reactivateAlias' => $engine->reactivateAlias(new ReactivateAliasCommand($identity, 'candidate', 0, $audit)),
                    'promoteAliasToCurrent' => $engine->promoteAliasToCurrent(new PromoteAliasToCurrentCommand($identity, 'candidate', 0, $audit)),
                    'atomicTransfer' => $engine->atomicTransfer(new AtomicTransferCommand($identity, $target, 'candidate', null, 0, 0, $audit)),
                    default => throw new \LogicException('Unknown admission test operation.'),
                };
                self::fail('The absent target was accepted.');
            } catch (SlugNotFoundException) {
                self::assertTrue($this->pdo->inTransaction());
                self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM admission_host_marker'));
                self::assertSame((int) $scopeExists, $this->scalar('SELECT COUNT(*) FROM maa_slug_scopes'));
                self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM maa_slug_bindings'));
                self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM maa_slug_operations'));
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM admission_host_marker'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function missingBindingCases(): iterable
    {
        foreach (['changeExact', 'changeGenerated', 'restoreHistorical', 'releaseClaim', 'addAlias', 'retireAlias', 'reactivateAlias', 'promoteAliasToCurrent', 'atomicTransfer'] as $operation) {
            yield $operation . ' without Scope' => [$operation, false];
            yield $operation . ' without Binding' => [$operation, true];
        }
    }

    #[DataProvider('invalidReadPrerequisites')]
    public function testResolveRejectsIncompatiblePersistedReadPrerequisites(string $prerequisite): void
    {
        $identity = $this->identity();
        $this->engine()->assignExact(new AssignExactCommand($identity, 'candidate', null, new AuditContextDTO()));
        $reader = $this->newTestConnection();
        match ($prerequisite) {
            'native prepares' => $reader->setAttribute(PDO::ATTR_EMULATE_PREPARES, true),
            'projection table' => $this->pdo->exec('DROP TABLE maa_slug_registry_lock_keys'),
            'projection marker' => $this->pdo->exec('DELETE FROM maa_slug_registry_lock_keys WHERE scope_id = 0'),
            default => throw new \LogicException('Unknown admission prerequisite.'),
        };

        $reader->exec('SET TRANSACTION READ ONLY');
        $reader->beginTransaction();
        $this->expectException(SlugRuntimeCompatibilityException::class);
        try {
            $this->engine($reader)->resolve(new ResolutionCriteria($identity->scopeProfile, 'candidate'));
        } finally {
            self::assertTrue($reader->inTransaction());
            $reader->rollBack();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReadPrerequisites(): iterable
    {
        foreach (['native prepares', 'projection table', 'projection marker'] as $prerequisite) {
            yield $prerequisite => [$prerequisite];
        }
    }

    #[DataProvider('unavailableConstraints')]
    public function testMutationRejectsUnavailableConstraintEnforcement(string $constraint): void
    {
        $identity = $this->identity();
        $engine = $this->engine();
        if ($constraint === 'warm session') {
            $engine->ensureScope($identity->scopeProfile);
        }
        match ($constraint) {
            'scope uniqueness' => $this->pdo->exec('ALTER TABLE maa_slug_scopes DROP INDEX uk_scope_identity'),
            'binding foreign key' => $this->pdo->exec('ALTER TABLE maa_slug_bindings DROP FOREIGN KEY fk_binding_scope'),
            'cold session', 'warm session' => $this->pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0'),
            default => throw new \LogicException('Unknown constraint fixture.'),
        };

        $this->pdo->exec('CREATE TEMPORARY TABLE admission_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO admission_host_marker VALUES (1)');
        try {
            $engine->assignExact(new AssignExactCommand($identity, 'candidate', null, new AuditContextDTO()));
            self::fail('The mutation accepted missing constraint enforcement.');
        } catch (SlugRuntimeCompatibilityException) {
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM admission_host_marker'));
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM maa_slug_bindings'));
            self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM maa_slug_scopes WHERE LEFT(namespace, 6) = '__cap_'"));
            if (in_array($constraint, ['cold session', 'warm session'], true)) {
                self::assertSame(0, $this->scalar('SELECT @@SESSION.foreign_key_checks'));
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=1');
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM admission_host_marker'));
        if (in_array($constraint, ['cold session', 'warm session'], true)) {
            self::assertSame('candidate', $engine->assignExact(new AssignExactCommand($identity, 'candidate', null, new AuditContextDTO()))->currentSlug?->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unavailableConstraints(): iterable
    {
        foreach (['scope uniqueness', 'binding foreign key', 'cold session', 'warm session'] as $constraint) {
            yield $constraint => [$constraint];
        }
    }

    #[DataProvider('metadataSqlModes')]
    public function testOnlyLiteralOwnedTablePrefixAffectsSchemaAdmission(string $sqlMode): void
    {
        $this->pdo->exec("SET SESSION sql_mode = '" . $sqlMode . "'");
        $this->pdo->exec('CREATE TABLE maaxslugxhost_owned (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();
            $identity = $this->identity();
            $this->engine()->assignExact(new AssignExactCommand($identity, 'candidate', null, new AuditContextDTO()));
            self::assertSame('candidate', $this->engine()->getCurrent(new CurrentSlugCriteria($identity))?->claim->slug->value);

            $this->pdo->exec('CREATE TABLE maa_slug_extra_fixture (id INT PRIMARY KEY) ENGINE=InnoDB');
            $this->expectException(SlugRuntimeCompatibilityException::class);
            $this->expectExceptionMessage('The installed package table set is not exact.');
            $this->engine()->getCurrent(new CurrentSlugCriteria($identity));
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS maa_slug_extra_fixture, maaxslugxhost_owned');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function metadataSqlModes(): iterable
    {
        yield 'backslash escapes' => ['STRICT_TRANS_TABLES'];
        yield 'no backslash escapes' => ['STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES'];
    }

    /** Creates a fresh facade so each test exercises its own admission cache. */
    private function engine(?PDO $pdo = null): SlugEngine
    {
        return SlugEngineFactory::create($pdo ?? $this->pdo, SlugProfileRegistryFactory::createBuiltIn(), new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('admission', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('record', 'one'),
        );
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        $statement->closeCursor();
        self::assertIsInt($value);

        return $value;
    }
}
