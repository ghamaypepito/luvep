# TKT-GHYL1H — Intake stage priority and monthly counts

## Findings

Reviewed current main and open PRs on 19 November 2026. Intake Monitoring currently groups each month's cards by broad categories (Needs attention, Immigration, Offers, English, Lead stages, Started) and displays only the monthly student total. No requested stage-priority order or combined four-stage ratio was found. The existing endpoint maps one card per registered student using the first study plan; multiple study plans do not create multiple cards.

## Resolution

Education Intake Monitoring presentation only: group each month in this order: Started Course, Approved Visa, Visa Lodged, Endorsed to Immigration, Unconditional Offer, Conditional Offer, Declined Visa. Keep all other stages in an Other stages group afterward. Within a stage, sort by name and ID. In the existing School view, retain alphabetical schools and apply stage priority inside each school.

Show a monthly ratio such as 7/10: students currently in Started Course, Approved Visa, Visa Lodged or Unconditional Offer / all matching students in the month. Also show the four separate stage counts. Count each row once, not once per milestone or study plan. These are current-stage snapshots, not historical events or a completed-task measure. Search and Track filters update numerator and denominator together. Unscheduled students remain visible separately and are excluded from monthly ratios. Zero-match searches show the existing empty state rather than 0/0.

Recognize Visa Approved / Approved Visa and Decline Visa / Declined Visa as display-group aliases, without rewriting saved values. Preserve original chip/export status text. CSV uses the same monthly/stage row order while retaining existing columns and all matching rows.

## Scope and limits

No backend application logic, schema, permissions, stage transition, intake parsing, first-study-plan selection, client data or other portals are changed. The existing endpoint's 1,000-student cap remains; ratios describe the matching rows supplied to the board, not a new unlimited aggregate. Existing Education-stage-first effective-status precedence is retained. If a client has conflicting departmental stages, this feature does not resolve that separate data/workflow issue.

## Verification and acceptance

Actual React page tests cover the exact seven-stage order, a 7/10 example and four-way breakdown, aliases, alphabetical ties, school grouping, Track/search filters, other/unknown/blank stages, Unscheduled, empty state, CSV order and immutable inputs. Backend feature tests confirm one card per student with multiple study plans, original stage/note/study-plan preservation, separate month/Unscheduled rows, pure-case exclusion and existing access boundaries. Related course-start/disposition tests are included in focused validation; final CI/results are recorded in the PR handover.

No migration or new setting is required. After Sir's review/merge, verify combined staging with a synthetic month containing all seven stages, a 7/10 ratio, filters, school view, original card links and CSV. Confirm Emma accepts the combined four-stage numerator out of all monthly entries and the displayed order before Done. Keep the ticket open until release and requester acceptance.
