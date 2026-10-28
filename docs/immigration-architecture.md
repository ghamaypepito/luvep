# Immigration Portal — System Architecture

> Reference for the **Immigration** department portal of ePathways (Laravel 12 + React 19 +
> Inertia.js). Written to orient an engineer (or Claude) working anywhere in the immigration domain.
> For the app-wide picture see [`../CLAUDE.md`](../CLAUDE.md). For the AI-assistant layer and its
> immigration-advice guardrails see [`ai-agent/`](ai-agent/).

---

## 1. What the immigration portal is

The department workspace at **`/portal/immigration/*`** where staff run visa cases end to end:
convert an enquiry into a case, work a stage pipeline through the INZ outcome, request and review the
client's documents, generate the engagement pack (agreement + IAA standard docs) and the tax invoice,
and manage the visa catalogue that prices all of it.

It is an Inertia portal, not an API/SPA: controllers return `Inertia::render(...)` page components.
Pages under `pages/portal/immigration/*` are auto-wrapped in `ImmigrationLayout` by `app.jsx`.

---

## 2. Roles & access control

Two layers, both enforced server-side:

1. **Role-level** — `EnsurePortalAccess` (`portal:<role>` middleware). The immigration portal group is
   gated `portal:immigration`, but `User::canAccessPortal('immigration')` also admits the immigration
   role variants and admins.
2. **Row-level** — controllers re-check ownership/`is_immigration_case` before acting (e.g. the Case
   Profile hard-404s a non-case lead). `EnsurePortalAccess` is role-level only and does **not**
   provide record scoping.

### Roles that resolve to this portal (`app/Models/User.php`)

| Role | Meaning | Notes |
|---|---|---|
| `immigration` | Standard department staff (the singleton in `User::PORTAL_ROLES`) | Full department access including document/engagement/invoice writes |
| `immigration_manager` | Department head | Can **edit visa types incl. price** (`VisaTypePolicy`); full case write |
| `immigration_adviser` | Licensed Immigration Adviser (LIA) | **Full write on cases** (notes, threads, documents, stage, verdict) and visa catalogue CRUD, including fees. Advice-bearing artifacts are gated by licence, not role — see below |
| `admin` / `super-admin` | Full access to every portal | `canAccessPortal` returns true for all `portal:*` checks |

`User::IMMIGRATION_ROLES = [immigration_manager, immigration_adviser]`. `homeRoute()` lands all three
immigration roles on `/portal/immigration/dashboard`.

Catalogue access was expanded to advisers on 2026-08-22 (`fba4a056`, "visas for adviser").
`VisaTypePolicy` permits adviser create/update/delete; fee edits are audited by `VisaTypeController`.
This ordinary commercial write permission does not bypass the advice-bearing approval gate.

### Advice-bearing content is licence-gated, not role-gated (Build 12 §2)

Authoring or approving an **advice-bearing artifact** — eligibility endorsement + reasoning, case
verdict, RFI responses to INZ, lodgement sign-off, written advice under the engagement, or any
client-facing statement about whether the applicant qualifies — requires a **current IAA licence**,
enforced by `AdviceBearingPolicy::approve` / the `approve-advice-bearing` Gate, which calls
`User::holdsCurrentLicence()` (non-empty `iaa_licence_number` **and** `iaa_licence_expiry` in the
future). A lapsed licence closes the gate automatically; no role string substitutes for it, and an
AI/system actor (never licensed) can never be recorded as approver.

Everything else — contact details, appointments, document upload/checklist stamping, invoice
generation, payment recording, internal notes/threads, handoffs, non-judgment stage moves — is
ordinary and follows normal role permissions.

> **Why this replaced the old "adviser is read-only" rule:** the IAA 2007 restricts *who may give
> advice* (the LIA), not who may edit a record. Making the adviser read-only pushed advice-bearing
> content onto unlicensed staff who authored it while the adviser merely nodded at it verbally. The
> content-level control puts the licensed person on the actual attestation. **Do not reinstate the
> role-level read-only rule.**

> ⚠️ As of Build 12 phase 1, the document/checklist/engagement/invoice write route group includes
> `immigration_manager` + `immigration_adviser` (previously base `immigration` + `admin` only).

---

## 3. Routing map

Two route homes, both in `routes/web.php`:

### A. `/portal/immigration/*` — `portal:immigration` group (`portal.immigration.*` names)

