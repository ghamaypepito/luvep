# TKT-BDFBBQ — Assigned Immigration case helpers

This opt-in Agent capability is separate from existing referral-case rights. The restricted module key is `assigned_immigration_cases`, hidden by default. An active Agent needs both that module and an explicit case-specific `Lead.immigration_helper_id` assignment. The nullable User foreign key is separate from referral ownership, custody, generic lead assignment and the adviser label. No names are matched and no existing referrals are assigned automatically. The FK is not mass assignable and is hidden from generic Lead serialization.

## Access matrix

| Surface | Allowed action |
| --- | --- |
| Agent `/portal/agent/assigned-cases` | Paginated summaries of currently assigned Immigration cases only |
| Agent `/portal/agent/assigned-cases/{lead}` | Case reference/name, visa, stage, update date; permitted file/checklist/request DTOs |
| Agent `/{lead}/documents/{document}/download` | View/download only permitted private uploaded files belonging to that assigned case |
| Agent `/{lead}/uploads` | Append a Submitted file to one visible required checklist slot or active document request |
| Manager/Admin `/portal/immigration/cases/{lead}/helper` | Review eligible opted-in Agents, confirm assignment/replacement/removal; preserve owner/adviser |

Agent routes require exact active Agent role and the module. Assignment and module eligibility are rechecked from fresh records; upload authorization is rechecked under case/User locks before storing bytes. Staff grant changes also lock the case and current actor/recipient. `expected_helper_id` prevents stale confirmation from replacing another manager’s assignment; an already-applied identical assignment/removal is idempotent. Assignment and uploads are audited; case views/downloads use CaseAuditView. These operations send no client/team email.

## Shared files and upload targets

Only visible, non-financial primary-applicant uploads matched to a valid case checklist or same-case document request are shared. Unknown/unmatched files are excluded even when StaffShared. Hidden checklist aliases, dependent files, generated documents, invoice/payment/engagement/signature metadata or Finance associations, foreign/missing request associations and wrong MediaDrive lineage are denied. Media-backed files must be an existing case document reference to the same lead root/path; the capability provides no library discovery or tree/share access. DTOs omit file paths, document notes/reviewer metadata, internal notes, financials, assessments, unrelated people and global case options. Filenames are never used to infer document permission.

Uploads require one file and exactly one `checklist_key` or positive `request_id`. Only visible required primary-applicant slots are helper upload targets; optional evidence can be explicitly requested through a document request. Requests with approved evidence are completed targets and do not accept helper uploads. Only known evidence-request origins (legacy/manual `null`, explicit `manual`, and `rfi`) qualify; unknown, differently cased or namespaced origins fail closed on request display, file download and upload. Financial/legal workflow requests are excluded. Existing approved files remain unchanged; new files use private unique storage, standard 50 MB/content-MIME validation, Submitted status and the actual uploader. Forged dependent/lead/media/review/uploader fields are prohibited. Newly hidden/deleted targets or revoked assignments fail closed. No review, delete, note, signature, stage, finance or advice action is added.

The existing Agent referral case-profile picker is scoped to that Agent's own `agent_id` referrals. Helper-only cases do not enter that older selector; staff/Admin case options retain their existing scope.

## Verification and activation

Meaningful backend tests cover opt-in/role isolation, strict DTOs and document metadata privacy, IDOR, hidden aliases, requests, private media lineage, append-only uploads, existing approval preservation, old referral isolation, module/assignment revocation including the validation window, and stale grant confirmation. The real React test exercises module navigation, narrow links, upload states/retry, fresh case context, assignment/removal confirmation and manager-only entry. It is registered in the Node20 CI workflow with browser globals supplied by JSDOM.

The migration adds a nullable FK only; it grants no module, portal or assignment. No production access/assignment was changed during implementation. After independent testing/review and deployment, present the concrete capability for action-time security-grant confirmation before enabling any real account. Case assignments then require the normal explicit staff confirmation. Existing referral rights remain unchanged when this module or an assignment is revoked.
