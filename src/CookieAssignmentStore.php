<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use DateInterval;
use InvalidArgumentException;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\ExperimentRegistry;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieSigner;

/**
 * Sticky-variant store backed by one bounded signed cookie. Legacy entries are a
 * JSON `{experiment: variant}` map; configuration-aware entries also carry the
 * core configuration id.
 *
 * The store is request-scoped: build it from the incoming request with
 * {@see fromRequest()}, resolve assignments through it, then write any new
 * variants back with {@see applyToResponse()} (typically from a middleware).
 *
 * The cookie is browser-scoped, so the `$subjectId` argument of {@see get()} /
 * {@see put()} is ignored — the cookie itself identifies the subject. A visitor
 * {@see StickyAssignmentMiddleware} applies the configured authentication
 * transition when a visitor logs in.
 *
 * @api
 */
final class CookieAssignmentStore implements ConfigurationAwareAssignmentStore
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
        private readonly int $maxEntries = 50,
        private readonly int $maxCookieBytes = 3800,
        /** @var array<string, string|null> */
        private array $configurationIds = [],
    ) {
        if ($maxEntries < 1) {
            throw new InvalidArgumentException('Maximum cookie entries must be at least 1');
        }

        if ($maxCookieBytes < 256) {
            throw new InvalidArgumentException('Maximum cookie size must be at least 256 bytes');
        }

        $this->enforceEntryLimit();
    }

    /**
     * Builds a store from the signed cookie on the request. A missing, tampered,
     * or malformed cookie yields an empty (clean) store.
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        CookieSigner $signer,
        string $cookieName = 'ab_variants',
        int $maxEntries = 50,
        int $maxCookieBytes = 3800,
    ): self {
        $decoded = self::decode(
            raw: $request->getCookieParams()[$cookieName] ?? null,
            signer: $signer,
            cookieName: $cookieName,
            maxCookieBytes: $maxCookieBytes,
        );

        return new self(
            signer: $signer,
            cookieName: $cookieName,
            variants: $decoded['variants'],
            maxEntries: $maxEntries,
            maxCookieBytes: $maxCookieBytes,
            configurationIds: $decoded['configurationIds'],
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
        unset($this->variants[$experiment], $this->configurationIds[$experiment]);
        $this->variants[$experiment] = $variant;
        $this->configurationIds[$experiment] = null;
        $this->dirty = true;
        $this->enforceEntryLimit();
    }

    #[\Override]
    public function getForConfiguration(
        string $experiment,
        string $subjectId,
        ?string $configurationId,
    ): ?string {
        if (!array_key_exists($experiment, $this->variants)
            || ($this->configurationIds[$experiment] ?? null) !== $configurationId) {
            return null;
        }

        return $this->variants[$experiment];
    }

    #[\Override]
    public function putForConfiguration(
        string $experiment,
        string $subjectId,
        string $variant,
        ?string $configurationId,
    ): void {
        unset($this->variants[$experiment], $this->configurationIds[$experiment]);
        $this->variants[$experiment] = $variant;
        $this->configurationIds[$experiment] = $configurationId;
        $this->dirty = true;
        $this->enforceEntryLimit();
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
                unset($this->configurationIds[$experiment]);
                $this->dirty = true;
            }
        }
    }

    /**
     * Writes the signed cookie when changed. Entries over configured count or
     * byte limits are evicted oldest-first; updating an entry makes it newest.
     *
     * @throws \JsonException
     */
    public function applyToResponse(ResponseInterface $response): ResponseInterface
    {
        if (!$this->dirty) {
            return $response;
        }

        do {
            $cookie = $this->signedCookie();
            $candidate = $cookie->addToResponse($response);
            $headers = $candidate->getHeader('Set-Cookie');
            $header = end($headers);

            if ($header === false) {
                throw new \LogicException('Signed cookie did not produce a Set-Cookie header');
            }

            if (strlen($header) <= $this->maxCookieBytes) {
                return $candidate;
            }

            if ($this->variants === []) {
                throw new LengthException('Cookie metadata exceeds the configured byte limit');
            }

            $this->evictOldest();
        } while (true);
    }

    /**
     * @throws \JsonException
     */
    private function signedCookie(): Cookie
    {
        $entries = [];

        foreach ($this->variants as $experiment => $variant) {
            $configurationId = $this->configurationIds[$experiment] ?? null;
            $entries[$experiment] = $configurationId === null
                ? $variant
                : ['v' => $variant, 'c' => $configurationId];
        }

        $cookie = (new Cookie(
            name: $this->cookieName,
            value: json_encode($entries, JSON_THROW_ON_ERROR),
            secure: $this->secure,
            sameSite: $this->sameSite,
        ))->withMaxAge($this->maxAge);

        return $this->signer->sign($cookie);
    }

    /**
     * @return array{variants: array<string, string>, configurationIds: array<string, string|null>}
     */
    private static function decode(
        mixed $raw,
        CookieSigner $signer,
        string $cookieName,
        int $maxCookieBytes,
    ): array {
        if (!\is_string($raw) || $raw === '' || strlen($raw) > $maxCookieBytes) {
            return ['variants' => [], 'configurationIds' => []];
        }

        $cookie = new Cookie(name: $cookieName, value: $raw);

        if (!$signer->isSigned($cookie)) {
            return ['variants' => [], 'configurationIds' => []];
        }

        try {
            return self::toEntryMaps(
                json_decode($signer->validate($cookie)->getValue(), associative: true, flags: JSON_THROW_ON_ERROR),
            );
        } catch (\RuntimeException|\JsonException) {
            return ['variants' => [], 'configurationIds' => []];
        }
    }

    /**
     * @return array{variants: array<string, string>, configurationIds: array<string, string|null>}
     */
    private static function toEntryMaps(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            return ['variants' => [], 'configurationIds' => []];
        }

        $variants = [];
        $configurationIds = [];

        foreach ($decoded as $experiment => $entry) {
            if (!\is_string($experiment)) {
                continue;
            }

            if (\is_string($entry)) {
                $variants[$experiment] = $entry;
                $configurationIds[$experiment] = null;

                continue;
            }

            if (!\is_array($entry)
                || !isset($entry['v'], $entry['c'])
                || !\is_string($entry['v'])
                || !\is_string($entry['c'])) {
                continue;
            }

            $variants[$experiment] = $entry['v'];
            $configurationIds[$experiment] = $entry['c'];
        }

        return ['variants' => $variants, 'configurationIds' => $configurationIds];
    }

    private function enforceEntryLimit(): void
    {
        while (count($this->variants) > $this->maxEntries) {
            $this->evictOldest();
        }
    }

    private function evictOldest(): void
    {
        $experiment = array_key_first($this->variants);

        if ($experiment === null) {
            return;
        }

        unset($this->variants[$experiment], $this->configurationIds[$experiment]);
        $this->dirty = true;
    }
}