| Area | Routes |
|---|---|
| **Dashboard** | `GET /dashboard` |
| **Leads** (shared Sales component under this layout) | `GET/POST /leads/{id}`, `/leads/{id}/registration`, `/leads/bulk-agent`, `/leads/bulk-delete`, `/leads/{id}/portal-invitation/request` |
| **Assessments / intakes** | `GET /assessments`, `GET /intakes`, `GET /intakes/{type}/{id}` (work\|student\|visitor), `POST /assessments/{id}/convert-to-case`, `GET /inz-forms` |
| **Cases** | `GET /cases`, `POST /cases` (create), `POST /cases/{id}` (edit), inline `POST /cases/{id}/stage`, `/cases/{id}/visa`, `/cases/{id}/priority` |
| **Case Profile** (Build 11.D) | `GET /cases/{lead}/profile`, `POST /cases/{lead}/personal`, `cases/{lead}/agreements/*` (managed agreements) |
| **Engagement / Invoice workspaces** | `GET /cases/engagement`, `GET /cases/invoice` |
| **Documents** | `GET /documents` |
| **Appointments** | `GET /appointments`, `POST /appointments/{id}/resend-invoice`, `POST /availability` |
| **Visa catalogue (SETUP)** | `GET /visa-types`, `POST /visa-types`, `POST /visa-types/{id}`, `DELETE /visa-types/{id}`, `GET /visa-types/templates`, `GET /visa-types/{id}/price-history` |
| **Students** (Education-owned screen, this layout) | `GET /students`, `POST /students*` |
| **Task Board / Reports / Profile** | `GET /tasks`, `GET /reports`, `GET/POST /profile`, `POST/DELETE /profile/signature` |
| **Email/SMS/Replies** | shared `BulkEmailController` / `EmailReplyController` under this prefix |

### B. `/admin/immigration/*` — shared `portal:admin,immigration` group

Historical home; the immigration sidebar deep-links here.

- `resident-intakes` index/show, `resident-intakes/{id}/documents/{key}`, `resident-intakes/{id}/edit-link`
- `user-reviews` index/show (immigration-scoped moderation)

### C. Lead-document endpoints (cross-department, `/admin/leads/*`)

The immigration Case Profile Documents tab uses these shared `LeadDocumentController` routes:
- `POST /admin/leads/{id}/documents/requests` (request docs) — gate `portal:admin,sales,immigration,immigration_manager,immigration_adviser`
- `POST /admin/leads/{id}/documents/{docId}/status` (approve/reject)
- checklist upload / generate / engagement generate / invoice generate / download-all — gate
  `portal:admin,sales,education,english,immigration,accommodation,finance`

---

## 4. Controllers

| Controller | Responsibility |
|---|---|
| `Portal\ImmigrationController` | The portal's pages: `dashboard`, `cases`, `engagement`, `invoice`, `documents`, `assessments`, `intakes`, `appointments`, `reports`, `profile`, case CRUD + inline stage/visa/priority, `convertAssessmentToCase`. Uses `BuildsLeadRow` trait for lead/case row payloads. |
| `Immigration\CaseProfileController` | Build 11.D purpose-built Case Profile (`/cases/{lead}/profile`); `updatePersonal`. Hard-404s non-cases. |
| `Immigration\AgreementController` | **Managed** agreements on the Case Profile (`cases/{lead}/agreements/*`): index, templates, generate, send, downloadPdf, void. Re-checks case + agreement↔lead ownership. |
| `VisaTypeController` | Visa catalogue CRUD + price history + checklist templates. Shared with admin; `VisaTypePolicy` permits admin, manager, adviser and base immigration staff edits. Deletion excludes base immigration staff. |
| `LeadDocumentController` | Cross-department document engine: requests, status review, checklist uploads, **engagement pack generation**, **invoice generation**, previews, downloads, `documentsJson` (Files popover). |
| `ResidentIntakeController` | Resident-visa intake: public submit + `/admin/immigration/resident-intakes` review + token edit-links. |
| `LeadPortalInvitationController` | Lead Portal access request→approve→setup flow (client portal). |

---

## 5. Domain models & key fields

### `Lead` (the central case record; `app/Models/Lead.php`)
A case is a `Lead` with `is_immigration_case = true`. Key immigration columns:
- **Identity of case-hood:** `is_immigration_case`, `immigration_converted_at/by`, `assessment_id`
- **Pipeline:** `immigration_stage` (see `IMMIGRATION_STAGES`), `immigration_priority`
  (`urgent|high|medium|low|done`), `immigration_assignee`, `stage_updated_at/by`, `stage_history`
