<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Rasuvaeff\Yii3AbTesting\AssignmentStore;

/**
 * In-memory {@see AssignmentStore} recording every `put()` for assertions.
 *
 * @internal
 */
final class ArrayAssignmentStore implements AssignmentStore
{
    /**
     * @var array<string, string>
     */
    public array $stored = [];

    /**
     * @var list<array{experiment: string, subjectId: string}>
     */
    public array $gets = [];

    /**
     * @var list<array{experiment: string, subjectId: string, variant: string}>
     */
    public array $puts = [];

    #[\Override]
    public function get(string $experiment, string $subjectId): ?string
    {
        $this->gets[] = ['experiment' => $experiment, 'subjectId' => $subjectId];

        return $this->stored[$experiment] ?? null;
    }

    #[\Override]
    public function put(string $experiment, string $subjectId, string $variant): void
    {
        $this->stored[$experiment] = $variant;
        $this->puts[] = ['experiment' => $experiment, 'subjectId' => $subjectId, 'variant' => $variant];
    }
}
