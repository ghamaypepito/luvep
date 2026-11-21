# TKT-BBOQ6I — LIA-reviewed VIF additions

Status: implementation branch. Check the current PR CI result and obtain staging/LIA acceptance before closure. Do not mark Done from this document alone.

## Findings

The live ticket was inspected read-only on 18 November 2026. It contains seven PDFs and a 4.57-second video. The PDFs total 187 pages, with 163 highlight annotations on 68 pages. Highlighted pages were rendered and reviewed; no client examples or attachment contents are committed. The video was reviewed visually (its audio was not independently transcribed). It shows the public Immigration assessment entry cards, rather than a failing form submission. Six visa categories are listed in the request (the final reference to five is inconsistent). SMC has separate EOI and Resident Visa attachments.

Existing forms already capture many names, contact, passport, employment, health and character fields. They also already have private document/checklist workflows. A number of visa-specific questions were missing. Some Student list/military/travel fields existed in storage but were absent from the editable/rendered layout. The actual VIF exports use ImmigrationController::buildVifSections; the older VisaInformationForm helper is not the active export path.

## Changes

- Shared additional-question definitions drive public forms, client editing, staff editing, server validation and generated VIF sections.
- Nullable vif_answers JSON on the five existing intake tables stores new answers without adding identity columns or moving existing files. Existing qualification/family/contact/travel keys are reused where available; earlier alias keys remain readable.
- Student: study intentions, commitments, study/work relationship, prior employment and background questions; existing education/family/contact/military/travel lists become editable and exportable.
- Visitor: financial support/sponsorship, multiple journeys, partner inclusion/occupation and multiple NZ contacts with dates of birth.
- Work: AEWV employer NZBN/name confirmation/occupation/minimum-skills details and police-certificate history. PSWV has its own question block and selection within the existing Work intake. A new PSWV submission selects the configured POST_STUDY_WORK/PSWV visa, rather than silently becoming AEWV; it does not require AEWV employer details.
- Resident/SMC: detailed health, character, residence-country, occupation/pathway, English and family questions.
- Family: supporting-parent, dependent-child residence, health and character additions are shown only for dependent-child residence forms.
- Additional answers render before the declaration in PDF/Word. Unsubmitted supplemental keys survive partial edits. Editing supplementary answers refreshes any already-generated VIF linked by the exact assessment FK and sends it back through existing adviser verification.
- No production records, ticket statuses, issued agreements, finance data or role grants changed. No new external service or email delivery was introduced.

## Source comparison

| Source | Highlighted pages | Coverage |
| --- | --- | --- |
| AEWV | 1–5, 8 | Reuse existing identity/history/employment/health/character; add missing employer/occupation/PCC/contact details. |
| Dependent Child Resident Visa | 1–4, 6–7 | Reuse Family identity/history and medical fields; add supporting-parent/residence/character details. Existing passport/birth-certificate checklist workflows remain. |
| General Visitor Visa | 1–8 | Reuse existing identity/history/travel dates/health; add missing support/journey/partner/contact questions. Existing passport/itinerary uploads remain. |
| Post Study Work Visa | 1–3, 5 | Add PSWV-specific history and detailed certificate questions within Work; reuse personal data and existing private qualification/funds uploads. |
| SMC EOI | 4–17, 24–28, 30, 33, 40 | Add missing detailed health/character/pathway/family questions; reuse qualifications, employment and English evidence. |
| SMC RV | 51, 68–75 | Add additional-family information; retain existing private document collection rather than duplicate uploaded files or recreate the official INZ submission flow. |
| Student Visa | 1–10, 12–14 | Reuse existing scalar fields and list storage; add missing study-intention, employment/background and relationship questions. |

Highlighted headings sometimes cover a multi-page section. Hendry/Nova must confirm the rendered question wording and completeness during staging; no automated eligibility decisions were added from these examples. This is information capture, not a replacement for the official INZ application.

## Verification and release

Portable React interaction coverage is in tests/js/vif-supplement.test.mjs and is included in the existing CI workflow. Backend coverage in VifSupplementTest exercises public submission, staff/client persistence, exports, approval gating, cross-account denial, invalid inputs, multiple contacts and new PSWV classification. Existing public intake and VIF regression tests must remain green.

Deploy only after CI and reviewer approval. Run the normal Laravel migrations for the nullable JSON additions. Stage synthetic SV, GVV, SMC, AEWV, PSWV and DCRV examples, including an existing generated/approved VIF, before production acceptance. Confirm existing documents remain accessible and Education/Accommodation/Finance workflows remain unchanged. Production deployment and LIA acceptance are not established by a branch or green CI.


## Follow-up source comparison — 19 November 2026

Independent inspection reconfirmed all seven PDFs: 187 pages, 163 yellow highlight annotations on 68 pages. Scanned highlighted regions were read with local OCR; the supporting evidence remains private and is not committed. The short video shows the public assessment entry cards; its audio has not been transcribed. The Taste Vault form-reference query returned no matches; this correction reuses the existing shared question component.

Two source-backed capture gaps were corrected in the shared catalogue, without changing eligibility rules:

| Source evidence | Field mapping | Compatibility |
| --- | --- | --- |
| **Dependent Child Resident Visa.pdf, page2:** the highlighted parent question records a specific citizenship/residency class, including “New Zealand resident or permanent resident visa holder”. A Yes/No answer alone loses that information. | `family.vif_answers.supporting_parent_nz_status`, text, **Parent’s New Zealand citizenship or residence status** | Retain `parent_nz_citizen_or_resident` and its historical Yes/No answers. The additional text records the parent’s stated status; it grants no eligibility or waiver. Remains limited to dependent-child residence forms. |
| **SMC EOI.pdf, page17:** highlighted **What type of employment do you or will you have?** includes an independent-midwife basis absent from the ordinary employment-type selector. | `resident.vif_answers.smc_employment_basis`, select, with the four source options below | Keep ordinary `employment_type` unchanged. No pathway, occupation or eligibility is inferred from this answer. |

