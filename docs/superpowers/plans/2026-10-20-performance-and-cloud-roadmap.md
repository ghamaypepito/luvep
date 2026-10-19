# Luvep performance and cloud migration plan

> **For agentic workers:** Use superpowers:executing-plans to implement each approved phase, with measured verification and deployment rollback.

**Goal:** Improve loading and interaction speed now; build a measured, reversible path to more resilient hosting without changing user access or losing records/files.

**Architecture:** Retain Laravel 12, React 19, Inertia 2, MySQL and Redis. First reduce browser work and measure bottlenecks on the current VPS. Evaluate managed Laravel hosting after performance, capacity, availability and recovery requirements are known. Cloud provisioning/migration is a later decision, not part of the immediate optimization.

**Current evidence:** Application bundles every JSX page eagerly. Last build entry ~5.29 MB raw / 1.32 MB gzip. Several images exceed 1 MB; a bundled video is ~18.9 MB (asset presence does not prove every page downloads it). One VPS hosts production application, MySQL, Redis, workers and uploads; existing staging also shares the server. Main-site HR uses existing sessions. Production backup exists on that same server; it is not independent disaster recovery.

## Success measures and baseline

Record cold and warm homepage, login, public activities, Admin, Immigration and HR dashboard/calendar journeys. Record browser/device/network profile, median of at least three runs, script/image/video transfer, request waterfall, LCP, CLS, interaction latency, server response time, database queries and queue lag. Authenticated benchmarks use synthetic isolated data or an explicitly scoped existing session, never public traces with personal records.

Provisional targets: initial login JavaScript below 1.5 MB decoded for this first release, then tighter budgets after measurement; field p75 LCP <=2.5s, INP <=200ms, CLS <=0.1. Treat these as targets, not guarantees. Compare like-for-like and distinguish local lab results from production/network performance. Budget authenticated dashboard query/response times from observed baseline rather than guessing traffic capacity.

## Phase 1 — Immediate loading improvement (authorized now)

- [x] Baseline the production build and a real-browser login resource budget.
- [x] Replace eager page imports in `resources/js/app.jsx` with async page resolution. Load automatic layouts only for the requested page; retain explicit page layouts and missing-page diagnostics.
- [x] Preserve hashed assets from previous releases in production/staging rsync so already-open sessions can load their older chunks after deployment. Add a documented retention/cleanup policy after release-age data is available.
- [x] Verify login, public navigation, role layouts, main-site HR, calendar, preview exit and logout with real-browser synthetic fixtures; verify imported pages resolve and no chunk-load/console errors appear.
- [x] Measure initial script bytes before/after and build manifest gzip sizes. Run HR/backend regressions appropriate to the change; no permissions or database schema changes.
- [x] Deploy through the existing fork workflow, verify live login/public pages and new chunk responses, retain rollback assets and exact release SHA.

## Phase 2 — Media and request optimization

Inventory actual network downloads first. Convert oversized raster images to appropriately sized WebP/AVIF with responsive srcsets; retain original sources and acceptable visual quality. Prioritize the above-fold hero image/poster, lazy-load below-fold images. Investigate hero/background videos separately: create suitable posters, compress alternate encodes, defer below-fold playback, respect reduced motion/data preferences, and avoid duplicate video downloads. Review font families/weights and use WOFF2/subsets where licensing permits. Test mobile appearance before promotion.

Audit Inertia props and polling. Use pagination, selective reloads and deferred nonessential data where supported. Pause hidden-tab polling, deduplicate requests, preserve the HR clock server-authoritative freshness and break/reminder behavior. Do not cache user-specific responses publicly or let caching cross permission boundaries.

## Phase 3 — VPS and backend resilience

Measure CPU, RAM, swap, disk latency, PHP-FPM saturation, slow requests/queries and Redis memory under representative concurrent work. Inspect indexes and eager loading on actual slow queries. Cache only data with clear invalidation and authorization scope. Keep exports/email/PDF work on queues; monitor failed jobs, queue delay and worker restarts. Verify PHP OPcache, HTTPS, compression and immutable hashed-asset caching. Tune from measured load; do not increase workers beyond memory capacity.

Introduce uptime/error/latency alerts with secrets and personal data redacted. Copy encrypted backups off the VPS, set retention, protect credentials separately and perform a restore drill. Record actual recovery time and recovery point. Confirm staging cannot modify production DB, queues or storage.

## Phase 4 — Cloud decision and cost model

Collect peak concurrent sessions, requests/second, background jobs, database size/growth, uploads/egress, staff locations, monthly budget, required uptime and acceptable data loss/downtime. Measure candidate regions from actual user locations; colocate app/database/cache. Confirm data residency expectations before choosing regions/providers.

Compare (A) hardened VPS plus CDN/offsite backups, (B) Laravel Cloud with dedicated workers and managed data services, and (C) AWS app replicas/load balancer with managed MySQL/Redis and object storage. Favor B for reduced operations burden if workload, regions, extensions and cost fit; choose C only when availability/control needs justify complexity. A managed platform or one replica alone does not establish high availability.

Price app minimum/peak replicas, workers, HA database, Redis, private object storage, backups, CDN/egress, logs, staging and support using current provider quotes. Set budget alerts and bounded scaling. Production workers should remain available for scheduled work; avoid sleep-related delays for attendance and notifications.

## Phase 5 — Prepare application for multiple instances

Move uploads and HR documents to private S3-compatible storage with authorization-preserving downloads; copy and checksum files before changing references. Audit every local-path dependency: PDFs, signatures, generated forms, temporary exports and stored credentials. Keep public assets and private employee documents in separate policies/buckets.

