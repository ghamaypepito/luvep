# Creative Requests Implementation Plan

**Goal:** Add System / Digital tabs, the approved creative brief with captions and private references, and an assigned task/notification visible in Sharry's DTR.

**Architecture:** Keep system-support tickets unchanged. A creative request owns its original brief and private reference attachments and belongs to exactly one existing LeadTask. LeadTask owns current assignment, deadline and workflow status; no duplicate task/status synchronizer. Use the existing private TicketAttachments helpers. Resolve Sharry's explicitly supplied account email; requests fail validation if it is absent or inactive.

**Tech stack:** Laravel 12, Inertia, React, existing Lucide and CSS. No dependencies.

**Scope:** New migration/model/controller/notification, focused workflow tests, Digital queue/form/detail, shared ticket tabs, HR and legacy DTR assigned-work panel, and request links in task detail. Existing system-ticket routes, reports, attendance and stored work logs retain behavior.

**Design:** Adapt supplied references' white rounded panels, green actions, lime selection and quiet schedule rail. Show a request queue and DTR/task preview. No marketing metrics or decorative charts.

## Invariants / review focus

- Only active internal users submit. Only submitter, assigned owner and admins read briefs/files; only owner/admin change workflow.
- One request creates one task atomically. Failure cleans stored files. Notifications follow commit.
- Private file paths never enter browser payloads; validate both MIME and extension using existing upload rules.
- Queue status reads the linked task, including changes through existing Task Board.
- DTR assigned work is distinct from recorded time; never fabricate attendance or hours.
- HR employee preview remains read-only and scopes assigned work to the selected employee.
- Owner routing requires the explicitly supplied existing active account, never a name guess.

## Execution

- [x] Write failing feature tests for creation/assignment, privacy, uploads, status, queue separation and DTR scoping.
- [x] Add additive schema and request behavior; run focused tests.
- [x] Build Digital form/queue/detail and System/Digital tabs; use existing file picker.
- [x] Add assigned creative work to HR/legacy DTR and links from task details; run regression checks.
- [x] Build and inspect actual UI at desktop/mobile; show DTR/task preview; review diff.

## Ledger

- Base: 2a43155d, clean existing linked worktree, feature branch codex/creative-requests.
- Dependency lockfiles match compose-attachments-fix; copied PHP dependencies and reuse installed JS dependencies. PHP 8.3 selected to match production.
- Initial ticket baseline needed an application key and Vite build; resolving local setup before feature tests.
- User supplied Sharry's account and explicitly requested a separate email alert recipient. Both use server-only environment configuration; in-app delivery uses the existing database notification inbox. Live preflight verified the existing profile and corrected a domain typo without creating an account.
- Work is local until reviewed; no production account, ticket, attendance or notification is changed by tests.
- Initial full suite exhausted PHP's default 128 MB PDF-renderer limit; reran at 768 MB. The first expanded run had 24 AI-only failures because the local test environment disabled AI; restored the suite's normal enabled default and reran.
- Review fixes: deny creative creator-only edits through shared Task API; initialize a valid task department; keep a short task summary while preserving the complete brief. Added failing regression tests, then verified passing.
- Browser reproduced a next-day deadline shift on unchanged Task Board Save in Asia/Manila. Fixed date hydration to use local dates, preserve unchanged instants and submit edited times as ISO instants. Committed local-only E2E regression passes.
- Focused regression: 54 tests / 395 assertions; build and formatting pass. Browser verifies actual caption, reference-image upload, status, task-board Save, DTR and System/Digital tabs at 1440/768/390/320 widths without runtime errors.
- Final full suite: 2,027 tests / 16,148 assertions, no failures, 2 skipped, 3 existing PHPUnit deprecations. Final committed browser regression and screenshot walkthrough pass, including actual Task Board Save in Manila. Keep the feature branch local for the requested appearance review; no merge, deployment or real email sent.
