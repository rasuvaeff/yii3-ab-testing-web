<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves nothing, so the sticky store stays untouched by the handler.
 *
 * @internal
 */
final readonly class PassthroughHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseInterface $response,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
