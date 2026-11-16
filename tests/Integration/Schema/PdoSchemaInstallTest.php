<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaInstaller;

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

        $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' AND (COLUMN_COMMENT IS NULL OR CHAR_LENGTH(TRIM(COLUMN_COMMENT)) < 8)");
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn(), 'Every package column must carry a meaningful schema comment.');
    }

    public function testSchemaInstallAndCleanupAreRepeatableTwice(): void
    {
        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $this->reinstallPackageSchema();
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();

            $this->pdo->exec('DROP TABLE maa_slug_history, maa_slug_registry, maa_slug_operation_bindings, maa_slug_operations, maa_slug_bindings, maa_slug_scopes');
            $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%'");
            self::assertNotFalse($statement);
            self::assertSame('0', (string) $statement->fetchColumn(), 'cleanup iteration ' . $iteration);
        }

        $this->reinstallPackageSchema();
    }

    public function testPublishedRc1ThenAdditiveOperationalAssetPassesVerification(): void
    {
        $this->dropSchemaForUpgradeProof();
        $base = file_get_contents(dirname(__DIR__, 3) . '/schema/mysql/001_slug_rc1.sql');
        $additive = file_get_contents(dirname(__DIR__, 3) . '/schema/mysql/002_operational_reporting_indexes.sql');
        self::assertIsString($base);
        self::assertIsString($additive);

        $installer = new PdoSchemaInstaller($this->pdo);
        $installer->installSql($base);
        $installer->installSql($additive);
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
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
        $this->pdo->exec('CREATE TABLE maa_slug_registry (marker VARCHAR(32) NOT NULL)');
        $this->pdo->exec("INSERT INTO maa_slug_registry (marker) VALUES ('keep-this-row')");

        [$status, $output] = $this->runPersistedExample(dirname(__DIR__, 3));

        self::assertNotSame(0, $status);
        self::assertSame(1, $this->examplePackageTableCount(), 'The existing package table must survive the example refusal.');
        $sentinel = $this->pdo->query('SELECT marker FROM maa_slug_registry');
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
        try {
            self::assertTrue(mkdir($fixture . '/examples', 0700));
            self::assertTrue(mkdir($fixture . '/schema/mysql', 0700, true));
            self::assertTrue(symlink($root . '/vendor', $fixture . '/vendor'));
            self::assertTrue(copy($root . $example, $fixture . $example));
            self::assertTrue(copy($root . $additiveAsset, $fixture . $additiveAsset));
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
            foreach ([$example, $baseAsset, $additiveAsset, '/vendor'] as $path) {
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
        $result = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 9) = 'maa_slug_'");
        self::assertNotFalse($result);
        $count = $result->fetchColumn();
        self::assertIsInt($count);

        return $count;
    }

    private function dropSchemaForUpgradeProof(): void
    {
        foreach (['maa_slug_history', 'maa_slug_registry', 'maa_slug_operation_bindings', 'maa_slug_operations', 'maa_slug_bindings', 'maa_slug_scopes'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

}
