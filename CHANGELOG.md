# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — unreleased

- `SubjectIdMiddleware` — PSR-15 middleware establishing a stable subject id (cookie `ab_id` or an upstream request attribute) for deterministic A/B assignment.
- `CookieAssignmentStore` — sticky-variant `AssignmentStore` backed by one signed cookie; request-scoped via `fromRequest()` / `applyToResponse()`.
- `StickyAssignmentResolver` — get-or-assign resolver over `AbTesting` and any `AssignmentStore`; forced variant bypasses the store, disabled experiments return fallback, stale stored variants are re-assigned.
- Requires `rasuvaeff/yii3-ab-testing` ^1.1 (`AssignmentStore`).
