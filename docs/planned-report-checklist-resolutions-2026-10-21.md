# Reporting and checklist ticket resolutions — 21 October 2026

## #73 — New clients added this week

Immigration Reports now opens on This week. New client count and named list use the recorded `immigration_converted_at`, from Monday through the current day. Explicit date presets and custom ranges remain available. Missing historical conversion dates are not invented.

## #159 — Endorsed to LIA

The old adviser-name counters (assessment/Dev and Hendry) are replaced by Endorsed to LIA. Both count and named list use recorded immigration `Endorsed` stage-history events in the selected period. A case counts once, dated by its latest qualifying event, even if its current adviser or stage has changed. Assignment alone does not count as an endorsement.

## #143 — Dates first and visible outcome names

Date controls remain above the report. Approved, interim, RFI and declined outcome cards now display client names, visa labels and dates directly, without requiring hover. Named reports and report-note edits require an internal immigration, immigration-manager, immigration-adviser, administrator or super-administrator role. External accounts cannot gain access through an immigration portal grant.

## #74 — Sponsor passport

Read-only production catalogue inspection found the sponsor passport on Student Visa but absent from the three partnership catalogues PRSWV, PRAEWV and PRNZRV. Migration `2026_10_21_170000_add_partner_sponsor_passport.php` appends the missing sponsor passport to those codes and the equivalent seeded PARTNER_WORK/PARTNER_RES codes. It skips empty catalogues and catalogues already containing the item. Existing keys and files remain intact. Partnership templates also include the item for future use.

The migration saves original/patched definitions in `visa_checklist_ticket_74_backups`. Rollback restores only untouched patched definitions and conservatively refuses to remove the slot if any sponsor-passport upload exists, covering dependants and later visa changes.

## #81 — Interim Visa and RFI outcomes

Migration `2026_10_21_180000_add_interim_and_rfi_outcome_slots.php` adds optional Interim Visa and Request for Further Information slots to catalogues that already have an approved/declined outcome or decision section. Existing equivalent slots are retained; no new mandatory requirement is imposed on unrelated catalogues. Original definitions are retained in `visa_checklist_ticket_81_backups`. Rollback preserves staff edits and uploaded files. RFI also appears alongside Interim Visa in report outcomes for the selected period.

## #78 — Student employment certificate

Read-only production inspection confirmed Student Visa (SV) already has one applicant Certificate of Employment under Financial Documents (Applicant). Sponsor and spouse employment certificates describe different people and are preserved. The future Student Visa template now matches the verified financial placement while retaining the existing `certificate_of_employment` key.

## #76 and #80 coordination

The live PRSWV catalogue contains both `sponsor_nz_visa` and `nz_visa` (“NZ Visa of Partner”). This is a genuine duplicate; the separate case-profile change handles display aliases and preserves all uploads. The separate education-checklist change handles #80's client checklist. Neither is resolved by deleting document records.

## Verification and deployment

PHP feature tests cover period membership, deduped endorsement events, named outcome props, external-role denial, migration targeting/idempotence/backups/conditional rollback, and template schema. Browser acceptance is `e2e/hr-immigration-report.spec.ts` using the synthetic HR fixture server. Run migrations and rebuild frontend assets as part of deployment. No production mutations or notifications were performed during the catalogue audit.
