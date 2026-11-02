# Multiple payment proofs — TKT-WEILQE

Approved direction: user reviewed the interactive preview and instructed implementation, deployment and ticket documentation. Preserve existing structure, navigation, theme and styling; add the feature within the existing invoice card, proof table and document review surfaces.

## Selected approach

Retain each uploaded file with an individual status. Admin/Finance confirm a distinct payment amount/date/reference against an invoice, or link a supporting file to an existing payment. One transfer contributes once to the existing CaseFinancePayment ledger. Upload-only changes would retain the old erroneous behavior in which any approved file settles the entire invoice, so they do not meet the instalment requirement.

## Client flow

Existing sent engagement tokens remain pack scoped. Allow 1–10 PDF/JPEG/PNG/WebP/GIF files per upload, 10 MB each; validate the whole batch before storing. Retain all previous files. Associate each new proof with an invoice in the same lead and engagement pack; require invoice selection when ambiguous. Store documents privately and clean up newly stored files if the transaction fails. Existing view/download ownership checks remain in force. Fire the existing upload automation once per successful batch after commit.

The existing invoice cards list each proof with Submitted/Under review, Confirmed or Needs attention status. Show confirmed and remaining amounts in that invoice's currency. Allow further evidence uploads after partial or full payment. Do not treat a proof upload or a partial payment as full invoice settlement. Render cards for multiple invoices in a pack using the same existing component and filter proofs by their association. Old unassigned proofs remain available in the case and staff queue for deliberate allocation.

## Staff flow and data

Only active Admin/Super-admin/Finance staff may change payment-proof statuses. Other staff retain their existing document-view permissions but cannot confirm or reject financial evidence through direct generic status endpoints. Use the existing Invoice > Proof of payment table, showing every file (remove current per-lead deduplication), invoice, reviewer/time, and verified amount. Existing eye/download actions open a shared review component reused in case Documents. Keep existing document notes and activity history.

Create a payment_proof_reviews satellite table: unique document_id, invoice_document_id, nullable finance_payment_id, and supporting flag. Store status/reviewer/time/rejection explanation on the existing LeadDocument. Add nullable invoice_document_id and currency to CaseFinancePayment. Do not add columns to leads. Foreign keys and deletion guards preserve invoices, receipts and proofs that are needed for reconciliation. Existing documents and receipts are not backfilled or re-valued automatically.

For a new payment, require a positive two-decimal amount, payment date and transfer reference. Lock the lead, proof, invoice and any selected existing payment in a transaction. Reject duplicate normalized references within the case; let Finance explicitly link the file to the matching existing receipt. A receipt may be allocated to only one invoice and currency. An existing manually entered receipt can be allocated to the invoice once; do not create another ledger row for it. Record actor and review details in the audit log. Replayed confirmation creates no extra money, history or notification. Confirmed proofs are immutable in this flow; financial corrections require a separate reviewed correction, rather than deleting evidence or silently changing a confirmed amount.

Keep payment currencies explicit. Match invoice and linked receipt currencies; never infer an exchange rate. CaseFinancial totals sum receipts in the case financial record's currency, including legacy null-currency receipts under the existing case currency convention. Show currency on individual financial ledger rows. Manual ledger additions record the current case currency. Protect linked receipts from removal; retain current unrelated ledger behavior.

Only verified distinct ledger payments allocated to an invoice reduce that invoice's remaining balance. Mark only that invoice paid when the total covers invoice_total. Preserve already Approved historical invoices; do not infer amounts for legacy approved proofs. Partial confirmations do not send invoice-paid messages, advance the case to Invoice Paid, or unlock paid onboarding steps. Case-level paid milestones require all relevant invoice documents to be settled. Existing configured paid automation runs once after actual settlement, after commit, with the invoice context. Align client pack status, case document badges, milestones and overdue checks with invoice settlement instead of any approved proof.

## Acceptance and limits

- A batch of three files creates three retained proofs; a later upload keeps them all. Invalid batch, pack or invoice references cannot create partial records or cross-client access.
- For a 1,200 invoice, verifying 400 leaves 800; adding a separate 300 leaves 500. Linking another file to the 400 receipt leaves the balance unchanged. Other invoices stay unpaid.
- Reviewers can mark an individual pending proof Needs attention with an explanation; other files and receipts are unaffected. Rejected files remain accessible and can be reviewed again when appropriate.
- Unauthorized roles cannot mutate financial evidence through any status path. Replays, duplicate references, wrong-currency links, cross-invoice links, deleted/archived leads and failed audits are covered by tests.
- Existing historical approvals are preserved without generating money. No automatic duplicate-account reconciliation, payment gateway, FX conversion or redesign is included.
- Use synthetic data for tests. Production smoke checks open forms without confirming real payments. Deploy through the established reviewed GitHub workflow, then save the ticket solution/report, publish sanitized steps and mark Done after verification.
