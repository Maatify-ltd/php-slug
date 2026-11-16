<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Schema;

use PDO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Throwable;

/** Installs the package-owned MySQL schema through an injected PDO connection. */
final readonly class PdoSchemaInstaller
{
    /** Stores the connection used for schema installation. */
    public function __construct(
        private PDO $pdo,
        private ?PdoCapabilityGuard $capabilities = null,
    ) {}

    /** Loads and executes all ordered package schema assets. */
    public function installPackageSchema(): void
    {
        ($this->capabilities ?? new PdoCapabilityGuard($this->pdo))->assertSupported();
        $paths = glob(dirname(__DIR__, 5) . '/schema/mysql/[0-9][0-9][0-9]_*.sql');
        $required = ['001_slug_rc1.sql', '002_operational_reporting_indexes.sql', '003_registry_lock_projection.sql'];
        if ($paths === false || array_diff($required, array_map(basename(...), $paths)) !== []) {
            throw new SlugPersistenceInvariantException('The ordered package schema assets are incomplete.');
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new SlugPersistenceInvariantException(sprintf('The package schema asset cannot be read: %s.', basename($path)));
            }
            $this->installSql($sql);
        }
    }

    /** Executes caller-supplied schema SQL without opening a separate connection. */
    public function installSql(string $sql): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|\z)/', $sql);
        if ($statements === false) {
            throw new SlugPersistenceInvariantException('The package schema could not be split into statements.');
        }

        $executed = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            try {
                if ($this->pdo->exec($statement) === false) {
                    throw new SlugPersistenceInvariantException('A package schema statement returned false.');
                }
                $executed++;
            } catch (SlugPersistenceInvariantException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new SlugPersistenceInvariantException('A package schema statement failed.', 0, $throwable);
            }
        }

        if ($executed < 1) {
            throw new SlugPersistenceInvariantException('The package schema asset contained no executable statements.');
        }
    }
}
