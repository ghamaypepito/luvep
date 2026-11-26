# Affiliate Resumable Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Applicants save/resume their registration and review/sign inside onboarding; Eireen/Admin has a clear registration entry point.
**Architecture:** Extend AffiliateApplication and AffiliateOnboarding, retaining signed relative links, existing agreement signature/activation service and Admin guards.
**Tech Stack:** Laravel/PHP 8.3, React/Inertia, existing private agreement storage.
**Spec:** docs/superpowers/specs/2026-11-26-affiliate-resumable-onboarding.md

## Global Constraints
No live emails, applications or signatures as test data. Preserve current applications, issued terms, history and client referral registration. No additional access grants required. No dependencies. Deployment follows the existing PR/CI workflow already authorized for this feature.

## Review Focus
Signed URL tampering/expiry, unverified writes, anonymous duplicate submissions, draft race/freeze, incomplete approval, selected agreement version and both-signature activation.

### Task 1: Resumable application state
- [ ] Add failing backend tests for partial saves, email verification, private edits/submission, stale revisions, duplicate privacy and incomplete Admin approval. Run focused PHPUnit; expected missing draft behavior.
- [ ] Add additive submitted_at/draft_version migration with existing-row backfill. Extend model/service/controller/routes and Admin review gating.
- [ ] Run affiliate tests; expected green including current activation tests.

### Task 2: In-onboarding review/sign and registration UI
- [ ] Add failing frontend tests for Save application, private resume, inline review and optional download. Run Node test; expected missing controls.
- [ ] Extend existing form for contact draft editing, render private frozen agreement review within onboarding, retain existing digital signature modal; add Admin registration action and accurate draft dates.
- [ ] Run frontend/backend affiliate tests and build; expected green.

### Task 3: Review and ship
- [ ] Fresh-context whole-branch review; address important findings with regression tests.
- [ ] Run full backend/frontend CI, merge reviewed exact head, deploy and verify production SHA, migration and read-only Admin/public pages. Record results; no live signing or email required.
