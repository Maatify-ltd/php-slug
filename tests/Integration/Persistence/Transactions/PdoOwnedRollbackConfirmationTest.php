<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Transactions;

use Maatify\Slug\Lifecycle\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionRollback;
use Maatify\Slug\Tests\Support\FaultInjectingMySqlPdo;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoOwnedRollbackConfirmationTest extends TestCase
{
    private PDO $observer;
    private string $table;

    protected function setUp(): void
    {
        self::assertStringEndsWith('_test', (string) getenv('SLUG_TEST_DB_NAME'));
        $this->observer = $this->newTestConnection();
        $table = 'maa_slug_rollback_test_' . bin2hex(random_bytes(8));
        $this->observer->exec("CREATE TABLE $table (id INT PRIMARY KEY, marker INT NOT NULL) ENGINE=InnoDB");
        $this->table = $table;
        $rows = array_map(static fn(int $id): string => "($id, 0)", range(1, 66));
        self::assertSame(66, $this->observer->exec("INSERT INTO $table VALUES " . implode(', ', $rows)));
    }

    protected function tearDown(): void
    {
        if (isset($this->table)) {
            $this->observer->exec("DROP TABLE $this->table");
        }
    }

    public function testOwnedRollbackDiscardsPendingWritesBeforeReturning(): void
    {
        $owned = $this->newTestConnection();

        $result = (new PdoTransactionCoordinator($owned))->run(function () use ($owned): PdoTransactionRollback {
            self::assertSame(1, $owned->exec("UPDATE $this->table SET marker=7 WHERE id=66"));
            self::assertSame(7, $this->marker($owned, 66));
            self::assertSame(0, $this->marker($this->observer, 66));

            return new PdoTransactionRollback('discarded');
        });

        self::assertSame('discarded', $result);
        self::assertFalse($owned->inTransaction());
        $this->assertMarkerRolledBack(66);
    }

    public function testInjectedInactiveStateLeavesCleanupToTheConnectionOwner(): void
    {
        $owned = $this->newTestConnection();

        try {
            (new PdoTransactionCoordinator($owned))->run(function () use ($owned): PdoTransactionRollback {
                self::assertSame(1, $owned->exec("UPDATE $this->table SET marker=7 WHERE id=66"));
                $owned->reportInactiveOnce = true;

                return new PdoTransactionRollback('discarded');
            });
            self::fail('Expected an unconfirmed rollback exception.');
        } catch (SlugTransactionParticipationException $exception) {
            $this->assertUnconfirmedRollback($exception);
            self::assertTrue($owned->inTransaction());
            self::assertSame(7, $this->marker($owned, 66));
            self::assertSame(0, $this->marker($this->observer, 66));
        } finally {
            if ($owned->inTransaction()) {
                self::assertTrue($owned->rollBack());
            }
        }
        $this->assertMarkerRolledBack(66);
    }

    public function testHostCanCommitItsEarlierWriteAfterLocalDiscard(): void
    {
        $host = $this->newTestConnection();
        self::assertTrue($host->beginTransaction());
        try {
            self::assertSame(1, $host->exec("UPDATE $this->table SET marker=3 WHERE id=1"));
            $result = (new PdoTransactionCoordinator($host))->run(function () use ($host): PdoTransactionRollback {
                self::assertSame(1, $host->exec("UPDATE $this->table SET marker=7 WHERE id=66"));
                self::assertSame(7, $this->marker($host, 66));

                return new PdoTransactionRollback('discarded');
            });

            self::assertSame('discarded', $result);
            self::assertTrue($host->inTransaction());
            self::assertSame(3, $this->marker($host, 1));
            self::assertSame(0, $this->marker($host, 66));
            self::assertSame(0, $this->marker($this->observer, 1));
            self::assertSame(0, $this->marker($this->observer, 66));
            self::assertTrue($host->commit());
        } finally {
            if ($host->inTransaction()) {
                $host->rollBack();
            }
        }
        self::assertSame(3, $this->marker($this->observer, 1));
        self::assertSame(0, $this->marker($this->observer, 66));
    }

    #[DataProvider('deadlockCases')]
    public function testOwnedRollbackRequestAfterServerDeadlock(bool $refreshStatus): void
    {
        $owned = $this->newTestConnection();
        $worker = proc_open(
            [PHP_BINARY, __DIR__ . '/PdoRollbackDeadlockWorker.php', $this->table],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        self::assertIsResource($worker);
        $workerCompleted = false;

        try {
            self::assertSame('LOCKED', $this->readWorkerLine($pipes[1]));
            $activeAtRollback = null;
            try {
                $result = (new PdoTransactionCoordinator($owned))->run(
                    function () use ($owned, $pipes, $refreshStatus, &$activeAtRollback): PdoTransactionRollback {
                        self::assertSame(2, $owned->exec("UPDATE $this->table SET marker=7 WHERE id IN (1, 66)"));
                        self::assertSame(7, $this->marker($owned, 66));
                        self::assertSame(0, $this->marker($this->observer, 66));
                        self::assertSame(6, fwrite($pipes[0], "CROSS\n"));
                        self::assertSame('CROSSING', $this->readWorkerLine($pipes[1]));

                        try {
                            $owned->exec("UPDATE $this->table SET marker=7 WHERE id=2");
                            self::fail('Expected InnoDB to choose the smaller transaction as the deadlock victim.');
                        } catch (PDOException $exception) {
                            self::assertSame('40001', $exception->errorInfo[0] ?? null);
                            self::assertSame(1213, $exception->errorInfo[1] ?? null);
                        }

                        self::assertSame('WON', $this->readWorkerLine($pipes[1]));
                        // A locking read proves the server released the victim's lock before coordinator cleanup.
                        $this->assertMarkerRolledBack(66);

                        if ($refreshStatus) {
                            $statement = $owned->query('SELECT 1');
                            self::assertNotFalse($statement);
                            self::assertSame(1, (int) $statement->fetchColumn());
                            self::assertTrue($statement->closeCursor());
                        }
                        $activeAtRollback = $owned->inTransaction();
                        if ($refreshStatus) {
                            self::assertFalse($activeAtRollback);
                        }

                        return new PdoTransactionRollback('discarded');
                    },
                );
                self::assertTrue($activeAtRollback);
                self::assertSame('discarded', $result);
            } catch (SlugTransactionParticipationException $exception) {
                self::assertFalse($activeAtRollback);
                $this->assertUnconfirmedRollback($exception);
            }

            self::assertSame(0, $this->marker($this->observer, 66));
            self::assertSame(7, fwrite($pipes[0], "FINISH\n"));
            self::assertSame('DONE', $this->readWorkerLine($pipes[1]));
            $workerCompleted = true;
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            if (! $workerCompleted) {
                proc_terminate($worker, 9);
            }
            $workerExitCode = proc_close($worker);
            if ($owned->inTransaction()) {
                $owned->rollBack();
            }
        }
        self::assertSame(0, $workerExitCode);
    }

    /** @return iterable<string, array{bool}> */
    public static function deadlockCases(): iterable
    {
        yield 'immediately after deadlock' => [false];
        yield 'after successful query' => [true];
    }

    private function newTestConnection(): FaultInjectingMySqlPdo
    {
        $pdo = new FaultInjectingMySqlPdo();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=6');
        $pdo->exec('SET SESSION lock_wait_timeout=6');

        return $pdo;
    }

    /** @param resource $pipe */
    private function readWorkerLine($pipe): string
    {
        $read = [$pipe];
        $write = $except = null;
        self::assertSame(1, stream_select($read, $write, $except, 10), 'Timed out waiting for the deadlock worker.');
        $line = fgets($pipe);
        self::assertIsString($line, 'The deadlock worker closed its output early.');

        return trim($line);
    }

    private function marker(PDO $pdo, int $id, bool $locking = false): int
    {
        $sql = "SELECT marker FROM $this->table WHERE id=$id";
        $statement = $pdo->query($sql . ($locking ? ' FOR UPDATE' : ''));
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertNotFalse($value);
        self::assertTrue($statement->closeCursor());

        return (int) $value;
    }

    private function assertMarkerRolledBack(int $id): void
    {
        self::assertTrue($this->observer->beginTransaction());
        try {
            self::assertSame(0, $this->marker($this->observer, $id, locking: true));
        } finally {
            self::assertTrue($this->observer->rollBack());
        }
    }

    private function assertUnconfirmedRollback(SlugTransactionParticipationException $exception): void
    {
        self::assertSame('Unable to confirm rollback of the package-owned transaction.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }
}