Use managed shared Redis for sessions/cache/queues, with separate namespaces per environment and HA appropriate to recovery goals. Ensure scheduled jobs run once, use distributed locks/idempotency, and preserve notification delivery deduplication. Validate runtime extensions, PHP version, cron, queue supervisors and file-size/time limits.

Securely retain Laravel APP_KEY: existing encrypted fields/cookies/files depend on it. Do not rotate it accidentally during migration. Preserve database charset/collation/timezones and indexes. Test MySQL compatibility, transaction locking, clocks, signed URLs and exports. Configure health/readiness checks, secrets management, least-privilege service identities and deployment rollback.

## Phase 6 — Staging rehearsal and controlled cutover

1. Provision staging only after provider/budget/region selection. Use sanitized data and log/sandbox email; never process real production queues in rehearsal.
2. Restore a representative backup and file set; reconcile row counts, file hashes and encrypted-field readability.
3. Run role/permission regression, login/password-reset/logout, HR attendance/break/DTR/leave/preview, confidential downloads, notifications, imports/exports and existing department workflows.
4. Run representative load and failure tests, including replica restart and restore/failover where provisioned. Verify metrics and alert routing.
5. Produce a cutover runbook with owners, timings, read/write freeze, final DB synchronization and file delta, queue drain/pause, DNS/proxy switch and validation gates. Avoid dual writers.
6. Take a fresh independent backup; perform the rehearsed cutover in an agreed window. Confirm pending HR roster remains pending and no permission changes are introduced.
7. Monitor errors/latency/queues and data reconciliation. Keep old infrastructure isolated and recoverable through the rollback window.
8. Rollback must reconcile any new writes before switching back; DNS reversal alone is insufficient after cloud writes begin. Retire old resources only after restore/recovery checks and user acceptance.

## Deliverables and sequencing

Immediate: code-splitting release, browser checks, before/after byte report, deployment evidence. Next: media/query audit, off-server restore drill and monitored VPS baseline. Later: priced cloud comparison, isolated rehearsal and approved cutover runbook. Estimate migration effort only after storage/runtime/dependency inventory; do not buy infrastructure before sizing it.

References: https://laravel.com/cloud/docs/compute ; https://developers.cloudflare.com/cache/get-started/ ; https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Concepts.MultiAZ.Failover.html ; https://web.dev/articles/top-cwv

## Phase 1 verification results

Cold-login browser resource measurement: 5,287,553 → 703,912 decoded JavaScript bytes (86.7% reduction). New initial dependency set is approximately 220,886 gzip bytes versus the prior ~1.32 MB compressed bundle. This measures payload reduction, not real-user elapsed load-time improvement. All pages are no longer downloaded up front; automatic layouts load only as needed.

Four Chrome tests pass: payload budget + production stylesheet/computed layout, main-site HR login/calendar/attendance, Admin preview exit, and public/Admin/Immigration/Sales/Education/English/Accommodation/Finance/HR navigation. 135 HR backend tests / 745 assertions pass. Build passes with existing education duplicate-key and chunk-size warnings. Synthetic rsync check confirms old chunks retained, new chunks copied and manifest replaced. Independent review caught the consolidated-CSS template integration; fixed and covered with a failing-then-passing style check. Visual HR calendar inspected after the fix.

CSS deliberately remains consolidated; production Blade explicitly includes style.css, while development continues using Vite-injected CSS. Existing hashed assets are protected from deletion. Add a bounded retention cleanup only after checking active release manifests and session/tab lifetime; monitor disk growth meanwhile.

## Live release — 20 October 2026

Merged PR #2: https://github.com/ghamaypepito/luvep/pull/2 . VPS deployment marker: `9c8cc3f8d82a8b7933fabfd792264112ea877a95`, run `39286487863`. Source checksums match the tested frontend/template. GitHub status API lagged the verified server state; live evidence establishes deployment, not a claimed completed API status.

Live Chrome cold-login measurement before: 5,287,553 decoded and transferred JavaScript bytes, one script. After: 703,912 decoded bytes and 222,725 transferred bytes across nine required scripts. This is 86.7% less decoded JavaScript and 95.8% less transferred JavaScript. These are single-run payload measurements; no median LCP or real-user speed multiplier is claimed. One built stylesheet is linked and applied; computed login min-height is correct and no JavaScript runtime errors occurred.

Additional immediate VPS changes: enabled gzip types for CSS/JavaScript/SVG via `/etc/nginx/conf.d/luvep-static-compression.conf`; added `public, max-age=31536000, immutable` only to generated `/build/assets/` static file types in the Luvep virtual host. Nginx syntax/reload checks passed. Live CSS and JS return 200 with gzip and immutable caching. Public home, activities and login return 200 with private/no-cache HTML; guest HR redirects to login. Read-only live checks confirm Admin HR 200 and pending employee 403; worker is healthy.

Rollback snapshot: `/root/luvep-performance-20261020` on the VPS contains the prior frontend/build and Nginx configuration. Revert the performance code through the release workflow if necessary; preserve old asset files for open tabs. To reverse server-only tuning, remove the dedicated compression include, restore the saved Luvep virtual host, run `nginx -t`, then reload. No cloud resources were provisioned, no database schema changes were introduced by this optimization, and employee enrollment was unchanged.

Next planned work: representative mobile/desktop timing baselines, media optimization, query/response profiling, independent off-server backups and the priced cloud evaluation.
