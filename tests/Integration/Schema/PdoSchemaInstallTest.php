<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use PDO;
use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaInstaller;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PHPUnit\Framework\Attributes\DataProvider;

final class PdoSchemaInstallTest extends MySqlIntegrationTestCase
{
    public function testCleanSchemaInstallUsesExactPackageTableSet(): void
    {
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();

        $statement = $this->pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maa_slug_operations' AND COLUMN_NAME = 'result_snapshot'");
        self::assertNotFalse($statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('utf8mb4_bin', $row['COLLATION_NAME']);

        $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_' AND (COLUMN_COMMENT IS NULL OR CHAR_LENGTH(TRIM(COLUMN_COMMENT)) < 8)");
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn(), 'Every package column must carry a meaningful schema comment.');

        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_' AND INDEX_NAME = 'PRIMARY' ORDER BY BINARY TABLE_NAME, SEQ_IN_INDEX",
        );
        self::assertNotFalse($statement);
        self::assertSame([
            ['maa_slug_bindings', 'id'],
            ['maa_slug_history', 'id'],
            ['maa_slug_operation_bindings', 'id'],
            ['maa_slug_operations', 'id'],
            ['maa_slug_registry', 'id'],
            ['maa_slug_registry_lock_keys', 'id'],
            ['maa_slug_scopes', 'id'],
        ], $statement->fetchAll(PDO::FETCH_NUM));
    }

    public function testSchemaInstallAndCleanupAreRepeatableTwice(): void
    {
        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $this->reinstallPackageSchema();
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();

            $this->dropSchemaForUpgradeProof();
            $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_'");
            self::assertNotFalse($statement);
            self::assertSame('0', (string) $statement->fetchColumn(), 'cleanup iteration ' . $iteration);
        }

        $this->reinstallPackageSchema();
    }

    public function testRegistryLockProjectionBackfillsPublishedSchemaAndCanBeRetried(): void
    {
        $this->dropSchemaForUpgradeProof();
        $installer = new PdoSchemaInstaller($this->pdo);
        $installer->installSql($this->readSchemaAsset('001_slug_rc1.sql'));
        $installer->installSql($this->readSchemaAsset('002_operational_reporting_indexes.sql'));
        $this->seedPublishedRegistry();
        $before = $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id');
        self::assertCount(3, $before);

        $projection = $this->readSchemaAsset('003_registry_lock_projection.sql');
        $installer->installSql($projection);
        $keys = $this->readRows('SELECT scope_id, scope_end, binding_partition, key_kind, slug, registry_id, owner_binding_id FROM maa_slug_registry_lock_keys ORDER BY scope_id, scope_end, binding_partition, key_kind, slug');
        self::assertSame([
            [0, 0, 0, 2, '', null, null],
            [11, 0, 0, 0, '', null, null],
            [11, 0, 0, 1, 'current-origin', 1001, 101],
            [11, 0, 0, 1, 'other-current', 1003, 102],
            [11, 0, 0, 1, 'retained-alias', 1002, 101],
            [11, 0, 0, 2, '', null, null],
            [11, 0, 101, 0, '', null, null],
            [11, 0, 101, 1, 'current-origin', 1001, 101],
            [11, 0, 101, 1, 'retained-alias', 1002, 101],
            [11, 0, 101, 2, '', null, null],
            [11, 0, 102, 0, '', null, null],
            [11, 0, 102, 1, 'other-current', 1003, 102],
            [11, 0, 102, 2, '', null, null],
            [11, 1, 0, 2, '', null, null],
            [12, 0, 0, 0, '', null, null],
            [12, 0, 0, 2, '', null, null],
            [12, 1, 0, 2, '', null, null],
        ], $keys);
        self::assertSame($before, $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id'));

        $installer->installSql($projection);
        self::assertSame($keys, $this->readRows('SELECT scope_id, scope_end, binding_partition, key_kind, slug, registry_id, owner_binding_id FROM maa_slug_registry_lock_keys ORDER BY scope_id, scope_end, binding_partition, key_kind, slug'));
        self::assertSame($before, $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id'));
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();

        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('upgrade-with-claims', null, null), new SlugProfileKey('ascii-v1'));
        $binding = new BindingIdentityDTO($scope, new EntityReference('article', 'primary'));
        $result = $engine->changeExact(new ChangeExactCommand($binding, 'post-upgrade', 1, new AuditContextDTO()));
        self::assertSame('post-upgrade', $result->after->currentClaim?->slug->value);
        $resolved = $engine->resolve(new ResolutionCriteria($scope, 'retained-alias'));
        self::assertSame(MatchKindEnum::ALIAS, $resolved->matchKind);
        self::assertSame('post-upgrade', $resolved->currentSlug?->value);
        self::assertSame([[0, 101], [101, 101]], $this->readRows("SELECT binding_partition, owner_binding_id FROM maa_slug_registry_lock_keys WHERE slug = 'post-upgrade' ORDER BY binding_partition"));
    }

    #[DataProvider('invalidRuntimeProjections')]
    public function testFacadeRejectsIncompatibleSchemaBeforeMutation(?string $projectionChange, string $reason): void
    {
        $this->dropSchemaForUpgradeProof();
        $installer = new PdoSchemaInstaller($this->pdo);
        $installer->installSql($this->readSchemaAsset('001_slug_rc1.sql'));
        $installer->installSql($this->readSchemaAsset('002_operational_reporting_indexes.sql'));
        $this->seedPublishedRegistry();
        if ($projectionChange !== null) {
            $installer->installSql($this->readSchemaAsset('003_registry_lock_projection.sql'));
            $this->pdo->exec($projectionChange);
        }

        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('upgrade-with-claims', null, null), new SlugProfileKey('ascii-v1'));
        $binding = new BindingIdentityDTO($scope, new EntityReference('article', 'primary'));
        $criteria = new ResolutionCriteria($scope, 'retained-alias');
        $before = $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id');
        try {
            $engine->resolve($criteria);
            self::fail('The public read accepted an incomplete Schema 003.');
        } catch (SlugRuntimeCompatibilityException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }

        $this->pdo->beginTransaction();
        try {
            // The caller's own write must survive the rejected package call.
            $this->pdo->exec('UPDATE maa_slug_bindings SET updated_at = created_at + INTERVAL 1 DAY WHERE id = 102');
            try {
                $engine->changeExact(new ChangeExactCommand($binding, 'must-not-persist', 1, new AuditContextDTO(idempotencyKey: 'upgrade-test')));
                self::fail('The public mutation accepted an incomplete Schema 003.');
            } catch (SlugRuntimeCompatibilityException $exception) {
                self::assertStringContainsString($reason, $exception->getMessage());
            }
            self::assertTrue($this->pdo->inTransaction());
            $observer = $this->newTestConnection();
            $lock = $observer->query('SELECT id FROM maa_slug_scopes WHERE id = 11 FOR UPDATE NOWAIT');
            self::assertNotFalse($lock);
            self::assertSame(11, $lock->fetchColumn());
            self::assertSame($before, $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id'));
            self::assertSame([[1]], $this->readRows('SELECT revision FROM maa_slug_bindings WHERE id = 101'));
            self::assertSame([], $this->readRows('SELECT id FROM maa_slug_history'));
            self::assertSame([], $this->readRows('SELECT id FROM maa_slug_operations'));
            self::assertSame([[1]], $this->readRows('SELECT DATEDIFF(updated_at, created_at) FROM maa_slug_bindings WHERE id = 102'));
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        self::assertSame([[0]], $this->readRows('SELECT DATEDIFF(updated_at, created_at) FROM maa_slug_bindings WHERE id = 102'));
        self::assertSame($before, $this->readRows('SELECT * FROM maa_slug_registry ORDER BY id'));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidRuntimeProjections(): iterable
    {
        yield 'RC.2 schema without migration' => [null, 'installed package table set is not exact'];
        yield '003 table without completion marker' => [
            'DELETE FROM maa_slug_registry_lock_keys WHERE scope_id = 0',
            'Registry lock projection is unavailable or incompatible',
        ];
        yield 'projection slug with an incompatible collation' => [
            'ALTER TABLE maa_slug_registry_lock_keys MODIFY slug VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
            'Registry lock projection is unavailable or incompatible',
        ];
    }

    #[DataProvider('incompleteProjectionSchemas')]
    public function testIncompleteProjectionIsRejectedByVerifierAndRuntime(string $change, string $reason): void
    {
        $this->pdo->exec($change);

        try {
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();
            self::fail('Schema verification accepted an incomplete Registry lock projection.');
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }

        $this->expectException(SlugRuntimeCompatibilityException::class);
        $this->expectExceptionMessage('The Registry lock projection is unavailable or incompatible.');
        (new PdoCapabilityGuard($this->pdo))->assertInstalledSchemaSupported();
    }

    public function testUnusableProjectionIndexFailsBeforeCapabilityProbe(): void
    {
        $server = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $attribute = is_string($server) && str_contains($server, 'MariaDB') ? 'IGNORED' : 'INVISIBLE';
        $this->pdo->exec('ALTER TABLE maa_slug_registry_lock_keys ALTER INDEX uk_registry_lock_partition ' . $attribute);

        $this->expectException(SlugRuntimeCompatibilityException::class);
        $this->expectExceptionMessage('The Registry lock projection is unavailable or incompatible.');
        (new PdoCapabilityGuard($this->pdo))->assertInstalledSchemaSupported();
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompleteProjectionSchemas(): iterable
    {
        yield 'out-of-order columns' => [
            'ALTER TABLE maa_slug_registry_lock_keys DROP INDEX uk_registry_lock_partition, ADD UNIQUE INDEX uk_registry_lock_partition (scope_id, binding_partition, scope_end, key_kind, slug)',
            'ordered unique partition index',
        ];
        yield 'prefix instead of full slug' => [
            'ALTER TABLE maa_slug_registry_lock_keys DROP INDEX uk_registry_lock_partition, ADD UNIQUE INDEX uk_registry_lock_partition (scope_id, scope_end, binding_partition, key_kind, slug(30))',
            'full ascending unique keys',
        ];
        yield 'id without automatic allocation' => [
            'ALTER TABLE maa_slug_registry_lock_keys MODIFY id BIGINT UNSIGNED NOT NULL',
            'requires an auto-increment unsigned bigint identity',
        ];
        yield 'table installed but backfill incomplete' => [
            'DELETE FROM maa_slug_registry_lock_keys WHERE scope_id = 0',
            'backfill has not been completed',
        ];
    }

    public function testMissingOperationalIndexFailsVerification(): void
    {
        $this->pdo->exec('ALTER TABLE maa_slug_history DROP INDEX ix_history_occurred_id');

        $this->expectException(\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException::class);
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
    }

    public function testEachRequiredOperationalIndexIsIndividuallyVerified(): void
    {
        $indexes = [
            ['maa_slug_bindings', 'ix_binding_scope_status_id', '(scope_id, status, id)'],
            ['maa_slug_history', 'ix_history_occurred_id', '(occurred_at, id)'],
            ['maa_slug_history', 'ix_history_scope_occurred_id', '(scope_namespace_snapshot, scope_locale_snapshot, scope_context_snapshot, occurred_at, id)'],
        ];
        foreach ($indexes as [$table, $index, $definition]) {
            $this->pdo->exec(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $index));
            try {
                (new PdoSchemaVerifier($this->pdo))->assertInstalled();
                self::fail(sprintf('Verifier accepted missing index %s.%s.', $table, $index));
            } catch (\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException) {
                self::addToAssertionCount(1);
            }
            $this->pdo->exec(sprintf('ALTER TABLE %s ADD INDEX %s %s', $table, $index, $definition));
        }
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
    }

    public function testPersistedExampleRejectsNonDisposableDatabaseName(): void
    {
        [$status, $output] = $this->runPersistedExample(dirname(__DIR__, 3), ['SLUG_TEST_DB_NAME' => 'slug_example_non_disposable']);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('must identify a disposable database ending in _test', $output);
    }

    public function testPersistedExampleDoesNotTouchExistingPackageTableOrRow(): void
    {
        $this->dropSchemaForUpgradeProof();
        $this->pdo->exec('CREATE TABLE maa_slug_registry_lock_keys (marker VARCHAR(32) NOT NULL)');
        $this->pdo->exec("INSERT INTO maa_slug_registry_lock_keys (marker) VALUES ('keep-this-row')");

        [$status, $output] = $this->runPersistedExample(dirname(__DIR__, 3));

        self::assertNotSame(0, $status);
        self::assertSame(1, $this->examplePackageTableCount(), 'The existing package table must survive the example refusal.');
        $sentinel = $this->pdo->query('SELECT marker FROM maa_slug_registry_lock_keys');
        self::assertNotFalse($sentinel);
        self::assertSame('keep-this-row', $sentinel->fetchColumn());
        self::assertStringContainsString('must have no pre-existing Slug tables or views', $output);
    }

    public function testPersistedExampleCompletesOnCleanDatabaseAndRemovesItsTables(): void
    {
        $this->dropSchemaForUpgradeProof();
        [$status, $output] = $this->runPersistedExample(dirname(__DIR__, 3));

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('PERSISTED_LIFECYCLE_EXAMPLE=hello-world SCOPE_REPORTING=PASS HISTORY_WINDOW=PASS', $output);
        self::assertSame(0, $this->examplePackageTableCount());
    }

    public function testPersistedExampleCleansUpPartialInstallAndReportsOriginalFailure(): void
    {
        $this->dropSchemaForUpgradeProof();
        $root = dirname(__DIR__, 3);
        $fixture = sys_get_temp_dir() . '/slug-persisted-example-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($fixture, 0700));
        $example = '/examples/persisted-lifecycle.php';
        $baseAsset = '/schema/mysql/001_slug_rc1.sql';
        $additiveAsset = '/schema/mysql/002_operational_reporting_indexes.sql';
        $projectionAsset = '/schema/mysql/003_registry_lock_projection.sql';
        try {
            self::assertTrue(mkdir($fixture . '/examples', 0700));
            self::assertTrue(mkdir($fixture . '/schema/mysql', 0700, true));
            self::assertTrue(symlink($root . '/vendor', $fixture . '/vendor'));
            self::assertTrue(copy($root . $example, $fixture . $example));
            self::assertTrue(copy($root . $additiveAsset, $fixture . $additiveAsset));
            self::assertTrue(copy($root . $projectionAsset, $fixture . $projectionAsset));
            $incompleteInstall = <<<'SQL'
CREATE TABLE maa_slug_scopes (id INT NOT NULL PRIMARY KEY);
ALTER TABLE maa_slug_scopes ADD INDEX ix_example_duplicate (id);
ALTER TABLE maa_slug_scopes ADD INDEX ix_example_duplicate (id);
SQL;
            self::assertNotFalse(file_put_contents($fixture . $baseAsset, $incompleteInstall));

            [$status, $output] = $this->runPersistedExample($fixture);

            self::assertNotSame(0, $status);
            self::assertStringContainsString("Duplicate key name 'ix_example_duplicate'", $output);
            self::assertStringNotContainsString('PERSISTED_LIFECYCLE_EXAMPLE=hello-world', $output);
            self::assertSame(0, $this->examplePackageTableCount());
        } finally {
            foreach ([$example, $baseAsset, $additiveAsset, $projectionAsset, '/vendor'] as $path) {
                if (is_file($fixture . $path) || is_link($fixture . $path)) {
                    unlink($fixture . $path);
                }
            }
            foreach (['/schema/mysql', '/schema', '/examples', ''] as $path) {
                if (is_dir($fixture . $path)) {
                    rmdir($fixture . $path);
                }
            }
        }
    }

    /**
     * Executes the public example in a fresh PHP process and captures its result.
     *
     * @param array<string, string> $overrides
     * @return array{int, string}
     */
    private function runPersistedExample(string $root, array $overrides = []): array
    {
        $outputStream = tmpfile();
        self::assertIsResource($outputStream);
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $root . '/examples/persisted-lifecycle.php'],
                [1 => $outputStream, 2 => $outputStream],
                $pipes,
                $root,
                array_replace(getenv(), $overrides, ['XDEBUG_MODE' => 'off']),
            );
            self::assertIsResource($process);
            $status = proc_close($process);
            self::assertTrue(rewind($outputStream));
            $output = stream_get_contents($outputStream);
            self::assertIsString($output);

            return [$status, $output];
        } finally {
            fclose($outputStream);
        }
    }

    /** Counts fixture package tables in the disposable test database. */
    private function examplePackageTableCount(): int
    {
        $result = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(CAST(TABLE_NAME AS BINARY), 9) = 'maa_slug_'");
        self::assertNotFalse($result);
        $count = $result->fetchColumn();
        self::assertIsInt($count);

        return $count;
    }

    private function dropSchemaForUpgradeProof(): void
    {
        foreach (['maa_slug_history', 'maa_slug_registry_lock_keys', 'maa_slug_registry', 'maa_slug_operation_bindings', 'maa_slug_operations', 'maa_slug_bindings', 'maa_slug_scopes'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private function readSchemaAsset(string $asset): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/schema/mysql/' . $asset);
        self::assertIsString($sql);

        return $sql;
    }

    /** @return list<list<mixed>> */
    private function readRows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return array_map(static function (mixed $row): array {
            self::assertIsArray($row);

            return array_values($row);
        }, array_values($statement->fetchAll(PDO::FETCH_NUM)));
    }

    private function seedPublishedRegistry(): void
    {
        $this->pdo->exec(
            <<<'SQL'
INSERT INTO maa_slug_scopes (id, namespace, locale_key, context_key, profile_key, created_at, updated_at)
VALUES (11, 'upgrade-with-claims', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456'),
       (12, 'upgrade-empty-scope', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')
SQL,
        );
        $this->pdo->exec(
            <<<'SQL'
INSERT INTO maa_slug_bindings (id, scope_id, entity_type, entity_key, current_registry_id, status, revision, created_at, updated_at)
VALUES (101, 11, 'article', 'primary', 1001, 'ACTIVE', 1, '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456'),
       (102, 11, 'article', 'other', 1003, 'ACTIVE', 1, '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')
SQL,
        );
        $this->pdo->exec(
            <<<'SQL'
INSERT INTO maa_slug_registry (id, scope_id, binding_id, slug, claim_role, claimed_at, updated_at)
VALUES (1001, 11, 101, 'current-origin', 'CURRENT_CANONICAL', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456'),
       (1002, 11, 101, 'retained-alias', 'ACTIVE_ALIAS', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456'),
       (1003, 11, 102, 'other-current', 'CURRENT_CANONICAL', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')
SQL,
        );
    }

}
