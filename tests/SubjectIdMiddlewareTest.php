<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;

#[CoversClass(SubjectIdMiddleware::class)]
final class SubjectIdMiddlewareTest extends TestCase
{
    #[Test]
    public function generatesOpaqueIdAndSetsCookieWhenNonePresent(): void
    {
        $handler = new CapturingHandler(new Response());
        $response = (new SubjectIdMiddleware())->process(new ServerRequest('GET', '/'), $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
        $this->assertIsString($subjectId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $subjectId);

        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('ab_id=' . $subjectId, $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);
        $this->assertStringContainsString('Secure', $setCookie);
        $this->assertStringContainsString('Max-Age=', $setCookie);
    }

    #[Test]
    public function treatsEmptyCookieValueAsAbsentAndGeneratesId(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => '']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $subjectId = $handler->received?->getAttribute('ab.subjectId');
        $this->assertIsString($subjectId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $subjectId);
        $this->assertTrue($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function reusesCookieValueWithoutSettingCookie(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['ab_id' => 'existing-id']);

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $this->assertSame('existing-id', $handler->received?->getAttribute('ab.subjectId'));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function leavesPreSetAttributeUntouchedAndSetsNoCookie(): void
    {
        $handler = new CapturingHandler(new Response());
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['ab_id' => 'anon-id'])
            ->withAttribute('ab.subjectId', 'user-42');

        $response = (new SubjectIdMiddleware())->process($request, $handler);

        $this->assertSame('user-42', $handler->received?->getAttribute('ab.subjectId'));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function honoursCustomCookieAndAttributeNames(): void
    {
        $handler = new CapturingHandler(new Response());
        $middleware = new SubjectIdMiddleware(cookieName: 'sid', attribute: 'subject');

        $response = $middleware->process((new ServerRequest('GET', '/'))->withCookieParams(['sid' => 'abc']), $handler);

        $this->assertSame('abc', $handler->received?->getAttribute('subject'));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }
}
