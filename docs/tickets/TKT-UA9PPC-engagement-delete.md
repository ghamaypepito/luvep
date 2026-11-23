# TKT-UA9PPC — Engagement-only archive

Ma’am Ei confirmed option A: remove the selected entry from the active Engagement list, retain documents/history and make no change in Finance. This supersedes the earlier delete-feedback-only proposal.

Replace the workspace Delete action with an explicit Archive confirmation and dedicated authenticated POST. Only active Admin/Super Admin and Immigration staff/manager/adviser roles may archive. Validate the canonical live Immigration case (including legacy handoffs), explicit pack ID and original engagement-document ID. Reject foreign, stale, invoice-only and deleted-case targets.

Store identity, actor and timestamp in the new engagement_pack_archives table, unique per case/pack. Filter that marker only in the active Engagement list, before search/document limiting. Do not update Leads, LeadDocuments or files, revoke signing links, cancel agreements, send notifications or change invoices, receivables, payments, balances or Finance reports. Other packs and private historical documents remain available.

Serialize with the existing case Engagement lock and a database transaction. Archive and actor-attributed engagement.archived audit commit together. Retries are idempotent; audit failure rolls back. No private document/financial text is copied into the marker or audit.

Tests compare full before/after Leads, documents, receivables, payments, proof links and case-financial rows and file/signature hashes. Cover colleague-owned signed/Sent/draft/legacy packs, other case/pack isolation, search and pre-limit filtering, permissions, stale/deleted targets, retry and rollback. Actual React tests cover non-destructive confirmation, exact POST payload, cancellation, failure/retry, duplicate guards and action visibility.

Deploy the additive archive-table migration with the application. No Finance migration or backfill is included. Keep archive data during an application rollback; older application code can show archived entries again without changing Finance/documents. No self-service restore or invoice cancellation feature is added. No live record is archived by this session. Keep In review until Sir Ghamay reviews/merges/deploys and the requester verifies the selected entry is removed only from active Engagement.
