<?php

declare(strict_types=1);

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Example policy that leaves all slugs available. */
final class ExampleReservedSlugPolicy implements ReservedSlugPolicyInterface
{
    /** Returns false so the example can demonstrate a successful claim. */
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
}

/** Deterministic UTC clock used to make example persistence timestamps repeatable. */
final class ExampleUtcClock implements ClockInterface
{
    /** Returns the fixed instant used by this example. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns the timezone associated with the deterministic clock. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

$pdo = null;
$createdTables = [];
$failure = null;

try {
    $configuration = databaseConfiguration();
    $schemaDirectory = dirname(__DIR__) . '/schema/mysql';
    if (! is_file($schemaDirectory . '/001_slug_rc1.sql') || ! is_file($schemaDirectory . '/002_operational_reporting_indexes.sql')) {
        throw new RuntimeException('The ordered package schema assets are incomplete.');
    }

    $pdo = connectToDatabase($configuration);
    assertDisposableDatabase($pdo, $configuration['SLUG_TEST_DB_NAME']);
    installPackageSchema($pdo, $schemaDirectory, $createdTables);

    $profileKey = new SlugProfileKey('ascii-v1');
    $profiles = SlugProfileRegistryFactory::createBuiltIn();
    $scopeProfile = new ScopeProfileRequestDTO(new SlugScope('example', null, null), $profileKey);
    $identity = new BindingIdentityDTO(
        $scopeProfile,
        new EntityReference('article', 'example-1'),
    );
    $engine = SlugEngineFactory::create($pdo, $profiles, new ExampleReservedSlugPolicy(), new ExampleUtcClock());

    $assigned = $engine->assignExact(new AssignExactCommand(
        $identity,
        'hello-world',
        null,
        new AuditContextDTO(
            actorKey: 'examples',
            reason: 'persisted lifecycle example',
            correlationKey: 'example-persisted-lifecycle',
        ),
    ));
    expect($assigned->after->state->currentSlug?->value === 'hello-world', 'Assignment did not persist hello-world.');

    $resolved = $engine->resolve(new ResolutionCriteria($scopeProfile, 'hello-world'));
    expect($resolved->matchedSlug?->value === 'hello-world', 'Resolution did not match hello-world.');
    expect($resolved->currentSlug?->value === 'hello-world', 'Resolution returned an unexpected current slug.');
    expect($resolved->entity?->entityType === 'article', 'Resolution returned an unexpected entity type.');
    expect($resolved->entity?->entityKey === 'example-1', 'Resolution returned an unexpected entity key.');

    $binding = $engine->getBinding(new BindingCriteria($identity));
    if (! $binding instanceof BindingDTO) {
        throw new RuntimeException('Management read did not return the binding.');
    }
    expect($binding->state->currentSlug?->value === 'hello-world', 'Management read returned an unexpected current slug.');

    $scopes = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10), 'example'));
    expect($scopes->total === 1 && $scopes->filtered === 1, 'Scope discovery returned an unexpected page.');

    $summary = $engine->getScopeOperationalSummary(new ScopeCriteria($scopeProfile));
    expect($summary !== null && $summary->bindingsTotal === 1, 'Scope summary returned an unexpected binding count.');

    $history = $engine->searchHistory(new HistorySearchCriteria(
        new PageRequest(1, 10, 'occurred_at', 'ASC'),
        $scopeProfile,
        null,
        new DateTimeImmutable('2026-01-01T00:00:00.123456Z'),
        new DateTimeImmutable('2026-01-01T00:00:01.123456Z'),
    ));
    expect($history->total === 1 && $history->filtered === 1, 'History window returned an unexpected page.');

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($pdo instanceof PDO) {
        $cleanupFailure = dropCreatedTables($pdo, $createdTables);
        if ($failure !== null && $cleanupFailure !== null) {
            fwrite(STDERR, "The example could not remove every table it created.\n");
        }
        $failure ??= $cleanupFailure;
    }
}

if ($failure !== null) {
    throw $failure;
}

echo "PERSISTED_LIFECYCLE_EXAMPLE=hello-world SCOPE_REPORTING=PASS HISTORY_WINDOW=PASS\n";

/** @return array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} */
function databaseConfiguration(): array
{
    $configuration = [];
    foreach (['SLUG_TEST_DB_HOST', 'SLUG_TEST_DB_PORT', 'SLUG_TEST_DB_NAME', 'SLUG_TEST_DB_USER', 'SLUG_TEST_DB_PASSWORD'] as $variable) {
        $value = getenv($variable);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Required environment variable %s is missing.', $variable));
        }
        $configuration[$variable] = $value;
    }

