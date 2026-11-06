# Ep Agent Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish a disabled-by-default, testable foundation for private Ep conversations and enforceable API spending controls without replacing the current assistant.

**Architecture:** Add an isolated Laravel module using existing Luvep authentication and encrypted connector configuration. New additive tables hold private conversations, explicit record grants, runs and an integer USD usage ledger; existing assistant tables and provider callers retain their behavior. The foundation has no paid provider transport or operational write tools.

**Tech Stack:** Laravel 12, PHP 8.2+, existing React 19/Inertia, PHPUnit, production-compatible SQL transactions.

**Spec:** `docs/ep-agent/readiness.md` (copied from the approved readiness assessment).

## Global Constraints

- Default activation flags off.
- No monthly amount has been explicitly selected by the user; the demo's $30 is illustrative.
- Paid activation waits for an explicit budget and a verified provider/model/price configuration.
- Apply scope before retrieval and before action execution.
- Admin reporting does not automatically expose private prompts, chat titles, notes or draft bodies.
- No production migrations, worker changes, connector edits, deployment or paid requests during this work.
- Preserve existing assistant, Pathy, marketing callers and unrelated portal functions.
- No columns added to `leads`; Employment and Residency denied until actual workflow mappings are verified.
- Implementation baseline: `origin/main` at `76f2c9ee`, isolated branch `codex/ep-agent-foundation` in `ep-agent-build`. This is a verified source baseline, not a verified production deployment revision.

## Review Focus

- Revoked users and reassigned records: access must be checked again on every request, including resumed conversations.
- Concurrent reservations: committed spend plus outstanding holds must never exceed the configured budget.
- Uncertain provider completion: timeouts and missing usage must keep their reservation held until reconciliation.
- Admin reporting and error paths: neither private content nor connector secrets may appear in responses or logs.
- Module disabled or database unavailable: no fallback paid request and no change to existing assistant routes.

## Release boundary and file map

This plan is the first foundation release, not the complete Ep Agent/Manager product. Later releases implement compact/full chat cutover, retrieval, Manager mobile screens, exact-action approvals, follow-ups, voice and artifact/media creation. Keep the approved preview available separately.

- `config/ep_agent.php`: module switch, paid switch fixed off for this release, portal allowlist.
- `database/migrations/*_create_ep_agent_foundation_tables.php`: additive conversations, messages, explicit record grants, runs, budget accounts and usage reservations. Foreign keys/indexes and reversible rollback; no existing business-table changes.
- `app/EpAgent/Access.php`: active staff, portal and explicit Lead assignment/grant checks; use actual `assigned_to` user id rather than free-text department assignee names.
- `app/EpAgent/ConversationService.php`: ownership-scoped creation/list/read/archive/message persistence with deterministic ordering and bounded pagination.
- `app/EpAgent/BudgetLedger.php`: atomic reserve/reconcile/uncertain/release state transitions in integer micro-USD.
- `app/EpAgent/RunService.php`: bounded/idempotent run state machine; foundation rejects actual generation as unavailable.
- `app/Http/Controllers/Api/EpAgentController.php`, `routes/ep-agent.php`: authenticated, gated private APIs, loaded from `routes/web.php` without modifying existing assistant routes.
- `app/Http/Controllers/Admin/EpAgentUsageController.php`: allowlisted operational metadata only, with explicit admin authorization.
- `tests/Feature/EpAgent/*`: ownership, scope, budgets, run states, privacy and feature-off compatibility.
- `docs/ep-agent/foundation-operations.md`: setup, isolated tests, schema review and future activation requirements.

### Task 1: Access and private conversation persistence

**Interfaces:** `Access::assertPortal(User $user, string $portal): void`; `Access::assertLead(User $user, string $portal, Lead $lead): void`; `ConversationService::create(User $user, string $portal, ?int $leadId): Model`; `list/read/archive/append` always take the authenticated User. All conversation access is owner-only, including admins. Lead access requires portal membership and either admin status, numeric `assigned_to` ownership or a current explicit module grant; education additionally requires `is_student`, immigration `is_immigration_case`. Unsupported portal names fail closed, including for admins.

- [ ] Write `ConversationAccessTest` first: default flag denies access; inactive/external/revoked users denied; another user/admin cannot read private conversation; unauthorized portal or unassigned lead denied; explicit grant works; reassignment/revocation prevents resumed access; unscoped conversations cannot silently acquire a record; Employment/Residency denied.
- [ ] Run the test using in-memory SQLite and fake HTTP with stray requests forbidden. Expected: failures from missing module behavior.
- [ ] Implement access checks, additive schema and private persistence. Keep context immutable for a conversation; archive is idempotent. Message ordering uses timestamp plus id; list page size at most 50, message content at most 8,000 characters. The client cannot supply owner id or role.
- [ ] Run the task tests and existing AiChatTest/AssistantPanelTest/ClaudeConnectorTest. Expected: all pass, no external requests.
- [ ] Commit `feat: add gated private Ep conversation foundation`.

