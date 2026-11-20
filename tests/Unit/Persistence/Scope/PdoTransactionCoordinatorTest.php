<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionRollback;
use Maatify\Slug\Lifecycle\Exception\SlugTransactionParticipationException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

final class PdoTransactionCoordinatorTest extends TestCase
{
    public function testOwnedRollbackRequestRequiresAnActiveTransaction(): void
    {
        $pdo = $this->ownedPdo(false);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new PdoTransactionCoordinator($pdo))->run(static fn() => new PdoTransactionRollback('discarded'));
            self::fail('The coordinator returned a normal value without confirming rollback.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertSame('Unable to confirm rollback of the package-owned transaction.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testOwnedConfirmedRollbackReturnsTheSameValue(): void
    {
        $pdo = $this->ownedPdo(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $value = new stdClass();

        self::assertSame($value, (new PdoTransactionCoordinator($pdo))->run(
            static fn() => new PdoTransactionRollback($value),
        ));
    }

    #[DataProvider('booleanCases')]
    public function testOwnedRollbackFailureRetainsItsMapping(bool $throws): void
    {
        $pdo = $this->ownedPdo(true);
        $pdo->expects(self::never())->method('commit');
        $failure = new PDOException('Rollback fault');
        $rollBack = $pdo->expects(self::once())->method('rollBack');
        if ($throws) {
            $rollBack->willThrowException($failure);
        } else {
            $rollBack->willReturn(false);
        }

        try {
            (new PdoTransactionCoordinator($pdo))->run(static fn() => new PdoTransactionRollback('discarded'));
            self::fail('Expected rollback failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertSame('Unable to roll back the package transaction.', $exception->getMessage());
            self::assertSame($throws ? $failure : null, $exception->getPrevious());
        }
    }

    public function testOwnedRollbackRequestPropagatesTheStateProbeFailure(): void
    {
        $failure = new PDOException('State probe fault');
        $pdo = $this->ownedPdo($failure);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new PdoTransactionCoordinator($pdo))->run(static fn() => new PdoTransactionRollback('discarded'));
            self::fail('Expected the original PDO exception.');
        } catch (PDOException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testOrdinaryFalseResultIsCommitted(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(false);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');

        self::assertSame(false, (new PdoTransactionCoordinator($pdo))->run(static fn(): bool => false));
    }

    #[DataProvider('booleanCases')]
    public function testOwnedCallbackFailureRetainsItsPrecedence(bool $activeAtCleanup): void
    {
        $pdo = $this->ownedPdo($activeAtCleanup);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects($activeAtCleanup ? self::once() : self::never())->method('rollBack')->willReturn(false);
        $failure = new PDOException('Callback fault');

        $this->expectException($activeAtCleanup ? SlugTransactionParticipationException::class : PDOException::class);
        try {
            (new PdoTransactionCoordinator($pdo))->run(static fn() => throw $failure);
        } catch (PDOException $exception) {
            self::assertSame($failure, $exception);
            throw $exception;
        } catch (SlugTransactionParticipationException $exception) {
            self::assertSame('Package transaction rollback failed after the original failure: Unable to roll back the package transaction.', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
            throw $exception;
        }
    }

    #[DataProvider('booleanCases')]
    public function testOwnedCommitFailureRetainsItsPrecedence(bool $activeAtCleanup): void
    {
        $pdo = $this->ownedPdo($activeAtCleanup);
        $failure = new PDOException('Commit fault');
        $pdo->expects(self::once())->method('commit')->willThrowException($failure);
        $pdo->expects($activeAtCleanup ? self::once() : self::never())->method('rollBack')->willReturn(false);

        try {
            (new PdoTransactionCoordinator($pdo))->run(static fn() => false);
            self::fail('Expected commit failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertSame(
                $activeAtCleanup
                    ? 'Package transaction commit failed and rollback also failed: Unable to roll back the package transaction.'
                    : 'Unable to commit the package transaction.',
                $exception->getMessage(),
            );
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function booleanCases(): iterable
    {
        yield 'false' => [false];
        yield 'true' => [true];
    }

    private function ownedPdo(bool|PDOException $cleanupState): PDO&MockObject
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('inTransaction')->willReturnOnConsecutiveCalls(
            false,
            $cleanupState instanceof PDOException ? $this->throwException($cleanupState) : $cleanupState,
        );
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('exec');

        return $pdo;
    }

    public function testGeneratedSavepointNamesUseTheClosedPackageFormat(): void
    {
        /** @var PDO $pdo */
        $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
        $coordinator = new PdoTransactionCoordinator($pdo);

        for ($index = 0; $index < 16; $index++) {
            self::assertMatchesRegularExpression('/\Amaa_slug_sp_[A-F0-9]{32}\z/', $coordinator->newSavepointName());
        }
    }

    public function testBeginFailureIsReportedBeforeCallbackMutation(): void
    {
        $pdo = new BeginFailingPdo();
        $callbackCalled = false;

        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            self::fail('Expected transaction establishment failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertStringContainsString('begin', strtolower($exception->getMessage()));
        }

        self::assertFalse($callbackCalled);
    }

    public function testCommitFailureIsReportedAfterCallbackAndRollbackIsAttempted(): void
    {
        $pdo = new CommitFailingPdo();
        $called = false;

        $this->expectException(SlugTransactionParticipationException::class);
        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$called): void {
                $called = true;
            });
        } finally {
            self::assertTrue($called);
            self::assertTrue($pdo->rolledBack);
        }
    }

    public function testSavepointFailureIsReportedBeforeCallbackMutation(): void
    {
        $pdo = new SavepointFailingPdo();
        $callbackCalled = false;

        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            self::fail('Expected savepoint establishment failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertStringContainsString('savepoint', strtolower($exception->getMessage()));
        }

        self::assertFalse($callbackCalled);
    }
}

/** PDO double whose first transaction boundary cannot be established. */
final class BeginFailingPdo extends PDO
{
    /** Avoids opening a real connection because transaction behavior is injected. */
    public function __construct() {}

    /** Models a connection outside a transaction before begin failure. */
    public function inTransaction(): bool
    {
        return false;
    }

    /** Rejects transaction start so the callback must not run. */
    public function beginTransaction(): bool
    {
        return false;
    }
}

/** PDO double that accepts begin but rejects commit and records rollback cleanup. */
final class CommitFailingPdo extends PDO
{
    /** Tracks the synthetic transaction state used by the coordinator test. */
    public bool $active = false;

    /** Proves the coordinator attempted rollback after commit failure. */
    public bool $rolledBack = false;

    /** Avoids opening a real connection because transaction behavior is injected. */
    public function __construct() {}

    /** Reports the synthetic transaction state. */
    public function inTransaction(): bool
    {
        return $this->active;
    }

    /** Starts the synthetic transaction accepted by the coordinator. */
    public function beginTransaction(): bool
    {
        $this->active = true;
        return true;
    }

    /** Rejects commit to exercise coordinator error translation and cleanup. */
    public function commit(): bool
    {
        return false;
    }

    /** Clears the synthetic transaction and records that rollback was attempted. */
    public function rollBack(): bool
    {
        $this->active = false;
        $this->rolledBack = true;
        return true;
    }
}

/** PDO double that reports every savepoint statement as failed. */
final class SavepointFailingPdo extends PDO
{
    /** Avoids opening a real connection because savepoint behavior is injected. */
    public function __construct() {}

    /** Reports an outer transaction so the coordinator chooses savepoint mode. */
    public function inTransaction(): bool
    {
        return true;
    }

    /** Rejects savepoint creation before the callback can mutate state. */
    public function exec(string $statement): int|false
    {
        return false;
    }
}
