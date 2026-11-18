<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Service\Resolution;

use Maatify\Slug\Lifecycle\Consumer\Service\SlugAvailabilityChecker;
use Maatify\Slug\Lifecycle\Consumer\Service\SlugQueryServiceInterface;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryResolutionReaderInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Read-only resolution service combining profile canonicalization with registry state. */
final readonly class SlugQueryService implements SlugQueryServiceInterface
{
    /** Injects profile, registry, availability, and schema capability boundaries. */
    public function __construct(
        private SlugProfileRegistryInterface $profiles,
        private RegistryRepositoryInterface $registry,
        private SlugAvailabilityChecker $availability,
        private CapabilityGuardInterface $capabilities,
        private ?RegistryResolutionReaderInterface $resolutionReader = null,
    ) {}

    /** Returns advisory availability without reserving or mutating a slug. */
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->availability->check($criteria);
    }

    /** Returns the current claim for a binding, or null when no current claim exists. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        if ($binding === null || $binding->currentClaim === null) {
            return null;
        }

        return new CurrentSlugDTO($binding, $binding->currentClaim, $binding->state->revision);
    }

    /**
     * Resolves a valid lookup after read-only connection and schema verification.
     * Invalid input returns an unmatched result without touching persistence.
     */
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        $profile = $this->profiles->get($criteria->scopeProfile->expectedProfileKey);
        $lookup = $profile->canonicalizeLookup($criteria->decodedSegment);
        if ($lookup->canonicality === InputFormCanonicalityEnum::INVALID || $lookup->canonicalSlug === null) {
            return new SlugResolutionDTO(
                $criteria->scopeProfile,
                $criteria->decodedSegment,
                InputFormCanonicalityEnum::INVALID,
                null,
                null,
                MatchKindEnum::NONE,
                null,
                null,
                null,
                null,
            );
        }

        $this->capabilities->assertInstalledSchemaSupported();
        $observation = $this->resolutionReader === null
            ? $this->findResolution($criteria, $lookup->canonicalSlug)
            : $this->resolutionReader->findResolution($criteria->scopeProfile, $lookup->canonicalSlug);
        if ($observation === null) {
            return $this->none($criteria, InputFormCanonicalityEnum::NOT_APPLICABLE, $lookup->canonicalSlug);
        }

        $claim = $observation['claim'];
        $owner = $observation['owner'];
        if ($owner->currentClaim === null) {
            throw new SlugPersistenceInvariantException('Resolution claim owner Binding cannot be read.');
        }

        $matchKind = match ($claim->role) {
            RegistryRoleEnum::CURRENT_CANONICAL => MatchKindEnum::CURRENT,
            RegistryRoleEnum::ACTIVE_ALIAS => MatchKindEnum::ALIAS,
            RegistryRoleEnum::HISTORICAL_CANONICAL => MatchKindEnum::HISTORICAL,
            RegistryRoleEnum::RETIRED_ALIAS => MatchKindEnum::RETIRED_ALIAS,
        };

        return new SlugResolutionDTO(
            $criteria->scopeProfile,
            $criteria->decodedSegment,
            $lookup->canonicality,
            $lookup->canonicalSlug,
            $claim->slug,
            $matchKind,
            $owner->state->status,
            $owner->currentClaim->slug,
            $owner->identity->entity,
            $owner->state->revision,
        );
    }

    /**
     * Reads from a registry supplied directly by an existing adapter caller.
     *
     * @return array{claim: RegistryClaimDTO, owner: BindingDTO}|null
     */
    private function findResolution(ResolutionCriteria $criteria, Slug $slug): ?array
    {
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        $record = $scope === null ? null : $this->registry->findClaimByScopeSlug($scope->id, $slug);
        if ($record === null) {
            return null;
        }

        $claim = $record->toDto($this->profiles);
        $owner = $this->registry->binding($claim->binding);
        if ($owner === null) {
            throw new SlugPersistenceInvariantException('Resolution claim owner Binding cannot be read.');
        }

        return ['claim' => $claim, 'owner' => $owner];
    }

    /** Builds the stable unmatched result while preserving lookup canonicality. */
    private function none(ResolutionCriteria $criteria, InputFormCanonicalityEnum $canonicality, Slug $canonicalSlug): SlugResolutionDTO
    {
        return new SlugResolutionDTO(
            $criteria->scopeProfile,
            $criteria->decodedSegment,
            $canonicality,
            $canonicalSlug,
            null,
            MatchKindEnum::NONE,
            null,
            null,
            null,
            null,
        );
    }
}
