# Agreement Custom Description Implementation Plan

**Goal:** Add an editable custom description for the highlighted Application Type fee-description line in New proposal or agreement (TKT-NQZZ0G).

**Architecture:** Optional plain-text application_description (max1,000 characters) in the existing shared consultancy overrides. The common payload and Blade template render it consistently for HTML preview, read-only PDF download, pending review and approved PDF. Persist within the existing review overrides, with no migration or global template edits.

**Scope:** The four Philippines consultancy scenarios and their Single/Couple dropdown variants. Blank/absent input uses the original scenario description and preserves trusted template formatting. Custom text is escaped and line breaks preserved. Original client documents are not regenerated or sent merely by deploying the feature.

**UI:** Custom description textarea in the existing settings panel, showing the scenario default, with clear default/reset behavior. Preview updates while typing; downloads and final submission use the same current value. Reset per document/type; hide and omit the field for unrelated document types. Preserve modal styling, fee confirmation, existing email/verification workflow.

**Tech Stack:** Laravel, existing AgreementGenerator/ConsultancyReviewService/LeadDocumentController/Blade, React/Inertia, PHPUnit/JSDOM and Dompdf.

## Tasks
- [x] Backend RED: custom text in preview and PDF, persistence through pending review/approval generation, defaults including voucher markup, plain-text escaping, max/type validation, unchanged read-only export behavior.
- [x] Backend common request override + payload/template implementation; focused tests GREEN.
- [x] Frontend control, submission and preview/download wiring with RED/GREEN interaction regressions. Frontend/backend may proceed independently against application_description contract.
- [x] Render a synthetic PDF and inspect it; desktop/mobile form QA, focused suites/full regression/build and fresh review.
- [ ] PR validation, deploy exact tested version, verify live control read-only and update ticket findings without submitting/sending a real agreement.

## Verification evidence
- Backend RED: four failures for missing custom preview, download, review persistence and validation behavior; original defaults already passed.
- Initial five backend cases passed (57 assertions), then focused agreement suite passed. Review identified valid many-line content overflowing a non-splittable table row; reproduced RED at y35.554 below the PDF footer.
- Custom-only rendering now uses escaped per-line rows so Dompdf can paginate. Final six cases passed with 380 assertions, including all80 test lines within page bounds; final focused suite29 tests529assertions passed. Default table/formatting remain unchanged.
- Full local PHP suite before final layout regression:1,644 tests9,860assertions passed. The final exact release is gated by GitHub full regression.
- All16 frontend CJS checks,26 JavaScript tests and production build passed. New modal interaction regression observed RED→GREEN. Existing build/dependency warnings remain.
- Synthetic PDF pages inspected: custom text wraps in Application Type; custom bank heading/details stay together. CUA checked actual modal at1113px and narrow390px; typed custom text updated synthetic preview. No real client document was generated or sent for testing.
- Fresh review and focused re-review resolved the PDF overflow finding; no remaining material findings.
