<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Scope\PdoScopeRepository;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;

final class CandidateCollationSystemTest extends MySqlIntegrationTestCase
{
    public function testExactAssignmentRejectsDatabaseEquivalentClaimBeforeInvokingPolicy(): void
    {
        $owner = $this->identity('existing');
        $challenger = $this->identity('challenger');
        $policy = new RecordingCandidatePolicy('alpha ');
        $engine = $this->engine($policy);
        $engine->assignExact(new AssignExactCommand($owner, 'alpha', null, new AuditContextDTO()));
        $policy->candidates = [];

        $this->expectException(SlugAlreadyClaimedException::class);
        try {
            $engine->assignExact(new AssignExactCommand($challenger, 'alpha ', null, new AuditContextDTO()));
        } finally {
            self::assertSame([], $policy->candidates);
        }
    }

    public function testGeneratedAssignmentSkipsDatabaseEquivalentCandidateWithoutPolicyAndPreservesStoredSlug(): void
    {
        $owner = $this->identity('existing');
        $challenger = $this->identity('challenger');
        $policy = new RecordingCandidatePolicy();
        $engine = $this->engine($policy);
        $engine->assignExact(new AssignExactCommand($owner, 'alpha ', null, new AuditContextDTO()));
        $policy->candidates = [];

        $generated = $engine->assignGenerated(new AssignGeneratedCommand($challenger, 'alpha', null, new AuditContextDTO()));

        self::assertSame('alpha ', $engine->getCurrent(new CurrentSlugCriteria($owner))?->claim->slug->value);
        self::assertSame('alpha-2', $generated->after->currentClaim?->slug->value);
        self::assertSame(['alpha-2'], $policy->candidates);
    }

    public function testOccupancyPreservesBothEquivalentRequestKeysAcrossBatches(): void
    {
        $owner = $this->identity('existing');
        $engine = $this->engine(new RecordingCandidatePolicy());
        $created = $engine->assignExact(new AssignExactCommand($owner, 'alpha ', null, new AuditContextDTO()));
        self::assertNotNull($created->after->currentClaim);
        $id = $created->after->currentClaim->id;
        $profiles = $this->profiles();
        $scopes = new PdoScopeRepository($this->pdo, $profiles, $this->clock());
        $registry = new PdoRegistryRepository($this->pdo, $profiles, $scopes, $this->clock());

        $this->pdo->beginTransaction();
        try {
            $scope = $registry->lockScope($owner->scopeProfile->scope, $owner->scopeProfile->expectedProfileKey);
            $binding = $registry->findBindingRecord($scope, $owner->entity, true);
            self::assertNotNull($binding);

            // Sort immediately before alpha to place equivalent bytes in separate batches.
            $free = array_map(static fn(int $index): string => 'a0-' . $index, range(1, 99));
            $matches = $registry->lockClaimKeysForMutation($scope->id, [$binding->id], [...$free, 'alpha', 'alpha ']);
            self::assertSame([
                ['id' => $id, 'binding_id' => $binding->id, 'slug' => 'alpha'],
                ['id' => $id, 'binding_id' => $binding->id, 'slug' => 'alpha '],
            ], $matches);
            $profile = $profiles->get($owner->scopeProfile->expectedProfileKey);
            $stored = $registry->findClaimByBindingSlug($binding->id, Slug::fromProfile($profile, 'alpha'), true);
            self::assertSame('alpha ', $stored?->slugValue);
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        $scope = new ScopeProfileRequestDTO(new SlugScope('candidate-collation', null, null), new SlugProfileKey('pad-fixture-v1'));
        return new BindingIdentityDTO($scope, new EntityReference('post', $entityKey));
    }

    private function engine(RecordingCandidatePolicy $policy): SlugEngine
    {
        return SlugEngineFactory::create($this->pdo, $this->profiles(), $policy, $this->clock());
    }

    private function profiles(): SlugProfileRegistry
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new PadSpaceCandidateProfile());
        return $profiles;
    }
}

/** Preserves trailing spaces to exercise equivalence defined by the Registry collation. */
final class PadSpaceCandidateProfile implements SlugProfileInterface
{
    public function key(): SlugProfileKey
    {
        return new SlugProfileKey('pad-fixture-v1');
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        return new GeneratedSlugDTO($this->key(), $source, Slug::fromProfile($this, $source));
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        return new CanonicalSlugDTO($this->key(), $candidate, Slug::fromProfile($this, $candidate));
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        return new LookupCanonicalizationDTO(
            $this->key(),
            $decodedSegment,
            InputFormCanonicalityEnum::CANONICAL,
            Slug::fromProfile($this, $decodedSegment),
        );
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        if (preg_match('/^[a-z][a-z0-9 -]*$/D', $candidate) !== 1) {
            throw new SlugInvalidArgumentException('Invalid test slug.');
        }
    }
}

/** Records policy calls to distinguish occupied candidates from free candidates. */
final class RecordingCandidatePolicy implements ReservedSlugPolicyInterface
{
    /** @var list<string> */
    public array $candidates = [];

    public function __construct(private readonly ?string $reserved = null) {}

    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        $this->candidates[] = $slug->value;
        return $slug->value === $this->reserved;
    }
}
