# Education source references Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for inline execution; steps use checkboxes for tracking.

**Goal:** Apply the approved reference-ph-events tag to Education registration contacts and show named linked event touchpoints in existing staff activity.
**Architecture:** Reuse LeadTag/pivot and existing source feed. Add a focused service for tagging and Education source items, plus an idempotent backfill command. Display existing reference tags through one small shared React badge component in the leads table and kanban, retaining existing editing/filtering.
**Tech Stack:** Laravel/Eloquent/PHP, React/Inertia/Vite/Tailwind. No new dependencies or schema.
**Spec:** docs/superpowers/specs/2026-10-30-education-source-reference.md (approved by user 2026-10-30, “Apply this approach”).

## Global Constraints
- Tag exactly reference-ph-events. Preserve other tags, original source/UTMs, owner/stage and RSVP/registration semantics.
- Only verified linked RSVPs appear; recorded facts only, no invented page visits or historical referral sources. Unconfirmed schedules remain unconfirmed.
- Existing manual tag editing and filters remain. Never send real test email/Slack. Production backfill has no outreach.
- Continue current dedicated feature branch; scoped live deployment must preserve unrelated changes.

## Review Focus
- Repeat submissions and backfill must retain single pivot and other tags.
- Returning contacts with non-Education source retain first source, ownership and stage.
- Unverified or another contact’s RSVP/registration must never appear in this lead’s timeline.
- RSVP cancellation must be labelled cancelled, not attendance or RSVP success.
- Large backfill must be chunked; live frontend release must preserve current source/build customizations.

### Task1: Reference tagging and backfill
Files: app/Services/EducationLeadReference.php (new); app/Http/Controllers/EducationLandingController.php; app/Console/Commands/TagEducationRegistrations.php (new); tests/Feature/EducationLeadReferenceTest.php (new).
Interfaces: `EducationLeadReference::attach(Lead $lead): bool` returns whether added; command `education:tag-registrations` processes distinct saved-registration contacts in chunks, emits counts only.
- [x] Add failing route tests for new/returning/replayed tags and preservation, command repeat/backfill plus RSVP-only non-conversion.
- [x] Run RED, implement attach inside existing registration transaction and chunked command, run GREEN.
- [x] Commit focused backend implementation.

### Task2: Explicit Education source timeline
Files: app/Services/EducationLeadReference.php; app/Http/Controllers/LeadController.php; tests/Feature/EducationLeadReferenceTest.php.
Interfaces: `EducationLeadReference::sources(Lead $lead): array` uses existing collectSources shape kind/label/detail/reference/date. Education registration snapshot gives event/school/date/time/timezone; verified linked RSVP gives event/school/current status. Event kind consumed by existing source styling.
- [x] Add failing actual staff route feed test: named registration + verified RSVP, sorted timestamps, no other-lead/unverified entries, cancelled RSVP labelled.
- [x] Run RED, merge service sources into collectSources once, run GREEN and focused regressions.
- [x] Commit timeline extension.

### Task3: Restrained badges, production and proof
Files: resources/js/components/leads/LeadReferenceBadges.jsx (new); resources/js/pages/portal/sales/Leads.jsx; spec/plan ledgers.
Interfaces: `LeadReferenceBadges({tags=[]})` renders only reference- names, exact text with title, wraps long labels and respects inherited theme. Used below lead ID on shared table and kanban.
- [x] Add simple accessible badge component, preserve default visuals and interactions, build frontend and inspect local synthetic examples in table/kanban/timeline.
- [x] Run focused Education/lead suites and one full-suite run reporting existing failures. Fresh review approved task delta.
- [x] Hash-check current production sources/build and prepare targeted source/bundle update with rollback backup. Run rollback-only live tests, then approved idempotent reference backfill without messages. Inspect live UI and save private-safe synthetic/local proof.
- [x] Update existing draft PR and document validation/deployment/backfill counts. Push feature branch; leave unrelated full-site deployment unmerged.

