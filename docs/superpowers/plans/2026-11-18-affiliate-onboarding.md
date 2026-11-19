# Affiliate Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend EP affiliate management with default Admin access, reversible lifecycle controls and verified application-to-signed-agreement onboarding.

**Architecture:** Extend the current affiliate controllers, User lifecycle and AgentAgreementService. A single AffiliateApplication domain owner controls approval, selected agreement and one-time activation, while private signed routes allow the applicant to sign before normal portal access.

**Tech Stack:** Laravel 12/PHP 8.3, existing React/Inertia, Dompdf/PDF worker, Laravel signed URLs, database queue and existing mail transport.

**Spec:** docs/superpowers/specs/2026-11-18-affiliate-onboarding.md

## Global Constraints

- Activation is **After Admin approval and both signatures.**
- Existing affiliates are preserved. Disabled or archived accounts are not automatically reactivated by old links or signatures.
- Super Admin and Admin have default management access. Other roles do not gain administrative permissions.
- Reuse the existing EP workspace and private agreement/PDF/signature history. No new dependencies or unrelated portal redesign.
- QA uses only synthetic local records and fake mail; production verification is read-only except the authorized ticket update.

## Review Focus

- An existing email or duplicate submission cannot overwrite an account, reserve an account before approval, or leak existence to the public.
- Unverified, unapproved, stale/superseded/draft/expired versions and tampered/expired signed links cannot activate or sign.
- Either signature order and concurrent/replayed second signatures activate only once; disabled/archived accounts stay disabled.
- User Management toggle/restore routes cannot bypass onboarding eligibility; authorization cannot be bypassed with another target ID.
- Archived partner documents and attributed lead history survive lifecycle actions and remain private.

### Task 1: Complete the affiliate administration and onboarding extension

**Files:**
- Extend config/modules.php, existing affiliate admin/reporting controllers/layout/pages and UserController lifecycle guards.
- Add AffiliateApplication model/migration and a focused onboarding service/controller/mail owner under existing app conventions.
- Extend AgentAgreementService and explicit agreement invitation handling at their existing version/signing boundaries.
- Add routes and public application/progress/sign pages, plus admin application queue/detail in the existing affiliate layout.
- Add targeted Feature/Affiliates onboarding/lifecycle tests, retain and update existing admin access tests, and frontend interaction checks in existing tests/Frontend conventions.
- Document the user flow and private link/account activation limits in a release note.

**Interfaces:**
- Retain all existing affiliate route names/DTOs and legacy behavior. New public entry is `/affiliate/apply`; administrative application queue is `/admin/agents/applications` declared before `{agent}` binding.
- Applicant private links use signed routes with UUID identifiers and expiry. Public sign requests include the exact issued agreement identity plus existing signature/terms payload. Admin controllers render affiliate admin pages with application records and authorized action URLs; public pages receive only the current applicant's data and scoped document/sign links.
- A single onboarding service owns `approve`, selected issued-version association and one-time activation eligibility. Existing agreement generation/signature entry points and generic user activation must call that owner. Name internal methods idiomatically and document final public DTOs in the report.

- [ ] Write failing focused tests for default Admin access, lifecycle retention and the activation state machine. Assert inactive user and no active referral URL after application/verification/approval alone; assert active only after both signatures on the selected version.
- [ ] Implement the application record, verification and private signed access, review flow and existing-account conflict protection. Verify using synthetic requests and Mail::fake.
- [ ] Extend the agreement service/invitation and generic user lifecycle guards. Test both signing orders, stale/current versions, replay/concurrency, disabled/archived history and no automatic reactivation.
- [ ] Commit the proven domain/backend increment.
- [ ] Implement the EP application/progress/sign pages and admin queue/status/actions. Use the shared signature modal and existing chart/table components. Test actual form/button interactions and errors.
- [ ] Run existing affiliate suites, new targeted suites, relevant frontend checks, Pint on changed PHP and production build. Commit the finished UI increment and release documentation.
- [ ] Record exact commits, commands/results and material limits in `/private/tmp/affiliate-onboarding-implementation-report.md`; return status and concise evidence. Do not deploy or touch production.

### Release gate (parent)

- [ ] Independent tester verifies the final candidate, including synthetic browser flow with fake/captured mail and both signatures; use MySQL concurrency probes when state-race tests require it.
- [ ] Different reviewer checks the exact tested tree against the spec; resolve concrete findings and repeat affected checks.
- [ ] Fresh main integration, required full CI, guarded exact-head merge and production deployment.
- [ ] Confirm live source/migrations/default access/form/menus and preserve existing PDF hashes. Update TKT-LBKTOL with this extension's verified resolution and Done status only after verification.
