<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

/**
 * Produces the subject id used for A/B assignment — and validates it coming
 * back from the cookie.
 *
 * Both halves belong to one contract on purpose. The cookie value is
 * attacker-controlled, so {@see SubjectIdMiddleware} only reuses a value the
 * generator recognises as its own; a generator that changed the format without
 * changing the check would have every request reject its own cookie, mint a
 * fresh id and — because assignment is deterministic in the subject id — flip
 * the visitor's variant on every page view.
 *
 * @api
 */
interface SubjectIdGeneratorInterface
{
    /**
     * @return non-empty-string
     */
    public function generate(): string;

    /**
     * Whether a value read from the cookie was produced by this generator.
     * Reject anything tampered, truncated or oversized: a value that passes
     * here becomes the subject id in logs and analytics.
     */
    public function isValid(string $id): bool;
}
