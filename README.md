# rasuvaeff/yii3-ab-testing-web

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-web.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-web.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-web/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-web.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Web identity and sticky-variant layer for Yii3 A/B testing. Gives every visitor a
stable subject id (so deterministic assignment holds across visits) and, when you
need it, pins a subject to a variant across weight changes via a signed cookie.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can ingest in your prompt context.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

> Assembling a combination? The family's integration matrix lives in the core:
> `vendor/rasuvaeff/yii3-ab-testing/docs/integration.md`.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.6 (`AssignmentResolver` and configuration ids)
- `yiisoft/cookies` ^1.2
- a PSR-7 implementation (e.g. `nyholm/psr7`) and a PSR-15 stack

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-web
```

Upgrading from 1.x? See [UPGRADE.md](UPGRADE.md).

## Identity vs stickiness

Assignment is deterministic in `subjectId` (`sha256(salt:subjectId)`), so a stable
id alone keeps a visitor in the same variant across visits — no variant is stored.
Two cookie roles solve two different problems:

| Need | Use |
|---|---|
| A stable id for anonymous visitors | `SubjectIdMiddleware` (cookie `ab_id`) |
| Keep a variant across requests | `StickyAssignmentMiddleware` |

A logged-in user already has a stable id (`userId`) — set it as the request
attribute upstream and the middleware leaves it alone.

## Subject identity middleware

Add `SubjectIdMiddleware` to your PSR-15 stack. It resolves the subject id and
exposes it through `SubjectIdRequestAccessor` (and keeps the historical string
attribute `ab.subjectId` for compatibility):

1. if the attribute is already set (an upstream auth middleware put `userId` there)
   it is kept — no cookie;
2. otherwise the `ab_id` cookie is reused — only when the `SubjectIdGeneratorInterface` recognises the value as its own (32 lowercase hex chars by default); a tampered or oversized value is discarded and regenerated;
3. otherwise a new opaque id is generated; it is persisted as an `HttpOnly`,
   `SameSite=Lax` cookie only when the consent policy permits persistence.

```php
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

$consent = new CallbackConsentPolicy(
    static fn ($request): bool => $request->getAttribute('analyticsConsent') === true,
);
$middleware = new SubjectIdMiddleware(consentPolicy: $consent);

// in your action/handler:
$subjectId = (new SubjectIdRequestAccessor())->require($request);
$assignment = $ab->resolve(experiment: 'checkout-button', subjectId: $subjectId->value);
```

Before consent, incoming identity and assignment cookies are ignored, a fresh
`SubjectIdSource::Ephemeral` id exists only for the request, and no cookie is
written. When the request still carries an `ab_id` or `ab_variants` cookie that
earlier consent wrote, the response expires it — withdrawing consent clears the
identifier instead of leaving it to travel in request headers until its own
max-age runs out. A request without those cookies gets no `Set-Cookie` header at
all. `AllowAllConsentPolicy` remains the default for backward compatibility;
applications that require consent must pass their policy to both middleware.

### Anonymous to authenticated identity

Configure `identityTransition` when auth middleware supplies `ab.subjectId` and
an anonymous `ab_id` cookie also exists:

| Strategy | Subject id after login | Existing browser assignments |
|---|---|---|
| `MigrateAssignments` (default) | authenticated id | retained |
| `UseAuthenticatedId` | authenticated id | discarded and started afresh |
| `KeepAnonymousId` | anonymous cookie id | retained |

```php
use Rasuvaeff\Yii3AbTestingWeb\AnonymousToAuthenticatedStrategy;

$middleware = new SubjectIdMiddleware(
    consentPolicy: $consent,
    identityTransition: AnonymousToAuthenticatedStrategy::UseAuthenticatedId,
);
```

### Custom subject id format

The id format and the check that accepts it back from the cookie are one
contract, `SubjectIdGeneratorInterface`. The default `HexSubjectIdGenerator`
produces 32 lowercase hex characters and accepts nothing else:

```php
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdGeneratorInterface;

final readonly class PrefixedSubjectIdGenerator implements SubjectIdGeneratorInterface
{
    public function generate(): string
    {
        return 'sub_' . bin2hex(random_bytes(8));
    }

    public function isValid(string $id): bool
    {
        return preg_match('/^sub_[0-9a-f]{16}\z/', $id) === 1;
    }
}

