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

- [ ] Run existing AI, Social and Marketing regressions and frontend regressions.
- [ ] Inspect staged paths for secrets and runtime files; commit the current feature work on its existing branch.
- [ ] Capture production source and distinguish additional live changes from this chat's source.

### Task 2: Integrate in isolation

- [ ] Create an isolated integration checkout and codex/integrate-final-handover branch.
- [ ] Preserve additional live changes in a separate commit.
- [ ] Merge current Luvep main and final-handover; resolve shared-file conflicts by preserving both behaviors.
- [ ] Add deployment copying for resources/social-workspace to its private runtime location.

### Task 3: Verify combined version

- [ ] Run meaningful feature suites for AI, Marketing, Social, Immigration, Finance, intakes and changed production modules.
- [ ] Build frontend and inspect route registration and migration compatibility.
- [ ] Obtain independent review and fix actionable findings.

### Task 4: Deliver combined release

- [ ] Push the integration branch and attach its PR with verified results and limitations.
- [ ] Verify a production backup and current-source guards before activation.
- [ ] Deploy only the verified combined release and verify the affected live screens without triggering business communications.
- [ ] Record commits, backups and rollout results for handover.
