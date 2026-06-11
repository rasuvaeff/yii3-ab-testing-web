<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\AssignmentStore;

/**
 * Resolves an assignment with stickiness: a previously stored variant wins over a
 * fresh deterministic assignment, so a subject keeps their variant across weight
 * changes. Keeps {@see AbTesting::assign()} pure — the sticky layer lives here.
 *
 * Rules:
 *  - A forced variant always bypasses the store (QA override).
 *  - A disabled experiment returns its fallback and never reads or writes the
 *    store, so the kill switch always takes effect.
 *  - A stored variant is reused only while it is still a variant of the
 *    experiment; if it was removed, a fresh variant is assigned and stored.
 *  - Fallback results are not stored.
 *  - An assignment served from the store carries `isSticky = true`.
 *
 * @api
 */
final readonly class StickyAssignmentResolver
{
    public function __construct(
        private AbTesting $abTesting,
        private AssignmentStore $store,
    ) {}

    public function resolve(
        string $experiment,
        string $subjectId,
        ?string $forcedVariant = null,
        ?AssignmentContext $context = null,
    ): Assignment {
        if ($forcedVariant !== null) {
            return $this->abTesting->assign(
                experiment: $experiment,
                subjectId: $subjectId,
                forcedVariant: $forcedVariant,
                context: $context,
            );
        }

        $definition = $this->abTesting->getRegistry()->get($experiment);

        if ($definition->enabled) {
            $stored = $this->store->get($experiment, $subjectId);

            if ($stored !== null && isset($definition->variants[$stored])) {
                return new Assignment(
                    experiment: $experiment,
                    variant: $stored,
                    subjectId: $subjectId,
                    context: $context,
                    isSticky: true,
                );
            }
        }

        $assignment = $this->abTesting->assign(
            experiment: $experiment,
            subjectId: $subjectId,
            context: $context,
        );

        if (!$assignment->isFallback) {
            $this->store->put($experiment, $subjectId, $assignment->variant);
        }

        return $assignment;
    }
}
