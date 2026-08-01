<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\AssignmentReceipt;
use Rasuvaeff\Yii3AbTesting\AssignmentSource;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SignedReceiptCodec::class)]
final class SignedReceiptCodecTest
{
    private const string SECRET = 'a-secret-of-at-least-thirty-two-bytes';

    private SignedReceiptCodec $codec;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->codec = new SignedReceiptCodec(self::SECRET);
    }

    public function survivesARoundTrip(): void
    {
        $receipt = $this->receipt();

        $restored = $this->codec->decode($this->codec->encode($receipt));

        Assert::notNull($restored);
        Assert::same($restored->toArray(), $receipt->toArray());
    }

    public function roundTripSurvivesAMissingRevision(): void
    {
        $receipt = $this->receipt(revision: null);

        $restored = $this->codec->decode($this->codec->encode($receipt));

        Assert::notNull($restored);
        Assert::null($restored->experimentRevision);
    }

    /**
     * The whole point: a client that edits the variant must not be believed.
     */
    public function rejectsATamperedPayload(): void
    {
        $encoded = $this->codec->encode($this->receipt());
        [$payload, $signature] = explode('.', $encoded);
        $forged = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        $forged['v'] = 'attacker-choice';
        $tampered = rtrim(strtr(base64_encode((string) json_encode($forged)), '+/', '-_'), '=') . '.' . $signature;

        Assert::null($this->codec->decode($tampered));
    }

    public function rejectsAReceiptSignedWithAnotherSecret(): void
    {
        $encoded = (new SignedReceiptCodec('a-completely-different-secret-value'))->encode($this->receipt());

        Assert::null($this->codec->decode($encoded));
    }

    #[DataProvider('malformedProvider')]
    public function rejectsMalformedInput(string $value): void
    {
        Assert::null($this->codec->decode($value));
    }

    public static function malformedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no separator' => ['justsomestring'];
        yield 'empty payload' => ['.deadbeef'];
        yield 'payload is not base64' => ['!!!.deadbeef'];
        yield 'payload is not json' => [base64_encode('not json') . '.deadbeef'];
        yield 'payload is a json scalar' => [base64_encode('42') . '.deadbeef'];
        yield 'standard base64 alphabet' => ['a+b/c=.deadbeef'];
    }

    /**
     * A valid signature over a payload the core rejects means the secret leaked
     * or the receipt predates a contract change — not usable either way.
     */
    public function rejectsASignedButUnusablePayload(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['eid' => 'evt-1', 'at' => 'yesterday'])), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, self::SECRET);

        Assert::null($this->codec->decode($payload . '.' . $signature));
    }

    public function rejectsAShortSecret(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessage('Receipt signing secret must be at least 32 bytes');

        new SignedReceiptCodec('too-short');
    }

    /**
     * The value travels wherever the application puts it — cookie, JSON body,
     * query string — so it must use only characters none of those escape.
     */
    public function theEncodedFormNeedsNoEscapingAnywhere(): void
    {
        $encoded = $this->codec->encode($this->receipt());

        Assert::same($encoded, rawurlencode($encoded));
        Assert::same(json_decode((string) json_encode($encoded)), $encoded);
    }

    private function receipt(?string $revision = 'db:7'): AssignmentReceipt
    {
        return new AssignmentReceipt(
            exposureEventId: '0198f2c1-4d3a-7c9e-8b21-6f4a2d9e0c17',
            occurredAt: new DateTimeImmutable('2026-08-01 10:00:00.123', new DateTimeZone('UTC')),
            experiment: 'checkout_button',
            variant: 'b',
            subjectId: 'b7d3f1a95c2e4408',
            reason: DecisionReason::Assigned,
            source: AssignmentSource::Store,
            experimentRevision: $revision,
        );
    }
}
