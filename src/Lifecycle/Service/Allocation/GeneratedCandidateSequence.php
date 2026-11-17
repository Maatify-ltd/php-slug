<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Allocation;

use InvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Exception\SlugValidationException;

/**
 * Applies bounded suffix preparation to the selected Profile and returns
 * accepted Slug values in allocation order before mutation locks are taken.
 */
final class GeneratedCandidateSequence
{
    /**
     * Returns validated candidates in allocation order, beginning with the generated base.
     *
     * @return non-empty-list<Slug>
     */
    public function fromSource(SlugProfileInterface $profile, string $source): array
    {
        $base = $profile->generateFromSource($source)->slug;

        $values = SuffixCandidateGenerator::prepare($base->value);
        $candidates = [$base];
        foreach (array_slice($values, 1) as $value) {
            try {
                $candidates[] = Slug::fromProfile($profile, $value);
            } catch (InvalidArgumentException|SlugValidationException) {
                // A custom grammar may reject individual suffixes or all of them.
                // Unexpected profile failures must still reach the caller.
                continue;
            }
        }
        return $candidates;
    }
}
