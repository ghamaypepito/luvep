# Accommodation Dashboard and Reports

Approved by the requester on26November2026: use the supplied green-and-white references, summary cards, occupancy by property, real upcoming viewings, weekly task reports and existing notes. Taste Vault returned the same three supplied screenshots; the second reference guides composition.

The Dashboard answers which properties need attention now. Show a large summary strip, a distinct-room occupancy chart, current coverage and a viewing calendar, followed by the existing property inventory and recent leads. Reports answers what the department completed during the selected week, retaining weekly notes and showing daily task counts alongside an explicitly current portfolio snapshot.

Use white surfaces on pale sage, forest green ink/accent, restrained lime selection and coral only for unavailable/attention data. Keep the existing font and portal shell. Use generous spacing,20px panels and aligned tabular figures; stack on mobile. Adapt the references to real Accommodation records rather than reproducing their promotional/financial numbers.

Reuse RoomInventory for all room/agreement/household counts and ReportWeek for the reporting period. Unknown capacity or incomplete assignments must stay unknown, never zero or assumed vacant. Viewing calendar uses actual Accommodation viewing bookings with the New Zealand date; tasks use department-scoped records and half-open period boundaries. Current occupancy is never presented as historical weekly occupancy. No invented revenue, trends, percentage changes, room assignments or client events.

Implementation uses the existing routes and native React/CSS charts. A cosmetic-only reskin would leave Reports without useful analytics; a new analytics database would add unnecessary data ownership. A small read model combining existing sources meets the brief. No new dependency, migration, permission grant, real client fixture or outgoing test email.

Proof: backend period/scope/unknown-data tests, actual React filtering/calendar/zero-state tests, responsive real-browser inspection, independent testing and separate review, exact-head CI, staging and live source/interaction checks. Leave the source-data reconciliation ticket In Review until the real records support its target totals.
