<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether A/B testing may use persistent browser identifiers for a
 * request.
 *
 * @api
 */
interface ConsentPolicyInterface
{
    public function allowsPersistence(ServerRequestInterface $request): bool;
}