- **INZ facts:** `inz_visa_type`, `inz_status`, `inz_reference`, `inz_lodged_at`, `inz_decision_at`
- **Activity tracking:** `last_activity_at/by/desc` (staff "Updated" column, via `stampLastActivity()`)
- **Scope:** `scopeImmigrationCase()` — `is_immigration_case = true`
- Uses `LogsActivity` trait (audited to `/admin/activity-logs`).

**`IMMIGRATION_STAGES`:** For Assessment → Endorsed → Agreement Sent → Agreement Signed →
For Agreement & Invoice → Invoice Paid → Visa Lodged → Interim Visa Issued →
Request for Information → Approved in Principle → Approved Visa → Decline Visa.

The order is load-bearing — `CaseStepService::deriveStage()` treats the array index as a
forward-only rank. *Interim Visa Issued* has no process-chain step mapped to it, so it is a
manual-only stage: `jumpToStage()` returns false and the controller falls through to the legacy
direct write (which still stamps `stage_updated_*` and pushes stage history).

### `VisaType` (`app/Models/VisaType.php`) — the pricing catalogue
- Consultation fee/duration, checklist (`checklist_items` JSON), INZ form ref, icon, category.
- **Fee model (two axes):**
  - **Location** (`FEE_LOCATIONS = [onshore, offshore]`) × **Tier** (`FEE_TIERS = [discounted, normal]`)
  - Columns: `professional_fees` (onshore/normal), `professional_fees_discounted` (onshore/disc),
    `professional_fees_offshore`, `professional_fees_discounted_offshore` — all **exclusive of GST**.
  - `inz_application_fee` is a **single government charge** shared across both locations (no GST).
  - `professionalFeeFor($tier, $location)` and `feeBreakdown($location)` compute the GST-inclusive
    RRP (`GST_RATE = 0.15`) and `total = prof-fee-incl-GST + INZ-fee`. Totals are **derived, never
    stored**, so screens and generated documents can't disagree.

### Documents
- `LeadDocument` — a file on a case. `status` (Submitted / UnderReview / Approved / Rejected /
  StaffShared), `source` (upload / generated / …), `checklist_key`, `source_variant`
  (`engagement:*`, `invoice`), review + uploader relations. Generated docs carry no review status.
- `LeadDocumentRequest` — a staff ask for a specific document; emails the lead; uploads link back via
  `request_id`.

### Agreements — **two coexisting systems**
1. **Engagement pack** (older, workspace-driven): `LeadDocumentController::generateEngagement` +
   `EngagementDocumentGenerator`, surfaced on the **Engagement** workspace (`Engagement.jsx`). Renders
   Written Agreement + 3 IAA standard docs to PDF, stored as `LeadDocument` (`source_variant=engagement:*`).
2. **Managed agreements** (Build 11.D, Case Profile): `Immigration\AgreementController` +
   `AgreementService` + an `Agreement` model, surfaced on the Case Profile **Agreement** tab
   (generate → send → sign → void lifecycle, `AgreementSignedNotification`).

### Intake / assessment
- `ResidentIntake` / `Assessment` — public visa-interest submissions; converted into cases via
  `convertAssessmentToCase`. Work/Student/Visitor intakes use `/intakes/{type}/{id}`; Resident uses
  the richer `/admin/immigration/resident-intakes/*`.

---

## 6. Core services (`app/Services/Immigration/`)

| Service | Role |
|---|---|
| `CaseChecklistService` | Resolves the document checklist for a case from its visa type (`forCase()`); powers the Documents tab + the client `/track` requirements panel. |
| `EngagementDocumentGenerator` | Renders the engagement pack (Written Agreement + IAA docs) via Blade→dompdf. Reads fees via `professionalFeeFor($tier, $location)`; honours `fee_tier`, `fee_location`, `include_gst`, `signer_id` overrides. |
| `InvoiceGenerator` | Builds/render the tax invoice; `defaultsFor($lead,$tier,$includeGst,$location)` seeds line items from the visa's fees; `nextInvoiceNumber()`. |
| `AgreementService` | Backs the managed-agreement lifecycle (Case Profile). |
| `StubSignatureProvider` | Signature stand-in for the managed-agreement flow. |

