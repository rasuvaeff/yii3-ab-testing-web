<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

/**
 * Default generator: 32 lowercase hex characters over 128 random bits — the
 * format `ab_id` cookies have always used, so it stays the default and
 * existing visitors keep their variant.
 *
 * The id is opaque and carries no personal data, which is why it is not a UUID:
 * nothing about the visitor should be derivable from it.
 *
 * @api
 */
final readonly class HexSubjectIdGenerator implements SubjectIdGeneratorInterface
{
    // \z, not $: PCRE's $ also matches before a trailing newline, so a cookie
    // of "32 hex chars + \n" would otherwise pass and land in logs as a
    // subject id
    private const string ID_PATTERN = '/^[0-9a-f]{32}\z/';

    #[\Override]
    public function generate(): string
    {
        /** @var non-empty-string */
        return bin2hex(random_bytes(16));
    }

    #[\Override]
    public function isValid(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }
}
