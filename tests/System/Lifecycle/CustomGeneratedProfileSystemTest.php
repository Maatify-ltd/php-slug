<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Support\GeneratedFixtureProfile;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class CustomGeneratedProfileSystemTest extends MySqlIntegrationTestCase
{
    #[DataProvider('generatedPaths')]
    public function testCustomGrammarAcrossGeneratedPathsPreservesHostRollback(string $operation, string $pattern, string $value): void
    {
        $profile = new GeneratedFixtureProfile($pattern);
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('custom-generated', null, null), $profile->key());
        $targetScope = new ScopeProfileRequestDTO(new SlugScope('custom-generated-target', null, null), $profile->key());
        $source = new BindingIdentityDTO($scope, new EntityReference('post', 'source'));
        $target = new BindingIdentityDTO($scope, new EntityReference('post', 'target'));
        $transitionTarget = new BindingIdentityDTO($targetScope, $source->entity);
        $audit = new AuditContextDTO();
        $engine->ensureScope($scope);
        $engine->ensureScope($targetScope);
        if ($operation !== 'assign') {
            $engine->assignExact(new AssignExactCommand($source, 'initial', null, $audit));
        }
        if ($operation === 'transfer') {
            $engine->assignExact(new AssignExactCommand($target, 'target', null, $audit));
            $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, $audit));
        }

        $this->pdo->beginTransaction();
        try {
            match ($operation) {
                'assign' => $engine->assignGenerated(new AssignGeneratedCommand($source, $value, null, $audit)),
                'change' => $engine->changeGenerated(new ChangeGeneratedCommand($source, $value, 1, $audit)),
                'transition' => $engine->transitionScope(new TransitionScopeCommand($source, $targetScope, ScopeTransitionModeEnum::PARALLEL, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::GENERATED, $value), 1, $audit)),
                'transfer' => $engine->atomicTransfer(new AtomicTransferCommand($source, $target, 'initial', new TransferReplacementIntentDTO(ClaimIntentModeEnum::GENERATED, $value), 1, 2, $audit)),
                default => throw new \LogicException('Unknown generated operation fixture.'),
            };
            self::assertTrue($this->pdo->inTransaction());
            $changed = $operation === 'transition' ? $transitionTarget : $source;
            self::assertSame($value, $engine->getCurrent(new CurrentSlugCriteria($changed))?->claim->slug->value);
            if ($operation === 'transfer') {
                self::assertSame('initial', $engine->getCurrent(new CurrentSlugCriteria($target))?->claim->slug->value);
            }
        } finally {
            $this->pdo->rollBack();
        }

        $observer = SlugEngineFactory::create($this->newTestConnection(), $profiles, new TestReservedSlugPolicy(), $this->clock());
        self::assertSame($operation === 'assign' ? null : 'initial', $observer->getCurrent(new CurrentSlugCriteria($source))?->claim->slug->value);
        self::assertNull($observer->getCurrent(new CurrentSlugCriteria($transitionTarget)));
        if ($operation === 'transfer') {
            self::assertNull($observer->getCurrent(new CurrentSlugCriteria($target)));
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function generatedPaths(): array
    {
        $cases = [];
        foreach (['assign', 'change', 'transition', 'transfer'] as $operation) {
            $cases[$operation . ' dotted'] = [$operation, '/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/', 'another.word'];
            $cases[$operation . ' letters only'] = [$operation, '/\A[a-z]+\z/', 'another'];
        }
        return $cases;
    }

    public function testOccupiedBaseWithNoValidSuffixExhaustsWithoutEndingHostTransaction(): void
    {
        $profile = new GeneratedFixtureProfile('/\A[a-z]+\z/', true);
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('custom-exhausted', null, null), $profile->key());
        $owner = new BindingIdentityDTO($scope, new EntityReference('post', 'owner'));
        $challenger = new BindingIdentityDTO($scope, new EntityReference('post', 'challenger'));
        $engine->assignExact(new AssignExactCommand($owner, 'occupied', null, new AuditContextDTO()));

        $this->pdo->beginTransaction();
        try {
            try {
                $engine->assignGenerated(new AssignGeneratedCommand($challenger, 'occupied', null, new AuditContextDTO()));
                self::fail('The profile has no accepted free candidate.');
            } catch (SlugAllocationExhaustedException) {
                self::assertTrue($this->pdo->inTransaction());
                self::assertSame('occupied', $engine->getCurrent(new CurrentSlugCriteria($owner))?->claim->slug->value);
                self::assertNull($engine->getCurrent(new CurrentSlugCriteria($challenger)));
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testGeneratedAllocationSkipsAProfileRejectedSuffixAfterAnOccupiedBase(): void
    {
        $profile = new GeneratedFixtureProfile('/\Aseed(?:-3)?\z/');
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $scope = new ScopeProfileRequestDTO(new SlugScope('custom-suffix', null, null), $profile->key());
        $owner = new BindingIdentityDTO($scope, new EntityReference('post', 'owner'));
        $challenger = new BindingIdentityDTO($scope, new EntityReference('post', 'challenger'));
        $engine->assignExact(new AssignExactCommand($owner, 'seed', null, new AuditContextDTO()));

        $result = $engine->assignGenerated(new AssignGeneratedCommand($challenger, 'seed', null, new AuditContextDTO()));

        self::assertSame('seed-3', $result->after->currentClaim?->slug->value);
        self::assertSame('seed', $engine->getCurrent(new CurrentSlugCriteria($owner))?->claim->slug->value);
        self::assertFalse($this->pdo->inTransaction());
    }
}
