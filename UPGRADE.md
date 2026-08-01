# Upgrade guide

## 1.x → 2.0

This release exists because `rasuvaeff/yii3-ab-testing` 2.0 changed the event
contract; read [its upgrade guide](https://github.com/rasuvaeff/yii3-ab-testing/blob/master/UPGRADE.md)
first, since everything below assumes it.

**Nothing stored changes.** The signed sticky cookie keeps its format (the v1
string-map form is still accepted), the `ab_id` subject cookie is untouched, and
bucketing is unchanged — so no visitor changes variant and no cookie is
invalidated by upgrading. There is no data migration and no downtime step.

### `ConfigurationAwareAssignmentStore` moved to the core

The interface now lives in `Rasuvaeff\Yii3AbTesting`. If you implement it,
change the import:

```php
// 1.x
use Rasuvaeff\Yii3AbTestingWeb\ConfigurationAwareAssignmentStore;

// 2.0
use Rasuvaeff\Yii3AbTesting\ConfigurationAwareAssignmentStore;
```

It moved because more than one storage implements it — the cookie store here and
`DbAssignmentStore` in `yii3-ab-testing-db` — and two sibling adapters must not
depend on each other to share a contract.

**Do not keep a local copy of the interface to avoid the edit.** A store
implementing your copy still satisfies `AssignmentStore`, so nothing fails: it
just stops being recognised. `StickyAssignmentResolver` selects the
configuration-aware path with `instanceof`, so the store silently falls back to
the plain contract, and a variant pinned under old weights is replayed after a
reweight instead of being dropped. That failure is invisible in the response and
shows up only as a skewed experiment.

### Assignments returned by the resolver report the decision differently

`Assignment`'s four boolean properties became methods in core 2.0, and this
package returns those objects from `StickyAssignmentResolver::resolve()`:

```php
// 1.x
if ($assignment->isSticky) { … }

// 2.0
if ($assignment->isSticky()) { … }
```

`$assignment->isSticky` now reads a property that does not exist: PHP raises a
warning and evaluates it to null, so the branch silently stops being taken.
Search for `->isSticky`, `->isForced`, `->isFallback` and
`->isTargetingMismatch` without parentheses.

Internally a stored variant is now marked with `source: AssignmentSource::Store`
rather than the old `isSticky: true` flag. `isSticky()` answers the same
question, so this matters only if you construct `Assignment` yourself.

### New: receipts without cookies (`SignedReceiptCodec`)

Additive — no action required unless you want it.

Signing used to live inside `CookieAssignmentStore`, tied to `CookieSigner`,
which made it unusable where a cookie is not the transport. A single-page
application holds the receipt in `localStorage` and posts it back in a JSON body:

```php
use Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec;

$codec = new SignedReceiptCodec(secret: $key);   // ≥ 32 bytes

$exposure = $abTesting->trackExposure($assignment);
$token = $codec->encode($exposure->receipt());   // hand to the client

// …a later request, days later
$receipt = $codec->decode($token);               // null if forged or absent
if ($receipt !== null) {
    $abTesting->trackConversionForReceipt($receipt, goal: 'purchase');
}
```

`decode()` returns null for anything this codec did not produce — missing,
malformed, tampered with, or signed by another secret — and the caller must
treat that exactly like "no receipt": a forged one must not be distinguishable
from an absent one by its effect.

Re-resolving on the server is not a substitute. After a reweight it returns the
variant the visitor *would* get now, not the one they saw, which is the whole
reason receipts exist. Without a signature a client could claim variant B while
assigned A, corrupting the analytics rather than breaking anything visibly.

Rotate the secret by deploying a new value; outstanding receipts stop decoding,
which is the intended effect of a rotation.

### No action needed for

- `SubjectIdMiddleware`, `SubjectId`, the generators and the `ab_id` cookie;
- `ConsentPolicyInterface`, `AllowAllConsentPolicy`, `CallbackConsentPolicy`;
- `StickyAssignmentMiddleware` wiring, middleware order and cookie limits;
- `CookieAssignmentStore`'s stored format and eviction behaviour;
- application `config/*` — this package ships no `config/di.php` on purpose.
