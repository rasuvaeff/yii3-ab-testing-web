<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;
use Yiisoft\Cookies\CookieSigner;

$ab = new AbTesting(
    provider: new ConfigExperimentProvider(config: [
        'checkout-button' => [
            'salt' => 'checkout-v1',
            'fallbackVariant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ],
    ]),
    strategy: new WeightedHashAssignmentStrategy(),
);

$signer = new CookieSigner('example-secret-key-example-secret-32');
$subjectId = 'anon-' . bin2hex(random_bytes(4));

// --- First request: no sticky cookie yet ---
$request = new ServerRequest('GET', '/');
$store = CookieAssignmentStore::fromRequest($request, $signer);
$resolver = new StickyAssignmentResolver($ab, $store);

$first = $resolver->resolve(experiment: 'checkout-button', subjectId: $subjectId);
$response = $store->applyToResponse(new Response());
$setCookie = $response->getHeaderLine('Set-Cookie');

echo "First visit  -> {$first->variant} (stored sticky cookie)\n";

// --- Second request: the browser sends the cookie back ---
$pair = explode(';', $setCookie, 2)[0];
$cookieValue = urldecode(substr($pair, strpos($pair, '=') + 1));

$request2 = (new ServerRequest('GET', '/'))->withCookieParams(['ab_variants' => $cookieValue]);
$store2 = CookieAssignmentStore::fromRequest($request2, $signer);
$second = (new StickyAssignmentResolver($ab, $store2))->resolve(experiment: 'checkout-button', subjectId: $subjectId);

echo "Second visit -> {$second->variant} (served from the sticky cookie)\n";
echo $first->variant === $second->variant ? "Sticky: variant preserved.\n" : "ERROR: variant changed!\n";
