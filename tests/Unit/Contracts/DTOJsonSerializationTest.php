<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\DTO\OwnershipClaimResultDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use PHPUnit\Framework\TestCase;

final class DTOJsonSerializationTest extends TestCase
{
    /** @return iterable<string, array{0: \JsonSerializable, 1: list<string>}> */
    public static function dtoCases(): iterable
    {
        $identity = ContractFixtures::identity(1);
        $binding = ContractFixtures::binding($identity, 100);
        $claim = $binding->currentClaim;
        if ($claim === null) {
            throw new \LogicException('Fixture binding must have a claim.');
        }
        $slug = ContractFixtures::slug('hello');
        $scope = $identity->scopeProfile;

        yield 'audit' => [new AuditContextDTO('actor', ' reason ', 'correlation', '0123456789abcdef0123456789abcdef'), ['actor_key', 'reason', 'correlation_key', 'idempotency_key']];
        yield 'generated' => [new GeneratedSlugDTO($scope->expectedProfileKey, 'Hello', $slug), ['profile_key', 'source', 'slug']];
        yield 'canonical' => [new CanonicalSlugDTO($scope->expectedProfileKey, 'hello', $slug), ['profile_key', 'input', 'slug']];
        yield 'lookup' => [new LookupCanonicalizationDTO($scope->expectedProfileKey, 'hello', InputFormCanonicalityEnum::CANONICAL, $slug), ['profile_key', 'decoded_segment', 'canonicality', 'canonical_slug']];
        yield 'scope' => [new ScopeDTO(1, $scope->scope, $scope->expectedProfileKey, ContractFixtures::date(), ContractFixtures::date()), ['id', 'scope', 'profile_key', 'created_at', 'updated_at']];
        yield 'scope operational summary' => [new ScopeOperationalSummaryDTO(new ScopeDTO(1, $scope->scope, $scope->expectedProfileKey, ContractFixtures::date(), ContractFixtures::date()), 3, 1, 1, 1, 4, 1, 1, 1, 1, 2), ['scope', 'bindings_total', 'bindings_active', 'bindings_inactive', 'bindings_released', 'registry_claims_total', 'registry_current_canonical', 'registry_historical_canonical', 'registry_active_aliases', 'registry_retired_aliases', 'history_events_total']];
        yield 'current' => [new CurrentSlugDTO($binding, $claim, 1), ['binding', 'claim', 'revision']];
        yield 'alias' => [new AliasDTO(ContractFixtures::claim($identity, $slug, 201, RegistryRoleEnum::ACTIVE_ALIAS), true, 1), ['claim', 'resolvable_as_alias', 'binding_revision']];
        yield 'availability' => [new SlugAvailabilityDTO($scope, 'hello', $slug, AvailabilityStatusEnum::AVAILABLE, $binding, true), ['scope_profile', 'requested_input', 'canonical_slug', 'status', 'owner', 'advisory']];
        yield 'resolution' => [new SlugResolutionDTO($scope, 'hello', InputFormCanonicalityEnum::CANONICAL, $slug, $slug, MatchKindEnum::CURRENT, \Maatify\Slug\Lifecycle\Enum\BindingStatusEnum::ACTIVE, $slug, $identity->entity, 1), ['scope_profile', 'requested_segment', 'input_canonicality', 'lookup_canonical_slug', 'matched_slug', 'match_kind', 'binding_status', 'current_slug', 'entity', 'binding_revision']];
        yield 'transition intent' => [new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'hello'), ['mode', 'value']];
        yield 'transfer intent' => [new TransferReplacementIntentDTO(ClaimIntentModeEnum::GENERATED, 'Hello source'), ['mode', 'value']];
        yield 'mutation result' => [ContractFixtures::mutation(), ['operation_type', 'operation_key', 'replayed', 'before', 'after', 'affected_claims', 'previous_slug', 'current_slug', 'change_type', 'revision', 'history_events']];
        yield 'transition result' => [ContractFixtures::transition(), ['operation_type', 'operation_key', 'replayed', 'mode', 'target_created', 'source_result', 'target_result', 'source_before', 'source_after', 'target_before', 'target_after', 'source_claim', 'target_claim', 'source_revision', 'target_revision', 'history_events']];
        yield 'transfer result' => [ContractFixtures::transfer(), ['operation_type', 'operation_key', 'replayed', 'source_result', 'target_result', 'transferred_claim', 'source_replacement_result', 'source_revision', 'target_revision', 'history_events']];
        yield 'adoption result' => [ContractFixtures::adoption(), ['operation_type', 'operation_key', 'replayed', 'before', 'after', 'adopted_claim', 'history_event']];
    }

    /** @param list<string> $keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('dtoCases')]
    public function testEachPublicDTOSerializesItsExactTopLevelShape(\JsonSerializable $dto, array $keys): void
    {
        $serialized = $this->serializedObject($dto);

        self::assertSame($keys, array_keys($serialized));
        json_encode($dto, JSON_THROW_ON_ERROR);
    }

    public function testCurrentSlugRejectsRevisionDifferentFromBinding(): void
    {
        $binding = ContractFixtures::binding(ContractFixtures::identity(1), 100);
        $claim = $binding->currentClaim;
        self::assertNotNull($claim);

        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        new CurrentSlugDTO($binding, $claim, $binding->state->revision + 1);
    }

    /** @return iterable<string, array{BindingDTO, RegistryClaimDTO}> */
    public static function nonCurrentClaimCases(): iterable
    {
        $identity = ContractFixtures::identity(1);
        $binding = ContractFixtures::binding($identity, 100);
        $slug = ContractFixtures::slug('hello');
        $alias = ContractFixtures::claim($identity, $slug, 200, RegistryRoleEnum::ACTIVE_ALIAS);
        $foreign = ContractFixtures::claim(ContractFixtures::identity(2), $slug, 200);

        yield 'different claim ID' => [$binding, ContractFixtures::claim($identity, $slug, 201)];
        yield 'different owner with the same claim ID' => [$binding, $foreign];
        yield 'alias with the same claim ID' => [$binding, $alias];
        yield 'stale snapshot of the same claim' => [$binding, new RegistryClaimDTO(
            200,
            $identity,
            $slug,
            RegistryRoleEnum::CURRENT_CANONICAL,
            ContractFixtures::date(),
            ContractFixtures::date()->modify('-1 second'),
        )];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonCurrentClaimCases')]
    public function testCurrentSlugRejectsClaimDifferentFromBindingCurrentClaim(BindingDTO $binding, RegistryClaimDTO $claim): void
    {
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        new CurrentSlugDTO($binding, $claim, $binding->state->revision);
    }

    public function testCurrentSlugAcceptsAnEquivalentCurrentClaimSnapshot(): void
    {
        $binding = ContractFixtures::binding(ContractFixtures::identity(1), 100);
        $claim = ContractFixtures::claim(ContractFixtures::identity(1), ContractFixtures::slug('hello'), 200);

        $result = new CurrentSlugDTO($binding, $claim, $binding->state->revision);

        self::assertNotSame($binding->currentClaim, $claim);
        self::assertSame($claim, $result->claim);
    }

    /** @return iterable<string, array{RegistryClaimDTO}> */
    public static function invalidCurrentBindingClaims(): iterable
    {
        $slug = ContractFixtures::slug('hello');
        yield 'alias presented as current' => [ContractFixtures::claim(ContractFixtures::identity(1), $slug, 200, RegistryRoleEnum::ACTIVE_ALIAS)];
        yield 'current claim belonging to another Binding' => [ContractFixtures::claim(ContractFixtures::identity(2), $slug, 200)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCurrentBindingClaims')]
    public function testBindingRejectsInvalidCurrentClaim(RegistryClaimDTO $claim): void
    {
        $binding = ContractFixtures::binding(ContractFixtures::identity(1), 100);
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        new BindingDTO(100, $binding->identity, $binding->state, $claim, $binding->createdAt, $binding->updatedAt);
    }

    /** @return iterable<string, array{RegistryRoleEnum, bool}> */
    public static function invalidAliasStates(): iterable
    {
        yield 'canonical claim' => [RegistryRoleEnum::CURRENT_CANONICAL, true];
        yield 'resolvable retired alias' => [RegistryRoleEnum::RETIRED_ALIAS, true];
        yield 'non-resolvable active alias' => [RegistryRoleEnum::ACTIVE_ALIAS, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAliasStates')]
    public function testAliasRejectsInvalidRoleOrResolutionState(RegistryRoleEnum $role, bool $resolvable): void
    {
        $claim = ContractFixtures::claim(ContractFixtures::identity(1), ContractFixtures::slug('hello'), 200, $role);
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        new AliasDTO($claim, $resolvable, 1);
    }

    public function testAliasAcceptsNonResolvableRetiredClaim(): void
    {
        $claim = ContractFixtures::claim(ContractFixtures::identity(1), ContractFixtures::slug('hello'), 200, RegistryRoleEnum::RETIRED_ALIAS);
        self::assertFalse(new AliasDTO($claim, false, 1)->resolvableAsAlias);
    }

    /** @return iterable<string, array{bool}> */
    public static function invalidMutationListFields(): iterable
    {
        yield 'affected claims' => [true];
        yield 'history events' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMutationListFields')]
    public function testMutationRejectsObjectsOfTheWrongDTOType(bool $invalidClaim): void
    {
        $result = ContractFixtures::mutation();
        $claims = $invalidClaim ? [(object) ['id' => 200]] : $result->affectedClaims;
        $history = $invalidClaim ? $result->historyEvents : [(object) ['id' => 1, 'sequenceNo' => 1]];
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        (new \ReflectionClass(SlugMutationResultDTO::class))->newInstance(
            $result->operationType,
            $result->operationKey,
            $result->replayed,
            $result->before,
            $result->after,
            $claims,
            $result->previousSlug,
            $result->currentSlug,
            $result->changeType,
            $result->revision,
            $history,
        );
    }

    public function testBindingStateResultRejectsObjectsOfTheWrongHistoryDTOType(): void
    {
        $result = ContractFixtures::mutation();
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        (new \ReflectionClass(BindingStateResultDTO::class))->newInstance($result->before, $result->after, true, $result->revision, [(object) ['id' => 1, 'sequenceNo' => 1]]);
    }

    public function testScopeTransitionRejectsObjectsOfTheWrongHistoryDTOType(): void
    {
        $result = ContractFixtures::transition();
        $this->expectException(\Maatify\Slug\Exception\SlugInvalidArgumentException::class);
        (new \ReflectionClass(ScopeTransitionResultDTO::class))->newInstance(
            $result->operationType,
            $result->operationKey,
            $result->replayed,
            $result->mode,
            $result->targetCreated,
            $result->sourceResult,
            $result->targetResult,
            $result->sourceBefore,
            $result->sourceAfter,
            $result->targetBefore,
            $result->targetAfter,
            $result->sourceClaim,
            $result->targetClaim,
            $result->sourceRevision,
            $result->targetRevision,
            [(object) ['id' => 1, 'sequenceNo' => 1, 'bindingId' => $result->sourceAfter->id]],
        );
    }

    public function testInternalOwnershipClaimResultPreservesItsSerializedFields(): void
    {
        $binding = ContractFixtures::binding(ContractFixtures::identity(1), 100);
        $claim = $binding->currentClaim;
        if ($claim === null) {
            throw new \LogicException('Fixture binding must have a claim.');
        }
        $result = new OwnershipClaimResultDTO($binding, $claim, 2);
        $expected = ['binding' => $binding, 'claim' => $claim, 'attempts' => 2];

        self::assertInstanceOf(\JsonSerializable::class, $result);
        self::assertSame($expected, $result->jsonSerialize());
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function serializedObject(\JsonSerializable $dto): array
    {
        $serialized = $dto->jsonSerialize();
        if (! is_array($serialized) || array_is_list($serialized)) {
            self::fail('DTO must serialize as an object.');
        }
        $object = [];
        foreach ($serialized as $key => $value) {
            if (! is_string($key)) {
                self::fail('DTO object keys must be strings.');
            }
            $object[$key] = $value;
        }
        return $object;
    }
}
