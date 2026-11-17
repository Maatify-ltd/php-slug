<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Allocation;

use Maatify\Slug\Canonicalization\Service\CanonicalSlugRules;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\Service\SlugInputValidator;

/** Generates bounded numeric suffix candidates for generated slug allocation. */
final class SuffixCandidateGenerator
{
    /**
     * Returns the base and bounded `-2` through `-1000` candidates.
     *
     * @return non-empty-list<string>
     */
    public static function prepare(string $base): array
    {
        SlugInputValidator::assertSafe($base, 'base');
        SlugInputValidator::assertCodePointLength($base, CanonicalSlugRules::MAX_CODE_POINTS, 'base');
        if ($base === '') {
            throw new SlugInvalidArgumentException('The generated base must be non-empty.');
        }

        $candidates = [$base];
        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            $suffixText = '-' . $suffix;
            $baseLimit = CanonicalSlugRules::MAX_CODE_POINTS - SlugInputValidator::codePointLength($suffixText);
            $prefix = rtrim(mb_substr($base, 0, $baseLimit, 'UTF-8'), '-');
            if ($prefix === '') {
                continue;
            }
            $candidates[] = $prefix . $suffixText;
        }

        return $candidates;
    }

    private function __construct() {}
}
