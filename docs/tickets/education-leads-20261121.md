# Education lead-list fixes — 21 November 2026

## TKT-WZUEDW (253): affiliate lead profiles and update dates

The Education affiliate detail view reused Sales AgentLeads. Names were plain text and the table showed only creation dates, although the shared server row already supplied last staff activity, author and description. Names now use the existing portal-specific profile route. The added Updated column uses the same staff activity data as the main Leads list. Rows derive directly from Inertia props, so a server response refreshes dates and stages rather than keeping a stale local copy. Failed submissions retain the saved data. Existing creation dates, search, stage controls and expandable rows remain available.

## TKT-WDZE1V (252): agreement filter

The shared Consultancy Agreement quick filter compared only the legacy exact status. Modern Consultancy Agreement Sent and Signed rows were therefore hidden. The filter now groups those three statuses. This change does not widen pipeline membership or enable department-conversion stages. Clarification remains necessary about whether the separate Visa Process request concerns manual stage choices or visibility of already-converted clients; that part remains unfinished.

## TKT-RRFMBJ (250): recorded decline displayed as New

An existing Immigration Decline Visa outcome with no explicit Education stage fell back to the generic New lead status. The serializer now supplies canonical Declined Visa as a display fallback for that existing outcome only. Explicit Education stages win, and other Immigration states retain the prior fallback. A separate server-issued education_stage_hint identifies this known outcome; a generic Sales status sharing an Education label is not treated as an Education stage. The actual Students table shows the fallback, finds it through its Education status filter and explains that no Education stage has been set. Inspecting the dropdown does not select a saved Education stage or submit a write. No outcome, case flag, history, timeline event, file, client account or notification is changed by the display repair. Education reporting continues to use recorded department stages and events, not inferred new events.

## Verification

Before fixes: actual shared React probes failed affiliate profile link, Updated column, modern agreement grouping and legacy decline row display; a real synthetic controller GET returned New rather than the recorded decline. After fixes, those probes pass. The committed actual-component test covers role-specific profile URLs, authoritative refreshed props, stage requests/failed completion, preserved creation dates, legacy/sent/signed filter grouping, row/filter/dropdown fallback consistency and no automatic stage POST. Five backend tests cover read-only field preservation, explicit Education precedence, unrelated Immigration states, affiliate membership and denied client access.

Author affected suite: 238 PHP tests / 3,258 assertions. Related referral, per-program intake, Education disposition and new lead-list actual-component checks pass. Production build, independent exact-head testing, different-reviewer approval, CI and deployed checks are tracked in the PR/ticket reports before closure.

## Other Education tickets

The two requested profile merges require corroborated identity and survivor/contact/referral/intake decisions before consolidation. A referral edit is tested as an update of the existing record, not proof that it created a duplicate. The older public-registration, proposal-verification and missing-file reports require their original target/error/file evidence. Keep unfinished tickets In Progress as requested, preserve owners and record dependencies separately from successful synthetic tests. Do not approve real proposals, send real client mail, merge by name or alter unrelated files for diagnostics.