Blade templates for generated docs live in `resources/views/agreements/engagement/*`.

---

## 7. Frontend

**Layout:** `components/layout/ImmigrationLayout` (sidebar + chrome; badges via `sidebarBadges`).

**Pages** (`pages/portal/immigration/`): `Dashboard`, `Cases`, `CaseProfile`, `Engagement`,
`Invoice`, `VisaTypes`, `Documents`, `Assessments`, `Intakes`, `ResidentIntakes` (+ details),
`Appointments`, `Tasks`, `Reports`, `Profile`, `ProposalsAgreements`, `Students` (re-export of
Education's), `UserReviews`, plus shared Email/SMS/Replies screens.

**Case Profile tabs** (`components/immigration/case-profile/`): `PersonalTab`, `DocumentsTab`,
`AgreementTab`, `AssessmentTab`, `CommunicationsTab`, `NotesTab`, `AIHealthTab` +
`CaseProfileHeader`, `GenerateAgreementModal`.

**Shared immigration components:** `CaseFilesModal` (per-case file history, used by Cases/Documents/
Leads/Students), `AvailabilitySettings`, `BookingsCalendar`.

---

## 8. Key flows

- **Enquiry → case:** public intake/assessment → `convertAssessmentToCase` flips
  `is_immigration_case`, stamps converter, seeds the case → appears on the **Cases** board.
- **Pipeline:** staff move `immigration_stage` inline on the Cases table (audited; stamps
  `last_activity_*` and `stage_updated_*`). Priority ring driven by `immigration_priority`.
- **Documents:** staff **request** docs (`LeadDocumentRequest`, emails lead) → lead uploads on
  `/portal/lead/documents` or `/track/{code}` → staff review status; approved files can push to Drive
  and bundle via download-all (approved-only).
- **Engagement:** Engagement workspace → pick case + docs + **Applicant location** (onshore/offshore)
  + **Payment basis** (discounted/normal) + **GST** + signing adviser → live preview → generate PDFs.
- **Invoice:** Invoice workspace → same location/tier/GST selectors seed editable line items → live
  preview → generate. "Ready to invoice" strip surfaces cases with an engagement but no invoice yet.
- **Fee resolution (single source of truth):** UI mirrors `VisaType::FEE_FIELDS`; the server computes
  the fee from `{location, tier}` + GST at generation time. No fee is ever a stored/duplicated total.
- **Client portal access:** `LeadPortalInvitationController::request` (per-portal route) → admin
  approve → lead setup. Row menus read `portal_invitation_status` to show current state.

---

## 9. Guardrails (binding)

The immigration domain is the reason the AI layer is capped — see [`ai-agent/01-guardrails.md`](ai-agent/01-guardrails.md).
Non-negotiables that also shape human-facing code:

1. **No AI surface gives immigration advice.** Only the Licensed Immigration Adviser advises, under
   the Immigration Advisers Licensing Act 2007. Agent output on immigration matters is internal and
   drafted for the LIA to review/sign; client-facing surfaces state status/process only.
2. **Advice-bearing content is licence-gated** (`AdviceBearingPolicy` / `holdsCurrentLicence()`), not
   role-gated. The human adviser has full case write; the licence is the gate on advice. AI may draft
   an advice-bearing artifact but must **never be recorded as its approver** — enforced structurally
   (an AI actor holds no licence, so `approve` returns false). Replaces the old "adviser read-only"
   rule (Build 12 §2) — see §2 above and do not reinstate it.
3. **No generated numbers.** Fees/balances/dates come from a live tool call (here: `VisaType`
   fee methods) or "unknown".
4. **Audit everything**; writes idempotent + reversible; row-level scope before retrieval.

---

## 10. Gotchas

- **Two agreement systems coexist** (engagement pack vs managed agreements) — check which surface you
  are on before changing agreement code (§5).
- **Fees are GST-exclusive in the DB**; RRP/total are derived. Never store a total.
- **INZ fee is shared** across onshore/offshore — entered once, echoed elsewhere.
- **Custom row payloads** (Cases, Students) must explicitly include fields the shared row menus read
  (e.g. `portal_invitation_status`) — they don't come through `BuildsLeadRow` automatically.
- **Tailwind v4:** literal class strings only (no dynamic concatenation for JIT).
- Commits on this branch deploy; `.env` holds live secrets — never recreate/echo it.
