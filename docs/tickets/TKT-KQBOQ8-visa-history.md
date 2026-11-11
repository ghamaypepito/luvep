# TKT-KQBOQ8 — Case-specific Visa History classification

## Findings

CaseChecklistService matches LeadDocument.checklist_key against a per-case
document_checklist override, otherwise the visa-type catalogue. Blank or
unrecognised keys stay visible under Other (no checklist match). The screenshot
shows a categorisation problem; it does not establish file loss. The live
record's exact visa type, override and stored document key have not been read.

## Proposed change

An internal immigration staff member or Admin selects an unmatched upload's
More menu and explicitly confirms Move to Visa History. The action adds an
optional Visa History item to that case's existing per-case checklist override,
preserving all resolved items. It moves the selected document by changing its
checklist key; its file bytes/path, identity, upload metadata, review status and
notes remain intact. The catalogue and other cases are unchanged.

A newly added Visa History row starts hidden from the public tracker; staff
control its visibility using the existing checkbox. No client email is sent.
This uses a case-specific checklist snapshot: subsequent catalogue edits do not
automatically replace this customised case checklist.

Classification is transactional and repeat-safe. An audit event records the
actor, case/document IDs and old/new keys. Matched requirements, generated
agreements, payment evidence, RFI documents, request-linked uploads and dependent
documents are excluded. Cross-case document IDs return 404. External accounts
cannot invoke the action, even with an Immigration portal grant.

## Validation

- Backend: VisaHistoryDocumentTest covers metadata preservation, repeated calls,
  multiple history files, optional progress counts, catalogue isolation,
  per-case overrides, cross-case/non-case denial and protected workflow files.
- Frontend: visa-history.cjs exercises confirmation, cancellation, duplicate
  submission prevention, visible errors and retry.
- Run existing CaseDocumentsTabTest and CaseChecklistServiceTest as regression.
- PHP/Composer are not installed in the current local environment. Backend
  results must be verified in CI or a prepared test environment.
- Record actual executed frontend/build outcomes separately; tests being
  present does not mean they passed.

## Staging and acceptance

Use synthetic case/document records and designated test users. Classify two
unmatched uploads, confirm both appear in Visa History, retain their original
review status and metadata, and confirm future uploads use the new checklist
row. Verify a second case is unchanged, required progress is unchanged and
tracker visibility stays hidden until explicitly enabled. Verify unrelated/
external users and cross-case IDs are denied.

After review, staging and deployment, an authorised staff member must verify
the originally reported file and explicitly classify it. Merge alone does not
recategorise existing production documents. Nova must confirm the expected
file appears in Visa History and opens before marking Done.

## Status

In review; Normal priority. No production record has been changed.
GitHub publication requires a connection with repository write access.

## Local validation outcome — 12 November 2026

- JSX syntax checks passed for DocumentsTab.jsx and VisaHistoryDialog.jsx.
- git diff --check passed.
- Interaction test/full build did not complete: dependency installation failed
  with ECONNRESET; the cached retry was stopped after continued incomplete
  downloads. Missing React DOM/jsdom/jiti modules prevented those runs from
  reaching application assertions. This is not a passing test result.
- Backend tests were not run because PHP and Composer are unavailable locally.
- Connected GitHub branch creation returned 403 Resource not accessible by
  integration. A non-interactive Git push also failed because no usable Git
  credentials were available. No remote branch or PR was created.
- No staging deployment, production change or requester acceptance was performed.
