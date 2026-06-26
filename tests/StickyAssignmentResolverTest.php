<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidExperimentException;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(StickyAssignmentResolver::class)]
final class StickyAssignmentResolverTest
{
    private AbTesting $abTesting;

    private ArrayAssignmentStore $store;

    private StickyAssignmentResolver $resolver;

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
                'pricing-page' => [
                    'enabled' => false,
                    'salt' => 'pricing-v1',
                    'fallbackVariant' => 'control',
                    'variants' => ['control' => 50, 'variant-b' => 50],
                ],
            ]),
            strategy: new WeightedHashAssignmentStrategy(),
        );
        $this->store = new ArrayAssignmentStore();
        $this->resolver = new StickyAssignmentResolver(abTesting: $this->abTesting, store: $this->store);
    }

    public function assignsFreshAndStoresWhenNothingStored(): void
    {
        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::array($this->store->stored)->hasKeys('checkout-button');
        Assert::same($this->store->stored['checkout-button'], $assignment->variant);
        Assert::count($this->store->puts, 1);
    }

    public function reusesStoredVariantWithoutReassigning(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::same($assignment->variant, 'control');
        Assert::true($assignment->isSticky);
        Assert::same($this->store->puts, []);
    }

    public function freshAssignmentIsNotSticky(): void
    {
        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::false($assignment->isSticky);
    }

    public function forcedVariantBypassesStore(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1', forcedVariant: 'green');

        Assert::same($assignment->variant, 'green');
        Assert::true($assignment->isForced);
        Assert::same($this->store->puts, []);
    }

    public function disabledExperimentReturnsFallbackAndIgnoresStore(): void
    {
        $this->store->stored['pricing-page'] = 'variant-b';

        $assignment = $this->resolver->resolve(experiment: 'pricing-page', subjectId: 'user-1');

        Assert::same($assignment->variant, 'control');
        Assert::true($assignment->isFallback);
        Assert::same($this->store->puts, []);
    }

    public function reassignsWhenStoredVariantNoLongerExists(): void
    {
        $this->store->stored['checkout-button'] = 'removed-variant';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::true(in_array($assignment->variant, ['control', 'green'], true));
        Assert::same($this->store->stored['checkout-button'], $assignment->variant);
        Assert::count($this->store->puts, 1);
    }

    public function throwsForUnknownExperiment(): void
    {
        Expect::exception(InvalidExperimentException::class);

        $this->resolver->resolve(experiment: 'nope', subjectId: 'user-1');
    }
}
