<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTesting\AssignmentResolver;
use Yiisoft\Cookies\CookieSigner;

/**
 * Creates the request-scoped signed-cookie store and sticky resolver, exposes
 * both through {@see StickyAssignmentRequestAccessor}, then applies store
 * changes to the response.
 *
 * Run after {@see SubjectIdMiddleware}.
 *
 * @api
 */
final readonly class StickyAssignmentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AssignmentResolver $resolver,
        private CookieSigner $signer,
        private ConsentPolicyInterface $consentPolicy = new AllowAllConsentPolicy(),
        private SubjectIdRequestAccessor $subjectIdAccessor = new SubjectIdRequestAccessor(),
        private StickyAssignmentRequestAccessor $stickyAccessor = new StickyAssignmentRequestAccessor(),
        private string $cookieName = 'ab_variants',
        private int $maxEntries = 50,
        private int $maxCookieBytes = 3800,
    ) {}

    /**
     * @throws \JsonException
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $subjectId = $this->subjectIdAccessor->require($request);
        $persistenceAllowed = $this->consentPolicy->allowsPersistence($request);
        $keepExisting = $persistenceAllowed
            && ($subjectId->source !== SubjectIdSource::Authenticated || $subjectId->preserveAnonymousAssignments);

        $store = $keepExisting
            ? CookieAssignmentStore::fromRequest(
                request: $request,
                signer: $this->signer,
                cookieName: $this->cookieName,
                maxEntries: $this->maxEntries,
                maxCookieBytes: $this->maxCookieBytes,
            )
            : new CookieAssignmentStore(
                signer: $this->signer,
                cookieName: $this->cookieName,
                dirty: $persistenceAllowed && isset($request->getCookieParams()[$this->cookieName]),
                maxEntries: $this->maxEntries,
                maxCookieBytes: $this->maxCookieBytes,
            );

        $stickyResolver = new StickyAssignmentResolver(resolver: $this->resolver, store: $store);
        $response = $handler->handle($this->stickyAccessor->with($request, $stickyResolver, $store));

        if ($persistenceAllowed) {
            return $store->applyToResponse($response);
        }

        // withdrawn consent must clear what earlier consent wrote, not merely
        // stop touching it; no cookie in the request means no header at all
        return isset($request->getCookieParams()[$this->cookieName])
            ? $store->applyDeletionToResponse($response)
            : $response;
    }
}
