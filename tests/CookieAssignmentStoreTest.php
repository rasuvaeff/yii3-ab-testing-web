<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\ExperimentRegistry;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieSigner;

#[CoversClass(CookieAssignmentStore::class)]
final class CookieAssignmentStoreTest extends TestCase
{
    private const string COOKIE = 'ab_variants';

    private CookieSigner $signer;

    #[\Override]
    protected function setUp(): void
    {
        $this->signer = new CookieSigner('test-secret-key-test-secret-key-32');
    }

    #[Test]
    public function getReturnsNullForUnknownExperiment(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);

        $this->assertNull($store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function putThenGetReturnsStoredVariant(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');

        $this->assertSame('green', $store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function fromRequestWithoutCookieIsEmpty(): void
    {
        $store = CookieAssignmentStore::fromRequest(new ServerRequest('GET', '/'), $this->signer);

        $this->assertNull($store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function notDirtyStoreWritesNoCookie(): void
    {
        $store = CookieAssignmentStore::fromRequest(new ServerRequest('GET', '/'), $this->signer);

        $response = $store->applyToResponse(new Response());

        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function roundTripsThroughSignedCookie(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');
        $store->put('pricing-page', 'user-1', 'variant-b');

        $response = $store->applyToResponse(new Response());
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('Secure', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);

        $restored = CookieAssignmentStore::fromRequest($this->requestWithCookieFrom($setCookie), $this->signer);

        $this->assertSame('green', $restored->get('checkout-button', 'user-1'));
        $this->assertSame('variant-b', $restored->get('pricing-page', 'user-1'));
    }

    #[Test]
    public function rejectsTamperedCookie(): void
    {
        $signed = $this->signedValue('{"checkout-button":"green"}');
        $tampered = substr($signed, 0, -1) . ($signed[-1] === 'a' ? 'b' : 'a');

        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie($tampered), $this->signer);

        $this->assertNull($store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function ignoresUnsignedCookie(): void
    {
        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie('{"checkout-button":"green"}'), $this->signer);

        $this->assertNull($store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function ignoresSignedButMalformedJson(): void
    {
        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie($this->signedValue('not-json')), $this->signer);

        $this->assertNull($store->get('checkout-button', 'user-1'));
    }

    #[Test]
    public function filtersNonStringEntriesAndKeepsLaterValidOnes(): void
    {
        // The non-string entry comes first so a valid entry follows it: the bad
        // entry must be skipped, not stop the loop, and never stored as-is.
        $store = CookieAssignmentStore::fromRequest(
            $this->requestWithCookie($this->signedValue('{"weird":123,"checkout-button":"green"}')),
            $this->signer,
        );

        $this->assertSame('green', $store->get('checkout-button', 'user-1'));
        $this->assertNull($store->get('weird', 'user-1'));
    }

    private function signedValue(string $value): string
    {
        return $this->signer->sign(new Cookie(name: self::COOKIE, value: $value, encodeValue: false))->getValue();
    }

    private function requestWithCookie(string $value): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/'))->withCookieParams([self::COOKIE => $value]);
    }

    private function requestWithCookieFrom(string $setCookieHeader): ServerRequestInterface
    {
        $pair = explode(';', $setCookieHeader, 2)[0];
        $value = substr($pair, strpos($pair, '=') + 1);

        return $this->requestWithCookie(urldecode($value));
    }

    #[Test]
    public function pruneRemovesVariantsOfRemovedExperimentsAndRewritesCookie(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');
        $store->put('retired-experiment', 'user-1', 'variant-b');

        $store->prune($this->registryWith('checkout-button'));

        $this->assertSame('green', $store->get('checkout-button', 'user-1'));
        $this->assertNull($store->get('retired-experiment', 'user-1'));
        $this->assertTrue($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function pruneWithOnlyKnownExperimentsWritesNoCookie(): void
    {
        $request = new ServerRequest('GET', '/');
        $store = CookieAssignmentStore::fromRequest($request, $this->signer);

        $store->prune($this->registryWith('checkout-button'));

        $this->assertFalse($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
    }

    private function registryWith(string $experiment): ExperimentRegistry
    {
        return new ExperimentRegistry(provider: new ConfigExperimentProvider(config: [
            $experiment => [
                'salt' => 'salt-v1',
                'fallbackVariant' => 'control',
                'variants' => ['control' => 50, 'green' => 50],
            ],
        ]));
    }
}
