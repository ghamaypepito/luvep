# Luvep employee HR portal

Date: 19 October 2026
Status: Design approved by the user; Manager/Admin notification channels confirmed. Admin employee-preview addition incorporated. Product implementation and deployment have not started.
Repository: ghamaypepito/luvep
Source examined: 2bc05a073932d3c32f9e59d4f114c283c1e8f73e (matches the production deployment marker).

## Outcome

Provide a dedicated employee portal at https://hr.luvep.com with an HR dashboard, employee profiles, leave/absence filing and status tracking, and daily/weekly time records. Reuse the existing Luvep account system, database records, and staff interface design. Existing HR-related records remain available through the main application as well.

The user requested the subdomain, all existing HR details, employee-only profiles, a leave request form, and DTR records. The user subsequently requested seamless single sign-on from luvep.com, clock-in/out and break-out/in controls, a work timer that pauses during breaks, a break pop-up requiring an explicit return to work, and a subtle dashboard reminder 15 minutes before the scheduled clock-out time. The user also requested a professional corporate HR portal that retains Luvep branding, enhancement of existing employee interfaces, the necessary HR functions, and restricted access to confidential files and other employees’ profiles. Detailed permission customization will follow, but private defaults must be present before any real data is exposed. The shared-database architecture and access controls below implement the approved direction. The user additionally confirmed Manager/Admin in-app and email notifications and requested an Admin avatar menu for viewing an employee dashboard.

## Existing capabilities verified in source

| Area | Existing behavior | Change needed |
| --- | --- | --- |
| Employee account | User name, email, avatar, phone, location; staff profile card in departmental portals | Dedicated private HR profile combining account and employment information |
| Staff setup | DtrSetting holds position, team, timezone, schedule, employment type, free-text reports_to, archive status | Explicit employee enrollment, linked supervisor and Operations reviewer, separate department from office/team |
| DTR | Own clock-in/out, tasks, carry-forward work, daily reports, weekly PDFs, management screens and summaries | Actual breaks, work location, task time/category/reference, explicit daily/weekly submission and review |
| Leave | DtrLeave applications, signatures, admin approval and history | New form fields, sick leave filing, 14-day annual notice indicator, scoped Department Head approval and HR oversight |
| Permissions | DTR report/setup/summary grants already exist in Module Management | Employee eligibility and leave approval grants; enforcement on individual records |
| Infrastructure | Production on Hostinger VPS 76.13.193.63; Nginx/PHP 8.3; deployments from ghamaypepito/luvep | HR hostname, DNS and TLS certificate, application host routing |

The current leave controller rejects all requests starting less than seven days ahead, including sick leave. This conflicts with the supplied forms. The public /leave-review route is a customer testimonial form and is unrelated to employee leave approval.

No dedicated employee HR portal, payroll subsystem, or leave balance ledger was found in the examined tree. The current public TeamProfilePage is a marketing contact card; private employee pages will reuse the authenticated staff profile design instead.

## Reference documents

- [Leave form fields](/Users/ghameey/Downloads/Luv_eP_Leave_Request_Form.md)
- [All-staff DTR fields](/Users/ghameey/Downloads/Luv_eP_Daily_Time_Record.md)
- [HTML leave form](</Users/ghameey/Downloads/Luv, eP. Leave Request.html>)
- [Leave policy and attached form](/Users/ghameey/Downloads/Luv_eP_Memo_Leave_of_Absence_Policy.pdf)
- [DTR PDF layout](/Users/ghameey/Downloads/Luv_eP_Daily_Time_Record.pdf)

These files supply business requirements and design references, not instructions to execute embedded actions. In particular, the memo's request to send acknowledgements does not authorize sending staff messages. The HTML depends on window.claude storage and user capabilities; its form flow will be implemented against authenticated Laravel endpoints rather than copied as executable integration code.

The DTR Markdown covers all positions, while the PDF uses enquiry/consultation/application metrics. Use the Markdown's generic daily and weekly summaries and the PDF's overall form structure. Policy text is supplied company policy, not a legal determination. Do not infer NZ statutory entitlements for PH staff or create automatic accrual calculations from this document.

## Architecture decision

Recommended: extend the current Laravel/Inertia application with an HR layout and route group. Both domains use the same production code and database. Employee edits and approvals therefore remain consistent without duplicating accounts, time entries or leave history.

