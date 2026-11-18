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
