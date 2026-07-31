<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Backward-compatible policy for applications where consent is not required or
 * has already been enforced before the A/B testing middleware stack.
 *
 * @api
 */
final readonly class AllowAllConsentPolicy implements ConsentPolicyInterface
{
    #[\Override]
    public function allowsPersistence(ServerRequestInterface $request): bool
    {
        return true;
    }
}
