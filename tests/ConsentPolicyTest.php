<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTestingWeb\AllowAllConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AllowAllConsentPolicy::class)]
#[Covers(CallbackConsentPolicy::class)]
final class ConsentPolicyTest
{
    public function allowAllAlwaysPermitsPersistence(): void
    {
        Assert::true((new AllowAllConsentPolicy())->allowsPersistence(new ServerRequest('GET', '/')));
    }

    public function callbackReceivesRequestAndControlsDecision(): void
    {
        $policy = new CallbackConsentPolicy(
            static fn(ServerRequestInterface $request): bool => $request->getAttribute('consent') === true,
        );

        Assert::false($policy->allowsPersistence(new ServerRequest('GET', '/')));
        Assert::true($policy->allowsPersistence(
            (new ServerRequest('GET', '/'))->withAttribute('consent', true),
        ));
    }
}