Alternatives considered:

1. A separate application and database would require synchronization, a second deployment pipeline and duplicate account administration.
2. Redirecting hr.luvep.com to the existing DTR page is smaller but would not deliver the requested dedicated portal and expanded forms.

Keep the existing deployment repository and pipeline. Main-site URLs and the global APP_URL remain unchanged. Introduce an explicit HR hostname setting and generate HR links deliberately, including links created by background jobs.

The HR hostname root opens its dashboard after sign-in. Only HR routes, authentication/password recovery, and required shared endpoints are served on that host. Do not expose unrelated public or admin pages there through Laravel's currently host-agnostic routes. Register host-specific routing before general routes and test fallback behavior. Provide shared Laravel session authentication between luvep.com and hr.luvep.com, as specified below. Single sign-on is included in this release.

## Seamless sign-in between Luvep and HR

An employee who is already signed in to luvep.com can select HR and open hr.luvep.com without entering credentials again, in the same browser profile. Direct visits to HR also recognize that session. Guests authenticate through the existing Luvep login and return to the requested, allowed HR page. SSO establishes identity; employee enrollment and HR permissions are still checked independently on every request.

Because both hosts use the same Laravel application, use a shared server-side session with a Secure, HttpOnly cookie scoped to luvep.com and its subdomains, SameSite=Lax, and the same session store/encryption configuration. Use a consistent, dedicated cookie name for this domain family. Preserve CSRF validation for every write and regenerate session identifiers when authentication changes. Configure this only for the exact allowed production hosts; do not globally change session settings for epathways.co.nz or staging. Audit sibling-host ownership before enabling a parent-domain cookie, since this design trusts subdomains under luvep.com.

Migrate existing authenticated main-site sessions into the shared session on their next validated request, with explicit removal of obsolete host-only cookies. Test old/new cookie coexistence and refreshes so users do not get login loops. A fresh login remains necessary if their original session has expired. Do not expose credentials or session IDs in URLs or browser storage.

Logout from either portal invalidates the shared session and remember-me authentication for this browser. Other open tabs must recognize invalidation on their next request. Account deactivation, employee archiving and password reset must revoke the relevant access immediately on the next server request; back-button rendering never permits a write after logout.

