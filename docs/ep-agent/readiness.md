# Ep Agent and Ep Manager — build readiness

Reviewed 6 November 2026. Ready to begin an isolated implementation. Production replacement, paid agent runs and automated writes are not enabled or approved by this assessment.

## Evidence and limits

The approved interactive concepts are in `ep-agent-preview/`: private staff conversations, Admin task/cost overview, connector setup, manager mobile views, briefing/command/note modes, record-linked personnel and client quick details.

Reviewed the existing Laravel 12 / React 19 / Inertia assistant implementation in `education-live-build`, HEAD `0693dcd8`, branch `codex/education-rsvp-contact-update`, with a clean working tree. `handover-integration` is a separate checkout at `27d47796`; neither was verified against the current production deployment in this review. Reconcile the canonical repository, latest production revision and outstanding changes before implementing. Do not use the standalone demo as production source or overwrite either checkout with it.

Focused baseline command (local SQLite memory database and fake provider requests):

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array php vendor/bin/phpunit --do-not-cache-result tests/Feature/Ai/AiChatTest.php tests/Feature/Ai/AssistantPanelTest.php tests/Feature/Ai/ClaudeConnectorTest.php
```

Result: 27 tests, 96 assertions, no test failures; four deprecation notices under PHP 8.5.11. This is not a full regression pass, live-provider test, concurrency test or production certification. No credentials were entered, paid requests made, migrations applied, workers changed or production files modified.

## Existing foundations to preserve

- `AiChatController`: user-owned conversation persistence and record access checks on resumed chats.
- `AssistantDock` and `/assistant`: compact chat, existing sidebar event and full-page expansion.
- `AiScope` and `AiRecordContext`: starting point for scoped factual context, not a complete multi-entity authorization layer.
- `/admin/ai-connectors` and `ClaudeSettings`: existing Super Admin-only configuration with encrypted server-side credentials. Extend deliberately; do not create a second competing credential store.
- Global and per-assistant AI switches. Pathy, marketing AI and existing integrations have their own callers and must be preserved during the staff-assistant transition.
- Existing policy, document, task, communication and activity services. Agent actions must use authorized application services rather than bypassing policies with arbitrary database writes.

## Scope confirmed by the conversation

Ep Agent replaces the staff EP Assistant after a tested cutover. Keep compact chat and full private workspace connected to the same conversation. Every user has their own history and can work concurrently. Portal identity and record scope come from authenticated server context, not prompt text or a browser-supplied role.

Admin sees permitted task metadata, approvals, and usage/costs. Admin reporting does not automatically expose private prompts, chat titles, notes or draft bodies. Shared summaries use explicit disclosure and authorization. Any exceptional administrator access and retention policy must be documented before launch; do not promise privacy while exposing raw audit payloads in Admin.

Ep Manager is a mobile web companion sharing Luvep accounts, APIs and operational records. Include Today, Attention, Team and Ask Ep, source-linked briefings, missing-document/stalled-case checks, personnel/client popups, and permission-scoped workloads. Add installability only after real authentication, HTTPS, offline policy and cache behavior are verified. Never cache private API responses or client documents in a service worker.

Companion actions include briefings, voice-to-text, notes, proposed workflow updates, follow-up tasks, activity history, document/presentation creation and connected media workflows. Every connected capability must be labeled accurately; no sample generator can be presented as live functionality.

## Gaps that gate live capabilities

1. **Authorization.** Existing `AiScope` broadly checks department flags and is limited to Lead records. Build and test a shared record-level policy/query scope covering assignments, manager grants, extra portal access, inactive/revoked users, conversations, attachments and client/personnel detail endpoints. Apply scope before retrieval and before action execution. Changing access must invalidate or recheck ongoing jobs and resumed threads.
2. **Actual portal mapping.** `User::PORTAL_ROLES` currently contains sales, education, english, immigration, accommodation, finance, agent and sub_agent. Employment and Residency are not standalone roles in this reviewed source. Map them to real workflows/entities and configurable grants, initially default-denied where a mapping is not verified; do not assume names in the demo are authorization roles.
3. **Usage and budgets.** The existing client combines input/output tokens into one count. Introduce separate usage categories, model/provider identifiers, versioned prices, per-run costs and budget reservations. Reserve worst-case bounded costs atomically before parallel requests; reconcile actual usage, hold uncertain/timeout reservations until resolved, cap retries/steps/output and count background/tool/media work. Reject new runs when the ledger is unavailable. Provider limits and alerts supplement the app's cutoff. No monthly amount has been explicitly selected by the user; the demo's $30 is illustrative. Paid activation waits for an explicit budget and a verified provider/model/price configuration.
4. **Provider isolation.** Staff chat currently uses `ClaudeClient`, also used by other surfaces. Add the agent path without changing Pathy's or marketing's behavior. Verify current provider capabilities/prices at implementation time; preserve credentials and do not interpret consumer subscriptions as API credits.
5. **Durable actions.** New run/action/audit/approval records, idempotency keys, retry bounds, approved payload snapshots and record-version checks are required. Recheck actor and record permissions at execution. Audit metadata must not leak private chat content. A human approval applies to the exact reviewed action and expires after edits or access changes.
6. **Queue isolation.** Reviewed default `retry_after` is 90 seconds while several existing jobs declare timeouts of 120–600 seconds; runtime overrides were not inspected. Use a dedicated agent queue/connection and bounded worker pool with compatible timeout/retry settings. Do not reuse or reconfigure the production campaign/reminder worker pool during rollout.
7. **Knowledge and documents.** Implement approved, versioned, portal-scoped knowledge retrieval and private file handling. Revalidate old Phase 0 findings against current source/runtime: historical audit findings are not evidence that every issue still exists. Keep education and immigration knowledge boundaries. Existing licence/content policy supersedes an outdated blanket read-only-adviser statement in the old guardrail document.
8. **Communications and integrations.** Start with internal drafts and follow-up tasks. Sending, publishing and calendar invitations require the existing channel permissions, consent, idempotency and exact-content approval controls. Voice/media providers have separate metering and limits. A connector metadata check alone does not prove a paid generation workflow works.

## How to protect existing portal functions

Use an isolated branch/worktree from the reconciled current baseline, retaining unrelated in-progress work. Keep Luvep's established stack and authentication. Add a separately configured agent module, routes, policies, tables and worker pool; default activation flags off. Avoid extending the already-large `leads` row for agent state. New migrations should be additive and evaluated on the production database engine, with locking characteristics checked.

Keep the current staff assistant available through a reversible per-user/per-portal feature flag during pilot. Preserve conversation ownership and compatibility; use a tested conversion only if the schema requires one. Only switch the existing sidebar/dock entry to Ep Agent after the pilot passes. Preserve the public Pathy assistant and all other callers.

Ep Manager uses the same database as the portal through authenticated APIs. Avoid a second CRM database or synchronization process. Use paginated, indexed, scope-first queries and per-scope caches; never share cache keys across permission scopes. Generate deterministic counts/checklists without an AI call where possible. Heavy generation belongs on the isolated worker pool. Targeted polling or streaming must stop on logout, revocation and hidden/disconnected screens.

## Delivery sequence

1. Reconcile source/deployment, capture current regression/build/performance baselines, and establish isolated implementation and rollback artifacts.
2. Build scope policies, private conversations, detailed usage ledger, atomic budget controls, run/audit infrastructure and feature flags. Provider requests are faked by default in tests; activation remains off.
3. Integrate compact/full Ep Agent with the existing staff portal and scoped knowledge. Pilot private chat and read-only record summaries with designated staff.
4. Connect manager read-only briefings, workloads, real client/personnel profiles and source links, then validate the mobile install/authentication/cache experience.
5. Introduce exact-action proposals, approvals, reversible/idempotent notes, staff assignment and follow-up tasks. Run concurrency and stale-record tests before enabling writes.
6. Add voice, document/presentation creation, and independently metered media/calendar/messaging capabilities in individually tested increments. Keep the user's requested full feature set on the roadmap; do not label the first release as the entire completed product.
7. Pilot, compare operational metrics and error rates, then expand per portal. Production activation has its own explicit budget, connector and role configuration, verified backups, deployment-window checks and rollback rehearsal.

## Acceptance before production cutover

- Full relevant PHP and frontend builds/tests compared with the reconciled baseline. Existing failures are recorded, not suppressed or represented as passing.
- End-to-end tests for per-user chat isolation, cross-portal access denial, role changes, same conversation in compact/full views, and manager visibility without private chat leakage.
- Parallel staff runs and overlapping record changes: no duplicate writes, lost updates, approval replay or budget overspend caused by simultaneous reservations.
- Failure tests for provider outages, timeouts, uncertain charges, exhausted budgets, job retries and disabled modules. Failures remain local to the agent feature.
- Regression coverage for login/navigation, lead/client records, education/RSVP, immigration/licence policies, private document access, finance/payments, HR/DTR, social approvals, bookings and existing follow-up jobs.
- Compare page latency, database query counts and worker throughput. Feature-off behavior preserves existing portal behavior; agent queue exhaustion does not starve the portal's normal jobs.
- Reversible source/asset/flag rollout and additive schema compatibility. Roll back code or flags without restoring an old database over new business records.

## Go / no-go

**Go:** isolated implementation and automated tests, beginning with authorization and cost-control foundations.

**Not yet:** replacing the existing production assistant for everyone, enabling unattended writes or starting paid agent/media/voice work. The gates above must be satisfied first. Zero disruption is a rollout goal supported by isolation, testing and rollback—not a guarantee already established by this local review.
