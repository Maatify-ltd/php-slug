<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Examples;

use InvalidArgumentException;
use LowercaseWordsV1Profile;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomProfileExampleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ob_start();
        try {
            require_once dirname(__DIR__, 3) . '/examples/custom-profile.php';
        } finally {
            ob_end_clean();
        }
    }

    public function testGenerationBoundsOutputAndRetainsCanonicalityAtTheBoundary(): void
    {
        $profile = new LowercaseWordsV1Profile();
        self::assertSame(str_repeat('a', 160), $profile->generateFromSource(str_repeat('a', 161))->slug->value);
        self::assertSame(str_repeat('a', 159), $profile->generateFromSource(str_repeat('a', 159) . ' b')->slug->value);
        self::assertSame(str_repeat('a', 160), $profile->canonicalizeClaim(str_repeat('A', 160))->slug->value);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $profile->canonicalizeLookup(str_repeat('a', 160))->canonicality);
    }

    #[DataProvider('unsafeSources')]
    public function testGenerationRejectsUnsafeInputBeforeNormalization(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LowercaseWordsV1Profile())->generateFromSource($source);
    }

    /** @return array<string, array{string}> */
    public static function unsafeSources(): array
    {
        return [
            'slash' => ['one/two'],
            'backslash' => ['one\\two'],
            'NUL' => ["one\0two"],
            'control' => ["one\ntwo"],
            'format' => ["one\u{200D}two"],
            'invalid UTF-8' => ["one\xFFtwo"],
            'empty normalized output' => ['!!!'],
        ];
    }

    #[DataProvider('invalidCanonicalInputs')]
    public function testInvalidClaimIsRejectedAndLookupIsInvalid(string $value): void
    {
        $profile = new LowercaseWordsV1Profile();
        $lookup = $profile->canonicalizeLookup($value);
        self::assertSame(InputFormCanonicalityEnum::INVALID, $lookup->canonicality);
        self::assertNull($lookup->canonicalSlug);
        $this->expectException(InvalidArgumentException::class);
        $profile->canonicalizeClaim($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidCanonicalInputs(): array
    {
        return self::unsafeSources() + ['overlong' => [str_repeat('a', 161)], 'empty' => ['']];
    }
}
