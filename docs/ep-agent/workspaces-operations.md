# Ep private workspace and Manager pilot

This release adds `/ep-agent` and `/ep-manager` on the foundation. It uses existing Luvep authentication, database, role shell and server connector configuration. There are no new migrations, packages, workers or credentials. Production records are read through an explicit SQL scope; local screenshots use a separate, clearly named demo SQLite database.

The user selected **Admins only** on 6 November 2026. After deployment checks, enable `EP_AGENT_ENABLED=true`, `EP_AGENT_UI_ENABLED=true`, `EP_AGENT_PILOT_ADMINS=true`, and leave `EP_AGENT_PILOT_USERS=` empty. Preserve other environment settings. Back up `.env` privately before editing and rebuild config cache through the established operational process. Paid generation stays fixed false; no price schedule or budget is provisioned.

Navigation and all new Ep APIs must enforce the pilot while the UI switch is on. Other staff retain the existing EP Assistant. Super Admin user-view cannot access Ep, even when viewing an admin account. The old `/assistant` and existing provider callers remain available for compatibility; this release does not disable or meter those separate existing surfaces.

## Staff workspace

The sidebar opens compact private chat; expansion uses the same persisted conversation id. Full page history, archive, portal choice and record context are backed by real authenticated APIs. Saved requests are user messages only. Ep displays that generation is off and never fabricates an assistant response. Creating a run records a blocked task with `provider_not_activated`.

Drafts remain in component memory, not browser storage. Switching threads or expanding is guarded while a draft or save is pending. Failed saves retain the draft. If the save succeeded but task-status retrieval failed, the interface still says the request was saved. A network loss may leave delivery uncertain: refresh history before retrying rather than submitting again blindly. Browser/Inertia navigation warns before discarding a draft and blocks navigation during saving. Confirmed navigation/logout can still discard an unsaved draft; there is no durable draft recovery.

History/message APIs page at 50 items; Manager records page at 25. Manager uses replacement pages, so later reads never merge rows whose earlier authorization may have changed. Reads use no-store, cancellation/sequence guards and fresh permission checks. Visibility return revalidates data; there is no background polling or service worker. Permission denial clears private content. The application does not promise instantaneous cross-device revocation before the next request.

## Manager

Today and Attention show authorized CRM records with real client and assigned-personnel details. Team counts only assignments among accessible records. Admins can read mapped department records; Immigration Managers need current assignment or explicit unexpired record grants and are limited to Immigration. The first live pilot is admins only.

“No update in seven days” describes a rolling timestamp condition, not an urgent deadline, missing task, or case outcome. Unknown timestamps remain unknown. Missing-document metrics are omitted because requirement/request portal ownership has not been verified. Team initially shows up to 25 personnel; individual assigned-personnel buttons remain available in paginated CRM records. Personal/payroll/licence/document bodies are not included in quick details.

Quick details use native accessible modal dialogs with cancellation and focus restoration. Client and personnel endpoints reauthorize each request. “Private chat” links carry validated record/portal context; this release does not redirect into legacy record routes with different authorization policies.

Ask Ep opens private history; it does not generate a whole-portal briefing. Voice, notes/actions, approvals, follow-ups, knowledge retrieval, documents/slides/media and Employment/Residency remain subsequent verified integrations. This is a mobile-ready web interface, not a native app or installable offline app.

## Release checks and rollback

Run the full PHPUnit suite plus frontend interaction/regression tests and production build. Desktop/mobile Chrome QA must exercise real session login, both quick-detail types, history/resume and no horizontal overflow. New interaction tests are included in the existing CI workflow. Existing baseline warnings/deprecations are recorded rather than hidden.

Deploy through the existing reviewed main-branch workflow, preserving concurrent changes. Verify source revision, public health/browser render, new routes, paid=false, and initial switches off. Then apply the authorized admins-only pilot flags and verify fresh admin/non-admin/user-view decisions server-side. Do not generate a live reply to test activation.

To roll back the pilot, set `EP_AGENT_ENABLED=false` and `EP_AGENT_UI_ENABLED=false`, rebuild config cache, and confirm the original assistant returns. Leave conversation/run/ledger tables and private history intact. Do not restore an old database over new business records or destructively roll back foundation migrations.
