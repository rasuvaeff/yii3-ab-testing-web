# AGENTS.md — yii3-ab-testing-web

Guidance for AI agents working on this package. Read before changing code.

## What this is

Web/identity layer for Yii3 A/B testing: a PSR-15 middleware that gives every
visitor a stable subject id, and a signed-cookie sticky-variant store implementing
the core `AssignmentStore`. Namespace: `Rasuvaeff\Yii3AbTestingWeb`.

Public API:
- `SubjectIdMiddleware` — establishes `subjectId` (cookie `ab_id` or an upstream
  attribute) and exposes typed + legacy request attributes.
- `StickyAssignmentMiddleware` — ready request-scoped PSR-15 integration.
- `CookieAssignmentStore implements AssignmentStore` — sticky variants in one
  signed cookie; request-scoped (`fromRequest()` / `applyToResponse()`).
- `StickyAssignmentResolver` — core `AssignmentResolver` decorator over another
  resolver + any `AssignmentStore`.
- `SignedReceiptCodec` — signs a core `AssignmentReceipt` into a
  transport-independent string. Use it wherever the receipt leaves the server
  without a cookie (a SPA posting it back in JSON). Never trust an unsigned
  receipt: a client that edits the variant would corrupt analytics silently,
  and re-resolving server-side answers a different question — after a reweight
  it returns the variant the visitor *would* get now, not the one they saw.

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

## Mutation testing

`minMsi` is 98, and the gap is accounted for rather than unexplained. Of 188
mutants: 182 killed outright, 4 timed out, 2 escaped.

**The 4 timeouts are real detections, not flakiness.** Each removes the call or
flips the comparison that makes an eviction loop terminate
(`applyToResponse`'s `do…while(true)`, `enforceEntryLimit`'s `while`,
`evictOldest`'s null guard), so the mutant hangs — which is exactly what the
code would do in production. Do not "fix" them by adding time limits.

**The 2 escaped are equivalent under this code's constraints:**

| Where | Mutation | Why no test can kill it |
|---|---|---|
| `CookieAssignmentStore` `!$signer->isSigned()` guard | `return` removed | `CookieSigner::validate()` throws `RuntimeException` on an unsigned cookie, and the surrounding `catch (\RuntimeException…)` returns the same empty maps. The guard is explicit flow rather than exception-driven flow, so it stays. |
| `toEntryMaps` guard chain, first `\|\|` → `&&` | operand merge | Killing it needs a non-array whose `isset($x['v'])` is true — an `ArrayAccess` object. `json_decode(assoc: true)` never produces one, and strings are handled by the branch above. |

If a change makes either killable, kill it. Adding a **third** escaped mutant
fails the gate — strengthen the assertion, never lower `minMsi`.

## Invariants & gotchas

- **Withdrawn consent clears cookies, it does not merely stop touching them.**
  Both middleware expire an `ab_id`/`ab_variants` the request still carries when
  the policy denies persistence; a persistent identifier that keeps riding in
  request headers for up to a year is exactly what the consent governs. Two
  rules hold the behaviour together: the deletion must repeat the `secure`,
  `SameSite` and path the cookie was written with (a browser matches on those
  before replacing anything), and a request without the cookie must produce no
  `Set-Cookie` at all. The sticky deletion lives in
  `CookieAssignmentStore::applyDeletionToResponse()`, not in `applyToResponse()`
  — a store built under a denied policy is never dirty, and that method's job is
  writing, which consent forbids.

- Requires core `^2.0`. `ConfigurationAwareAssignmentStore` now lives in the
  **core**, not here: the database package implements it too, and sibling
  adapters must not depend on each other to share a store contract. A local
  copy of that interface would be worse than a duplicate — `StickyAssignmentResolver`
  matches on it with `instanceof`, so a store implementing the *other* copy
  would silently take the non-configuration-aware branch and reuse a variant
  across a reweight.
- **`SubjectIdGeneratorInterface` owns BOTH generation and validation.** They
  cannot be split: the middleware reuses a cookie only when `isValid()` accepts
  it, so a generator whose check rejects its own output mints a new id on every
  request — and since assignment is deterministic in the subject id, the visitor
  flips variants on every page view. `isValid()` is a security boundary: the
  cookie is attacker-controlled and whatever passes becomes the subject id in
  logs and analytics. Anchor patterns with `\z`, never `$` — PCRE's `$` also
  matches before a trailing newline (that was a real hole in 1.0.x).
- `SubjectIdMiddleware`: consent is checked before reading or writing `ab_id`;
  denied consent gets a request-only ephemeral id. Auth transitions are explicit
  (`UseAuthenticatedId`, `KeepAnonymousId`, `MigrateAssignments`). Cookie TTL
  uses `Max-Age`, so there is no clock dependency.
- `CookieAssignmentStore` is browser-scoped: the `$subjectId` argument is ignored
  (the cookie identifies the subject). It is capped by entries and actual
  `Set-Cookie` bytes; deterministic eviction is FIFO and updating an entry moves
  it to newest. Never remove either limit or decode an oversized input.
  `prune(ExperimentRegistry)` drops entries of removed experiments; call it before
  `applyToResponse()` (rewrite happens only when something changed).
- Configuration-aware cookie entries carry core `configurationId`; retain v1
  string-map decoding, but never reuse an entry for a different configuration.
- `StickyAssignmentResolver` precedence: disabled returns fallback before forced
  or sticky resolution (kill switch always wins); forced on an enabled experiment
  bypasses targeting/store; otherwise targeting is evaluated before store access,
  and mismatch returns fallback without reading/writing sticky data. A stored
  variant is reused only while it remains in the experiment
  (`source: AssignmentSource::Store`); fallbacks are not stored.
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
