# AGENTS.md — yii3-ab-testing-web

Guidance for AI agents working on this package. Read before changing code.

## What this is

Web/identity layer for Yii3 A/B testing: a PSR-15 middleware that gives every
visitor a stable subject id, and a signed-cookie sticky-variant store implementing
the core `AssignmentStore`. Namespace: `Rasuvaeff\Yii3AbTestingWeb`.

Public API:
- `SubjectIdMiddleware` — establishes `subjectId` (cookie `ab_id` or an upstream
  attribute) and exposes it as a request attribute.
- `CookieAssignmentStore implements AssignmentStore` — sticky variants in one
  signed cookie; request-scoped (`fromRequest()` / `applyToResponse()`).
- `StickyAssignmentResolver` — get-or-assign over `AbTesting` + any `AssignmentStore`.

**No config-plugin (`config/di.php`).** A cookie store is request-scoped and cannot
be a DI singleton, and middleware are added to the application's middleware stack
by the app — so there is intentionally nothing to bind. This is a deliberate
omission, not a gap. Wire the pieces in your application (see README).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **A tampered or unsigned cookie is never trusted.** `CookieAssignmentStore`
   reads the variant map only through `CookieSigner::validate`; a missing,
   unsigned, tampered, or malformed cookie yields an empty store, never a partial
   or attacker-controlled map. The subject id is opaque (`random_bytes`, not a
   UUID) and holds no PII, but it is a persistent identifier — honour consent.
4. **Preserve the public contract.** Update both READMEs + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- Requires core `^1.4` (targeting rules and mismatch metadata). Resolves from
  Packagist; no path repository needed.
- **`SubjectIdGeneratorInterface` owns BOTH generation and validation.** They
  cannot be split: the middleware reuses a cookie only when `isValid()` accepts
  it, so a generator whose check rejects its own output mints a new id on every
  request — and since assignment is deterministic in the subject id, the visitor
  flips variants on every page view. `isValid()` is a security boundary: the
  cookie is attacker-controlled and whatever passes becomes the subject id in
  logs and analytics. Anchor patterns with `\z`, never `$` — PCRE's `$` also
  matches before a trailing newline (that was a real hole in 1.0.x).
- `SubjectIdMiddleware`: pre-set attribute (logged-in `userId`) wins → no cookie;
  else reuse `ab_id` cookie (only when the generator accepts it — foreign
  values are regenerated); else generate + set a long-lived `HttpOnly`,
  `SameSite=Lax` cookie. Cookie TTL uses `Max-Age` (a `DateInterval`), so no clock
  dependency. `process()` declares `@throws \Random\RandomException`.
- `CookieAssignmentStore` is browser-scoped: the `$subjectId` argument is ignored
  (the cookie identifies the subject). An anonymous→logged-in visitor keeps the
  variants stored under their anonymous identity — intentional, documented.
  `prune(ExperimentRegistry)` drops entries of removed experiments; call it before
  `applyToResponse()` (rewrite happens only when something changed).
- `StickyAssignmentResolver` precedence: disabled returns fallback before forced
  or sticky resolution (kill switch always wins); forced on an enabled experiment
  bypasses targeting/store; otherwise targeting is evaluated before store access,
  and mismatch returns fallback without reading/writing sticky data. A stored
  variant is reused only while it remains in the experiment (`isSticky = true`);
  fallbacks are not stored.
- Tests use `nyholm/psr7` for PSR-7 messages and a real `Yiisoft\Cookies\CookieSigner`.
- Code: `declare(strict_types=1)`; `SubjectIdMiddleware`/`StickyAssignmentResolver`
  are `final readonly`, `CookieAssignmentStore` is `final` (mutable variant map);
  `#[\Override]`, explicit types.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` and `README.ru.md` together (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
