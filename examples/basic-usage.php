<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectId;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
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
$subjectAccessor = new SubjectIdRequestAccessor();
$middleware = new StickyAssignmentMiddleware(resolver: $ab, signer: $signer);

// --- First request: no sticky cookie yet ---
$request = $subjectAccessor->with(
    new ServerRequest('GET', '/'),
    new SubjectId(value: $subjectId, source: SubjectIdSource::Anonymous),
);
$handler = new class(new Response()) implements RequestHandlerInterface {
    public ?Assignment $assignment = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $subjectId = (new SubjectIdRequestAccessor())->require($request);
        $this->assignment = (new StickyAssignmentRequestAccessor())->resolver($request)->resolve(
            experiment: 'checkout-button',
            subjectId: $subjectId->value,
        );

        return $this->response;
    }
};
$response = $middleware->process($request, $handler);
$first = $handler->assignment ?? throw new RuntimeException('Assignment was not resolved');
$setCookie = $response->getHeaderLine('Set-Cookie');

echo "First visit  -> {$first->variant} (stored sticky cookie)\n";

// --- Second request: the browser sends the cookie back ---
$pair = explode(';', $setCookie, 2)[0];
$cookieValue = urldecode(substr($pair, strpos($pair, '=') + 1));

$request2 = $subjectAccessor->with(
    (new ServerRequest('GET', '/'))->withCookieParams(['ab_variants' => $cookieValue]),
    new SubjectId(value: $subjectId, source: SubjectIdSource::Anonymous),
);
$handler2 = clone $handler;
$middleware->process($request2, $handler2);
$second = $handler2->assignment ?? throw new RuntimeException('Assignment was not resolved');

echo "Second visit -> {$second->variant} (served from the sticky cookie)\n";
echo $first->variant === $second->variant ? "Sticky: variant preserved.\n" : "ERROR: variant changed!\n";
