<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\AssignmentReceipt;

/**
 * Encodes an {@see AssignmentReceipt} into a signed, transport-independent
 * string, and decodes it back only if the signature holds.
 *
 * Signing lived inside {@see CookieAssignmentStore}, tied to `CookieSigner`,
 * which made it unusable anywhere a cookie is not the transport. A single-page
 * application holds the receipt in `localStorage` and posts it back in a JSON
 * body; without a signature the server has no way to tell a real receipt from a
 * fabricated one, and a client could claim variant B while assigned A —
 * corrupting the analytics rather than breaking anything visibly.
 *
 * Re-resolving the assignment server-side is the alternative, but it answers a
 * different question: after a reweight it returns the variant the visitor
 * *would* get now, not the one they saw. That is precisely why receipts exist.
 *
 * The signature covers the exact bytes that are decoded, so reordering or
 * editing any field invalidates it.
 *
 * @api
 */
final readonly class SignedReceiptCodec
{
    private const string SEPARATOR = '.';

    private const string ALGORITHM = 'sha256';

    /**
     * @param non-empty-string $secret A private key, not a password: at least
     *     32 bytes of entropy, never shared with the client, rotated by
     *     deploying a new value (which invalidates outstanding receipts).
     */
    public function __construct(
        private string $secret,
    ) {
        if (\strlen($secret) < 32) {
            throw new InvalidArgumentException('Receipt signing secret must be at least 32 bytes');
        }
    }

    public function encode(AssignmentReceipt $receipt): string
    {
        $payload = $this->payload($receipt);

        return $payload . self::SEPARATOR . $this->signature($payload);
    }

    /**
     * Returns null for anything that is not a receipt this codec produced:
     * missing, malformed, tampered with, or signed by a different secret. The
     * caller treats that exactly like "no receipt" — a forged one must never
     * be distinguishable from an absent one by its effect.
     */
    public function decode(string $value): ?AssignmentReceipt
    {
        $separator = strrpos($value, self::SEPARATOR);

        if ($separator === false) {
            return null;
        }

        $payload = substr($value, 0, $separator);
        $signature = substr($value, $separator + 1);

        // hash_equals, not ===: signature comparison must not leak position
        // through timing.
        if (!hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $decoded = json_decode($this->decodePayload($payload), true);

        if (!\is_array($decoded)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            return AssignmentReceipt::fromArray($decoded);
        } catch (InvalidArgumentException) {
            // A valid signature over a payload the core rejects means the
            // secret leaked or the receipt predates a contract change. Either
            // way it is not usable.
            return null;
        }
    }

    /**
     * base64**url**: the standard alphabet's `+` and `/` would need escaping in
     * a query string, and this value travels wherever the application puts it.
     */
    private function payload(AssignmentReceipt $receipt): string
    {
        return rtrim(strtr(base64_encode(json_encode($receipt->toArray(), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodePayload(string $payload): string
    {
        return (string) base64_decode(strtr($payload, '-_', '+/'), true);
    }

    private function signature(string $payload): string
    {
        return hash_hmac(self::ALGORITHM, $payload, $this->secret);
    }
}
