<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AllowListAnalyticsContextPolicy;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\LoggerConversionTracker;
use Rasuvaeff\Yii3AbTesting\LoggerExposureTracker;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec;

/**
 * A single-page application moves one thing: the server no longer knows whether
 * the visitor saw the variant. Only the client does.
 *
 * So the assignments endpoint must NOT track exposure — doing that there
 * inflates the numbers with prefetches, routes never reached and repeat
 * navigations. Exposure becomes a call the client makes when it renders.
 *
 * Three endpoints, simulated below without an HTTP layer:
 *
 *   GET  /ab/assignments  -> variants + a signed receipt, no tracking
 *   POST /ab/exposure     -> the client saw it
 *   POST /ab/conversion   -> the goal happened, possibly much later
 *
 * The endpoints themselves belong in your application: routing, authentication
 * and rate limiting are not this package's business.
 */
$logger = new class extends Psr\Log\AbstractLogger {
    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        echo '  → ' . json_encode($context['event'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
};

$ab = new AbTesting(
    provider: new ConfigExperimentProvider(config: [
        'checkout-button' => [
            'enabled' => true,
            'salt' => 'checkout-v1',
            'fallbackVariant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ],
    ]),
    strategy: new WeightedHashAssignmentStrategy(),
    exposureTracker: new LoggerExposureTracker(logger: $logger),
    conversionTracker: new LoggerConversionTracker(logger: $logger),
    contextPolicy: new AllowListAnalyticsContextPolicy(allowedAttributes: ['country']),
);

// In a token-authenticated SPA there is no cookie: an auth middleware puts the
// user id into the request attribute SubjectIdMiddleware reads, and the
// cookie branch is never taken.
$subjectId = 'user-4711';
$context = AssignmentContext::forEnvironment('production')->withAttribute('country', 'RU');

// Keep this out of your repository — it is a private key, not a password.
$codec = new SignedReceiptCodec(secret: str_repeat('demo-secret-not-for-production', 2));

echo "GET /ab/assignments\n";
$assignment = $ab->assign(experiment: 'checkout-button', subjectId: $subjectId, context: $context);
echo sprintf("  variant: %s (reason: %s) — nothing tracked yet\n", $assignment->variant, $assignment->reason->value);

echo "\nPOST /ab/exposure   (client rendered the variant)\n";
$exposure = $ab->trackExposure($assignment);

// The receipt goes to the client, which stores it and sends it back later. It
// is signed, so a client that edits the variant is not believed.
$receiptForClient = $codec->encode($exposure->receipt());
echo sprintf("  receipt handed to the client (%d bytes)\n", \strlen($receiptForClient));

echo "\nPOST /ab/conversion (a later request, possibly days later)\n";
$receipt = $codec->decode($receiptForClient);

if ($receipt === null) {
    // Missing, tampered with, or signed by another secret — treat all three
    // exactly like "no receipt".
    echo "  rejected: not a receipt this server issued\n";

    exit(1);
}

$ab->trackConversionForReceipt($receipt, goal: 'purchase', context: $context);

echo "\nA client that edits the variant is not believed:\n";
[$payload, $signature] = explode('.', $receiptForClient);
$claim = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
$claim['v'] = 'control';  // "I was in the control arm, honest"
$forged = rtrim(strtr(base64_encode((string) json_encode($claim)), '+/', '-_'), '=') . '.' . $signature;

echo $codec->decode($forged) === null
    ? "  rejected — the signature no longer matches the payload\n"
    : "  ACCEPTED — this must never happen\n";

echo "\nWhy the receipt rather than re-resolving on the server: after a\n";
echo "reweight, re-resolving returns the variant the visitor WOULD get now,\n";
echo "not the one they actually saw. The conversion would be attributed to the\n";
echo "wrong arm, and nothing would look broken.\n";
