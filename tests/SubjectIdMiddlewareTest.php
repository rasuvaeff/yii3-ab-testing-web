<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTestingWeb\AnonymousToAuthenticatedStrategy;
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdGeneratorInterface;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Cookies\Cookie;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(SubjectIdMiddleware::class)]
final class SubjectIdMiddlewareTest
{
    public function generatesOpaqueIdAndSetsCookieWhenNonePresent(): void
    {
        $handler = $this->capturingHandler();
        $response = (new SubjectIdMiddleware())->process(new ServerRequest('GET', '/'), $handler);

        $subjectId = $this->receivedRequest($handler)?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);

        $setCookie = $response->getHeaderLine('Set-Cookie');
        Assert::true(str_starts_with($setCookie, 'ab_id=' . $subjectId));
        Assert::string($setCookie)->contains('HttpOnly');
        Assert::string($setCookie)->contains('SameSite=Lax');
        Assert::string($setCookie)->contains('Secure');
        Assert::string($setCookie)->contains('Max-Age=');
    }

    public function treatsEmptyCookieValueAsAbsentAndGeneratesId(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => '']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $this->receivedRequest($handler)?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function treatsEmptyPreSetAttributeAsAbsentAndGeneratesId(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))->withAttribute('ab.subjectId', '');

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $this->receivedRequest($handler)?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function reusesCookieValueWithoutSettingCookie(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('ab.subjectId'), 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function regeneratesIdWhenCookieValueHasForeignFormat(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'tampered-or-oversized-value']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $this->receivedRequest($handler)?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function customGeneratorMintsTheId(): void
    {
        $handler = $this->capturingHandler();
        $middleware = new SubjectIdMiddleware(idGenerator: $this->prefixedGenerator());

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('ab.subjectId'), 'sub_0001');
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('ab_id=sub_0001');
    }

    public function customGeneratorAlsoDecidesWhichCookieValuesAreReused(): void
    {
        // the trap this guards: if validation stayed hard-coded to 32 hex chars,
        // the middleware would reject its own cookie on every request, mint a new
        // id each time and — assignment being deterministic in the subject id —
        // flip the visitor between variants on every page view
        $generator = $this->prefixedGenerator();
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => 'sub_0007']);

        $response = (new SubjectIdMiddleware(idGenerator: $generator))->process($request, $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('ab.subjectId'), 'sub_0007');
        Assert::false($response->hasHeader('Set-Cookie'));
        verify(fn() => $generator->generate(), never: true);
    }

    public function customGeneratorRejectsTheDefaultFormat(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = (new SubjectIdMiddleware(idGenerator: $this->prefixedGenerator()))
            ->process($request, $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('ab.subjectId'), 'sub_0001');
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function leavesPreSetAttributeUntouchedAndSetsNoCookie(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'anon-id'])
            ->withAttribute('ab.subjectId', 'user-42');

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('ab.subjectId'), 'user-42');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function honoursCustomCookieAndAttributeNames(): void
    {
        $handler = $this->capturingHandler();
        $middleware = new SubjectIdMiddleware(cookieName: 'sid', attribute: 'subject');

        $response = $middleware->process((new ServerRequest('GET', '/'))->withCookieParams(['sid' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']), $handler);

        Assert::same($this->receivedRequest($handler)?->getAttribute('subject'), 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function consentDenialCreatesOnlyAnEphemeralId(): void
    {
        $handler = $this->capturingHandler();
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);
        $request = (new ServerRequest('GET', '/'))->withCookieParams([
            'ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        ]);

        $response = (new SubjectIdMiddleware(consentPolicy: $policy))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->source, SubjectIdSource::Ephemeral);
        Assert::false($subjectId->value === 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        // the only cookie written is the deletion of the withdrawn one
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('ab_id=;');
    }

    public function withdrawnConsentExpiresTheIdentifierCookie(): void
    {
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);
        $request = (new ServerRequest('GET', '/'))->withCookieParams([
            'ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        ]);

        $response = (new SubjectIdMiddleware(consentPolicy: $policy))
            ->process($request, $this->capturingHandler());

        $setCookie = $response->getHeaderLine('Set-Cookie');
        Assert::true(str_starts_with($setCookie, 'ab_id=;'));
        // a browser replaces a cookie only when the attributes match the ones
        // it was stored with
        Assert::string($setCookie)->contains('Secure');
        Assert::string($setCookie)->contains('SameSite=Lax');
        Assert::string($setCookie)->contains('Path=/');
        Assert::string($setCookie)->contains('Expires=');
        Assert::true((new Cookie(name: 'ab_id'))->expire()->isExpired());
    }

    public function withdrawnConsentExpiresTheCookieForAnAuthenticatedVisitorToo(): void
    {
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = (new SubjectIdMiddleware(consentPolicy: $policy))
            ->process($request, $this->capturingHandler());

        Assert::true(str_starts_with($response->getHeaderLine('Set-Cookie'), 'ab_id=;'));
    }

    public function consentDenialWithoutACookieWritesNoHeader(): void
    {
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);

        $response = (new SubjectIdMiddleware(consentPolicy: $policy))
            ->process(new ServerRequest('GET', '/'), $this->capturingHandler());

        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function theDeletionKeepsTheConfiguredCookieAttributes(): void
    {
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);
        $request = (new ServerRequest('GET', '/'))->withCookieParams([
            'ab_visitor' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        ]);

        $response = (new SubjectIdMiddleware(
            cookieName: 'ab_visitor',
            secure: false,
            sameSite: Cookie::SAME_SITE_STRICT,
            consentPolicy: $policy,
        ))->process($request, $this->capturingHandler());

        $setCookie = $response->getHeaderLine('Set-Cookie');
        Assert::true(str_starts_with($setCookie, 'ab_visitor=;'));
        Assert::string($setCookie)->contains('SameSite=Strict');
        Assert::false(str_contains($setCookie, 'Secure'));
    }

    public function defaultTransitionMigratesAnonymousAssignmentsToAuthenticatedId(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware())->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->value, 'user-42');
        Assert::same($subjectId->source, SubjectIdSource::Authenticated);
        Assert::true($subjectId->preserveAnonymousAssignments);
    }

    public function transitionCanStartFreshWithAuthenticatedId(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware(
            identityTransition: AnonymousToAuthenticatedStrategy::UseAuthenticatedId,
        ))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->value, 'user-42');
        Assert::false($subjectId->preserveAnonymousAssignments);
    }

    public function transitionCanKeepAnonymousIdAfterAuthentication(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware(
            identityTransition: AnonymousToAuthenticatedStrategy::KeepAnonymousId,
        ))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->value, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::same($subjectId->source, SubjectIdSource::Anonymous);
    }

    public function authenticatedIdWithoutAnAnonymousCookieNeedsNoTransition(): void
    {
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))->withAttribute('ab.subjectId', 'user-42');

        (new SubjectIdMiddleware())->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->value, 'user-42');
        Assert::same($subjectId->source, SubjectIdSource::Authenticated);
        Assert::false($subjectId->preserveAnonymousAssignments);
    }

    public function anAnonymousCookieIdentityIsNeverTransitioned(): void
    {
        // only an *authenticated* upstream identity triggers a transition; a
        // returning anonymous visitor must keep the exact cookie id, otherwise
        // the deterministic assignment flips on every request
        $handler = $this->capturingHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware(
            identityTransition: AnonymousToAuthenticatedStrategy::UseAuthenticatedId,
        ))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($this->receivedRequest($handler) ?? $request);

        Assert::same($subjectId->value, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::same($subjectId->source, SubjectIdSource::Anonymous);
        Assert::false($subjectId->preserveAnonymousAssignments);
    }

    private function capturingHandler(): RequestHandlerInterface
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))->returns(new Response());

        return $handler;
    }

    private function receivedRequest(RequestHandlerInterface $handler): ?ServerRequestInterface
    {
        return Understudy::lastCall(fn() => $handler->handle(Arg::any()))?->arg('request');
    }

    /**
     * A project-specific scheme the default generator would reject outright —
     * the case that proves generation and validation must travel together.
     */
    private function prefixedGenerator(): SubjectIdGeneratorInterface
    {
        $generator = Understudy::for(SubjectIdGeneratorInterface::class);
        when(fn() => $generator->isValid(Arg::any()))->returns(false);
        when(fn() => $generator->isValid(Arg::string(matches: '/^sub_\d{4}\z/')))->returns(true);
        when(fn() => $generator->generate())->returns('sub_0001');

        return $generator;
    }
}
