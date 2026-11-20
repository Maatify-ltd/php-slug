<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Transaction;

use PDO;
use Maatify\Slug\Lifecycle\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Lifecycle\Repository\Transaction\TransactionCoordinatorInterface;
use Throwable;

/** PDO transaction coordinator supporting owned transactions and savepoints. */
final readonly class PdoTransactionCoordinator implements TransactionCoordinatorInterface
{
    /** Stores the caller-owned PDO connection. */
    public function __construct(private PDO $pdo) {}

    /**
     * Runs the callback in a transaction or savepoint managed by the coordinator.
     *
     * The callback must leave the transaction boundary under the coordinator's
     * control. An owned rollback request returns its value after rollback succeeds.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     * @throws SlugTransactionParticipationException If transaction participation fails or an owned rollback cannot be confirmed.
     */
    public function run(callable $callback): mixed
    {
        if (! $this->pdo->inTransaction()) {
            try {
                if (! $this->pdo->beginTransaction()) {
                    throw new SlugTransactionParticipationException('Unable to begin the package transaction.');
                }
            } catch (SlugTransactionParticipationException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new SlugTransactionParticipationException('Unable to begin the package transaction.', 0, $throwable);
            }

            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                $this->rollbackOwnedPreserving($throwable);

                throw $throwable;
            }

            if ($result instanceof PdoTransactionRollback) {
                $this->rollbackOwnedOrThrow(requireActiveTransaction: true);

                return $result->value;
            }

            try {
                if (! $this->pdo->commit()) {
                    throw new SlugTransactionParticipationException('Unable to commit the package transaction.');
                }
            } catch (SlugTransactionParticipationException $exception) {
                try {
                    $this->rollbackOwnedOrThrow();
                } catch (Throwable $rollbackFailure) {
                    throw new SlugTransactionParticipationException(
                        'Package transaction commit failed and rollback also failed: ' . $rollbackFailure->getMessage(),
                        0,
                        $exception,
                    );
                }
                throw $exception;
            } catch (Throwable $throwable) {
                try {
                    $this->rollbackOwnedOrThrow();
                } catch (Throwable $rollbackFailure) {
                    throw new SlugTransactionParticipationException(
                        'Package transaction commit failed and rollback also failed: ' . $rollbackFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
                throw new SlugTransactionParticipationException('Unable to commit the package transaction.', 0, $throwable);
            }

            return $result;
        }

        $savepoint = $this->newSavepointName();
        $this->execOrThrow(
            sprintf('SAVEPOINT %s', $savepoint),
            'Unable to create the package savepoint before mutation.',
        );

        try {
            $result = $callback();
        } catch (Throwable $throwable) {
            try {
                $this->rollbackToSavepoint($savepoint);
            } catch (Throwable $cleanupFailure) {
                throw new SlugTransactionParticipationException(
                    'Package savepoint cleanup failed after the original failure: ' . $cleanupFailure->getMessage(),
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }

        if ($result instanceof PdoTransactionRollback) {
            $this->rollbackToSavepoint($savepoint);

            return $result->value;
        }

        try {
            $this->execOrThrow(
                sprintf('RELEASE SAVEPOINT %s', $savepoint),
                'Unable to release the package savepoint.',
            );
        } catch (Throwable $throwable) {
            try {
                $this->rollbackToSavepoint($savepoint);
            } catch (Throwable $cleanupFailure) {
                throw new SlugTransactionParticipationException(
                    'Package savepoint cleanup failed after release failure: ' . $cleanupFailure->getMessage(),
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }

        return $result;
    }

    /** Returns a safe unique savepoint name for the current transaction. */
    public function newSavepointName(): string
    {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(16)));
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException(
                'Unable to generate a transaction savepoint name.',
                0,
                $throwable,
            );
        }

        return 'maa_slug_sp_' . $suffix;
    }

    /** Rolls back a nested transaction scope to its savepoint. */
    private function rollbackToSavepoint(string $savepoint): void
    {
        $this->execOrThrow(
            sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint),
            'Unable to roll back the package savepoint.',
        );
        $this->execOrThrow(
            sprintf('RELEASE SAVEPOINT %s', $savepoint),
            'Unable to release the rolled-back package savepoint.',
        );
    }

    /** Rolls back an active owned transaction. Explicit rollback requests require confirmation. */
    private function rollbackOwnedOrThrow(bool $requireActiveTransaction = false): void
    {
        if (! $this->pdo->inTransaction()) {
            if ($requireActiveTransaction) {
                throw new SlugTransactionParticipationException('Unable to confirm rollback of the package-owned transaction.');
            }

            return;
        }

        try {
            if (! $this->pdo->rollBack()) {
                throw new SlugTransactionParticipationException('Unable to roll back the package transaction.');
            }
        } catch (SlugTransactionParticipationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException('Unable to roll back the package transaction.', 0, $throwable);
        }
    }

    /** Rolls back an owned transaction while preserving the original failure. */
    private function rollbackOwnedPreserving(Throwable $original): void
    {
        try {
            $this->rollbackOwnedOrThrow();
        } catch (Throwable $rollbackFailure) {
            throw new SlugTransactionParticipationException(
                'Package transaction rollback failed after the original failure: ' . $rollbackFailure->getMessage(),
                0,
                $original,
            );
        }
    }

    /** Executes transaction-control SQL and maps failure to the package exception. */
    private function execOrThrow(string $sql, string $message): void
    {
        try {
            if ($this->pdo->exec($sql) === false) {
                throw new SlugTransactionParticipationException($message);
            }
        } catch (SlugTransactionParticipationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException($message, 0, $throwable);
        }
    }
}