### Task 2: Atomic spending ledger

**Interfaces:** `BudgetLedger::reserve(int $accountId, string $idempotencyKey, int $upperBoundMicros): Model`; `reconcile(int $reservationId, int $actualMicros, array $usage): Model`; `markUncertain(int $reservationId): Model`; `releaseUnsent(int $reservationId): Model`. Methods are internal trusted-server interfaces, never accept cost bounds from a public request. Account has immutable currency USD, fixed start/end timestamps, integer limit, spent and held totals. No default budget is provisioned. Usage stores provider/model/pricing version plus separate input, output, cached-read/write and tool units; missing price or usage is unresolved, never zero-cost.

- [ ] Write `BudgetLedgerTest`: missing budget denies; zero/negative/overflow costs denied; exact remaining allowance succeeds; next reservation fails; replay same key returns same hold, changed amount/key ownership conflicts; held+spent stays within limit; reconciliation cannot double-charge; unknown completion stays held; only provably unsent requests can release; expired window rejects new reservations while older holds remain reconcilable; unknown prices/units fail closed.
- [ ] Run tests. Expected: fail on missing ledger.
- [ ] Implement transactions with row locking and conditional balance updates, unique idempotency keys and guarded state transitions. Arithmetic uses checked integer bounds. If actual cost exceeds the reserved bound, record the full incurred amount, freeze new spending and raise a reconciliation exception; never silently truncate charges.
- [ ] Run SQLite functional tests plus a separate two-connection MySQL integration test (`BudgetConcurrencyTest`) with simultaneous competing reservations. Expected: exactly one of two reservations that cannot fit together succeeds, totals remain consistent. If no disposable MySQL database is available, report this test unverified and keep production activation gated.
- [ ] Commit `feat: add Ep budget holds and usage reconciliation`.

### Task 3: Run lifecycle and privacy-safe admin reporting

**Interfaces:** `RunService::create(User $user, int $conversationId, string $idempotencyKey): Model`; states `blocked`, `reserved`, `running`, `succeeded`, `failed_unsent`, `uncertain`. Foundation create returns blocked with `provider_not_activated`, without a charge or provider request. Future workers must reauthorize before dispatch and before writes. Admin usage endpoint returns only run id, actor id, permitted portal, timestamps, state and numeric usage/cost; excludes prompts, titles, messages, drafts, connector values and raw exception/provider payloads.

- [ ] Write `RunPrivacyTest`: other users cannot queue against a conversation; replay does not duplicate a run; expired scope denies; disabled module denies; all generation attempts remain blocked and make no HTTP request; non-admin reporting denied; admin reporting excludes sentinel private text and secrets; failure responses contain only safe error codes.
- [ ] Run tests. Expected: fail before run/reporting implementation.
- [ ] Implement run records and gated authenticated routes, metadata allowlist and no-store headers for private endpoints. Reuse existing Super Admin connector location via documentation; no credential duplication or new worker registration.
- [ ] Run all Ep foundation tests and existing assistant/connector tests. Expected: pass with zero provider requests.
- [ ] Commit `feat: add blocked Ep runs and private usage reporting`.

### Task 4: Verification and handoff for subsequent releases

**Interfaces:** tests from Tasks 1–3; existing route/build/test commands. No runtime switch changes.

- [ ] Prepare isolated dependencies without copying live `.env` or credentials. Capture focused and complete test/build results from this branch and compare any failures against unchanged `76f2c9ee` under the same environment; do not suppress failures.
- [ ] Run PHP formatting, relevant PHPUnit regression, frontend production build and route listing. Expected: unchanged existing assistant routes, gated new routes, successful build; document exact counts/failures/deprecations. Full suite and real MySQL verification are required before any production cutover, with unavailable checks explicitly listed.
- [ ] Review new migrations for the production database engine, indexes and lock/rollback behavior. No migrations run against production.
- [ ] Write operations documentation with module-off rollback, unresolved production-revision comparison, unmapped portals, no approved dollar limit, no live provider activation and subsequent Manager/action/media release scope.
- [ ] Obtain one fresh whole-branch review focused on ownership, access revocation, concurrency, uncertain billing and feature-off behavior; fix significant findings with regression tests.
- [ ] Commit verified foundation and report exact delivered scope, checks and remaining gates. Do not represent it as the finished Manager app or enable it for staff.

## Self-review

The five review-focus conditions are covered by Tasks 1–3. Task 3 consumes ownership/scope checks from Task 1; future dispatch consumes Task 2's ledger but foundation deliberately has no provider transport. The readiness document's later capabilities remain sequenced releases rather than misleading stub functionality. Price/model choice, staff portal mappings, production revision and rollout budget remain activation prerequisites rather than inferred defaults.
