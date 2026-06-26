<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\ExperimentRegistry;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieSigner;

#[Test]
#[Covers(CookieAssignmentStore::class)]
final class CookieAssignmentStoreTest
{
    private const string COOKIE = 'ab_variants';

    private CookieSigner $signer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->signer = new CookieSigner('test-secret-key-test-secret-key-32');
    }

    public function getReturnsNullForUnknownExperiment(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);

        Assert::null($store->get('checkout-button', 'user-1'));
    }

    public function putThenGetReturnsStoredVariant(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');

        Assert::same($store->get('checkout-button', 'user-1'), 'green');
    }

    public function fromRequestWithoutCookieIsEmpty(): void
    {
        $store = CookieAssignmentStore::fromRequest(new ServerRequest('GET', '/'), $this->signer);

        Assert::null($store->get('checkout-button', 'user-1'));
    }

    public function notDirtyStoreWritesNoCookie(): void
    {
        $store = CookieAssignmentStore::fromRequest(new ServerRequest('GET', '/'), $this->signer);

        $response = $store->applyToResponse(new Response());

        Assert::false($response->hasHeader('Set-Cookie'));
    }

    public function roundTripsThroughSignedCookie(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');
        $store->put('pricing-page', 'user-1', 'variant-b');

        $response = $store->applyToResponse(new Response());
        $setCookie = $response->getHeaderLine('Set-Cookie');
        Assert::string($setCookie)->contains('HttpOnly');
        Assert::string($setCookie)->contains('Secure');
        Assert::string($setCookie)->contains('SameSite=Lax');

        $restored = CookieAssignmentStore::fromRequest($this->requestWithCookieFrom($setCookie), $this->signer);

        Assert::same($restored->get('checkout-button', 'user-1'), 'green');
        Assert::same($restored->get('pricing-page', 'user-1'), 'variant-b');
    }

    public function rejectsTamperedCookie(): void
    {
        $signed = $this->signedValue('{"checkout-button":"green"}');
        $tampered = substr($signed, 0, -1) . ($signed[-1] === 'a' ? 'b' : 'a');

        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie($tampered), $this->signer);

        Assert::null($store->get('checkout-button', 'user-1'));
    }

    public function ignoresUnsignedCookie(): void
    {
        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie('{"checkout-button":"green"}'), $this->signer);

        Assert::null($store->get('checkout-button', 'user-1'));
    }

    public function ignoresSignedButMalformedJson(): void
    {
        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie($this->signedValue('not-json')), $this->signer);

        Assert::null($store->get('checkout-button', 'user-1'));
    }

    public function filtersNonStringEntriesAndKeepsLaterValidOnes(): void
    {
        $store = CookieAssignmentStore::fromRequest(
            $this->requestWithCookie($this->signedValue('{"weird":123,"checkout-button":"green"}')),
            $this->signer,
        );

        Assert::same($store->get('checkout-button', 'user-1'), 'green');
        Assert::null($store->get('weird', 'user-1'));
    }

    public function pruneRemovesVariantsOfRemovedExperimentsAndRewritesCookie(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->put('checkout-button', 'user-1', 'green');
        $store->put('retired-experiment', 'user-1', 'variant-b');

        $store->prune($this->registryWith('checkout-button'));

        Assert::same($store->get('checkout-button', 'user-1'), 'green');
        Assert::null($store->get('retired-experiment', 'user-1'));
        Assert::true($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
    }

    public function pruneWithOnlyKnownExperimentsWritesNoCookie(): void
    {
        $request = new ServerRequest('GET', '/');
        $store = CookieAssignmentStore::fromRequest($request, $this->signer);

        $store->prune($this->registryWith('checkout-button'));

        Assert::false($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
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
