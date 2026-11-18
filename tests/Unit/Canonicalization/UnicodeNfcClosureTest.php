<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Canonicalization;

use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeNfcClosureTest extends TestCase
{
    #[DataProvider('caseConversions')]
    public function testPublicOperationsReturnTheNormalizedLowercaseToken(string $input, string $expected): void
    {
        self::assertTrue(Normalizer::isNormalized($input, Normalizer::FORM_C));
        $text = SlugTextServiceFactory::create(SlugProfileRegistryFactory::createBuiltIn());
        $key = new SlugProfileKey('unicode-v1');
        self::assertSame($expected, $text->generateFromSource($key, $input)->slug->value);
        self::assertSame($expected, $text->canonicalizeClaim($key, $input)->slug->value);
        $lookup = $text->canonicalizeLookup($key, $input);
        self::assertSame(InputFormCanonicalityEnum::NON_CANONICAL, $lookup->canonicality);
        self::assertSame($expected, $lookup->canonicalSlug?->value);
        self::assertTrue(Normalizer::isNormalized($expected, Normalizer::FORM_C));
        self::assertSame($expected, $text->generateFromSource($key, $expected)->slug->value);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $text->canonicalizeLookup($key, $expected)->canonicality);
    }

    #[DataProvider('caseConversions')]
    public function testTheLengthBoundaryUsesTheFinalNormalizedToken(string $input, string $expected): void
    {
        $text = SlugTextServiceFactory::create(SlugProfileRegistryFactory::createBuiltIn());
        $key = new SlugProfileKey('unicode-v1');
        $limit = str_repeat($expected, 160);
        self::assertSame($limit, $text->generateFromSource($key, str_repeat($input, 160))->slug->value);
        self::assertSame($limit, $text->canonicalizeClaim($key, str_repeat($input, 160))->slug->value);
        self::assertSame($limit, $text->canonicalizeLookup($key, str_repeat($input, 160))->canonicalSlug?->value);
        self::assertSame($limit, $text->generateFromSource($key, str_repeat($input, 161))->slug->value);
        self::assertSame(InputFormCanonicalityEnum::INVALID, $text->canonicalizeLookup($key, str_repeat($input, 161))->canonicality);
        $this->expectException(SlugInvalidArgumentException::class);
        $text->canonicalizeClaim($key, str_repeat($input, 161));
    }

    /** @return array<string, array{string, string}> */
    public static function caseConversions(): array
    {
        return ['Latin caron' => ["J\u{030C}", "\u{01F0}"], 'Greek accents' => ["\u{03AA}\u{0301}", "\u{0390}"]];
    }
}
