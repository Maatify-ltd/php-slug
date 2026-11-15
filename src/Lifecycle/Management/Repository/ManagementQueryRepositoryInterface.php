<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;

/** Read-only repository boundary for paginated management projections. */
interface ManagementQueryRepositoryInterface
{
    /**
     * Returns aliases for one binding.
     *
     * @return PageResultDTO<AliasDTO>
     */
    public function listAliases(int $bindingId, PageRequest $request): PageResultDTO;

    /**
     * Returns history for one binding, optionally filtered by event type.
     *
     * @return PageResultDTO<HistoryEventDTO>
     */
    public function getHistory(int $bindingId, ?HistoryEventTypeEnum $eventType, PageRequest $request): PageResultDTO;

    /**
     * Returns registry claims for a scope, optionally narrowed by binding and role.
     *
     * @return PageResultDTO<RegistryClaimDTO>
     */
    public function inspectRegistry(int $scopeId, ?int $bindingId, ?RegistryRoleEnum $role, PageRequest $request): PageResultDTO;

    /**
     * Returns bindings matching the supplied scope and filters.
     *
     * @return PageResultDTO<BindingDTO>
     */
    public function searchBindings(int $scopeId, BindingSearchCriteria $criteria): PageResultDTO;

    /**
     * Returns registry claims matching the supplied scope and filters.
     *
     * @return PageResultDTO<RegistryClaimDTO>
     */
    public function searchRegistry(int $scopeId, RegistrySearchCriteria $criteria): PageResultDTO;

    /**
     * Returns all persisted Scopes matching the supplied search criteria.
     *
     * @return PageResultDTO<ScopeDTO>
     */
    public function searchScopes(ScopeSearchCriteria $criteria): PageResultDTO;

    /** Returns persisted operational counts for one Scope. */
    public function getScopeOperationalSummary(ScopeDTO $scope): ScopeOperationalSummaryDTO;

    /**
     * Returns package history using persisted event Scope snapshots for attribution.
     *
     * @return PageResultDTO<HistoryEventDTO>
     */
    public function searchHistory(HistorySearchCriteria $criteria): PageResultDTO;
}
