---
name: rasuvaeff-yii3-ab-testing-web
description: >-
  Web and identity layer for Yii3 A/B testing with rasuvaeff/yii3-ab-testing-web
  — SubjectIdMiddleware, StickyAssignmentMiddleware, CookieAssignmentStore,
  consent policy, SignedReceiptCodec. Use when writing, reviewing or debugging
  visitor identity, sticky variants, cookie handling, consent, or SPA/headless
  integration in a project that has this package installed.
---

# rasuvaeff/yii3-ab-testing-web

Gives every visitor a stable subject id and keeps their variant across
requests. Namespace `Rasuvaeff\Yii3AbTestingWeb\`.

## Safety rules — verify these on every change

1. **`SubjectIdGeneratorInterface::isValid()` is a security boundary.** The
   cookie is attacker-controlled and whatever passes becomes the subject id in
   logs and analytics. Anchor patterns with `\z`, never `$` — PCRE's `$` also
   matches before a trailing newline, which was a real hole in 1.0.x.

2. **Generation and validation cannot be split.** The middleware reuses a cookie
   only when `isValid()` accepts it, so a generator whose check rejects its own
   output mints a new id every request — and since assignment is deterministic
   in the subject id, the visitor flips variants on every page view.

3. **A tampered cookie yields an empty store, never a partial one.** Read the
   variant map only through `CookieSigner::validate`.

4. **Never trust an unsigned receipt.** A client that edits the variant would
   corrupt analytics silently. Use `SignedReceiptCodec` wherever the receipt
   leaves the server without a cookie. Re-resolving server-side is not a
   substitute: after a reweight it returns the variant the visitor *would* get
   now, not the one they saw — which is the whole reason receipts exist.

5. **Resolution order is load-bearing:** disabled → forced → targeting → store →
   deterministic. A disabled experiment returns its fallback before any sticky
   lookup, so the kill switch always wins; a targeting mismatch never reads or
   writes the store, so stale stickiness cannot bypass targeting.

6. **No `config/di.php`, deliberately.** A cookie store is request-scoped and
   cannot be a DI singleton, and middleware are added by the application. This
   is a deliberate omission, not a gap — do not "fix" it.

## Cookies are bounded, and that matters

`CookieAssignmentStore` is capped by entries *and* by actual `Set-Cookie` bytes,
with FIFO eviction; updating an entry moves it to newest. Never remove either
limit or decode an oversized input. `prune(ExperimentRegistry)` drops entries of
experiments that no longer exist — call it before `applyToResponse()`.

`ConfigurationAwareAssignmentStore` lives in the **core**, not here: the
database package implements it too, and sibling adapters must not depend on
each other. A local copy would be worse than a duplicate — the resolver matches
on it with `instanceof`, so a store implementing the other copy silently loses
configuration awareness and reuses a variant across a reweight.

## SPA and headless

The server no longer knows whether the visitor saw the variant — only the client
does. So the assignments endpoint must **not** track exposure: that counts
prefetches, routes never reached and repeat navigations. Exposure becomes a call
the client makes on render.

Identity comes from the request attribute before the cookie, so a token-authenticated
application puts its user id there and the cookie branch is never taken. For a
cross-origin SPA set `SameSite=None` + `Secure` (both are constructor
parameters) and send credentials.

See `examples/spa-endpoints.php`.

## Full API

`vendor/rasuvaeff/yii3-ab-testing-web/llms.txt`. Upgrading:
`vendor/rasuvaeff/yii3-ab-testing-web/UPGRADE.md`.
