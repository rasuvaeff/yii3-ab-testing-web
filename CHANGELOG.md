# Changelog

## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-06-12

- `StickyAssignmentResolver` marks store-served assignments with `isSticky = true`.
- `SubjectIdMiddleware` validates the `ab_id` cookie value against the generated format (`/^[0-9a-f]{32}$/`) and regenerates it otherwise.
- `CookieAssignmentStore::prune(ExperimentRegistry)` removes variants of experiments that no longer exist, keeping the cookie bounded.

- `SubjectIdMiddleware` — PSR-15 middleware establishing a stable subject id (cookie `ab_id` or an upstream request attribute) for deterministic A/B assignment.
- `CookieAssignmentStore` — sticky-variant `AssignmentStore` backed by one signed cookie; request-scoped via `fromRequest()` / `applyToResponse()`.
- `StickyAssignmentResolver` — get-or-assign resolver over `AbTesting` and any `AssignmentStore`; forced variant bypasses the store, disabled experiments return fallback, stale stored variants are re-assigned.
- Requires `rasuvaeff/yii3-ab-testing` ^1.2 (`AssignmentStore`, `Assignment::isSticky`).
