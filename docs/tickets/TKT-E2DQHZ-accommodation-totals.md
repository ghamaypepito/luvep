# TKT-E2DQHZ — Accommodation totals and room assignments

The old dashboard counted listing rows and their publication status. It had no record of which physical rooms a household actually occupied. The ticket specifies 11 properties, 43 rooms, 40 occupied rooms, and three vacancies. The revised register lists 35 current households after the confirmed departure. The requester confirmed that agreements should have a separate total, with a reference count of 48.

## How the dashboard calculates the totals

- Active managed properties contribute their recorded capacity. The new private room register in **Setup → Properties** gives each physical room a stable ID, a label and an optional hold when empty. Public listing details stay separate.
- Staff select rooms in a tenancy record, at move-in or when moving a tenant to another property. An active tenancy can occupy multiple rooms. The dashboard counts the distinct assigned rooms across all current tenancies. Vacating the last household that uses a room releases it. Renewing a current tenancy before the next term keeps its rooms occupied. For whole-property rentals, empty rooms are not offered individually.
- A property needs one record per room and assignments for every current household to calculate vacancies. Once room tracking has started, incomplete assignments display as needing review. A property with no room register can use a valid dated property-level room check. Relevant changes invalidate the check.
- Household totals use current tenancies at active properties, including notice and vacating statuses. Future starts, pending payment, archived records and departed residents are excluded. The contract end date alone does not prove departure.
- The separate agreements total counts each signed Luvep agreement linked to an in-residence tenancy at the same active property. Recorded agreement start and end dates are evaluated using the New Zealand date. Unlinked legacy agreements are excluded until their records are reviewed and linked. Signing parties do not affect the count.

## Verification and rollout

Two additive migrations provide the manual room checks and the room register/assignments. Apply them before serving the new code. Application rollback can retain the new columns and table. Existing records are not backfilled by the migration.

Synthetic tests cover 11 properties, 43 rooms, 40 occupied, 3 vacant, 35 current households and 48 active linked agreements. They also cover shared and multi-room households, stale updates, renames, holds, moves, vacating and incomplete setup. The frontend tests exercise the separate dashboard totals and the room picker on move-in.

Reconcile the 11 canonical managed properties and their 43 room assignments before confirming the live figures. The workbook has blank room labels, and the ticket attachment shows the old dashboard, so neither is a room-by-room assignment source. Verify the two reported single shared-bath rooms at 97 Settlers Avenue and the ensuite room at 31 Vazey Way as part of setup. Review and link any historical signed agreements before comparing the live agreement figure to 48. Leave the ticket in review until live acceptance.

## Related TKT-HOKBXI

Existing tenant/property CRUD, unit labels, document access, viewing/calendar, template management and version-2 agreement-to-pending-tenant linkage are reused. This branch does not complete the full SOP, historical agreement backfill, Friday payment authority/checklist, approved Exalt template release or package setup.

Requester-approved direction: household counts first, staff room labels later, Exalt email drafts for approval, reference package items without guessed quantities/prices, and two named payment verifiers. Exact approved account identities still need resolution before financial grants; no permissions are changed here. Private source reconciliation, email drafts and item references are supplied outside Git for review.
