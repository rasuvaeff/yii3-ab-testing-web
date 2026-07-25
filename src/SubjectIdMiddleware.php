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
 *     middleware set `subjectId = userId`), it is left untouched — no cookie.
 *  2. Otherwise the `ab_id` cookie is read; a value the
 *     {@see SubjectIdGeneratorInterface} recognises as its own is reused —
 *     anything else (tampered, truncated, oversized) is discarded and
 *     regenerated, so arbitrary client-supplied strings never become subject
 *     ids in logs and analytics.
 *  3. Otherwise the generator mints a new opaque id (by default
 *     `random_bytes(16)` as hex) and a long-lived `HttpOnly`, `SameSite=Lax`
 *     cookie is set on the response.
 *
 * Because assignment is deterministic in `subjectId`, a stable id is enough to
 * keep an anonymous visitor in the same variant across visits — no variant is
 * stored. The id is opaque (not a UUID) and carries no personal data, but it is a
 * persistent identifier: set the cookie only after consent where required.
 *
 * @api
 */
final readonly class SubjectIdMiddleware implements MiddlewareInterface
{
    private SubjectIdGeneratorInterface $idGenerator;

    /**
     * @param ?SubjectIdGeneratorInterface $idGenerator null keeps the historical
     *                                                  format ({@see HexSubjectIdGenerator})
     */
    public function __construct(
        private string $cookieName = 'ab_id',
        private string $attribute = 'ab.subjectId',
        private DateInterval $maxAge = new DateInterval('P365D'),
        private bool $secure = true,
        private string $sameSite = Cookie::SAME_SITE_LAX,
        ?SubjectIdGeneratorInterface $idGenerator = null,
    ) {
        $this->idGenerator = $idGenerator ?? new HexSubjectIdGenerator();
    }

    /**
     * @throws \Random\RandomException when the platform CSPRNG is unavailable
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->stringOrNull($request->getAttribute($this->attribute)) !== null) {
            return $handler->handle($request);
        }

        $fromCookie = $this->validIdOrNull($request->getCookieParams()[$this->cookieName] ?? null);

        if ($fromCookie !== null) {
            return $handler->handle($request->withAttribute($this->attribute, $fromCookie));
        }

        $subjectId = $this->idGenerator->generate();
        $response = $handler->handle($request->withAttribute($this->attribute, $subjectId));

        $cookie = (new Cookie(
            name: $this->cookieName,
            value: $subjectId,
            secure: $this->secure,
            sameSite: $this->sameSite,
        ))->withMaxAge($this->maxAge);

        return $cookie->addToResponse($response);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function validIdOrNull(mixed $value): ?string
    {
        return \is_string($value) && $this->idGenerator->isValid($value) ? $value : null;
    }
}
