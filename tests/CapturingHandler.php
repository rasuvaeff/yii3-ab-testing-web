<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records the request it receives and returns a fixed response.
 *
 * @internal
 */
final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $received = null;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->received = $request;

        return $this->response;
    }
}
