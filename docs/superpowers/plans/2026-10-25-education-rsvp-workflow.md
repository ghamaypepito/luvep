# Education RSVP Workflow Implementation Plan

> Execute with superpowers:executing-plans in the existing isolated Education checkout.

**Goal:** Unified event RSVPs, confirmation/reminder workflow, verified journey matching and Education registration pipeline integration.
**Architecture:** Additive Laravel models/controllers, durable per-channel outbox, standalone Blade/static UI, existing programme registration and lead journey hooks.
**Spec:** docs/superpowers/specs/2026-10-25-education-rsvp-workflow.md

## Constraints
Preserve /education/register and unrelated live code. RSVP creates no lead/account; programme registration creates/matches Education pipeline, assigning new leads to Emma. Notify Emma and Dinah. All seven schedules initially TBC. Disregard partnership distinctions publicly. Slack #general only, encrypted webhook, no email/phone disclosure. No shared bundle rebuild or unrelated migrations.

## Tasks
- [x] Write and observe failing intake, proof/matching, schedule, outreach and authorization tests.
- [x] Implement migration, visit/RSVP/outbox models, transactional intake, signed verification, exact matching and private schedule serialization.
- [x] Implement confirmation email, staff notifications, Slack receipts, reminders, cancellation/rescheduling and non-overwriting seed.
- [x] Add protected schedule/attendance manager and encrypted Slack settings.
- [x] Connect the approved unified white/automatic-dark UI to real APIs, optional contact number and changing images.
- [x] Integrate programme pipeline creation/matching and lead-scoped RSVP journey history; observe integration test RED then GREEN.
- [x] Verify local browser submission, zero programme leads for RSVP, log-mail confirmation and signed email proof.
- [x] Run focused regression suite and full suite once; compare existing failures by name.
- [x] Obtain independent review. Fix and regression-test closed-intake communications and Slack backlog starvation; reviewer confirmed both resolved.
- [x] Create explicitly approved webhook-only Slack app for #general and store sender encrypted in production.
- [ ] Commit/push and update PR #15 around the final implementation.
- [ ] Back up live files, run only additive RSVP migration, deploy targeted files/patches, seed and add scoped cron.
- [ ] Verify live public/staff routes, rolled-back production RSVP/registration routing and actual Slack connection.
- [ ] Show final live page and report URLs, date-confirmation requirement and validation limits.