$middleware = new SubjectIdMiddleware(idGenerator: new PrefixedSubjectIdGenerator());
```

Implement both halves or the middleware rejects its own cookie on the next
request, mints a fresh id every time and — assignment being deterministic in
the subject id — flips the visitor between variants on every page view.

`isValid()` is a security boundary, not a formality: the cookie is
attacker-controlled and whatever passes becomes the subject id in your logs and
analytics. Anchor the pattern with `\z`, not `$` — PCRE's `$` also matches
before a trailing newline.

To tie the id to the logged-in user, no generator is needed: an upstream
middleware that sets the `ab.subjectId` attribute wins over both cookie and
generator (rule 1 above), so the same person keeps one variant across devices.


## Sticky variants

Use the ready PSR-15 `StickyAssignmentMiddleware` after `SubjectIdMiddleware`.
It creates a request-scoped `CookieAssignmentStore`, exposes an
`AssignmentResolver`, and applies changed assignments to the response:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware;
use Yiisoft\Cookies\CookieSigner;

$identityMiddleware = new SubjectIdMiddleware(consentPolicy: $consent);
$stickyMiddleware = new StickyAssignmentMiddleware(
    resolver: $ab,
    signer: new CookieSigner($secretKey),
    consentPolicy: $consent,
    maxEntries: 50,
    maxCookieBytes: 3800,
);
```

Then in your action:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

$subjectId = (new SubjectIdRequestAccessor())->require($request);
$resolver = (new StickyAssignmentRequestAccessor())->resolver($request);

$assignment = $resolver->resolve(
    experiment: 'checkout-button',
    subjectId: $subjectId->value,
);
```

The signed cookie is capped by both `maxEntries` and the actual `Set-Cookie`
header size (`maxCookieBytes`). Eviction is deterministic FIFO; updating an
experiment makes it newest. Oversized incoming cookies are rejected before JSON
decoding. Entries carry core's `configurationId`, so a changed experiment
definition invalidates its old sticky assignment. The v1 string-map format is
still accepted.

`StickyAssignmentResolver` implements core `AssignmentResolver`. Fallback,
forced and targeting decisions return before store access; disabled experiments
remain a kill switch. `AbTesting::assign()` stays pure.

## API reference

| Class | Description |
|---|---|
| `SubjectIdMiddleware` | PSR-15 middleware; stable subject id + `ab_id` cookie |
| `SubjectId`, `SubjectIdSource` | typed identity value and anonymous/authenticated/ephemeral source |
| `SubjectIdRequestAccessor` | typed request access while preserving `ab.subjectId` compatibility |
| `ConsentPolicyInterface` | persistence decision; use `CallbackConsentPolicy` for application consent |
| `AnonymousToAuthenticatedStrategy` | keep anonymous, use authenticated, or migrate assignments |
| `SubjectIdGeneratorInterface` | `generate()` + `isValid()`: the id format and the check that accepts it back |
| `HexSubjectIdGenerator` | default: 32 lowercase hex characters |
| `CookieAssignmentStore` | `AssignmentStore` over one signed cookie; `fromRequest()` / `applyToResponse()` |
| `SignedReceiptCodec` | Signs an `AssignmentReceipt` into a transport-independent string, so a SPA can return it in a JSON body |
| `StickyAssignmentMiddleware` | ready request-scoped cookie store + sticky resolver PSR-15 integration |
| `StickyAssignmentRequestAccessor` | typed access to the request resolver and store |
| `StickyAssignmentResolver` | `AssignmentResolver` decorator over any core resolver + store |

## Security & privacy

- The subject id is an opaque 128-bit token (`random_bytes`), not a UUID, and
  carries no personal data, but it is a persistent identifier. A denied consent
  policy prevents both identity and sticky-cookie reads/writes, and expires any
  such cookie the request still carries.
- The sticky cookie is signed (`yiisoft/cookies` `CookieSigner`); a missing,
  unsigned, tampered, or malformed cookie is ignored and yields an empty store —
  never a partial or attacker-controlled variant map. Provide a strong signing key.
- Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` by default.
- The sticky cookie is browser-scoped: the `$subjectId` argument of the store is
  ignored. Choose the authenticated transition explicitly for your product.

## Examples

See [examples/](examples/) for a runnable script (no server required).

## Development

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
