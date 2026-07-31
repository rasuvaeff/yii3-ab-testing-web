<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use DateInterval;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Cookies\Cookie;

/**
 * Establishes a stable subject id for A/B assignment and exposes it as a request
 * attribute.
 *
 * Resolution order:
 *  1. If the configured attribute is already a non-empty string (an upstream auth
 *     middleware set `subjectId = userId`), the configured identity-transition
 *     strategy chooses it or an existing anonymous id.
 *  2. When persistence is allowed, the `ab_id` cookie is read; a value the
 *     {@see SubjectIdGeneratorInterface} recognises as its own is reused —
 *     anything else (tampered, truncated, oversized) is discarded and
 *     regenerated, so arbitrary client-supplied strings never become subject
 *     ids in logs and analytics.
 *  3. Otherwise the generator mints a new opaque id (by default
 *     `random_bytes(16)` as hex). With consent it is persisted in a long-lived
 *     `HttpOnly`, `SameSite=Lax` cookie; without consent it is request-only.
 *
 * Because assignment is deterministic in `subjectId`, a stable id is enough to
 * keep an anonymous visitor in the same variant across visits — no variant is
 * stored. The id is opaque (not a UUID) and carries no personal data, but it is a
 * persistent identifier. {@see ConsentPolicyInterface} is checked before a
 * persistent identity is read or created.
 *
 * @api
 */
final readonly class SubjectIdMiddleware implements MiddlewareInterface
{
    private SubjectIdGeneratorInterface $idGenerator;

    private SubjectIdRequestAccessor $accessor;

    /**
     * @param ?SubjectIdGeneratorInterface $idGenerator null keeps the historical
     *                                                  format ({@see HexSubjectIdGenerator})
     */
    public function __construct(
        private string $cookieName = 'ab_id',
        string $attribute = 'ab.subjectId',
        private DateInterval $maxAge = new DateInterval('P365D'),
        private bool $secure = true,
        private string $sameSite = Cookie::SAME_SITE_LAX,
        ?SubjectIdGeneratorInterface $idGenerator = null,
        private ConsentPolicyInterface $consentPolicy = new AllowAllConsentPolicy(),
        private AnonymousToAuthenticatedStrategy $identityTransition = AnonymousToAuthenticatedStrategy::MigrateAssignments,
    ) {
        $this->idGenerator = $idGenerator ?? new HexSubjectIdGenerator();
        $this->accessor = new SubjectIdRequestAccessor(attribute: $attribute);
    }

    /**
     * @throws \Random\RandomException when the platform CSPRNG is unavailable
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $existing = $this->accessor->get($request);
        $persistenceAllowed = $this->consentPolicy->allowsPersistence($request);
        $anonymous = $persistenceAllowed
            ? $this->anonymousIdFromCookie($request)
            : null;

        if ($existing instanceof SubjectId) {
            $subjectId = $this->transitionIdentity(existing: $existing, anonymous: $anonymous);

            return $handler->handle($this->accessor->with($request, $subjectId));
        }

        if ($anonymous instanceof SubjectId) {
            return $handler->handle($this->accessor->with($request, $anonymous));
        }

        $subjectId = new SubjectId(
            value: $this->idGenerator->generate(),
            source: $persistenceAllowed ? SubjectIdSource::Anonymous : SubjectIdSource::Ephemeral,
        );
        $response = $handler->handle($this->accessor->with($request, $subjectId));

        if (!$persistenceAllowed) {
            return $response;
        }

        $cookie = (new Cookie(
            name: $this->cookieName,
            value: $subjectId->value,
            secure: $this->secure,
            sameSite: $this->sameSite,
        ))->withMaxAge($this->maxAge);

        return $cookie->addToResponse($response);
    }

    private function anonymousIdFromCookie(ServerRequestInterface $request): ?SubjectId
    {
        /** @var mixed $value */
        $value = $request->getCookieParams()[$this->cookieName] ?? null;

        return \is_string($value) && $this->idGenerator->isValid($value)
            ? new SubjectId(value: $value, source: SubjectIdSource::Anonymous)
            : null;
    }

    private function transitionIdentity(SubjectId $existing, ?SubjectId $anonymous): SubjectId
    {
        if ($existing->source !== SubjectIdSource::Authenticated || !$anonymous instanceof SubjectId) {
            return $existing;
        }

        if ($this->identityTransition === AnonymousToAuthenticatedStrategy::KeepAnonymousId) {
            return $anonymous;
        }

        return new SubjectId(
            value: $existing->value,
            source: SubjectIdSource::Authenticated,
            preserveAnonymousAssignments: $this->identityTransition === AnonymousToAuthenticatedStrategy::MigrateAssignments,
        );
    }
}
