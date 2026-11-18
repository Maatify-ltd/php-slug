<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;

/** Immutable mutation result containing before/after state and ordered history events. */
final readonly class BindingStateResultDTO implements JsonSerializable
{
    /** @param list<HistoryEventDTO> $historyEvents */
    public function __construct(
        public ?BindingDTO $before,
        public BindingDTO $after,
        public bool $mutated,
        public int $revision,
        public array $historyEvents,
    ) {
        if ($revision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($revision !== $after->state->revision) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Binding result revision must match the after state.');
        }
        $after->assertResultEvidence($before, $historyEvents);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'before' => $this->before,
            'after' => $this->after,
            'mutated' => $this->mutated,
            'revision' => $this->revision,
            'history_events' => $this->historyEvents,
        ];
    }
}
