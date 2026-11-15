<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;

/** Selects one binding's history and optionally narrows it by event type. */
final readonly class HistoryCriteria
{
    /** Keeps pagination and optional event filtering explicit. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
        public ?HistoryEventTypeEnum $eventType = null,
    ) {}
}
