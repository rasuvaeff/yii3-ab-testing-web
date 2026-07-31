<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Cookies\CookieSigner;

#[Test]
#[Covers(StickyAssignmentRequestAccessor::class)]
final class StickyAssignmentRequestAccessorTest
{
    public function roundTripsResolverAndStore(): void
    {
        $accessor = new StickyAssignmentRequestAccessor();
        $resolver = new AbTesting(
            provider: new ConfigExperimentProvider(config: []),
            strategy: new WeightedHashAssignmentStrategy(),
        );
        $store = new CookieAssignmentStore(
            signer: new CookieSigner('test-secret-key-test-secret-key-32'),
        );
        $request = $accessor->with(new ServerRequest('GET', '/'), $resolver, $store);

        Assert::same($accessor->resolver($request), $resolver);
        Assert::same($accessor->store($request), $store);
    }

    public function resolverThrowsWhenMissing(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage('Sticky assignment resolver is not available on the request');

        (new StickyAssignmentRequestAccessor())->resolver(new ServerRequest('GET', '/'));
    }

    public function storeThrowsWhenMissing(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage('Cookie assignment store is not available on the request');

        (new StickyAssignmentRequestAccessor())->store(new ServerRequest('GET', '/'));
    }
}