The four SMC choices are copied literally:

- Permanent or for a fixed term of at least 1 year
- A contract for services
- I am an independent midwife practitioner with a letter of authority to claim under the Maternity Notice and registration with the Midwifery Council
- None of the above

The shared catalogue drives validation, applicant/staff editing, owner persistence and VIF exports. Focused regressions cover both fields, all four SMC choices, preservation of the old parent Yes/No value, visa scoping, public submission, client/staff persistence and HTML/Word/PDF output. The CI merge retains PDF-worker compatibility, main’s critical-dependency checks and the VIF interaction tests.

Before acceptance, Hendry/Nova must review the regenerated synthetic SV/GVV/SMC/AEWV/PSWV/DCRV previews and confirm wording/completeness, particularly section-heading highlights and existing private upload/checklist coverage. The synthetic packet is a review artifact, not a production submission or LIA approval. This integration starts from main59dd9db9 and PR106head1238227f; the separate staff English-evidence change touches the same Resident/client intake writers and must be integrated and independently retested before any release. Do not close TKT-BBOQ6I before LIA acceptance and deployed staging verification.


### Recreation compatibility correction

The independent target-save check found that `IntakeTypeConverter` copied the entire shared `vif_answers` JSON column when recreating an intake as a different visa type. Source-only keys then failed the target form’s allowed-key validation. The converter now intersects copied supplemental answers with the target catalogue’s supplemental keys, using the same `VifSupplement::answerKeys` allowlist as validation. Common answers remain; source-only questions are left for the target form. Nullable or malformed legacy supplemental payloads become empty on the new target only. Original raw source records, ordinary shared-field copying, surname aliases, declaration reset and existing assessment creation semantics are retained.

Regression coverage recreates and saves Resident→Work, Work→Resident, Family→Resident and Resident→Family with a common middle-name answer and a source-specific answer. It verifies target validation, ordinary profile fields and untouched raw source rows. Separate175 staff evidence decisions must still be reset and must not copy to new assessments when the branches are combined; that protection is independent of this target-key filtering.

### Backend review corrections

- Known list fields accept legacy keyed/single-record shapes and render stable numbered rows. Recognized aliases are read into canonical keys; an explicitly present canonical empty/null value wins. Unknown row columns remain intact and reads do not rewrite stored source data.
- Catalogue visibility governs supplemental reads, exports and AI input. Inactive AEWV/PSWV or dependent-child residence answers are removed on relevant edits/submission; historical inactive values are filtered when read. AI serialization uses visible known Q/A rows, including canonical lists, rather than raw supplemental JSON or private staff exception metadata. Hidden stale fields are discarded against the fresh merged context before validation.
- Staff/client Work-category changes update only the exact paired Assessment's configured visa type. Public creation, recreation, fee lookup and historical backfill use the same explicit subtype mapping. AEWV fallback excludes known PSWV codes; missing subtype configuration rejects/rolls back an edit rather than assigning a different category. No case outcome or eligibility is inferred.
- Staff and client writers compare effective VIF content, excluding generation time. Unchanged saves and equivalent legacy canonicalization retain document approval and send no client-update notification. Real content/context edits refresh an existing VIF for adviser review. Empty client saves do not implicitly generate a first document; the explicit Generate action remains available. The Resident public edit-link path follows the same effective-content rule while retaining its locked staff-English protection and existing private file append behavior.
- Supplemental partial maps merge into the fresh locked row, preserving untouched siblings. The integrated175 reason/actor/history controls, applicant read-only English fields, public forgery validation and recreation reset remain in force. The UI separately sends changed top-level keys and changed supplemental keys only.

Regression coverage includes keyed/single/encoded lists, canonical clears, unknown row preservation, legacy scalar repair, subtype round trips and missing mappings, inactive read/AI privacy, no-op approval preservation, context-only refresh, explicit first generation, public edit links and existing intake/recreation/backfill behavior. The final combined revision must receive independent testing and separate review; no LIA acceptance or production release follows from these tests alone.


### Render and save consistency follow-up

Rendered-content comparisons are strict, including numeric-looking identifier text such as `001234` versus `1234`. The authenticated client update now renders/writes the replacement VIF and updates its document pointer/status while holding the same fresh intake lock and database transaction as the answer changes. Explicit generation uses that same lock. Render, failed/throwing storage and document-pointer failures roll back answer changes and preserve the previous approved document/file; failed unique candidates are removed when storage permits. Change notices are registered after commit, so a rolled-back edit sends none. An identical retry therefore remains a real edit and succeeds normally.

The actual generic client editor captures its initial baseline and posts only changed top-level keys and changed supplemental keys, excluding staff-only fields. Unchanged or reverted saves do not post. Assessment controls are disabled while saving so a late response cannot discard a newly typed draft; a failed response restores editable controls and retains the draft for retry. A successful canonical response resets both current values and baseline, preserving newer staff context/siblings from other tabs. Regression coverage includes all three numeric-identifier writer paths, render/write/pointer failure and retry, transaction/notification ordering, and actual React stale-tab, category/sibling, no-op and canonical-response behavior. These remain technical checks; LIA review and release verification are still pending.
