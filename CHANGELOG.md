# Changelog

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

