<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

/**
 * @internal
 */
final class StickyResolvingHandler implements RequestHandlerInterface
{
    public ?Assignment $assignment = null;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly string $experiment = 'checkout-button',
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $subjectId = (new SubjectIdRequestAccessor())->require($request);
        $resolver = (new StickyAssignmentRequestAccessor())->resolver($request);
        $this->assignment = $resolver->resolve(
            experiment: $this->experiment,
            subjectId: $subjectId->value,
        );

        return $this->response;
    }
}
