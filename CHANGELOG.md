# Changelog

## 2.1.0 — 2026-08-23

### Fixed

- Withdrawing consent left the cookies earlier consent had written in the browser ([#15](https://github.com/rasuvaeff/yii3-ab-testing-web/issues/15)). Both middleware stopped reading and writing them, but an `ab_id` (max-age up to 365 days) or `ab_variants` (90 days) kept travelling in every request header — into access logs and any upstream that records them — until it expired on its own. A response now expires such a cookie when the policy denies persistence and the request still carries it, with the same `Secure`, `SameSite` and `Path` the cookie was written with. A request without those cookies still gets no `Set-Cookie` header.

### Added

- `CookieAssignmentStore::applyDeletionToResponse()` — expires the sticky cookie. `applyToResponse()` cannot: a store built under a denied policy is never dirty, and writing is the very thing consent forbids.

### Changed

- `release.yml` now refuses to publish a GitHub Release for a tag that is not an ancestor of `master` or whose matrix build never went green, and matches the changelog heading as text rather than as a regular expression.

- Adopt `rasuvaeff/rector-named-literals` and apply the named-argument rule to literal calls (development tooling only; no runtime behaviour changes).

## 2.0.0 — 2026-08-01

### Added

- `SignedReceiptCodec` — signs a core `AssignmentReceipt` into a
  transport-independent string. Signing used to live inside
  `CookieAssignmentStore`, tied to `CookieSigner`, so a single-page application
  holding the receipt in `localStorage` had no way to prove it was genuine and
  a client could claim a variant it was never assigned.

### Changed

- **Breaking.** Requires `rasuvaeff/yii3-ab-testing` `^2.0`.
- **Breaking.** `ConfigurationAwareAssignmentStore` moved to the core. Import it
  from `Rasuvaeff\Yii3AbTesting` — a local copy would be worse than a
  duplicate, because `StickyAssignmentResolver` matches on it with `instanceof`
  and a store implementing the other copy silently loses configuration
  awareness.
- **Breaking.** `Assignment`'s boolean properties became methods
  (`isSticky()`, `isForced()`, …) and the constructor takes `reason` / `source`.

## 1.2.0 — 2026-08-01

- Add ready `StickyAssignmentMiddleware` and typed request accessors for subject
  identity, sticky resolver, and cookie store.
- Add consent policies; denied consent now uses an ephemeral request id and
  prevents identity and sticky-cookie reads and writes.
- Add configurable anonymous-to-authenticated transitions: use the authenticated
  id afresh, keep the anonymous id, or migrate browser assignments.
- Bound sticky cookies by entry count and actual `Set-Cookie` bytes with
  deterministic FIFO eviction and oversized-input rejection.
- Persist core `configurationId` with sticky assignments so changed experiment
  definitions invalidate old variants while retaining v1 cookie readability.
- Require `rasuvaeff/yii3-ab-testing` ^1.6 and implement its
  `AssignmentResolver` contract.

## 1.1.1 — 2026-07-29

- Evaluate enabled state and targeting before reading sticky assignments, so a
  previous variant cannot bypass the kill switch or current eligibility.
- Make disabled experiments win over forced variants in sticky resolution.
- Add regression coverage for changed `country`/`plan`, disabled and forced
  combinations, and stale stored variants.
- Correct the core Composer requirement from `^1.2` to `^1.4`; targeting APIs
  used since web 1.1.0 were introduced in core 1.4.

## 1.1.0 — 2026-07-25

- **Security fix.** The `ab_id` cookie was validated with `/^[0-9a-f]{32}$/`,
  and PCRE's `$` also matches before a trailing newline — so a cookie of 32 hex
  characters plus `\n` was accepted and became the subject id in logs and
  analytics, exactly what the check exists to prevent. The pattern is now
  anchored with `\z`.
- The subject id format is now an extension point: `SubjectIdGeneratorInterface`
  (`generate()` + `isValid()`), defaulting to `HexSubjectIdGenerator` — the
  historical 32 lowercase hex characters, so existing visitors keep their
  variant. `SubjectIdMiddleware`'s new `idGenerator` argument is last and
  optional.
- Generation and validation deliberately live in one contract: the middleware
  reuses a cookie only when the generator accepts it, so a custom format that
  changed only `generate()` would make every request mint a new id and — since
  assignment is deterministic in the subject id — flip the visitor between
  variants on every page view.

## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- `StickyAssignmentResolver` marks store-served assignments with `isSticky = true`.
- `SubjectIdMiddleware` validates the `ab_id` cookie value against the generated format (`/^[0-9a-f]{32}$/`) and regenerates it otherwise.
- `CookieAssignmentStore::prune(ExperimentRegistry)` removes variants of experiments that no longer exist, keeping the cookie bounded.

- `SubjectIdMiddleware` — PSR-15 middleware establishing a stable subject id (cookie `ab_id` or an upstream request attribute) for deterministic A/B assignment.
- `CookieAssignmentStore` — sticky-variant `AssignmentStore` backed by one signed cookie; request-scoped via `fromRequest()` / `applyToResponse()`.
- `StickyAssignmentResolver` — get-or-assign resolver over `AbTesting` and any `AssignmentStore`; forced variant bypasses the store, disabled experiments return fallback, stale stored variants are re-assigned.
- Requires `rasuvaeff/yii3-ab-testing` ^1.2 (`AssignmentStore`, `Assignment::isSticky`).
