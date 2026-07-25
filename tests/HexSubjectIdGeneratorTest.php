<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Rasuvaeff\Yii3AbTestingWeb\HexSubjectIdGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(HexSubjectIdGenerator::class)]
final class HexSubjectIdGeneratorTest
{
    public function generatesThirtyTwoLowercaseHexCharacters(): void
    {
        Assert::same(preg_match('/^[0-9a-f]{32}$/', (new HexSubjectIdGenerator())->generate()), 1);
    }

    public function acceptsItsOwnOutput(): void
    {
        $generator = new HexSubjectIdGenerator();

        Assert::true($generator->isValid($generator->generate()));
    }

    public function idsAreDistinct(): void
    {
        $generator = new HexSubjectIdGenerator();
        $ids = [];

        for ($i = 0; $i < 100; ++$i) {
            $ids[$generator->generate()] = true;
        }

        Assert::same(count($ids), 100);
    }

    #[DataProvider('rejectedValuesProvider')]
    public function rejectsAnythingElse(string $value): void
    {
        // these arrive from an attacker-controlled cookie
        Assert::false((new HexSubjectIdGenerator())->isValid($value));
    }

    public static function rejectedValuesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 31)];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'uppercase' => [str_repeat('A', 32)];
        yield 'non-hex' => [str_repeat('z', 32)];
        yield 'injection attempt' => ['<script>alert(1)</script>'];
        yield 'trailing newline' => [str_repeat('a', 32) . "\n"];
    }
}
