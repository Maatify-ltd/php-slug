<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateAliasCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseClaimCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAliasOperationNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugCurrentClaimReleaseException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugTransferReplacementConflictException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/CandidateCollationSystemTest.php';

/** Exercises database-equivalent requests through every same-Binding claim path. */
final class SameBindingCollationSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('claimPaths')]
    public function testExistingClaimUsesDatabaseIdentityAndStoredBytes(string $path, bool $equivalentInput, bool $buffered): void
    {
        $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        [$engine, $owner, $target, $revision] = $this->seed($path);
        $aliasPath = in_array($path, ['add-alias', 'retire-alias', 'reactivate-alias', 'release-claim', 'promote-alias', 'transfer-alias'], true);
        $stored = $aliasPath ? 'alias ' : 'alpha ';
        $requested = $equivalentInput ? rtrim($stored) : $stored;
        $availability = $engine->checkAvailability(new AvailabilityCriteria($owner->scopeProfile, $requested, $owner));
        self::assertSame(AvailabilityStatusEnum::OWNED_BY_SAME_BINDING, $availability->status);
        self::assertSame($stored, $engine->resolve(new ResolutionCriteria($owner->scopeProfile, $requested))->matchedSlug?->value);
        $before = $this->counts();
        $this->pdo->exec('CREATE TEMPORARY TABLE same_binding_host_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO same_binding_host_marker VALUES (1)');
        try {
            $result = $this->call($engine, $owner, $target, $revision, $path, $requested, new AuditContextDTO(idempotencyKey: 'same-binding-proof'));
            $after = $result instanceof AtomicTransferResultDTO ? $result->sourceResult->after : $result->after;
            $noOp = in_array($path, ['change-current-exact', 'change-current-generated', 'add-alias'], true);
            $expectedCurrent = match ($path) {
                'promote-alias' => 'alias ',
                'alias-from-historical' => 'other',
                'transfer-current' => 'fresh',
                default => 'alpha ',
            };
            self::assertSame($expectedCurrent, $after->currentClaim?->slug->value);
            self::assertSame($revision + (int) ! $noOp, $after->state->revision);
            self::assertSame($noOp, $result->historyEvents === []);
            foreach ($result->historyEvents as $event) {
                if ($event->slugSnapshot?->value !== 'other') {
                    self::assertContains($event->slugSnapshot?->value, ['alpha ', 'alias ', 'fresh']);
                }
                if ($event->previousSlugSnapshot !== null) {
                    self::assertContains($event->previousSlugSnapshot->value, ['alpha ', 'alias ', 'other']);
                }
            }
            if ($result instanceof AtomicTransferResultDTO) {
                $expectedMoved = match ($path) {
                    'transfer-alias' => 'alias ',
                    'transfer-current' => 'alpha ',
                    default => 'other',
                };
                self::assertSame($expectedMoved, $result->transferredClaim->slug->value);
                if ($path !== 'transfer-alias') {
                    self::assertSame($expectedMoved, $result->targetResult->after->currentClaim?->slug->value);
                    self::assertSame($expectedCurrent, $result->sourceReplacementResult?->currentSlug?->value);
                }
            }
            $countsAfterMutation = $this->counts();
            $replayed = $this->call($engine, $owner, $target, $revision, $path, $requested, new AuditContextDTO(idempotencyKey: 'same-binding-proof'));
            $expected = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($expected);
            $expected['replayed'] = true;
            if ($result instanceof AtomicTransferResultDTO && $result->sourceReplacementResult !== null) {
                self::assertIsArray($expected['source_replacement_result']);
                $expected['source_replacement_result']['replayed'] = true;
            }
            self::assertSame($expected, json_decode(json_encode($replayed, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($countsAfterMutation, $this->counts(), 'Replay must not append history or mutate claims.');
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame($before, $this->counts(), 'Host rollback must remove library changes.');
        $marker = $this->pdo->query('SELECT COUNT(*) FROM same_binding_host_marker');
        self::assertNotFalse($marker);
        self::assertSame('0', (string) $marker->fetchColumn());
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function claimPaths(): iterable
    {
        foreach (['change-current-exact', 'change-current-generated', 'change-historical-exact', 'change-historical-generated', 'add-alias', 'alias-from-historical', 'retire-alias', 'reactivate-alias', 'release-claim', 'promote-alias', 'restore-historical', 'transfer-alias', 'transfer-current', 'replacement-exact', 'replacement-generated'] as $path) {
            foreach ([false, true] as $equivalent) {
                foreach ([false, true] as $buffered) {
                    yield $path . '-' . (int) $equivalent . '-' . (int) $buffered => [$path, $equivalent, $buffered];
                }
            }
        }
    }

    /** @param class-string<\Throwable> $expectedException */
    #[DataProvider('rejectedClaims')]
    public function testEquivalentClaimsRetainOwnerAndRoleRejections(string $path, string $expectedException): void
    {
        [$engine, $owner, $target, $revision] = $this->seed('retire-alias');
        $other = new BindingIdentityDTO($owner->scopeProfile, new EntityReference('article', 'other-owner'));
        $engine->assignExact(new AssignExactCommand($other, 'taken ', null, new AuditContextDTO()));
        $before = $this->counts();
        $this->pdo->beginTransaction();
        $this->expectException($expectedException);
        try {
            match ($path) {
                'wrong-owner-change' => $engine->changeExact(new ChangeExactCommand($owner, 'taken', $revision, new AuditContextDTO())),
                'wrong-owner-alias' => $engine->addAlias(new AddAliasCommand($owner, 'taken', $revision, new AuditContextDTO())),
                'change-alias' => $engine->changeExact(new ChangeExactCommand($owner, 'alias', $revision, new AuditContextDTO())),
                'release-current' => $engine->releaseClaim(new ReleaseClaimCommand($owner, 'alpha', $revision, new AuditContextDTO())),
                'add-current-as-alias' => $engine->addAlias(new AddAliasCommand($owner, 'alpha', $revision, new AuditContextDTO())),
                'reactivate-active' => $engine->reactivateAlias(new ReactivateAliasCommand($owner, 'alias', $revision, new AuditContextDTO())),
                default => throw new \RuntimeException('Unknown rejection path.'),
            };
        } finally {
            self::assertTrue($this->pdo->inTransaction());
            $this->pdo->rollBack();
            self::assertSame($before, $this->counts());
        }
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function rejectedClaims(): iterable
    {
        yield 'wrong-owner-change' => ['wrong-owner-change', SlugAlreadyClaimedException::class];
        yield 'wrong-owner-alias' => ['wrong-owner-alias', SlugAlreadyClaimedException::class];
        yield 'change-alias' => ['change-alias', SlugAliasOperationNotPermittedException::class];
        yield 'release-current' => ['release-current', SlugCurrentClaimReleaseException::class];
        yield 'add-current-as-alias' => ['add-current-as-alias', SlugAliasOperationNotPermittedException::class];
        yield 'reactivate-active' => ['reactivate-active', SlugAliasOperationNotPermittedException::class];
    }

    public function testExactReplacementCannotBeDatabaseEquivalentToTheMovedCurrent(): void
    {
        [$engine, $owner, $target, $revision] = $this->seed('transfer-current');
        $this->expectException(SlugTransferReplacementConflictException::class);
        $engine->atomicTransfer(new AtomicTransferCommand($owner, $target, 'alpha ', new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'alpha'), $revision, 2, new AuditContextDTO()));
    }

    public function testGeneratedReplacementSkipsTheDatabaseEquivalentMovedCurrent(): void
    {
        [$engine, $owner, $target, $revision] = $this->seed('transfer-current');
        $result = $engine->atomicTransfer(new AtomicTransferCommand($owner, $target, 'alpha ', new TransferReplacementIntentDTO(ClaimIntentModeEnum::GENERATED, 'alpha'), $revision, 2, new AuditContextDTO()));
        self::assertSame('alpha-2', $result->sourceResult->after->currentClaim?->slug->value);
        self::assertSame('alpha ', $result->targetResult->after->currentClaim?->slug->value);
    }

    #[DataProvider('replaySlugKeys')]
    public function testReplayRejectsConflictingPaddedClaims(string $path, string $replacement): void
    {
        [$engine, $owner, $target, $revision] = $this->seed($path);
        if ($path === 'release-claim') {
            $engine->addAlias(new AddAliasCommand($owner, 'retained', $revision, new AuditContextDTO()));
            $revision++;
        }
        $audit = new AuditContextDTO(idempotencyKey: 'replay-slug-key');
        $result = $this->call($engine, $owner, $target, $revision, $path, 'alias ', $audit);
        self::assertTrue($this->call($engine, $owner, $target, $revision, $path, 'alias ', $audit)->replayed);

        $read = $this->pdo->prepare('SELECT result_snapshot FROM maa_slug_operations WHERE operation_key = :key');
        $read->execute(['key' => $result->operationKey]);
        $stored = $read->fetchColumn();
        $read->closeCursor();
        self::assertIsString($stored);
        /** @var array{result: array{affected_claims: list<array{slug: string}>}} $snapshot */
        $snapshot = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $snapshot['result']['affected_claims'][1]['slug'] = $replacement;
        $write = $this->pdo->prepare('UPDATE maa_slug_operations SET result_snapshot = :snapshot WHERE operation_key = :key');
        $write->execute(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'key' => $result->operationKey]);

        $this->expectException(SlugPersistenceInvariantException::class);
        $this->call($engine, $owner, $target, $revision, $path, 'alias ', $audit);
    }

    /** @return iterable<string, array{string, string}> */
    public static function replaySlugKeys(): iterable
    {
        yield 'duplicate current claim with distinct stored bytes' => ['add-alias', 'alpha'];
        yield 'released claim with distinct stored bytes' => ['release-claim', 'alias'];
    }

    /** @return array{SlugEngine, BindingIdentityDTO, BindingIdentityDTO, int} */
    private function seed(string $path): array
    {
        $profiles = new SlugProfileRegistry();
        $profile = new PadSpaceCandidateProfile();
        $profiles->register($profile);
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new RecordingCandidatePolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('same-binding-collation', null, null), $profile->key());
        $owner = new BindingIdentityDTO($scope, new EntityReference('article', 'owner'));
        $target = new BindingIdentityDTO($scope, new EntityReference('article', 'target'));
        $audit = new AuditContextDTO();
        $engine->assignExact(new AssignExactCommand($owner, 'alpha ', null, $audit));
        $revision = 1;
        if (in_array($path, ['add-alias', 'retire-alias', 'reactivate-alias', 'release-claim', 'promote-alias', 'transfer-alias'], true)) {
            $engine->addAlias(new AddAliasCommand($owner, 'alias ', 1, $audit));
            $revision = 2;
        }
        if ($path === 'reactivate-alias') {
            $engine->retireAlias(new RetireAliasCommand($owner, 'alias ', 2, $audit));
            $revision = 3;
        }
        if (in_array($path, ['change-historical-exact', 'change-historical-generated', 'alias-from-historical', 'restore-historical', 'replacement-exact', 'replacement-generated'], true)) {
            $engine->changeExact(new ChangeExactCommand($owner, 'other', 1, $audit));
            $revision = 2;
        }
        if (in_array($path, ['transfer-alias', 'transfer-current', 'replacement-exact', 'replacement-generated'], true)) {
            $engine->assignExact(new AssignExactCommand($target, 'target', null, $audit));
            if ($path !== 'transfer-alias') {
                $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, $audit));
            }
        }
        return [$engine, $owner, $target, $revision];
    }

    /** Executes the documented public command for the selected regression path. */
    private function call(SlugEngine $engine, BindingIdentityDTO $owner, BindingIdentityDTO $target, int $revision, string $path, string $candidate, AuditContextDTO $audit): SlugMutationResultDTO|AtomicTransferResultDTO
    {
        return match ($path) {
            'change-current-exact', 'change-historical-exact' => $engine->changeExact(new ChangeExactCommand($owner, $candidate, $revision, $audit)),
            'change-current-generated', 'change-historical-generated' => $engine->changeGenerated(new ChangeGeneratedCommand($owner, $candidate, $revision, $audit)),
            'add-alias', 'alias-from-historical' => $engine->addAlias(new AddAliasCommand($owner, $candidate, $revision, $audit)),
            'retire-alias' => $engine->retireAlias(new RetireAliasCommand($owner, $candidate, $revision, $audit)),
            'reactivate-alias' => $engine->reactivateAlias(new ReactivateAliasCommand($owner, $candidate, $revision, $audit)),
            'release-claim' => $engine->releaseClaim(new ReleaseClaimCommand($owner, $candidate, $revision, $audit)),
            'promote-alias' => $engine->promoteAliasToCurrent(new PromoteAliasToCurrentCommand($owner, $candidate, $revision, $audit)),
            'restore-historical' => $engine->restoreHistorical(new RestoreHistoricalCommand($owner, $candidate, $revision, $audit)),
            'transfer-alias' => $engine->atomicTransfer(new AtomicTransferCommand($owner, $target, $candidate, null, $revision, 1, $audit)),
            'transfer-current' => $engine->atomicTransfer(new AtomicTransferCommand($owner, $target, $candidate, new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'fresh'), $revision, 2, $audit)),
            'replacement-exact', 'replacement-generated' => $engine->atomicTransfer(new AtomicTransferCommand($owner, $target, 'other', new TransferReplacementIntentDTO($path === 'replacement-exact' ? ClaimIntentModeEnum::EXACT : ClaimIntentModeEnum::GENERATED, $candidate), $revision, 2, $audit)),
            default => throw new \RuntimeException('Unknown claim path.'),
        };
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['scopes', 'bindings', 'registry', 'registry_lock_keys', 'history', 'operations', 'operation_bindings'] as $table) {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_' . $table);
            self::assertNotFalse($statement);
            $counts[$table] = (int) $statement->fetchColumn();
            $statement->closeCursor();
        }
        return $counts;
    }
}
