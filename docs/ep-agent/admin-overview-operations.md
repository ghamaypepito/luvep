# Live Ep Admin

The local monitoring concept is implemented at `/admin/ep-agent`, including the `#connectors` deep link. It reads actual Ep runs, usage reservations and budget accounts. Access uses the current admins-only pilot, fresh identity and user-view denial. No new migrations, dependencies, workers, credentials, paid requests or configuration writes are introduced.

Tabs are Overview, Tasks, Usage & budgets and API Connectors. The sidebar link is visible only to pilot admins. Tasks expose operational metadata: run id, work-name owner, mapped portal, safe state/reason, timestamps and recorded metering. There is no conversation id/title/message/draft, raw error, idempotency key, private conversation link or transcript export. Details use the same server allowlist. List pages replace prior rows, have 25 rows and id cursors. Refresh/filter/period/visibility updates clear details and metadata; denied requests clear the view.

## Reporting scopes

The selected creation window is this UTC month, rolling seven days or rolling thirty days. UTC is formatted explicitly in the browser, independent of its local timezone. Run counts cover mapped portals; task portal/status filters affect the table only. Ledger totals cover all USD Ep ledger entries in the selected creation window, including unattributed entries. Outstanding holds cover all dates so old unresolved charges remain visible.

Recorded charges are reconciled ledger amounts, not a provider invoice or per-prompt flat price. Per-run null charges and tokens remain “Not recorded”; actual recorded zero remains zero. Missing reconciled costs and unresolved holds are explicitly reported. Token aggregates include reconciled entries independently of whether cost is known. Missing units keep the complete total unknown, while coverage exposes the recorded partial sum and missing-entry count. Overview and Usage flag incomplete accounting. Per-run amounts include currency; non-USD values are never formatted as USD. Budget accounts are shown individually with their own period, currency, limit, spent/held amounts and availability; frozen/expired/scheduled/unsupported-currency accounts have no available reservation allowance. No global team limit is inferred by adding accounts. Budget provisioning must respect the existing ledger numeric limits; out-of-band records beyond those limits are not valid operating accounts. Latest 20 accounts are shown with an explicit truncation notice.

Amounts cross HTTP as integer micro-USD strings and are formatted with BigInt; fractional charges and large values are not rounded through JavaScript Number. This is visibility into the existing ledger, not a new spending enforcement mechanism. A verified provider adapter, conservative bounds, explicit budget and price schedule are still required before generation activation.

## Connector behavior

Ep's paid transport and knowledge/actions remain unavailable. A configured existing Claude connector does not connect or activate Ep generation. Super Admins can see only safe legacy credential-presence/model/surface status and a link to `/admin/ai-connectors`. Plain admins receive no legacy connector status, instructions, credential-source detail or settings link. There is no duplicate key form, provider test or settings/budget editor. Existing connector behavior is preserved.

## Verification and release

New backend tests exercise role/pilot/user-view denial, privacy sentinels, exact costs, unknown/zero/orphan usage, old holds, account states, bounded filters/pagination and connector permissions. JavaScript tests exercise precise money, UTC independent of Asia/Manila time, no-store/session/cancellation, hash deep links, detail focus, replacement refreshes and permission loss. Existing CI runs the new interaction tests. Desktop/mobile Chrome uses only the separate local fixture database, verifies actual metadata and non-admin denial, and checks task-view overflow.

Deploy through the existing reviewed main workflow after regression, build and independent review pass. Keep all current pilot/provider flags unchanged. Post-deploy verify exact source, admin reporting on real MySQL, non-admin and user-view denial, connector allowlists, paid=false, health and the deep link. Do not create synthetic runs/budgets or generate replies in production to test this interface. No provider settings or credentials should change.

Rollback the code through a reviewed revert, preserving later unrelated releases. If the entire Ep pilot needs rollback, use the existing core/UI off switches and rebuild config cache, leaving private history and ledger tables intact. No destructive migrations or historical database restore are part of this release.
