<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTestingWeb\SubjectId;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SubjectId::class)]
final class SubjectIdTest
{
    public function exposesValueSourceAndStringRepresentation(): void
    {
        $subjectId = new SubjectId(value: 'user-42', source: SubjectIdSource::Authenticated);

        Assert::same($subjectId->value, 'user-42');
        Assert::same($subjectId->source, SubjectIdSource::Authenticated);
        Assert::same((string) $subjectId, 'user-42');
    }

    public function rejectsBlankValue(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Subject id must not be empty');

        new SubjectId(value: '  ', source: SubjectIdSource::Ephemeral);
    }
}
