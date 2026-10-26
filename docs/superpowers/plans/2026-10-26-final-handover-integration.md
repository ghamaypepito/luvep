# Final Handover Integration Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrate unknownhuman563/epathways final-handover while preserving the current Luvep production features.

**Architecture:** Save the current chat's work as a Git commit, capture additional production source changes without secrets or runtime caches, then merge upstream Luvep main and the handover into an isolated integration checkout. Preserve current deployment protections and version the private workspace source and its deployment copy step.

**Tech Stack:** Laravel 12, PHP 8.3, React/Vite, MySQL, GitHub Actions.

**Spec:** The user approved the four-step preservation, isolated merge, verification and combined deployment sequence in this chat.

## Global Constraints

- Preserve Claude connectors, Pathy, EP Assistant, visa-fee visibility, marketing references, saved approvals and GHL scheduling.
- Preserve other current production source fixes, routes and migrations.
- Never commit environment files, configuration caches, credentials, database dumps, uploads or dependencies.
- Do not publish, schedule or message real campaigns during testing.
- Retain production repository guards, deployment concurrency and old-asset protection.

## Review Focus

- Live-only source files must survive rsync deletion.
- Duplicate immigration migrations and incompatible existing schemas must be identified before deployment.
- Route order and shared case permissions must preserve both branches' behavior.
- Marketing approval gates must survive integration and deployment.
- Private runtime workspace must be installed from versioned source during deployment.

### Task 1: Preserve current work

- [x] Run existing AI, Social and Marketing regressions and frontend regressions.
- [x] Inspect staged paths for secrets and runtime files; commit the current feature work on its existing branch.
- [x] Capture production source and distinguish additional live changes from this chat's source.

### Task 2: Integrate in isolation

- [x] Create an isolated integration checkout and codex/integrate-final-handover branch.
- [x] Preserve additional live changes in a separate commit.
- [x] Merge current Luvep main and final-handover; resolve shared-file conflicts by preserving both behaviors.
- [x] Add deployment copying for resources/social-workspace to its private runtime location.

### Task 3: Verify combined version

- [x] Run meaningful feature suites for AI, Marketing, Social, Immigration, Finance, intakes and changed production modules.
- [x] Build frontend and inspect route registration and migration compatibility.
- [x] Obtain independent review and fix actionable findings.

### Task 4: Deliver combined release

- [ ] Push the integration branch and attach its PR with verified results and limitations.
- [ ] Verify a production backup and current-source guards before activation.
- [ ] Deploy only the verified combined release and verify the affected live screens without triggering business communications.
- [ ] Record commits, backups and rollout results for handover.

## Verification record

- Preserved the current feature work in `ddb6ffc7`; preserved 47 production education source files in `b2bb6c56`.
- Merged current Luvep main and handover commit `eb1c909097e6904a8d34d4cbdf155fd2be65c94b`; preserved both sides of the scheduled-task conflict.
- Deployment dry run discovered 17 additional live education landing/RSVP assets; these are now included. Existing environment variants and deployment markers are protected.
- Added private Marketing Workspace installation from versioned source; existing exports survive installation.
- Fixed incoming next-visa routing/eligibility and ownership, installment retry/deletion consistency, historical-payment reconciliation, zero-fee reconciliation, and reminder deduplication. Independent review cleared the fixes.
- Focused release suite: 186 tests, 733 assertions, no failures/errors (local PHP 8.5 dependency deprecations remain).
- Full suite: 1,392 tests, 7,153 assertions; the same 51 failures and 3 errors as the 1,355-test pre-merge baseline. No new failing tests. The existing failures cover checklist, tracker, template-scoping and other legacy behavior and are not represented as passing.
- Frontend build and five workspace Node regression scripts passed. Route registration includes both staff and agent next-visa endpoints.
- All 16 incoming migrations passed on MySQL 8.4 after applying the existing repository migrations. Read-only production prerequisite checks returned pass; no production schema export was needed.
- GitHub validation runs the release suite and frontend checks on PHP 8.3 / Node 20 before production merge.
