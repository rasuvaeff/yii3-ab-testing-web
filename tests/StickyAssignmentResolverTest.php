<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidExperimentException;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;

#[CoversClass(StickyAssignmentResolver::class)]
final class StickyAssignmentResolverTest extends TestCase
{
    private AbTesting $abTesting;

    private ArrayAssignmentStore $store;

    private StickyAssignmentResolver $resolver;

    #[\Override]
    protected function setUp(): void
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

    #[Test]
    public function assignsFreshAndStoresWhenNothingStored(): void
    {
        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        $this->assertArrayHasKey('checkout-button', $this->store->stored);
        $this->assertSame($assignment->variant, $this->store->stored['checkout-button']);
        $this->assertCount(1, $this->store->puts);
    }

    #[Test]
    public function reusesStoredVariantWithoutReassigning(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        $this->assertSame('control', $assignment->variant);
        $this->assertSame([], $this->store->puts);
    }

    #[Test]
    public function forcedVariantBypassesStore(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1', forcedVariant: 'green');

        $this->assertSame('green', $assignment->variant);
        $this->assertTrue($assignment->isForced);
        $this->assertSame([], $this->store->puts);
    }

    #[Test]
    public function disabledExperimentReturnsFallbackAndIgnoresStore(): void
    {
        $this->store->stored['pricing-page'] = 'variant-b';

        $assignment = $this->resolver->resolve(experiment: 'pricing-page', subjectId: 'user-1');

        $this->assertSame('control', $assignment->variant);
        $this->assertTrue($assignment->isFallback);
        $this->assertSame([], $this->store->puts);
    }

    #[Test]
    public function reassignsWhenStoredVariantNoLongerExists(): void
    {
        $this->store->stored['checkout-button'] = 'removed-variant';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        $this->assertContains($assignment->variant, ['control', 'green']);
        $this->assertSame($assignment->variant, $this->store->stored['checkout-button']);
        $this->assertCount(1, $this->store->puts);
    }

    #[Test]
    public function throwsForUnknownExperiment(): void
    {
        $this->expectException(InvalidExperimentException::class);

        $this->resolver->resolve(experiment: 'nope', subjectId: 'user-1');
    }
}
