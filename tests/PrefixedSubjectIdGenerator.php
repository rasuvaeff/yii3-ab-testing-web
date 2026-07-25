<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Rasuvaeff\Yii3AbTestingWeb\SubjectIdGeneratorInterface;

/**
 * A project-specific scheme the default generator would reject outright —
 * the case that proves generation and validation must travel together.
 */
final class PrefixedSubjectIdGenerator implements SubjectIdGeneratorInterface
{
    public int $calls = 0;

    #[\Override]
    public function generate(): string
    {
        ++$this->calls;

        return 'sub_' . str_pad((string) $this->calls, 4, '0', STR_PAD_LEFT);
    }

    #[\Override]
    public function isValid(string $id): bool
    {
        return preg_match('/^sub_\d{4}$/', $id) === 1;
    }
}
