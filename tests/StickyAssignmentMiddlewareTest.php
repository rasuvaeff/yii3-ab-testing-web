<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectId;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Cookies\CookieSigner;

#[Test]
#[Covers(StickyAssignmentMiddleware::class)]
final class StickyAssignmentMiddlewareTest
{
    private AbTesting $abTesting;

    private CookieSigner $signer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->abTesting = new AbTesting(
            provider: new ConfigExperimentProvider(config: [
                'checkout-button' => [
                    'salt' => 'checkout-v1',
                    'fallbackVariant' => 'control',
                    'variants' => ['control' => 50, 'green' => 50],
                ],
            ]),
            strategy: new WeightedHashAssignmentStrategy(),
        );
        $this->signer = new CookieSigner('test-secret-key-test-secret-key-32');
    }

    public function exposesReadyResolverAndWritesChangedStore(): void
    {
        $handler = new StickyResolvingHandler(new Response());
        $response = (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
        ))->process($this->requestWithSubject(
            new SubjectId(value: 'anon-1', source: SubjectIdSource::Anonymous),
        ), $handler);

        Assert::instanceOf($handler->assignment, \Rasuvaeff\Yii3AbTesting\Assignment::class);
        Assert::true($response->hasHeader('Set-Cookie'));
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('ab_variants=');
    }

    public function consentDenialDoesNotReadOrWritePersistentAssignments(): void
    {
        $seeded = $this->seededCookie('green');
        $request = $this->requestWithSubject(
            new SubjectId(value: 'ephemeral-1', source: SubjectIdSource::Ephemeral),
        )->withCookieParams(['ab_variants' => $seeded]);
        $handler = new StickyResolvingHandler(new Response());
        $middleware = new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
            consentPolicy: new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false),
        );

        $response = $middleware->process($request, $handler);

        Assert::false($handler->assignment?->isSticky() ?? true);
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function migrationRetainsAnonymousStickyAssignmentForAuthenticatedId(): void
    {
        $request = $this->requestWithSubject(new SubjectId(
            value: 'user-42',
            source: SubjectIdSource::Authenticated,
            preserveAnonymousAssignments: true,
        ))->withCookieParams(['ab_variants' => $this->seededCookie('green')]);
        $handler = new StickyResolvingHandler(new Response());

        (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
        ))->process($request, $handler);

        Assert::same($handler->assignment?->variant, 'green');
        Assert::true($handler->assignment?->isSticky() ?? false);
    }

    public function authenticatedFreshStrategyDiscardsAnonymousAssignments(): void
    {
        $request = $this->requestWithSubject(new SubjectId(
            value: 'user-42',
            source: SubjectIdSource::Authenticated,
        ))->withCookieParams(['ab_variants' => $this->seededCookie('green')]);
        $handler = new StickyResolvingHandler(new Response());

        $response = (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
        ))->process($request, $handler);

        Assert::false($handler->assignment?->isSticky() ?? true);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function startingFreshOverwritesAnExistingCookieEvenWithoutNewAssignments(): void
    {
        // the stale anonymous cookie must not survive the transition: the store
        // starts dirty exactly so the response replaces it
        $request = $this->requestWithSubject(new SubjectId(
            value: 'user-42',
            source: SubjectIdSource::Authenticated,
        ))->withCookieParams(['ab_variants' => $this->seededCookie('green')]);

        $response = (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
        ))->process($request, new PassthroughHandler(new Response()));

        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function startingFreshWithoutACookieWritesNothing(): void
    {
        $request = $this->requestWithSubject(new SubjectId(
            value: 'user-42',
            source: SubjectIdSource::Authenticated,
        ));

        $response = (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
        ))->process($request, new PassthroughHandler(new Response()));

        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function consentDenialLeavesAnExistingCookieAlone(): void
    {
        $request = $this->requestWithSubject(new SubjectId(
            value: 'user-42',
            source: SubjectIdSource::Authenticated,
        ))->withCookieParams(['ab_variants' => $this->seededCookie('green')]);

        $response = (new StickyAssignmentMiddleware(
            resolver: $this->abTesting,
            signer: $this->signer,
            consentPolicy: new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false),
        ))->process($request, new PassthroughHandler(new Response()));

        Assert::false($response->hasHeader('Set-Cookie'));
    }

    private function requestWithSubject(SubjectId $subjectId): ServerRequestInterface
    {
        $request = new ServerRequest('GET', '/');

        return (new SubjectIdRequestAccessor())->with($request, $subjectId);
    }

    private function seededCookie(string $variant): string
    {
        $assignment = $this->abTesting->resolve('checkout-button', 'anon-1');
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->putForConfiguration(
            experiment: 'checkout-button',
            subjectId: 'anon-1',
            variant: $variant,
            configurationId: $assignment->configurationId,
        );
        $setCookie = $store->applyToResponse(new Response())->getHeaderLine('Set-Cookie');
        $pair = explode(';', $setCookie, 2)[0];

        return urldecode(substr($pair, strpos($pair, '=') + 1));
    }
}
