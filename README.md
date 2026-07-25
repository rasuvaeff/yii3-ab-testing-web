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

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2 (adds `AssignmentStore` and `Assignment::isSticky`)
- `yiisoft/cookies` ^1.2
- a PSR-7 implementation (e.g. `nyholm/psr7`) and a PSR-15 stack

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-web
```

## Identity vs stickiness

Assignment is deterministic in `subjectId` (`sha256(salt:subjectId)`), so a stable
id alone keeps a visitor in the same variant across visits — no variant is stored.
Two cookie roles solve two different problems:

| Need | Use |
|---|---|
| A stable id for anonymous visitors | `SubjectIdMiddleware` (cookie `ab_id`) |
| Keep a variant even after weights/variants change | `CookieAssignmentStore` + `StickyAssignmentResolver` |

A logged-in user already has a stable id (`userId`) — set it as the request
attribute upstream and the middleware leaves it alone.

## Subject identity middleware

Add `SubjectIdMiddleware` to your PSR-15 stack. It resolves the subject id and
exposes it as a request attribute (`ab.subjectId` by default):

1. if the attribute is already set (an upstream auth middleware put `userId` there)
   it is kept — no cookie;
2. otherwise the `ab_id` cookie is reused — only when the `SubjectIdGeneratorInterface` recognises the value as its own (32 lowercase hex chars by default); a tampered or oversized value is discarded and regenerated;
3. otherwise a new opaque id is generated and a long-lived `HttpOnly`,
   `SameSite=Lax` cookie is set.

```php
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;

$middleware = new SubjectIdMiddleware(); // defaults: cookie 'ab_id', attribute 'ab.subjectId'

// in your action/handler:
$subjectId = $request->getAttribute('ab.subjectId');
$assignment = $ab->assign(experiment: 'checkout-button', subjectId: $subjectId);
```

For most experiments this is all you need.
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

Changing weights or the variant set shifts bucket boundaries and reshuffles
subjects. To pin a subject across such changes, resolve through a
`CookieAssignmentStore` (a signed `{experiment: variant}` cookie) and a
`StickyAssignmentResolver`. Because the store is request-scoped, wire it in a thin
middleware that reads the cookie, exposes the store, and writes it back:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Yiisoft\Cookies\CookieSigner;

final class StickyCookieMiddleware implements MiddlewareInterface
{
    public function __construct(private CookieSigner $signer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $store = CookieAssignmentStore::fromRequest($request, $this->signer);
        $response = $handler->handle($request->withAttribute('ab.store', $store));

        return $store->applyToResponse($response);
    }
}
```

Then in your action:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;

$store = $request->getAttribute('ab.store');                 // CookieAssignmentStore
$resolver = new StickyAssignmentResolver($ab, $store);

$assignment = $resolver->resolve(
    experiment: 'checkout-button',
    subjectId: $request->getAttribute('ab.subjectId'),
);
// first time: assigned and stored; later: the stored variant is returned
```

`StickyAssignmentResolver` keeps `AbTesting::assign()` pure: a forced variant
bypasses the store, a disabled experiment returns its fallback (the kill switch
always wins and nothing is stored), and a stored variant that is no longer part of
the experiment is re-assigned.

## API reference

| Class | Description |
|---|---|
| `SubjectIdMiddleware` | PSR-15 middleware; stable subject id + `ab_id` cookie |
| `SubjectIdGeneratorInterface` | `generate()` + `isValid()`: the id format and the check that accepts it back |
| `HexSubjectIdGenerator` | default: 32 lowercase hex characters |
| `CookieAssignmentStore` | `AssignmentStore` over one signed cookie; `fromRequest()` / `applyToResponse()` |
| `StickyAssignmentResolver` | get-or-assign over `AbTesting` + any `AssignmentStore` |

## Security & privacy

- The subject id is an opaque 128-bit token (`random_bytes`), not a UUID, and
  carries no personal data — but it is a persistent identifier. Set the cookie
  only after consent where the law requires it.
- The sticky cookie is signed (`yiisoft/cookies` `CookieSigner`); a missing,
  unsigned, tampered, or malformed cookie is ignored and yields an empty store —
  never a partial or attacker-controlled variant map. Provide a strong signing key.
- Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` by default.
- The cookie is browser-scoped: the `$subjectId` argument of the store is ignored.
  A visitor who was anonymous then logged in keeps the variants from their
  anonymous identity.

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
