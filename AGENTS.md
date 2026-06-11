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
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make mutation`. `composer.lock` is gitignored (library).

## Invariants & gotchas

- Requires core `^1.2` (`AssignmentStore`, `Assignment::isSticky`). Resolves from
  Packagist; no path repository needed.
- `SubjectIdMiddleware`: pre-set attribute (logged-in `userId`) wins → no cookie;
  else reuse `ab_id` cookie (only when it matches `/^[0-9a-f]{32}$/` — foreign
  values are regenerated); else generate + set a long-lived `HttpOnly`,
  `SameSite=Lax` cookie. Cookie TTL uses `Max-Age` (a `DateInterval`), so no clock
  dependency. `process()` declares `@throws \Random\RandomException`.
- `CookieAssignmentStore` is browser-scoped: the `$subjectId` argument is ignored
  (the cookie identifies the subject). An anonymous→logged-in visitor keeps the
  variants stored under their anonymous identity — intentional, documented.
  `prune(ExperimentRegistry)` drops entries of removed experiments; call it before
  `applyToResponse()` (rewrite happens only when something changed).
- `StickyAssignmentResolver`: forced variant bypasses the store; a disabled
  experiment returns its fallback and never reads/writes the store (kill switch
  always wins); a stored variant is reused only while it is still a variant of the
  experiment (and is served with `isSticky = true`); fallbacks are not stored.
- Tests use `nyholm/psr7` for PSR-7 messages and a real `Yiisoft\Cookies\CookieSigner`.
- Code: `declare(strict_types=1)`; `SubjectIdMiddleware`/`StickyAssignmentResolver`
  are `final readonly`, `CookieAssignmentStore` is `final` (mutable variant map);
  `#[\Override]`, explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` and paste the output.
