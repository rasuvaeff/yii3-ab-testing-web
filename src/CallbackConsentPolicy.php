<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Closure;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts an application consent callback to {@see ConsentPolicyInterface}.
 *
 * @api
 */
final readonly class CallbackConsentPolicy implements ConsentPolicyInterface
{
    /** @var Closure(ServerRequestInterface): bool */
    private Closure $callback;

    /**
     * @param callable(ServerRequestInterface): bool $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    #[\Override]
    public function allowsPersistence(ServerRequestInterface $request): bool
    {
        return ($this->callback)($request);
    }
}
