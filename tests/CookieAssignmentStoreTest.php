<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\ExperimentRegistry;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
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

    public function entryLimitEvictsOldestAssignmentDeterministically(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer, maxEntries: 2);
        $store->put('first', 'user-1', 'a');
        $store->put('second', 'user-1', 'b');
        $store->put('third', 'user-1', 'c');

        Assert::null($store->get('first', 'user-1'));
        Assert::same($store->get('second', 'user-1'), 'b');
        Assert::same($store->get('third', 'user-1'), 'c');
    }

    public function updatingAssignmentMakesItNewestForEviction(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer, maxEntries: 2);
        $store->put('first', 'user-1', 'a');
        $store->put('second', 'user-1', 'b');
        $store->put('first', 'user-1', 'updated');
        $store->put('third', 'user-1', 'c');

        Assert::same($store->get('first', 'user-1'), 'updated');
        Assert::null($store->get('second', 'user-1'));
        Assert::same($store->get('third', 'user-1'), 'c');
    }

    public function byteLimitEvictsOldestUntilSetCookieFits(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer, maxCookieBytes: 400);
        $store->put('first-experiment', 'user-1', str_repeat('a', 100));
        $store->put('second-experiment', 'user-1', str_repeat('b', 100));
        $store->put('third-experiment', 'user-1', str_repeat('c', 100));

        $response = $store->applyToResponse(new Response());
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $restored = CookieAssignmentStore::fromRequest(
            request: $this->requestWithCookieFrom($setCookie),
            signer: $this->signer,
            maxCookieBytes: 400,
        );

        Assert::true(strlen($setCookie) <= 400);
        Assert::null($restored->get('first-experiment', 'user-1'));
        Assert::same($restored->get('third-experiment', 'user-1'), str_repeat('c', 100));
    }

    public function oversizedIncomingCookieIsRejectedBeforeDecode(): void
    {
        $value = $this->signedValue(json_encode(['experiment' => str_repeat('x', 300)], JSON_THROW_ON_ERROR));
        $store = CookieAssignmentStore::fromRequest(
            request: $this->requestWithCookie($value),
            signer: $this->signer,
            maxCookieBytes: 256,
        );

        Assert::null($store->get('experiment', 'user-1'));
    }

    public function configurationAwareEntryRoundTripsAndInvalidatesOnChange(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer);
        $store->putForConfiguration('checkout-button', 'user-1', 'green', 'config-v1');
        $response = $store->applyToResponse(new Response());
        $restored = CookieAssignmentStore::fromRequest(
            $this->requestWithCookieFrom($response->getHeaderLine('Set-Cookie')),
            $this->signer,
        );

        Assert::same(
            $restored->getForConfiguration('checkout-button', 'user-1', 'config-v1'),
            'green',
        );
        Assert::null($restored->getForConfiguration('checkout-button', 'user-1', 'config-v2'));
    }

    public function rejectsZeroEntryLimit(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessage('Maximum cookie entries must be at least 1');

        new CookieAssignmentStore(signer: $this->signer, maxEntries: 0);
    }

    public function rejectsImpossiblySmallByteLimit(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessage('Maximum cookie size must be at least 256 bytes');

        new CookieAssignmentStore(signer: $this->signer, maxCookieBytes: 255);
    }

    public function aStoreExactlyAtTheEntryLimitKeepsEveryAssignment(): void
    {
        $store = new CookieAssignmentStore(
            signer: $this->signer,
            variants: ['first' => 'a', 'second' => 'b'],
            maxEntries: 2,
        );

        Assert::same($store->get('first', 'user-1'), 'a');
        Assert::same($store->get('second', 'user-1'), 'b');
        Assert::false($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
    }

    public function acceptsTheSmallestUsefulEntryLimit(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer, maxEntries: 1);
        $store->put('first', 'user-1', 'a');

        Assert::same($store->get('first', 'user-1'), 'a');
    }

    public function seededVariantsBeyondTheLimitAreEvictedOnConstruction(): void
    {
        $store = new CookieAssignmentStore(
            signer: $this->signer,
            variants: ['first' => 'a', 'second' => 'b', 'third' => 'c'],
            maxEntries: 2,
        );

        Assert::null($store->get('first', 'user-1'));
        Assert::same($store->get('second', 'user-1'), 'b');
        Assert::same($store->get('third', 'user-1'), 'c');
    }

    public function evictionOnConstructionMarksTheStoreDirty(): void
    {
        $store = new CookieAssignmentStore(
            signer: $this->signer,
            variants: ['first' => 'a', 'second' => 'b'],
            maxEntries: 1,
        );

        Assert::true($store->applyToResponse(new Response())->hasHeader('Set-Cookie'));
    }

    public function configurationAwareWritesRespectTheEntryLimit(): void
    {
        $store = new CookieAssignmentStore(signer: $this->signer, maxEntries: 2);
        $store->putForConfiguration('first', 'user-1', 'a', 'c1');
        $store->putForConfiguration('second', 'user-1', 'b', 'c1');
        $store->putForConfiguration('third', 'user-1', 'c', 'c1');

        Assert::null($store->getForConfiguration('first', 'user-1', 'c1'));
        Assert::same($store->getForConfiguration('third', 'user-1', 'c1'), 'c');
    }

    public function aSetCookieHeaderExactlyAtTheLimitIsKept(): void
    {
        $measuring = new CookieAssignmentStore(signer: $this->signer);
        $measuring->put('checkout-button', 'user-1', 'green');
        $exactSize = strlen($measuring->applyToResponse(new Response())->getHeaderLine('Set-Cookie'));

        $store = new CookieAssignmentStore(signer: $this->signer, maxCookieBytes: $exactSize);
        $store->put('checkout-button', 'user-1', 'green');
        $restored = CookieAssignmentStore::fromRequest(
            request: $this->requestWithCookieFrom(
                $store->applyToResponse(new Response())->getHeaderLine('Set-Cookie'),
            ),
            signer: $this->signer,
        );

        Assert::same($restored->get('checkout-button', 'user-1'), 'green');
    }

    public function anIncomingCookieExactlyAtTheLimitIsAccepted(): void
    {
        // the guard rejects a raw cookie *longer* than the limit; a cookie of
        // exactly the limit must still decode. The variant is padded so the
        // signed value clears the 256-byte minimum limit the store accepts.
        $variant = str_repeat('g', 256);
        $value = $this->signedValue(json_encode(['checkout' => $variant], JSON_THROW_ON_ERROR));
        $store = CookieAssignmentStore::fromRequest(
            request: $this->requestWithCookie($value),
            signer: $this->signer,
            maxCookieBytes: strlen($value),
        );

        Assert::same($store->get('checkout', 'user-1'), $variant);
    }

    #[DataProvider('malformedEntryProvider')]
    public function skipsMalformedConfigurationAwareEntries(mixed $entry): void
    {
        $value = $this->signedValue(json_encode(
            ['broken' => $entry, 'valid' => ['v' => 'green', 'c' => 'config-v1']],
            JSON_THROW_ON_ERROR,
        ));
        $store = CookieAssignmentStore::fromRequest($this->requestWithCookie($value), $this->signer);

        Assert::null($store->get('broken', 'user-1'));
        Assert::same($store->getForConfiguration('valid', 'user-1', 'config-v1'), 'green');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedEntryProvider(): iterable
    {
        yield 'scalar entry' => [42];
        yield 'list entry' => [['green']];
        yield 'empty array entry' => [[]];
        yield 'missing configuration id' => [['v' => 'green']];
        yield 'missing variant' => [['c' => 'config-v1']];
        yield 'non-string variant' => [['v' => 42, 'c' => 'config-v1']];
        yield 'non-string configuration id' => [['v' => 'green', 'c' => 42]];
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
