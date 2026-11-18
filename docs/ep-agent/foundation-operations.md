# Ep foundation release operations

The initial foundation release added backend infrastructure only. The subsequent UI release is documented in `workspaces-operations.md`. The current EP Assistant, public Pathy, staff navigation and Manager preview are unchanged. There is no new staff UI, model transport, retrieval, operational write tool, worker, scheduled agent or paid generation in this release.

## Activation and privacy

`EP_AGENT_ENABLED` defaults to false. Leave it false in production. `ep_agent.paid_enabled` is fixed false in code and no provider adapter is installed. No budget account, price schedule, grant or credential is seeded. Existing `/admin/ai-connectors` remains the sole connector administration location; existing connector settings are not changed.

Module endpoints use existing session authentication and CSRF protection. Private responses use `Cache-Control: no-store, private`; errors return safe codes without traces, request bodies or SQL. Controller failures are intercepted before Laravel can log private SQL bindings. Existing global session/auth middleware still applies. Ep denies requests in Super Admin user-view mode, even when the effective user owns the conversation. Direct database access and existing non-Ep assistant behavior are outside this module's privacy guarantees.

Only Education and Immigration have verified portal mappings here. Staff must hold portal access plus numeric Lead assignment or an unexpired explicit `ep_record_grants` entry (admins bypass record assignment, never conversation ownership). Department flags must also match. External agents, sub-agents, clients, inactive and unknown roles are denied. Lists filter ownership and current record scope in SQL before selecting private titles. No knowledge/documents are retrieved in this release.

## Data and interfaces

Seven additive tables: `ep_conversations`, `ep_messages`, `ep_record_grants`, `ep_budget_accounts`, `ep_usage_reservations`, `ep_runs`, `ep_events`. No existing business rows or columns are modified. Foreign keys reference existing bigint identifiers; new indexes cover ownership/cursors, record grants, budgets and runs. New migrations create only empty tables; FK validation/metadata locks on referenced tables still require a controlled deployment and monitoring. Rollback migrations are destructive to module history and are not the routine rollback method.

Private APIs under `/api/ep-agent`: create/list/read/archive conversations; append a user message; create an idempotent blocked run. Runs always report `provider_not_activated` and never call a provider. Admin usage API `/api/ep-agent/admin/usage` is metadata-only. Its null cost fields mean no reconciled cost exists, not a promise of zero billable usage. Append persists a user message only; it does not generate a reply. Read/list pages have a 50-item bound, with `before` id cursors.

Ledger methods are internal services, not public pricing APIs. USD amounts are integer micro-dollars. Account periods and currency are fixed when provisioned. A trusted future provider adapter must calculate a conservative upper bound for all billable input/output/cache/tool units before reserving; mark the reservation dispatched before networking, then reconcile complete usage against a verified versioned price schedule. Persist the price snapshot with the charge. The ledger alone does not establish an end-to-end provider spending guarantee.

Timeouts/missing prices/unknown usage retain holds; they never automatically become refunds or free usage. Only reserved/provably unsent requests can release. Reconciliation is idempotent. A charge above the bound is committed in full and freezes the account; new requests are denied. Investigate the bound/price discrepancy before any manual resolution. Do not move old uncertain holds into a new period or reset balances to bypass a cap. There is no automated reconciliation or budget-edit endpoint yet.

Audit events record actor, conversation/run ids, type and timestamp, without message bodies, titles or provider payloads. This release records conversation creation, message append, archive and blocked run events. No retrieval/tool/approval events exist because those capabilities are absent. Retention/deletion rules and exceptional private-chat access must be agreed before a staff pilot; do not add administrator transcript export casually.

## Verification environment

Use a separate checkout with exact Composer/npm lockfiles and a randomly generated local `.env.testing` encryption key. Never copy production `.env`. Run PHP 8.3, SQLite `:memory:`, array mail/cache/session and sync queue. New HTTP tests prevent stray provider requests. Existing full tests must also have current `public/build` assets or Inertia page tests fail on missing manifests.

```sh
APP_ENV=testing php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result
npm ci
npm run build
node --test tests/js/pdf-worker-build.test.mjs
```

The opt-in `BudgetConcurrencyTest` requires pcntl, pdo_mysql and `EP_TEST_MYSQL_SOCKET` pointing ONLY to a disposable local MySQL server. It creates/drops a random `ep_test_*` database and uses two forked connections. Never supply the portal database. The normal suite skips this test when the disposable socket is absent. MySQL schema verification should also apply/reverse all three new migrations against skeleton parent tables matching production identifier types.

## Deployment and rollback

User authorized deployment on 6 November 2026 after plan review. This supersedes the plan's initial deployment prohibition, not its paid-activation or staff-cutover gates. Use the existing reviewed main-branch GitHub Actions production workflow; preserve concurrent upstream work. Before merging, verify exact source revision, regression/build results, fresh review, schema validation and a protected server-side database backup. Do not alter production connector settings or worker topology.

A new production revision (`7903c79d`, RSVP support Reply-To) appeared during verification; merge and specifically test it before release. The earlier baseline was `76f2c9ee`.

Post-deploy: verify successful CI post-deploy migrations, exact deployment-source commit, health/front-end checks, existing assistant route availability and effective Ep module/paid flags false. Unauthenticated Ep access must not disclose data. No live provider generation is part of verification.

For module rollback: keep `EP_AGENT_ENABLED=false`, clear/rebuild config cache using the normal operational process, and roll back module code through a reviewed revert if necessary. Leave additive tables in place to preserve any module history and avoid restoring an old database over new business records. A whole-site rollback must preserve newer unrelated releases. No destructive down-migration or historical database restore is authorized as an automatic rollback.

## Subsequent releases

Implement the real compact/full private UI and approved knowledge retrieval first, then Manager read-only briefings/workloads and client/personnel details, then exact-action proposals/approvals and idempotent follow-ups. Voice, documents, presentations and media require separately verified connectors/metering. Before paid activation obtain an explicit dollar limit, verified model prices and conservative bounds, complete provider accounting/failure tests and resolve data-processing/retention requirements. Before Employment/Residency activation map actual workflows and permissions. Before any staff-wide replacement validate the pilot and reversible sidebar/dock switch.
