<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\AssignmentResolver;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidExperimentException;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
        $this->resolver = new StickyAssignmentResolver(resolver: $this->abTesting, store: $this->store);
    }

    public function assignsFreshAndStoresWhenNothingStored(): void
    {
        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::array($this->store->stored)->hasKeys('checkout-button');
        Assert::same($this->store->stored['checkout-button'], $assignment->variant);
        Assert::count($this->store->gets, 1);
        Assert::count($this->store->puts, 1);
    }

    public function reusesStoredVariantWithoutWriting(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::same($assignment->variant, 'control');
        Assert::true($assignment->isSticky());
        Assert::count($this->store->gets, 1);
        Assert::same($this->store->puts, []);
    }

    public function freshAssignmentIsNotSticky(): void
    {
        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::false($assignment->isSticky());
    }

    public function forcedVariantBypassesStore(): void
    {
        $this->store->stored['checkout-button'] = 'control';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1', forcedVariant: 'green');

        Assert::same($assignment->variant, 'green');
        Assert::true($assignment->isForced());
        Assert::same($this->store->gets, []);
        Assert::same($this->store->puts, []);
    }

    public function disabledExperimentReturnsFallbackAndIgnoresStore(): void
    {
        $this->store->stored['pricing-page'] = 'variant-b';

        $assignment = $this->resolver->resolve(experiment: 'pricing-page', subjectId: 'user-1');

        Assert::same($assignment->variant, 'control');
        Assert::true($assignment->isFallback());
        Assert::same($this->store->gets, []);
        Assert::same($this->store->puts, []);
    }

    public function disabledExperimentWinsOverForcedVariantAndIgnoresStore(): void
    {
        $this->store->stored['pricing-page'] = 'variant-b';

        $assignment = $this->resolver->resolve(
            experiment: 'pricing-page',
            subjectId: 'user-1',
            forcedVariant: 'variant-b',
        );

        Assert::same($assignment->variant, 'control');
        // Assert the reason itself: with one enum, `isFallback() && !isForced()`
        // is true by construction and would pass even if precedence broke.
        Assert::same($assignment->reason, DecisionReason::FallbackDisabled);
        Assert::same($this->store->gets, []);
        Assert::same($this->store->puts, []);
    }

    #[DataProvider('targetingMismatchContextProvider')]
    public function changedTargetingAttributesIgnorePreviousStickyAssignment(AssignmentContext $context): void
    {
        $resolver = $this->targetedResolver();
        $eligibleContext = new AssignmentContext(attributes: ['country' => 'DE', 'plan' => 'pro']);
        $initial = $resolver->resolve(
            experiment: 'targeted-checkout',
            subjectId: 'user-1',
            context: $eligibleContext,
        );
        Assert::false($initial->isFallback());
        Assert::count($this->store->puts, 1);

        $this->store->gets = [];
        $this->store->puts = [];

        $assignment = $resolver->resolve(
            experiment: 'targeted-checkout',
            subjectId: 'user-1',
            context: $context,
        );

        Assert::same($assignment->variant, 'control');
        Assert::true($assignment->isFallback());
        Assert::true($assignment->isTargetingMismatch());
        Assert::false($assignment->isSticky());
        Assert::same($this->store->gets, []);
        Assert::same($this->store->puts, []);
    }

    public static function targetingMismatchContextProvider(): iterable
    {
        yield 'country changed' => [
            new AssignmentContext(attributes: ['country' => 'US', 'plan' => 'pro']),
        ];
        yield 'plan changed' => [
            new AssignmentContext(attributes: ['country' => 'DE', 'plan' => 'free']),
        ];
    }

    public function forcedVariantBypassesTargetingAndStoreForEnabledExperiment(): void
    {
        $this->store->stored['targeted-checkout'] = 'control';

        $assignment = $this->targetedResolver()->resolve(
            experiment: 'targeted-checkout',
            subjectId: 'user-1',
            forcedVariant: 'green',
            context: new AssignmentContext(attributes: ['country' => 'US', 'plan' => 'free']),
        );

        Assert::same($assignment->variant, 'green');
        // Same reasoning: the reason must be Forced, not merely "not a
        // targeting mismatch", which one enum makes automatic.
        Assert::same($assignment->reason, DecisionReason::Forced);
        Assert::same($this->store->gets, []);
        Assert::same($this->store->puts, []);
    }

    public function reassignsWhenStoredVariantNoLongerExists(): void
    {
        $this->store->stored['checkout-button'] = 'removed-variant';

        $assignment = $this->resolver->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::true(in_array($assignment->variant, ['control', 'green'], strict: true));
        Assert::same($this->store->stored['checkout-button'], $assignment->variant);
        Assert::count($this->store->gets, 1);
        Assert::count($this->store->puts, 1);
    }

    public function aNonCoreResolverKeepsItsStoredVariantUnchecked(): void
    {
        // the "variant still exists" guard reads the core registry; behind any
        // other AssignmentResolver there is nothing to check against, so the
        // stored variant must be honoured as-is
        $inner = new readonly class implements AssignmentResolver {
            #[\Override]
            public function resolve(
                string $experiment,
                string $subjectId,
                ?string $forcedVariant = null,
                ?AssignmentContext $context = null,
            ): Assignment {
                return new Assignment(experiment: $experiment, variant: 'control', subjectId: $subjectId);
            }
        };
        $store = new ArrayAssignmentStore();
        $store->stored['checkout-button'] = 'variant-from-another-system';

        $assignment = (new StickyAssignmentResolver(resolver: $inner, store: $store))
            ->resolve(experiment: 'checkout-button', subjectId: 'user-1');

        Assert::same($assignment->variant, 'variant-from-another-system');
        Assert::true($assignment->isSticky());
    }

    public function throwsForUnknownExperiment(): void
    {
        Expect::exception(InvalidExperimentException::class);

        $this->resolver->resolve(experiment: 'nope', subjectId: 'user-1');
    }

    private function targetedResolver(): StickyAssignmentResolver
    {
        $provider = new readonly class implements ExperimentProvider {
            #[\Override]
            public function getExperiments(): array
            {
                return [
                    'targeted-checkout' => new Experiment(
                        name: 'targeted-checkout',
                        enabled: true,
                        salt: 'targeted-v1',
                        fallbackVariant: 'control',
                        variants: ['control' => 50, 'green' => 50],
                        targeting: new AndTargetingRule(rules: [
                            new AttributeTargetingRule(attribute: 'country', value: 'DE'),
                            new AttributeTargetingRule(attribute: 'plan', value: 'pro'),
                        ]),
                    ),
                ];
            }
        };

        return new StickyAssignmentResolver(
            resolver: new AbTesting(
                provider: $provider,
                strategy: new WeightedHashAssignmentStrategy(),
            ),
            store: $this->store,
        );
    }
}
