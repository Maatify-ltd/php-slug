<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Support;

use InvalidArgumentException;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use RuntimeException;

/** Profile-owned grammars that deliberately differ from the built-in profiles. */
final class GeneratedFixtureProfile implements SlugProfileInterface
{
    public function __construct(
        private readonly string $pattern,
        private readonly bool $packageException = false,
        private readonly bool $brokenSuffixValidator = false,
    ) {}

    public function key(): SlugProfileKey
    {
        return new SlugProfileKey('generated-fixture-v1');
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
        return new LookupCanonicalizationDTO($this->key(), $decodedSegment, InputFormCanonicalityEnum::CANONICAL, Slug::fromProfile($this, $decodedSegment));
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        if ($this->brokenSuffixValidator && str_ends_with($candidate, '-2')) {
            throw new RuntimeException('Unexpected profile failure.');
        }
        if (preg_match($this->pattern, $candidate) !== 1) {
            if ($this->packageException) {
                throw new SlugInvalidArgumentException('Rejected by fixture grammar.');
            }
            throw new InvalidArgumentException('Rejected by fixture grammar.');
        }
    }
}
