<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\AssignmentResolver;
use Rasuvaeff\Yii3AbTesting\AssignmentStore;

/**
 * Resolves an assignment with stickiness: a previously stored variant wins over a
 * fresh deterministic assignment, so a subject keeps their variant across
 * requests. The wrapped core {@see AssignmentResolver} remains side-effect free.
 *
 * Rules:
 *  - A disabled experiment returns its fallback and never reads or writes the
 *    store, so the kill switch always takes effect.
 *  - In an enabled experiment, a forced variant bypasses targeting and the
 *    store (QA override).
 *  - A targeting mismatch returns the core fallback and never reads or writes
 *    the store, so a previous sticky assignment cannot bypass targeting.
 *  - Configuration-aware stores reuse a variant only for the same experiment
 *    configuration id. Legacy stores retain their original behavior.
 *  - Fallback results are not stored.
 *  - An assignment served from the store carries `isSticky = true`.
 *
 * @api
 */
final readonly class StickyAssignmentResolver implements AssignmentResolver
{
    public function __construct(
        private AssignmentResolver $resolver,
        private AssignmentStore $store,
    ) {}

    #[\Override]
    public function resolve(
        string $experiment,
        string $subjectId,
        ?string $forcedVariant = null,
        ?AssignmentContext $context = null,
    ): Assignment {
        if ($this->resolver instanceof AbTesting && !$this->resolver->getRegistry()->get($experiment)->enabled) {
            return $this->resolver->resolve(
                experiment: $experiment,
                subjectId: $subjectId,
                context: $context,
            );
        }

        $assignment = $this->resolver->resolve(
            experiment: $experiment,
            subjectId: $subjectId,
            forcedVariant: $forcedVariant,
            context: $context,
        );

        if ($assignment->isFallback || $assignment->isForced || $assignment->isSticky) {
            return $assignment;
        }

        $stored = $this->store instanceof ConfigurationAwareAssignmentStore
            ? $this->store->getForConfiguration($experiment, $subjectId, $assignment->configurationId)
            : $this->store->get($experiment, $subjectId);

        if ($stored !== null
            && $this->resolver instanceof AbTesting
            && !isset($this->resolver->getRegistry()->get($experiment)->variants[$stored])) {
            $stored = null;
        }

        if ($stored !== null) {
            return new Assignment(
                experiment: $assignment->experiment,
                variant: $stored,
                subjectId: $assignment->subjectId,
                context: $assignment->context,
                isSticky: true,
                configurationId: $assignment->configurationId,
            );
        }

        if ($this->store instanceof ConfigurationAwareAssignmentStore) {
            $this->store->putForConfiguration(
                experiment: $experiment,
                subjectId: $subjectId,
                variant: $assignment->variant,
                configurationId: $assignment->configurationId,
            );
        } else {
            $this->store->put($experiment, $subjectId, $assignment->variant);
        }

        return $assignment;
    }
}
