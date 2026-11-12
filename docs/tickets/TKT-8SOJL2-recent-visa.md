# TKT-8SOJL2 — Recent visa document categorisation

Priority: High. Status: In review, pending validation, review, staging and requester acceptance.

## Corrected requirement

Nova withdrew TKT-KQBOQ8: the reported document is a recent visa, not Visa History. The old feature commit was reverted on `codex/kqboq8-visa-history` and that branch carries a superseded notice. Do not cherry-pick its original feature commit. The replacement branch is `codex/8sojl2-recent-visa-documents`, based directly on main `9b797d271e44a1a4277a92e704d536f0da0c0a7f`; it does not depend on the old proposal.

## Findings

The screenshot shows the file still present under Other (no checklist match), with StaffShared status and no completed review. This establishes a categorisation complaint, not file loss. The resolver joins a document's checklist_key to the case override or visa-type checklist; blank/unrecognised keys render under Other. The exact live metadata and file contents were not inspected. No production document has been moved by this work.

Current main has no file-menu action for assigning an unmatched upload to a recent-visa row. Some visa templates contain current-visa copy requirements, but they are not universal. The replacement provides an explicit case-specific category; it does not infer a file's purpose from its filename.

## Proposed correction

- In the Immigration Documents tab, authorised staff choose the unmatched file's menu, then Move to Recent visa / eVisa, and confirm its purpose.
- Add an optional `recent_visa` row under Visa applications & decisions to this case's existing checklist override when absent. Preserve all resolved checklist items; do not update the global visa catalogue or other cases.
- Change only the selected document's checklist key. Preserve its ID, file path/bytes, filename, source variant, case association, original upload date/uploader, status, reviewer and notes. No re-upload, duplicate document, automatic approval or case-stage change occurs.
- Add an audit event; retries do not duplicate rows or events. The service locks the case and selected document within a transaction.
- Reject cross-case document IDs, non-case leads, dependent/request/generated/payment/RFI documents and files already matched to another checklist item. Existing workflows remain responsible for those documents.
- The optional row starts hidden from the public tracker. Staff may enable it using the existing visibility checkbox when appropriate. Categorisation does not grant public or unrelated-account access. Subsequent uploads use the existing checklist upload flow.
- The new menu action appears only on Immigration portal routes, not other portals sharing the component. Existing role/case permissions are retained; no role grants, database migrations or other portal controllers are changed.

## Validation and acceptance

Local validation results and publication status are recorded in the delivery update. Do not equate a pushed branch with a production resolution.

Before deployment, verify with synthetic data that classification preserves file/review/upload metadata, existing checklist requirements and other cases; repeat requests remain idempotent; and cross-case IDs/external accounts are denied. Test existing Immigration Documents and client checklist upload/shared-document paths, including Education regression coverage. Confirm cancel, busy state, error/retry and refresh behavior in the dialog.

On staging, Nova confirms the row label/purpose. Move a synthetic recent visa using the menu, open it, upload another file to the row, and test staff-managed client visibility. Verify an unrelated account cannot download it. Then an authorised staff member must identify the exact live record/document and explicitly perform the approved classification. Do not guess from a similarly named file or client. Mark Done only after deployment and Nova's confirmation.

## Resolution report draft — not yet resolved in production

**Issue summary:** A recent visa document appears under Other (no checklist match). Nova corrected the earlier request: it must not be Visa History.

**Root cause:** The document has no matching checklist category in the displayed case. Exact live metadata remains to be checked; the existing file-menu workflow lacks a recent-visa classification action.

**Resolution:** Proposed replacement branch adds explicit case-specific Recent visa / eVisa classification under Visa applications & decisions while preserving the original document and review information. The superseded Visa History proposal was reverted separately. Pending review, validation and deployment.

**Verification:** Use the automated-test results and staging checklist above. No live document was modified and requester acceptance is outstanding.

**References:** TKT-8SOJL2 supersedes TKT-KQBOQ8; replacement branch `codex/8sojl2-recent-visa-documents`. Keep the actual client URL and file evidence in the restricted ticket.

**Follow-up:** Sir Ghamay reviews the replacement branch, completes CI/staging checks and approves deployment. Nova verifies the correct file/category before closure.

**Documentation summary:** Recent visa evidence is categorised separately from historical visas; classification preserves the file and does not approve it or change the visa stage.

## Suggested update to Sir Ghamay

Sir, Nova's correction TKT-8SOJL2 supersedes the Visa History request. Please hold the old branch; its feature was reverted. The document is a recent visa, so the replacement branch `codex/8sojl2-recent-visa-documents` provides a case-specific Recent visa / eVisa row under Visa applications & decisions, with an explicit move action that preserves the original file and review details. This is High priority. Please review the replacement and complete staging/requester verification before merge and deployment. No production files were changed.
