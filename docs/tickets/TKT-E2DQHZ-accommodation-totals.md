# TKT-E2DQHZ — Accommodation property, room and household totals

## Findings

Reviewed current main and the revised register/SOP on 24 November 2026. The dashboard counts all Property rows and public-listing availability, not active physical capacity and vacancy. The existing private Property occupancy accessor counts active Tenant records, so one household is incorrectly treated as one room. Public listing status is also a different concept from a vacant room.

The latest source controls are 11 properties, 43 rooms, 40 occupied and 3 vacant. The workbook still has 36 household rows; the requester confirms one November 20 departure, giving 35 current household entries. All 43 room labels are blank. The Properties sheet's Roster entries column repeats room capacity and must not be used as a household total. Household labels are not a verified count of individual people.

## Scoped change

- Count active managed Property records, recorded physical capacity and current household tenancies separately. Current uses existing active/notice_given/vacating statuses, excludes archived properties/soft-deleted tenants, and excludes future contract starts. An elapsed fixed-term end date does not automatically prove departure.
- Do not infer physical occupancy from household count, free-text units, blank labels, public listing status or a guessed whole-property allocation. A dated, staff-confirmed per-property check records occupied/vacant/unavailable rooms and its source. Counts must reconcile to the property's capacity.
- Record a hash of relevant tenancy membership/status/unit/start, capacity, active state and rental mode. Relevant changes invalidate the previous check; unknowns are visible and are not silently counted as vacant or zero. Complete portfolio occupancy figures appear only when coverage is complete. Current verified subtotals remain explained separately.
- Available-to-let rooms exclude bedrooms in whole-property lets. The vacancy/availability cards open a filtered property breakdown with links to existing property/tenant management. Physical room labels and individual allocation remain pending data; this is not a new room-level allocation engine.
- Property index/detail use the same new summary; the old Property accessor and public serialization are unchanged for other consumers. Public listing/EOI workflows remain separate.
- Active ordinary Accommodation/Admin sessions may confirm counts; View-as and unrelated roles cannot. Stale forms and inconsistent values are rejected. Counts and actor-attributed before/after audit commit in one transaction. The endpoint changes no tenant, identity, rent, invoice, payment, booking, agreement, property capacity or role.

This is an explicit property-level verification workflow while room labels/allocations are pending. It does not automatically import the register, mark a person departed, assign rooms or claim the portal currently matches the target totals.

## Deployment and verification

One additive migration creates `property_room_verifications`. Existing pages still render if it is not installed; the new confirmation endpoint reports installation-unavailable. Run migration before using confirmations. Application rollback retains verification data. No source Office file was edited, and private credentials/source household names are not committed.

Focused backend and actual React tests cover the 11/43/35/40/3 control, families sharing or occupying multiple rooms, whole-property exclusions, missing capacity, invalid/stale snapshots, lifecycle/date boundaries, permissions, audit rollback, missing-schema rollout, unknown-vs-zero presentation and vacancy navigation. Adjacent Property, Tenant, Agreement, Purchase Invoice and public Accommodation tests are included. Full exact-head CI and review are required before deployment.

Reconcile the 11 canonical live Property IDs/capacities and reviewed household records before claiming live accuracy. Use the usual vacate workflow on the exact confirmed departed tenancy; retain history. After verified records are entered, confirm 43 = 40 + 3 and 35 household records with the requester. Keep In review until deployment and live acceptance.

## Related TKT-HOKBXI

Existing tenant/property CRUD, unit labels, document access, viewing/calendar, template management and version-2 agreement-to-pending-tenant linkage are reused. This branch does not complete the full SOP, historical agreement backfill, room-level assignment/availability, navigation renames, Friday payment authority/checklist, approved Exalt template release or package setup.

Requester-approved direction: household counts first, staff room labels later, Exalt email drafts for approval, reference package items without guessed quantities/prices, and two named payment verifiers. Exact approved account identities still need resolution before financial grants; no permissions are changed here. Private source reconciliation, email drafts and item references are supplied outside Git for review.
