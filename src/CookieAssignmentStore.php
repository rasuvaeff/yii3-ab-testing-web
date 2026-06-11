<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use DateInterval;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\AssignmentStore;
use Rasuvaeff\Yii3AbTesting\ExperimentRegistry;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieSigner;

/**
 * Sticky-variant store backed by a single signed cookie holding a JSON
 * `{experiment: variant}` map. Survives weight/variant changes, unlike pure
 * deterministic assignment.
 *
 * The store is request-scoped: build it from the incoming request with
 * {@see fromRequest()}, resolve assignments through it, then write any new
 * variants back with {@see applyToResponse()} (typically from a middleware).
 *
 * The cookie is browser-scoped, so the `$subjectId` argument of {@see get()} /
 * {@see put()} is ignored — the cookie itself identifies the subject. A visitor
 * who was anonymous and then logs in therefore keeps the variants stored under
 * their anonymous identity; that is intentional.
 *
 * @api
 */
final class CookieAssignmentStore implements AssignmentStore
{
    /**
     * @param array<string, string> $variants
     */
    public function __construct(
        private readonly CookieSigner $signer,
        private readonly string $cookieName = 'ab_variants',
        private array $variants = [],
        private bool $dirty = false,
        private readonly DateInterval $maxAge = new DateInterval('P90D'),
        private readonly bool $secure = true,
        private readonly string $sameSite = Cookie::SAME_SITE_LAX,
    ) {}

    /**
     * Builds a store from the signed cookie on the request. A missing, tampered,
     * or malformed cookie yields an empty (clean) store.
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        CookieSigner $signer,
        string $cookieName = 'ab_variants',
    ): self {
        return new self(
            signer: $signer,
            cookieName: $cookieName,
            variants: self::decode($request->getCookieParams()[$cookieName] ?? null, $signer, $cookieName),
        );
    }

    #[\Override]
    public function get(string $experiment, string $subjectId): ?string
    {
        return $this->variants[$experiment] ?? null;
    }

    #[\Override]
    public function put(string $experiment, string $subjectId, string $variant): void
    {
        $this->variants[$experiment] = $variant;
        $this->dirty = true;
    }

    /**
     * Drops stored variants of experiments that no longer exist, so entries of
     * removed experiments do not ride along in the cookie for its whole max-age
     * (and the cookie stays clear of the 4 KB browser limit). Call before
     * {@see applyToResponse()}; the rewrite happens only when something was
     * actually removed (or stored).
     */
    public function prune(ExperimentRegistry $registry): void
    {
        foreach (array_keys($this->variants) as $experiment) {
            if (!$registry->has($experiment)) {
                unset($this->variants[$experiment]);
                $this->dirty = true;
            }
        }
    }

    /**
     * Writes the signed cookie to the response when a new variant was stored.
     *
     * @throws \JsonException
     */
    public function applyToResponse(ResponseInterface $response): ResponseInterface
    {
        if (!$this->dirty) {
            return $response;
        }

        $cookie = (new Cookie(
            name: $this->cookieName,
            value: json_encode($this->variants, JSON_THROW_ON_ERROR),
            secure: $this->secure,
            sameSite: $this->sameSite,
        ))->withMaxAge($this->maxAge);

        return $this->signer->sign($cookie)->addToResponse($response);
    }

    /**
     * @return array<string, string>
     */
    private static function decode(mixed $raw, CookieSigner $signer, string $cookieName): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $cookie = new Cookie(name: $cookieName, value: $raw);

        if (!$signer->isSigned($cookie)) {
            return [];
        }

        try {
            return self::toStringMap(
                json_decode($signer->validate($cookie)->getValue(), associative: true, flags: JSON_THROW_ON_ERROR),
            );
        } catch (\RuntimeException|\JsonException) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function toStringMap(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            return [];
        }

        $variants = [];

        foreach ($decoded as $experiment => $variant) {
            if (!\is_string($experiment) || !\is_string($variant)) {
                continue;
            }

            $variants[$experiment] = $variant;
        }

        return $variants;
    }
}
