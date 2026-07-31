<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use InvalidArgumentException;

/**
 * Typed subject identity carried through a PSR-7 request.
 *
 * @api
 */
final readonly class SubjectId implements \Stringable
{
    public function __construct(
        public string $value,
        public SubjectIdSource $source,
        public bool $preserveAnonymousAssignments = false,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Subject id must not be empty');
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