## Execution ledger
- User approved the concrete proposed design. Ruling: implement inline in current dedicated feature branch and continue through deployment; no repeated plan approval because authorization already covers this approach. Existing feature checkout is clean and dedicated, not main/master.
- Pre-flight: Task2 source records and Task1 attach share the focused service; Task3 consumes unchanged existing tags array, so no additional list API needed. No interface conflicts.
- Task1/2 verification: initial tag tests failed for absent tags/command; named-source test failed with zero event entries. Implemented actual route/service/command behavior;68 focused tests /470 assertions pass after the review fixes (four baseline PHP8.5 deprecations).
- Fresh read-only review identified an existing different-name/same-slug collision and MySQL REPEATABLE READ snapshot race. Reproduced the collision with a failing route test, then changed canonical tag creation to use a unique unused slug and conflict-safe inserts/current-read lookup. Live isolation is REPEATABLE-READ. Pivot inserts now use insertOrIgnore rather than a stale snapshot sync; existing pivots/timestamps are untouched. A two-connection live synthetic check is required before backfill.
- Ruling: retain existing generic origin/resubmit entries and legacy applicant-side RSVP history as separate preexisting surfaces; this task adds named facts to staff history once per linked record. Do not invent cancellation/check-in timestamps; label current attendance status alongside original RSVP received time.
- UI: new badges only for reference- tags; ordinary tags remain in existing editor/filter. Branch build succeeded using Vite runner config loading because the shared node_modules cache is outside writable roots. A separate build from current live source/locked npm dependencies succeeded, preserving live HR/PDF-worker/source differences. Preserve the live stylesheet and add only15 new utility rules/one new theme variable; original5056 rules verified intact.
- Local synthetic profile/table/kanban showed exact reference-ph-events and named registration/verified attendance with timestamp/reference. Preview contact contains only fabricated details. Existing full-suite run initially hit PHP128MB memory limit; rerun with1024MB to report the actual baseline failures rather than misclassifying the resource limit.

- Full-suite verification completed1343 tests /7074 assertions,3 errors and51 failures. All54 failing/error names exactly match the documented baseline in `2026-10-25-rsvp-editor-verification.md`; no new names. Four existing PHP8.5 deprecations and three PHPUnit deprecations remain. The initial128MB run was a resource failure, superseded by the completed1024MB run.
- Release7a52009 deployed with394 source/manifest/new-asset files after checking806 current live frontend input hashes. Six source files changed; all previous assets kept. Built from current production source/lockfile, preserved live proposal.review_status overlay and the existing stylesheet5056 rules with only15 utility rules/one theme variable added. No route/schema/global-source metadata replacement. Backup `/home/deploy/education-reference-backups/20261030-061420`.
- Live synthetic registration check passed exact tagging, completed replay, returning source/owner/stage/other-tag preservation, named source items, truthful cancellation and complete transaction rollback with delivery faked. Two-connection MySQL REPEATABLE READ check passed stale-snapshot concurrent canonical tag/pivot attachment without duplicate-key failure. Initial test-helper PHPUnit assertion was unavailable on production; replaced it with direct fake-transport inspection and reran successfully. Synthetic contacts/pivots were removed; two orphaned synthetic creation audit rows cleaned after verification. The canonical reference tag dictionary entry is the intended retained feature data.
- Approved production backfill processed4 distinct saved-registration contacts and added4 reference associations; repeat processed4 and added0. Exact contact source/owner/stage/UTM/updated_at states and all prior pivot rows/timestamps compared unchanged. No notifications/mail/outreach. Private prior-state backup `/home/deploy/education-reference-backups/backfill-20261030-061516.json`; audit records aggregate completion without contact details.
- Live authenticated browser verified all four list badges and a named EDU-REG touchpoint in the existing Journey Activity log. Public events remain Clark/Davao/Digos. Privacy-safe synthetic screenshots in parent workspace `education-rsvp-preview/reference-list-preview.png` and `reference-journey-preview.png`. Local kanban badge also verified. Do not claim a physical mobile test: no real mobile device was used.
