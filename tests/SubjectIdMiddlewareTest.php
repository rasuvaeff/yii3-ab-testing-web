<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3AbTestingWeb\AnonymousToAuthenticatedStrategy;
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SubjectIdMiddleware::class)]
final class SubjectIdMiddlewareTest
{
    public function generatesOpaqueIdAndSetsCookieWhenNonePresent(): void
    {
        $handler = new CapturingHandler(new Response());
        $response = (new SubjectIdMiddleware())->process(new ServerRequest('GET', '/'), $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
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
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => '']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function treatsEmptyPreSetAttributeAsAbsentAndGeneratesId(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withAttribute('ab.subjectId', '');

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function reusesCookieValueWithoutSettingCookie(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        Assert::same($handler->received?->getAttribute('ab.subjectId'), 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function regeneratesIdWhenCookieValueHasForeignFormat(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'tampered-or-oversized-value']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
        Assert::true(is_string($subjectId));
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $subjectId) === 1);
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function customGeneratorMintsTheId(): void
    {
        $handler = new CapturingHandler(new Response());
        $middleware = new SubjectIdMiddleware(idGenerator: new PrefixedSubjectIdGenerator());

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        Assert::same($handler->received?->getAttribute('ab.subjectId'), 'sub_0001');
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('ab_id=sub_0001');
    }

    public function customGeneratorAlsoDecidesWhichCookieValuesAreReused(): void
    {
        // the trap this guards: if validation stayed hard-coded to 32 hex chars,
        // the middleware would reject its own cookie on every request, mint a new
        // id each time and — assignment being deterministic in the subject id —
        // flip the visitor between variants on every page view
        $generator = new PrefixedSubjectIdGenerator();
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => 'sub_0007']);

        $response = (new SubjectIdMiddleware(idGenerator: $generator))->process($request, $handler);

        Assert::same($handler->received?->getAttribute('ab.subjectId'), 'sub_0007');
        Assert::false($response->hasHeader('Set-Cookie'));
        Assert::same($generator->calls, 0);
    }

    public function customGeneratorRejectsTheDefaultFormat(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = (new SubjectIdMiddleware(idGenerator: new PrefixedSubjectIdGenerator()))
            ->process($request, $handler);

        Assert::same($handler->received?->getAttribute('ab.subjectId'), 'sub_0001');
        Assert::true($response->hasHeader('Set-Cookie'));
    }

    public function leavesPreSetAttributeUntouchedAndSetsNoCookie(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'anon-id'])
            ->withAttribute('ab.subjectId', 'user-42');

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        Assert::same($handler->received?->getAttribute('ab.subjectId'), 'user-42');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function honoursCustomCookieAndAttributeNames(): void
    {
        $handler = new CapturingHandler(new Response());
        $middleware = new SubjectIdMiddleware(cookieName: 'sid', attribute: 'subject');

        $response = $middleware->process((new ServerRequest('GET', '/'))->withCookieParams(['sid' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']), $handler);

        Assert::same($handler->received?->getAttribute('subject'), 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function consentDenialCreatesOnlyAnEphemeralId(): void
    {
        $handler = new CapturingHandler(new Response());
        $policy = new CallbackConsentPolicy(static fn(ServerRequest $request): bool => false);
        $request = (new ServerRequest('GET', '/'))->withCookieParams([
            'ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        ]);

        $response = (new SubjectIdMiddleware(consentPolicy: $policy))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($handler->received ?? $request);

        Assert::same($subjectId->source, SubjectIdSource::Ephemeral);
        Assert::false($response->hasHeader('Set-Cookie'));
        Assert::false($subjectId->value === 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
    }

    public function defaultTransitionMigratesAnonymousAssignmentsToAuthenticatedId(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware())->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($handler->received ?? $request);

        Assert::same($subjectId->value, 'user-42');
        Assert::same($subjectId->source, SubjectIdSource::Authenticated);
        Assert::true($subjectId->preserveAnonymousAssignments);
    }

    public function transitionCanStartFreshWithAuthenticatedId(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware(
            identityTransition: AnonymousToAuthenticatedStrategy::UseAuthenticatedId,
        ))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($handler->received ?? $request);

        Assert::same($subjectId->value, 'user-42');
        Assert::false($subjectId->preserveAnonymousAssignments);
    }

    public function transitionCanKeepAnonymousIdAfterAuthentication(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('ab.subjectId', 'user-42')
            ->withCookieParams(['ab_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        (new SubjectIdMiddleware(
            identityTransition: AnonymousToAuthenticatedStrategy::KeepAnonymousId,
        ))->process($request, $handler);
        $subjectId = (new SubjectIdRequestAccessor())->require($handler->received ?? $request);

        Assert::same($subjectId->value, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Assert::same($subjectId->source, SubjectIdSource::Anonymous);
    }
}
