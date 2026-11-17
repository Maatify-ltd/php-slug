<?php

declare(strict_types=1);

use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Small application-owned profile with a stable, versioned semantic contract. */
final class LowercaseWordsV1Profile implements SlugProfileInterface
{
    private const int MAX_CODE_POINTS = 160;

    private SlugProfileKey $profileKey;

    public function __construct()
    {
        $this->profileKey = new SlugProfileKey('lowercase-words-v1');
    }

    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    /**
     * Generates lowercase ASCII letters/digits separated by single hyphens.
     * Rejects invalid UTF-8, controls, format characters, and path separators
     * before replacing other non-alphanumeric runs with hyphens. Trims edge
     * hyphens, truncates to 160 code points, then removes any trailing hyphen.
     *
     * @throws InvalidArgumentException If the source is unsafe or no slug remains.
     */
    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        $this->assertSafe($source);
        $value = trim(strtolower($source));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = rtrim(mb_substr($value, 0, self::MAX_CODE_POINTS, 'UTF-8'), '-');

        return new GeneratedSlugDTO($this->profileKey, $source, Slug::fromProfile($this, $value));
    }

    /**
     * Lowercases ASCII letters in an exact candidate without source cleanup.
     * Requires a safe, canonical alphanumeric/hyphen value of at most 160 code
     * points; it does not trim or truncate. Retains the original input in the DTO.
     *
     * @throws InvalidArgumentException If the lowercased candidate violates these input rules.
     */
    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        $this->assertSafe($candidate);
        $value = strtolower($candidate);
        $this->assertCanonicalSlug($value);

        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $value));
    }

    /**
     * Classifies a decoded segment using ASCII lowercase only, without cleanup.
     * Input that is unsafe, malformed, empty, or longer than 160 code points
     * returns INVALID with canonicalSlug = null. Otherwise returns the slug with
     * CANONICAL for unchanged input or NON_CANONICAL when letter case changed.
     */
    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        $value = strtolower($decodedSegment);
        try {
            $this->assertCanonicalSlug($value);
        } catch (InvalidArgumentException) {
            return new LookupCanonicalizationDTO($this->profileKey, $decodedSegment, InputFormCanonicalityEnum::INVALID, null);
        }

        return new LookupCanonicalizationDTO(
            $this->profileKey,
            $decodedSegment,
            $value === $decodedSegment ? InputFormCanonicalityEnum::CANONICAL : InputFormCanonicalityEnum::NON_CANONICAL,
            Slug::fromProfile($this, $value),
        );
    }

    /**
     * Requires non-empty lowercase ASCII alphanumeric groups separated by
     * single hyphens, at most 160 code points, without transforming the value.
     * Rejects invalid UTF-8, controls, format characters, and path separators.
     *
     * @throws InvalidArgumentException If safety, grammar, or length validation fails.
     */
    public function assertCanonicalSlug(string $candidate): void
    {
        $this->assertSafe($candidate);
        if (mb_strlen($candidate, 'UTF-8') > self::MAX_CODE_POINTS) {
            throw new InvalidArgumentException('The custom profile permits at most 160 Unicode code points.');
        }
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $candidate) !== 1) {
            throw new InvalidArgumentException('The custom profile requires lowercase hyphen-separated words.');
        }
    }

    /** Reject unsafe source bytes before a lossy transformation can hide them. */
    private function assertSafe(string $value): void
    {
        if (preg_match('//u', $value) !== 1
            || preg_match('/[\x00\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1
            || strpbrk($value, '/\\') !== false) {
            throw new InvalidArgumentException('The custom profile rejects invalid UTF-8, controls, format characters, and path separators.');
        }
    }
}

$profiles = SlugProfileRegistryFactory::createBuiltIn();
$profile = new LowercaseWordsV1Profile();
$profiles->register($profile);
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource($profile->key(), 'Hello, Custom Profile!');

if ($result->slug->value !== 'hello-custom-profile') {
    throw new RuntimeException(sprintf('Unexpected custom slug: %s', $result->slug->value));
}

echo "CUSTOM_PROFILE_EXAMPLE=hello-custom-profile PASS\n";
