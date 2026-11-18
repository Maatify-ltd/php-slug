<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable mutation result containing before/after state and ordered history events. */
final readonly class BindingStateResultDTO implements JsonSerializable
{
    /**
     * A mutated participant advances exactly one revision and records History.
     * An unchanged participant retains its complete before state and records no History.
     *
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public ?BindingDTO $before,
        public BindingDTO $after,
        public bool $mutated,
        public int $revision,
        public array $historyEvents,
    ) {
        if ($revision < 0) {
            throw new SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($revision !== $after->state->revision) {
            throw new SlugInvalidArgumentException('Binding result revision must match the after state.');
        }
        $after->assertResultEvidence($before, $historyEvents);
        if ($mutated) {
            if ($historyEvents === [] || $revision - ($before?->state->revision ?? 0) !== 1) {
                throw new SlugInvalidArgumentException('A mutated participant requires History and one revision increment.');
            }
            return;
        }

        $flags = JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if (
            $before === null || $historyEvents !== []
            || ($before !== $after && json_encode($before, $flags) !== json_encode($after, $flags))
        ) {
            throw new SlugInvalidArgumentException('An unchanged participant requires identical before and after states and no History.');
        }
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