Authentication primitives follow [Laravel 12 authentication guidance](https://laravel.com/docs/12.x/authentication), including session regeneration on authentication and session invalidation/CSRF regeneration at logout. Shared-domain routing and migration are project-specific design choices.

## Screens and access

### Employee

Navigation: Overview, My Profile, My Leave, Time & Attendance, My Documents, Policies & Support. Each area shows the signed-in employee’s own permitted records.

Dashboard: today's time record, live working-time indicator and clock/break controls, week-to-date hours, recent leave requests and decisions, and items awaiting the employee's action. Show an explicit empty/setup state when employment or scheduling details are missing.

Profile: reuse the existing avatar and staff card design with name, email, phone, position, department, office, employment type, supervisor, timezone and schedule. Employees may update their phone, avatar and emergency contact details. Employment status, reviewer assignments, department, schedule and permissions are managed by authorized HR staff. Avoid displaying unfinished security features as working controls.

Employees may read and update only their own permitted records. There is no public employee registration. Client, revoked-client, external agent and sub-agent accounts do not gain HR access merely because they can sign in to Luvep.

### Department Head

See and decide leave requests only for assigned direct reports. View only the employee identity and request information needed for that decision. No self-approval. Report access is granted separately; being a leave approver does not automatically expose all time records.

### HR / Management and Operations

HR dashboard: employee roster/setup, leave queue, decisions, existing DTR reports and summary, and reviewer assignments. Reuse existing DTR feature grants and introduce explicit leave review and emergency approval permissions.

Operations reviewers can review assigned daily/weekly DTRs and return them for correction. Existing administrators retain their current DTR management permissions. HR grants do not give ordinary employees general application administrator access.

Eligibility uses an explicit employment record linked to User. Initially populate candidates from completed, non-archived DTR setups belonging to internal staff roles; HR must confirm the roster before public launch. No automatic conversion of external accounts. Inactive or archived employees lose HR access on their next request while history is retained. HR administrators can configure the roster before employees are enrolled.

## Admin avatar menu and employee preview

Add **View profile** and **Login as employee** to the authorized Admin avatar dropdown on the Luvep and HR dashboards. View profile opens the Admin's own profile. Login as employee opens a searchable picker of eligible employees within the Admin's preview permission. Ordinary employees get only their own profile option.

The selected dashboard is a read-only employee preview with a persistent **Viewing [employee] · Read-only** banner and **Return to admin** control. Keep the original authenticated Admin as actor; store the selected employee as a separate preview context. Do not request or exchange employee credentials. Audit preview start/exit and actor/subject IDs.

Reads must satisfy both the Admin's preview scope and the employee's own visibility. Preview must not reveal other profiles, private HR notes, unreleased documents or admin-only menus. Block preview-context mutations on the server, including legacy routes on the main domain: clock/break actions, leave/DTR submission, profile updates, message replies, policy acknowledgement, notification read/dismiss and export creation. Exit and logout remain available. Read endpoints cannot silently claim reminders or generate employee notifications. Revalidate actor permission and active employee enrollment on every request; terminate preview after revocation or archiving. Apply private/no-store responses. Tabs display preview context consistently rather than silently changing identity.

## Manager and Admin notifications

The user confirmed **in-app notifications and email**. Reuse the existing Luvep bell/inbox and queue delivery after the business transaction commits. Notify the assigned Department Head for leave filing/decisions, the assigned Manager/Operations reviewer for DTR submission/review, and active Luvep Admins for actionable HR events. For private HR requests, only explicitly permitted assigned reviewers receive case access; other Admins receive generic triage notices. Assignment/overdue operational tasks alert their authorized owners and Managers/Admins. Employees receive their own confirmations and results in-app.

Deduplicate users who fill several roles, exclude inactive recipients and the acting user, and use stable event/delivery IDs for application-level retries. Missing Manager assignments fall back to Admin triage. Missing/invalid email or mail failure must not prevent the in-app notification or roll back the employee's request. Log attempts/failures for authorized administrators and retry queued delivery. External SMTP cannot guarantee exactly-once delivery after an ambiguous timeout.

Include only a generic event summary, reference and authorization-checked link. Never email medical details, leave reasons, private case text, compensation, emergency contacts or attachments. Routine clock/break changes remain attendance records; the 15-minute reminder stays in-app. Development/staging use synthetic accounts and test/log mail only. Real production notifications begin on genuine authorized events after release verification. See the master implementation plan for the per-event recipient contract.

## Professional Luvep interface

Preserve the supplied Luvep logo and the existing brand's green/charcoal identity. Extend the authenticated staff profile design into a cohesive employee portal, with readable typography, restrained color, consistent controls, carefully spaced tables and clear state labels. Use brand green for primary actions and active navigation; use warning/error colors only when status requires them.

Two distinct experiences share components and data:

- Employee home: personal action list, prominent clock/break controls, current shift, own leave requests and employment details. Personal documents, policies, HR support and DTR are one clear navigation step away. Do not display an employee directory, other profiles, private workforce statistics or administrative menus to ordinary employees.
- HR workspace: an operational priority queue, employee records, leave decisions, attendance review, document management, onboarding/offboarding, policy acknowledgements, private HR requests, reports, and access/audit administration where permitted. Counts lead to the matching records, with practical filters and clear owners rather than decorative charts.

Every implemented area needs useful loading, empty, validation, saving, failure, no-access and success states. The responsive layout must remain usable on phones, with labelled controls, keyboard access, visible focus, readable contrast and no hover-only information. Do not ship placeholder modules that imply unavailable functionality.

An interactive design preview uses fictional employees and sample counts, explicitly labelled as illustrative. Its employee/HR selector is a preview control, not a permission-switching feature for production. The preview is not connected to live accounts or data. Visual approval does not prove that backend access controls exist.

## Core HR functions and delivery boundaries

The expanded scope is several workstreams. Preserve the existing HR foundation and implement these as independently testable increments; each increment gets a focused implementation plan and staging verification before it exposes real records.

| Area | Employee experience | Authorized HR / reviewer experience |
| --- | --- | --- |
| Employee records | Own contact details, employment summary and emergency contact | Maintain employee ID, status, department, position, office, employment dates, schedule and reviewer assignments; preserve change history |
| Time and attendance | Own live clock/break state, DTR and sign-offs | Review assigned entries, exceptions and corrections; manage schedules and export approved hours |
| Leave | Own requests, decisions, upcoming leave and history | Assigned approval queue, emergency review, absence calendar and recorded duration |
| Documents | Own documents explicitly released by HR | Upload/version/classify contracts and personnel documents; control employee release, access and archive state |
| Policies | Read published policies and acknowledge versions | Publish/version policies and inspect acknowledgement status |
| Onboarding and exits | Own assigned checklist tasks and completion status | Reusable checklists, task owners, due dates, equipment issue/return and access-revocation tasks |
| Private HR requests | Submit and follow own requests, such as an employment letter or contact correction | Categorize, assign, respond and close cases within authorized HR scope |
| Milestones | Own shared employment milestones | Track probation, contract dates and onboarding/exit deadlines; private notes remain private |
| Reports | Own time and leave reports | Permission-scoped employee-status, attendance, leave and acknowledgement reports; approved-hours export for payroll handoff |
| Access and audit | No administrative access | Configure granular permissions and inspect scoped change/download/export history |

Release sequence: (1) SSO and the employee/HR shell, private employee profiles, DTR/clock/break/reminder and leave workflows; (2) personnel documents, policies and private HR requests; (3) onboarding/offboarding, equipment checklist, milestones, management reports and permission administration. Access checks and audit events accompany every increment, not only the final permission-management screen.

Pay calculations, statutory accrual/entitlement engines, recruitment pipelines, benefits administration and automated payroll integrations require separate business requirements. Do not infer salary, entitlements or leave balances from sample data. The initial payroll support is approved attendance exports, with no automatic pay calculation.

## Confidentiality defaults before configuration

The user's plan to set detailed rules later means defaults must be restrictive now. Deny access unless explicitly allowed by role, employee ownership, assigned reviewer scope and document classification. Enforce the same rules on both domains, every backend action and downloads; menu visibility is only a convenience.

- Employees: their own profile's approved fields, attendance, leave and HR requests; their own explicitly released documents; published company policies. Ownership alone does not grant access to a private HR note, unreleased review, medical document or compensation file about that employee.
- Department Heads: assigned reports' leave decisions and minimal necessary profile information. DTR access requires a separate grant. No general access to medical files, compensation, disciplinary records, private HR support cases or all employee profiles.
- Operations: assigned attendance reviews and operational checklist/equipment tasks. This does not grant full HR files or compensation access.
- HR: only explicitly granted personnel management capabilities. Confidential document classes require their own permission. General application/DTR administration does not automatically grant access to every confidential document.
- Access administrators: designated personnel can manage grants and inspect audit events. Access assignment/revocation must be auditable and cannot be self-elevated by editing a profile or client request payload.

Classification: employee-shared, HR-private, restricted medical, restricted compensation, restricted case/disciplinary. New uploads default to HR-private; only an authorized action can release an appropriate document to its employee. Sensitive classes remain restricted even if someone guesses an ID or an old URL. Do not grant a whole department access to individual personnel files.

Store personnel files on private storage with authenticated, policy-checked streaming. Validate upload type/size; do not put private files in public/storage or expose storage paths in page props. Check access again for every version, preview, download and export. For asynchronous exports, recheck access at processing and download, expire results, and prevent revocation from leaving accessible artifacts.

Employee lists, search suggestions, dashboard counts, notifications, activity descriptions and error messages must not leak unauthorized names or documents. Serialize allowed fields explicitly. Use private/no-store cache behavior for sensitive responses and keep private HR content out of analytics and client logs. Separate operational activity from confidential notes.

Audit profile changes, permission changes, document classification/release/download, request decisions, DTR corrections and exports. Record actors and timestamps, without copying file content or confidential notes into a broadly visible log. No destructive account deletion or bulk permission assignment is implicit in an offboarding checklist; execution requires the correct permission and a clear action.

Acceptance tests must exercise own vs. another employee, direct links, altered IDs, revoked grants, archived employees, shared vs. private document versions, list/search/notification leakage, exported artifacts and both hostnames. These tests must pass before confidential records are loaded into the portal.

## Leave workflow

Confirmed approval flow: Department Head decides; HR oversees. No second HR approval step is required. Proposed exception handling: authorized HR may act for an unassigned/unavailable head, and Management handles short-notice emergency exceptions. Every decision records actor, timestamp and comment. An employee cannot approve their own application, including when they hold an admin role.

Form fields:

- Employee identity, mobile, department, position, and assigned supervisor, prefilled from the profile.
- Sick, annual, or other leave, with other type description. Preserve and display historical leave types.
- First/last date, full day or one half-day morning/afternoon, and computed working-day duration.
- Sick leave: self/partner/dependant; supervisor notification method and time; certificate status. Medical details are optional.
- Annual leave: team coordination, travel and handover/out-of-office checklist.
- Cover person, handover details, reason/comments, and a declaration with typed staff name and server timestamp. Retain old drawn signatures for historical applications.

Rules:

- Permit same-day and retrospective sick leave filing, including first-day-back submission. Show notice guidance and capture late-notice context; do not enforce the existing seven-day rejection.
- Annual leave normally requires 14 calendar days' notice. Accept a short-notice request with an explanation, visibly flag it and require management emergency approval before approval. Do not silently reject or auto-approve it.
- Calculate duration on the server using the employee's configured working days, timezone and applicable holiday calendar. Saturdays count when scheduled. Half-day requests cover a single working date. A missing schedule requires HR setup rather than a guessed duration.
- Store the calculated days/hours and relevant schedule snapshot with the request so later schedule edits cannot silently change past approvals.
- Flag the supplied policy's three-working-day response target in the review queue. Send approved event notifications in production; development and staging use a test mail sink.
- Show request status, decision and comments in My Leave. Allow cancellation of pending requests. Submitted requests are immutable; corrections use cancel/refile. Approved leave changes require an auditable HR action.
- Prevent conflicting duplicate submissions and overlapping active requests. Validate dates, leave types and declaration on the server. Restrict every detail/download endpoint by owner or authorized reviewer.
- Preserve legacy approved-in-part/deferred decisions for display; new requests use pending, approved, declined and cancelled with a separate emergency-approval flag. Do not rewrite historical records.

The first increment records certificate status. The subsequent personnel-document increment can support medical certificates under a separate restricted-medical permission and private storage; existing client document storage will not be repurposed for employee medical files.

## DTR workflow

Reuse DtrEntry and existing clock actions and carry-forward tasks. Add structured fields for:

- Work date, time in/out, break out/in or total break minutes, calculated net hours, work location.
- Tasks with start/end time, description, client/project reference as text, category, status and calculated hours.
- Completed/in-progress/carried-over counts, meetings and people assisted, blockers and tomorrow's priorities.
- Employee declaration/submission timestamp and Operations reviewer identity/date/comments.

Daily flow: draft/autosave -> submit -> reviewed, or returned for correction. Explicit submission must be distinguishable from today's existing heuristic that any task or clock-out means submitted. Legacy rows retain their original interpretation and are labelled historical rather than receiving fabricated sign-offs.

New submitted records are locked to employees. Authorized corrections require a reason and retain an audit event. Permit returned records to be corrected after their work date. Current legacy past-day locking remains for records that have not been returned or reopened by an authorized reviewer.

The weekly view defaults to Monday-Saturday from the supplied form, but includes Sunday when the employee's configured schedule requires it. It displays daily hours, weekly totals, generic activity counts, notes, employee sign-off and Operations review. Weekly sign-off references the reviewed daily record revisions; later corrections invalidate the sign-off until resubmitted.

Use date-aware time calculations for overnight shifts and NZ/PH timezones. Actual break entries must not also receive the old automatic break deduction. For legacy entries without actual break fields, preserve the existing configured-break calculation. Task intervals must fit the shift, cannot overlap each other or recorded breaks, and cannot create negative time. Make task-hour totals distinct from attendance hours.

Reuse and extend the daily and weekly printable reports. Employees can download only their own report; managers need the relevant report grant and row scope. Do not expose client records simply because a DTR contains a reference string.

## Clock, breaks and dashboard reminders

### Employee controls

Add a compact shared timekeeping control to the authenticated employee dashboard layout on luvep.com and the HR portal. It appears only for enrolled, active employees with completed scheduling setup. The same component and server endpoints power both locations.

| Current state | Primary control | Break control | Counted work time |
| --- | --- | --- | --- |
| Clocked out | Clock in | Disabled | Stopped |
| Working | Clock out | Start break | Increasing |
| On break | Clock out | Back to work | Paused |
| Shift closed | Show completed shift | Disabled | Final total |

Controls are visibly labelled buttons that change with state, with text/icons and accessible keyboard behavior. Do not rely on an ambiguous unlabelled toggle. Clocking out confirms that the shift is ending; clocking out while on break closes the break and the shift together, without counting break time as work. One active shift and at most one open break per employee. Multiple break intervals within a shift are supported. An accidentally closed shift is reopened/corrected by an authorized reviewer with a recorded reason rather than overwritten by another click.

### Timer and break pop-up

Clock in records a server timestamp and starts counted work time. Start break records a server timestamp, pauses counted work time immediately on successful save, and opens an accessible dialog saying “You're on a break”, with elapsed break time and a clear “Back to work” button. Back to work closes the break interval and resumes counting only after server acknowledgement.

Show the dialog on the main Luvep dashboard and HR dashboard while the stored status is on break. Restoring a tab, refreshing, navigating between portals, or signing back in must restore this status. Escape or backdrop dismissal may collapse the dialog to a persistent on-break banner for accessible navigation, but never resumes time. Reopening the dashboard presents the reminder again. Clock out remains available during a break. Do not block access to logout or help.

Store timekeeping events and intervals on the server in UTC, displaying them in the employee's configured timezone. Counted work time equals elapsed shift time minus the union of recorded break intervals; an open break stops the total growing at its start. Record actual instants for daylight-saving and overnight accuracy. Closing the browser or losing a session does not itself start/stop a break or clock out. If someone forgets to finish a shift or break, retain the record and flag it for reviewed correction; do not manufacture an end time.

The browser timer is a display derived from server state and time, not the attendance ledger. Synchronize when a page gains focus, after mutations, and by lightweight polling while visible. A successful transition updates the initiating tab immediately; other tabs/devices converge at their next refresh (target within 15 seconds while visible). Disable controls while a request is pending. Use an idempotency key and a transactional per-employee lock/version check to prevent duplicate or contradictory events from double-clicks, retries and different devices. On a stale-state conflict, refresh state and explain that it changed elsewhere. Offline controls show that the action could not be saved and do not claim a successful clock or break change.

Use live-event mode for new clocked shifts and preserve legacy manual entries. Do not let the old autosave form or legacy clock endpoints overwrite live timestamps, reopen a finished shift, erase breaks or bypass review locks. Live clock-out and DTR declaration/submission are separate actions: show the employee the final hours and any unfinished task/sign-off fields. The daily/weekly views and exported reports use the same server-calculated totals. No automatic configured-break deduction is applied on top of recorded breaks. This tracks requested worked time; it does not determine payroll treatment of breaks.

### Fifteen-minute reminder

Use the employee's schedule for that shift's start date, including their timezone and overnight end date. Snapshot the expected finish when the shift starts. At expected finish minus 15 minutes, show a quiet dismissible toast: “Your shift ends in 15 minutes. Please wrap up and complete your DTR.” The reminder is also visible within the break dialog if the employee is on break.

Trigger while an eligible shift is open, whether working or on break, and only before the expected end. If a suspended tab returns during the final 15 minutes, show the actual remaining minutes rather than a stale “15 minutes” message. Do not show a pre-finish reminder after the shift ends. Do not clock out automatically or extend the scheduled finish because a break was taken. A flexi schedule without an expected finish displays no timed reminder rather than inventing a clock-out time.

Persist reminder delivery/dismissal per employee and shift; atomically claim delivery from a visible tab to prevent repeated toast pop-ups across portals, tabs and reloads. Show a small remaining-time indicator after the toast is dismissed. These are in-app notifications while the dashboard is open; they do not require browser push permission, email or SMS, and cannot be guaranteed while all tabs are closed or suspended.

## Data and implementation boundaries

Use additive migrations and separate HR-focused controllers, form requests, policies and calculation services. Avoid growing the existing 57 KB DtrController further; extract only the shared DTR/leave logic needed by both portals.

Proposed additions:

- Employee employment/profile record keyed uniquely to user_id, with linked supervisor and Operations reviewer.
- Additional leave form/snapshot fields and append-only leave decision/cancellation events.
- Additional DTR location/submission fields, structured task metadata, review events and weekly sign-off records.
- Server-timestamped shift/break events and intervals, expected-finish snapshot, state version/idempotency records and per-shift reminder delivery/dismissal state.

Keep existing users, dtr_settings, dtr_entries and dtr_leaves identifiers. Preserve current schedules, signatures and task data. Backfill only values that can be established from existing records; do not infer reviewer identities from free-text reports_to or invent submission timestamps. Keep original reports_to text while HR maps actual accounts.

Shared services must be used by legacy endpoints as well as HR endpoints so direct requests to the main domain cannot bypass new leave rules or edit locks. Apply HR-host eligibility before allowing requests to shared endpoints. Existing agent DTR access on the main site requires explicit compatibility testing and does not imply HR enrollment.

## Verification and rollout

1. Create an isolated Luvep feature checkout/branch. The separate epathways-pxlcode Workers project is unrelated and must not receive these changes.
2. Establish relevant test baselines; implement authorization, calculations and state-transition tests alongside the feature. Run the repository's full PHP tests and compare known baseline failures. Build frontend assets.
3. Verify guest, employee, wrong employee, Department Head, HR, Operations, external agent/client, inactive and archived cases, including direct API/download access, self-approval attempts and concurrent decisions.
4. Test same-day/retrospective sick leave, annual notice, emergencies, holidays, half-days, missing schedules, NZ/PH timezone boundaries, overnight shifts, breaks, overlapping requests, repeat submissions and review locking.
5. Test historical data compatibility, printable daily/weekly reports, mobile layouts and profile avatar behavior. Verify SSO main-to-HR and direct-to-HR, guest return URLs, expired sessions, remember-me, migration of existing cookies, CSRF, cross-portal logout, revoked access and isolation from staging/epathways.co.nz.
   Exercise repeated clock/break clicks, multiple breaks, refresh/reopen while on break, cross-tab/device conflicts, network failure and server-authoritative time. Verify that legacy autosave cannot overwrite live state. Test 15-minute reminders before/at/after threshold, late tab wake-up, dismissal, duplicate tabs, breaks, overnight shifts, DST and flexi schedules without an expected finish.
6. Deploy and exercise the feature on staging with synthetic records and fake/disabled outbound delivery. Compare staging/main ancestry before promotion; do not merge unrelated staging changes into production.
7. Before production, take current database/config backups and validate additive migrations. Confirm the employee roster and reviewer assignments in the management UI.
8. Add Hostinger DNS A record hr -> 76.13.193.63. DNS authority is Hostinger; an authenticated DNS editing session still needs verification. Root VPS access alone does not establish DNS-panel access.
9. Add a dedicated Nginx HR virtual host pointing to /var/www/epathways-production/public, validate nginx configuration, issue the HR TLS certificate and test renewal configuration. Keep existing site server blocks intact.
10. Deploy from ghamaypepito/luvep and verify HTTPS, redirect behavior, access controls, profile, leave submission/decision and DTR submission/review using dedicated test records. Check the main website and existing portal routes too.
11. Rollback by disabling HR host routing and reverting application changes if needed; retain additive tables and records. Do not restore an old database over new employee submissions or run destructive down migrations.

## Scope boundaries and review decision

This design covers the expanded core HR functions above, delivered in increments, including the requested employee profiles, leave process and DTR additions. It does not add payroll, statutory leave accrual or public staff registration. Approved HR event notifications use in-app and email delivery to assigned Managers and active Luvep Admins. SSO between luvep.com and hr.luvep.com, live clock/break controls and in-app shift reminders are included. Leave balances will not display invented numbers when no verified balance ledger exists.

The user confirmed that the Department Head makes the leave decision with HR oversight; no second HR approval is required. The requested SSO, live clock/break/reminder behavior, professional Luvep interface, core HR functions and private-by-default permissions are incorporated above. The user approved the overall written design and confirmed both notification channels. The implementation plans cover feature development, verification and staged rollout. Admin employee preview is interpreted as read-only because the stated purpose is to see the employee dashboard; actions on behalf of employees are outside this addition.