    if (preg_match('/\A[A-Za-z0-9_]+_test\z/', $configuration['SLUG_TEST_DB_NAME']) !== 1) {
        throw new RuntimeException('SLUG_TEST_DB_NAME must identify a disposable database ending in _test.');
    }
    if (filter_var($configuration['SLUG_TEST_DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new RuntimeException('SLUG_TEST_DB_PORT must be a valid TCP port.');
    }

    return $configuration;
}

/** @param array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} $configuration */
function connectToDatabase(#[SensitiveParameter] array $configuration): PDO
{
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $configuration['SLUG_TEST_DB_HOST'],
            $configuration['SLUG_TEST_DB_PORT'],
            $configuration['SLUG_TEST_DB_NAME'],
        ),
        $configuration['SLUG_TEST_DB_USER'],
        $configuration['SLUG_TEST_DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, Pdo\Mysql::ATTR_MULTI_STATEMENTS => false],
    );
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');

    return $pdo;
}

/** Checks the selected database and rejects existing package objects before schema installation. */
function assertDisposableDatabase(PDO $pdo, string $database): void
{
    $selected = $pdo->query('SELECT DATABASE()');
    if ($selected === false || $selected->fetchColumn() !== $database) {
        throw new RuntimeException('The selected database must match the configured disposable database.');
    }

    $objects = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND LEFT(LOWER(TABLE_NAME), 9) = 'maa_slug_' LIMIT 1");
    if ($objects === false || $objects->fetchColumn() !== false) {
        throw new RuntimeException('The disposable database must have no pre-existing Slug tables or views.');
    }
}

/**
 * Applies the ordered schema and records tables only after successful creation.
 *
 * @param list<string> $createdTables
 */
function installPackageSchema(PDO $pdo, string $schemaDirectory, array &$createdTables): void
{
    $assets = glob($schemaDirectory . '/[0-9][0-9][0-9]_*.sql');
    if ($assets === false || $assets === []) {
        throw new RuntimeException('The package-owned schema assets could not be found.');
    }
    sort($assets, SORT_STRING);
    foreach ($assets as $asset) {
        $sql = file_get_contents($asset);
        if ($sql === false) {
            throw new RuntimeException('The package-owned schema asset could not be read.');
        }
        $statements = preg_split('/;\s*(?:\r?\n|\z)/', $sql);
        if ($statements === false) {
            throw new RuntimeException('The package-owned schema could not be split.');
        }
        foreach ($statements as $rawStatement) {
            $statement = preg_replace('/\A(?:--[^\r\n]*(?:\R|\z)\s*)+/', '', trim($rawStatement));
            if ($statement === '') {
                continue;
            }
            if ($statement === null || preg_match('/\A(CREATE|ALTER)\s+TABLE\s+(maa_slug_[a-z0-9_]+)(?=\s|\()/', $statement, $matches) !== 1) {
                throw new RuntimeException('The example schema asset contains an unsupported statement.');
            }
            [, $operation, $table] = $matches;
            if ($operation === 'ALTER' && ! in_array($table, $createdTables, true)) {
                throw new RuntimeException('The example can alter only tables created by this invocation.');
            }
            if ($pdo->exec($statement) === false) {
                throw new RuntimeException('An example schema statement did not execute.');
            }
            if ($operation === 'CREATE') {
                $createdTables[] = $table;
            }
        }
    }
}

/**
 * Removes only the tables created by this invocation in reverse creation order.
 *
 * @param list<string> $createdTables
 */
function dropCreatedTables(PDO $pdo, array $createdTables): ?Throwable
{
    $failure = null;
    foreach (array_reverse($createdTables) as $table) {
        try {
            if ($pdo->exec('DROP TABLE IF EXISTS `' . $table . '`') === false) {
                throw new RuntimeException('The example could not remove a table it created.');
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
    }

    return $failure;
}

/** Fails the example immediately when an expected observable result is absent. */
function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
