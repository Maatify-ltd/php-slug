<?php

declare(strict_types=1);

use Maatify\Slug\Tests\Support\FaultInjectingMySqlPdo;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$table = $argv[1] ?? '';
if (
    preg_match('/\Amaa_slug_rollback_test_[a-f0-9]{16}\z/', $table) !== 1
    || ! str_ends_with((string) getenv('SLUG_TEST_DB_NAME'), '_test')
) {
    throw new RuntimeException('Expected an isolated rollback fixture.');
}

$pdo = new FaultInjectingMySqlPdo();
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$pdo->exec('SET SESSION innodb_lock_wait_timeout=6');
$pdo->exec('SET SESSION lock_wait_timeout=6');
$awaitBarrier = static function (string $command): void {
    $read = [STDIN];
    $write = $except = null;
    if (stream_select($read, $write, $except, 10) !== 1 || fgets(STDIN) !== $command . "\n") {
        throw new RuntimeException("Missing deadlock worker barrier: $command.");
    }
};
$pdo->beginTransaction();
try {
    // Make this transaction heavier so InnoDB selects the coordinator's transaction as victim.
    if ($pdo->exec("UPDATE $table SET marker=2 WHERE id BETWEEN 2 AND 65") !== 64) {
        throw new RuntimeException('Could not establish the worker locks.');
    }
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    $awaitBarrier('CROSS');
    fwrite(STDOUT, "CROSSING\n");
    fflush(STDOUT);
    if ($pdo->exec("UPDATE $table SET marker=2 WHERE id=1") !== 1) {
        throw new RuntimeException('The worker did not acquire the lock released by the victim.');
    }
    fwrite(STDOUT, "WON\n");
    fflush(STDOUT);
    $awaitBarrier('FINISH');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
fwrite(STDOUT, "DONE\n");
