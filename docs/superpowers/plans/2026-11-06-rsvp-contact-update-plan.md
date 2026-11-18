# RSVP Contact Update Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement inline; no delegated work is needed.

**Goal:** Email missing-number RSVP contacts a secure form that saves their number.
**Architecture:** Signed public GET/POST routes call a focused service. A one-time command creates deduplicated EducationOutreach receipts; existing delivery logs/retries send a dedicated transactional mailable.
**Tech Stack:** Laravel, Blade, existing database/outreach and mail transport.
**Spec:** 2026-11-06-rsvp-contact-update-spec.md

## Constraints and review focus
- Seven-day email-bound signed links; CSRF and throttling; no account creation.
- Cancelled/non-consenting contacts and completed phone updates are excluded.
- Exact identity matching only; preserve existing linked applicant numbers and all stage/consent/attendance data.
- Repeat sends and concurrent jobs must reuse outreach receipts.
- Do not expose recipient lists, numbers, or signed tokens in logs/tool output.

## Tasks
- [x] Add failing tests for signed access/expiry/email change, required phone, exact matching, safe lead update and audit, campaign exclusion/dedupe/cancel-on-fill, and mail contents.
- [x] Add EducationRsvpContactUpdate service/controller, dedicated view and GET/POST routes.
- [x] Add EducationRsvpContactRequest mailable, accessible HTML/plain-text templates and narrow existing outreach integration; add dry-run-by-default command with --send.
- [x] Keep the previously requested mandatory phone in new RSVP form/validation in this production-source branch.
- [ ] Run focused tests, mail render inspection and source review; create a narrow PR and integrate/deploy this requested workflow.
- [ ] Verify signed-link flow safely, then run the one-time send and verify delivery counts without exporting contacts.

## Execution ledger
- Production-source RSVP tests were absent; existing EducationTeamNotice baseline passed (1assertion). New feature tests were observed failing before implementation.
- Current focused suite:10tests/60assertions, no failures, existing PHP8.5 deprecations. Missing/expired/tampered links, changed emails, required phones, matching and existing applicant values, campaign dry-run/dedupe and late phone fill tested.
- Schema makes consented_at non-null, so impossible null-consent fixture was removed; runtime consent guard retained.
- Fresh whole-branch read-only review returned no blocking findings and independently confirmed10tests/60assertions.
- Synthetic email and form preview inspected in browser. EP logo and readable green/white email verified; form supports automatic dark mode and20px corners. No real recipients or tokens in previews.
- Ruling: create a narrow branch from production main13c0188, keeping draftPR15 separate, so emailed update routes survive normal deployments. This is necessary to fulfill the requested automated update link.
