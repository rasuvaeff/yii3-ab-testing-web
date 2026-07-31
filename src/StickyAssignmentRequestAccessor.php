<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\AssignmentResolver;
use RuntimeException;

/**
 * Typed access to the request-scoped sticky resolver and cookie store.
 *
 * @api
 */
final readonly class StickyAssignmentRequestAccessor
{
    public function __construct(
        private string $resolverAttribute = 'ab.assignmentResolver',
        private string $storeAttribute = 'ab.assignmentStore',
    ) {}

    public function resolver(ServerRequestInterface $request): AssignmentResolver
    {
        /** @var mixed $resolver */
        $resolver = $request->getAttribute($this->resolverAttribute);

        return $resolver instanceof AssignmentResolver
            ? $resolver
            : throw new RuntimeException('Sticky assignment resolver is not available on the request');
    }

    public function store(ServerRequestInterface $request): CookieAssignmentStore
    {
        /** @var mixed $store */
        $store = $request->getAttribute($this->storeAttribute);

        return $store instanceof CookieAssignmentStore
            ? $store
            : throw new RuntimeException('Cookie assignment store is not available on the request');
    }

    public function with(
        ServerRequestInterface $request,
        AssignmentResolver $resolver,
        CookieAssignmentStore $store,
    ): ServerRequestInterface {
        return $request
            ->withAttribute($this->resolverAttribute, $resolver)
            ->withAttribute($this->storeAttribute, $store);
    }
}
