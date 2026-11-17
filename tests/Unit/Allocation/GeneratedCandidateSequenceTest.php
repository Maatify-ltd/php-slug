<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Tests\Support\GeneratedFixtureProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratedCandidateSequenceTest extends TestCase
{
    public function testUsesTheAcceptedBoundedOrderWithoutMinusOne(): void
    {
        $sequence = (new GeneratedCandidateSequence())->fromSource(new TestSlugProfile(), 'Product');

        self::assertCount(1000, $sequence);
        self::assertSame('product', $sequence[0]->value);
        self::assertSame('product-2', $sequence[1]->value);
        self::assertSame('product-9', $sequence[8]->value);
        self::assertSame('product-10', $sequence[9]->value);
        self::assertSame('product-1000', $sequence[999]->value);
        self::assertNotContains('product-1', array_map(static fn($slug): string => $slug->value, $sequence));
    }

    public function testLengthBoundaryPreservesTheBoundedSuffixAlgorithm(): void
    {
        $profile = new TestSlugProfile(new SlugProfileKey('ascii-v1'));
        $sequence = (new GeneratedCandidateSequence())->fromSource($profile, str_repeat('a', 160));

        self::assertSame(160, strlen($sequence[0]->value));
        self::assertSame(160, strlen($sequence[1]->value));
        self::assertSame(str_repeat('a', 158) . '-2', $sequence[1]->value);
        self::assertCount(1000, $sequence);
    }

    public function testCustomPunctuationIsNotRestrictedToBuiltInGrammar(): void
    {
        $profile = new GeneratedFixtureProfile('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/');
        $sequence = (new GeneratedCandidateSequence())->fromSource($profile, 'hello.world');

        self::assertCount(1000, $sequence);
        self::assertSame('hello.world', $sequence[0]->value);
        self::assertSame('hello.world-2', $sequence[1]->value);
    }

    #[DataProvider('validationExceptionModes')]
    public function testProfileCanRejectAllSuffixesWithoutRejectingItsBase(bool $packageException): void
    {
        $profile = new GeneratedFixtureProfile('/\A[a-z]+\z/', $packageException);
        $sequence = (new GeneratedCandidateSequence())->fromSource($profile, 'hello');

        self::assertSame(['hello'], array_map(static fn($slug): string => $slug->value, $sequence));
    }

    public function testRejectedSuffixDoesNotHideLaterValidCandidates(): void
    {
        $profile = new GeneratedFixtureProfile('/\Ahello(?:-3)?\z/');
        $sequence = (new GeneratedCandidateSequence())->fromSource($profile, 'hello');

        self::assertSame(['hello', 'hello-3'], array_map(static fn($slug): string => $slug->value, $sequence));
    }

    public function testUnexpectedProfileFailurePropagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected profile failure.');

        (new GeneratedCandidateSequence())->fromSource(new GeneratedFixtureProfile('/.*/', false, true), 'hello');
    }

    #[DataProvider('unsafeGeneratedBases')]
    public function testCustomGrammarCannotBypassSharedGeneratedBaseBoundaries(string $base): void
    {
        $this->expectException(SlugInvalidArgumentException::class);

        (new GeneratedCandidateSequence())->fromSource(new GeneratedFixtureProfile('/.*/s'), $base);
    }

    /** @return array<string, array{string}> */
    public static function unsafeGeneratedBases(): array
    {
        return [
            'empty' => [''],
            'slash' => ['one/two'],
            'backslash' => ['one\\two'],
            'control' => ["one\0two"],
            'format' => ["one\u{200D}two"],
            'invalid UTF-8' => ["one\xFFtwo"],
            'overlong' => [str_repeat('a', 161)],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function validationExceptionModes(): array
    {
        return ['native validation exception' => [false], 'package validation exception' => [true]];
    }
}
