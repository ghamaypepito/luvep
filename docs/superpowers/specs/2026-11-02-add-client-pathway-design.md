# Add a pathway to an existing client

Ticket: TKT-T7PUED — Immigration to Education (Emma Ceballo).

Status: approved by the user on 2 November 2026; implementation in progress.

## Intent and agreed direction

Allow staff to add Education, Immigration, English, or Accommodation to an existing client without creating another account or entering their personal information again. The user selected extending the current shared client structure rather than introducing separate application records.

Emma's example: an existing Immigration client with an Approved Visa begins Education at Proposal Sent. Both departments use the same client, documents, login, and personal details. Immigration retains its outcome and history.

## Approaches

1. **Selected: extend the shared client record.** Use existing department membership flags, stage fields, and conversion metadata. Smaller change and compatible with existing lists and relationships. One current journey per department; repeat applications remain a separate future design.
2. **Separate application records under one client.** Supports multiple cases or enrolments in each department but requires broader changes to routing, relationships, and reporting. Not selected.

## Existing foundation and constraints

The inspected local `education-source` snapshot has a shared Lead model, Education/Immigration/English stage fields, membership flags for all four departments, and conversion endpoints. Its lead profile already has a Move to menu. The Education stage list includes New Lead and Proposal Sent. Converted clients leave the general Sales lead queue; adding Education must therefore make them reachable in Education without putting them back into the general lead queue.

Existing conversion endpoints do not provide a consistent stage-selection or authorization flow. Some reporting and list membership derive from stages as well as explicit flags. Immigration membership can derive from an Education handoff stage; English membership can derive from an English stage. Treat such existing membership as active to prevent accidental resets.

The workspace contains several source copies and build outputs. Before coding, establish the current canonical checkout and check its current routes, access rules, and conversion behavior. Adapt file locations to that checkout rather than treating a historical snapshot as production truth.

## Proposed behavior

- Present a clearly named **Add pathway** action on the existing client profile, available from the department views that expose this profile to authorized staff.
- Show Education, Immigration, English, and Accommodation, with active pathways clearly identified.
- For a new pathway, show its existing supported stages, an optional destination adviser where the department supports assignment, and an optional handoff note. Do not invent Accommodation stages or adviser fields if its existing model has none.
- Education defaults to New Lead for this action; staff can select Proposal Sent or another valid existing stage. Existing unrelated conversion entry points keep their existing defaults unless deliberately routed through this new flow.
- Immigration and English use their existing valid stages and normal initial stage. The builder determines the exact initial values from the canonical source and centralizes them with existing stage definitions.
- Adviser selection is optional. Leaving it empty preserves any existing destination assignment and otherwise uses the current unassigned/default handling. Validate any selection against the destination department's existing assignment rules.
- Adding a pathway preserves all source membership, stages, advisers, and conversion metadata. Only initialize a destination that is not already active.
- An active pathway offers **Open pathway** rather than another add operation. A repeated or concurrent add request is a safe no-op that does not change its stage, assignment, history, or notifications.
- Destination lists expose the client using the same underlying ID. Use the existing department detail route or list destination; do not require a new portal account.

## Shared information and boundaries

Personal details, portal identity, and attached information remain linked to the same record. Reuse documents through existing document access and checklist rules; do not physically duplicate uploads or mark them newly verified. Preserve existing expiry and verification behavior.

Do not duplicate, reset, or automatically mark complete any proposals, invoices, agreements, tasks, or checklists. Adding a pathway creates no new billable item. Existing records retain their current associations and access rules. Fully independent per-application billing, proposals, and repeated applications are outside this shared-record change; the UI must not promise that isolation.

## Access, history, and notifications

Enforce authorization on the server: the actor must have access to this client and permission for the requested handoff under the existing role policy. Preserve department access restrictions and reject unauthorized mutation even if submitted directly.

Record one successful addition in the existing activity/history mechanism, including actor, time, destination, starting stage, assignment if supplied, and handoff note if supplied. Use a transaction and a concurrency-safe membership check. Keep source history intact and use existing destination stage-history helpers where available.

Notify the destination team once using the existing internal notification behavior. Preserve existing configured business automation semantics, dispatching only after successful commit and only once. Do not send new portal credentials or introduce new client-facing messages as part of this feature. Local verification uses fakes rather than sending messages to real clients or staff.

## Already duplicated clients

This feature prevents the need for future duplicate accounts. Do not automatically merge existing records by email or name. Emma's existing duplicate requires identifying both records and reviewing conflicting details, documents, portal credentials, and financial associations before any reconciliation. A general-purpose merge tool and changes to real client data are outside this implementation.

## Verification and acceptance

1. An authorized staff member adds Education at Proposal Sent to an Approved Visa Immigration client. Client ID/login stay the same, Education lists the client at Proposal Sent, and Immigration outcome/history remain unchanged.
2. All four destinations use valid existing states and preserve previously supplied personal details and attached records.
3. Existing active and implicitly active pathways open without resetting progress. Duplicate/concurrent submissions create one transition and one set of notifications.
4. Authorized staff can reach the action from relevant department profile views; unauthorized users cannot view restricted data or add a pathway through direct requests.
5. Invalid stages and incompatible assignees fail without partial updates. A failed operation leaves both departments unchanged.
6. Existing conversions and reversions retain their established behavior. Lists and relevant department totals count the same client once within each department.
7. Run focused backend tests for the transition, authorization, preservation, and idempotency; build the frontend and inspect the action in a browser using a test record. Never mutate Emma's actual client for testing.

## Builder discretion

Use existing routes, shared components, notifications, audit tools, and styling where practical. Choose the smallest service/controller arrangement that keeps validation and transitions consistent. Resolve the canonical checkout before edits and document verification limitations. No new dependencies, broad schema redesign, deployment, or production data repair is required by this design.
