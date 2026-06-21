<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Benchmarks;

use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Testo\Bench;
use Yiisoft\Cookies\CookieSigner;
use Yiisoft\Security\Mac;

final class AdapterBench
{
    #[Bench(
        callables: [
            'ten-variants' => [self::class, 'storeTenVariants'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function storeOneVariant(): string
    {
        $signer = new CookieSigner(mac: new Mac(), key: str_repeat('k', 32));
        $store = new CookieAssignmentStore(signer: $signer);
        $store->put(experiment: 'checkout-button', subjectId: 'u1', variant: 'treatment');

        return $store->get(experiment: 'checkout-button', subjectId: 'u1') ?? '';
    }

    public static function storeTenVariants(): string
    {
        $signer = new CookieSigner(mac: new Mac(), key: str_repeat('k', 32));
        $store = new CookieAssignmentStore(signer: $signer);

        foreach (range(1, 10) as $i) {
            $store->put(experiment: "exp-{$i}", subjectId: 'u1', variant: $i % 2 === 0 ? 'treatment' : 'control');
        }

        return $store->get(experiment: 'exp-5', subjectId: 'u1') ?? '';
    }
}
