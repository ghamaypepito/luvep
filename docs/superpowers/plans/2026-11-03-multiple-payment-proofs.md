# Multiple Payment Proofs Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline, followed by independent whole-feature review.

**Goal:** Retain multiple proofs and verify separate invoice payments without double counting.
**Architecture:** Add satellite proof-to-invoice/receipt links and a shared PaymentProofService. Existing controllers delegate proof uploads/reviews and settlement summaries. A shared review modal serves the Invoice workspace and case Documents; existing client invoice cards accept batches.
**Tech stack:** Laravel 12, MySQL/SQLite, React 19/Inertia, existing CSS and private storage.
**Spec:** docs/superpowers/specs/2026-11-03-multiple-payment-proofs-design.md

## Global constraints

Preserve structure/themes. No leads columns, gateway, FX inference, automatic legacy money backfill or real client payment mutations during QA. All financial decisions remain with authorized human Admin/Finance users. Approved preview supplies the interaction design.

## Review focus

Cross-pack/invoice access, duplicate transfers and replays, historical approvals, partial settlement notifications/unlocking, currency mismatch and deletion consistency.

### Task 1: Payment proof associations and atomic review

Files: new database/migrations/2026_11_03_000001_add_payment_proof_reviews.php; app/Models/PaymentProofReview.php; app/Services/PaymentProofService.php; tests/Feature/Immigration/MultiplePaymentProofTest.php. Modify CaseFinancePayment, CaseFinancial, LeadDocument, LeadDocumentController and CaseProfileController ledger writes/deletions.

Interfaces: PaymentProofService::review(User, LeadDocument, array): bool; ::invoiceSummary(LeadDocument): array; ::metadata(LeadDocument): array; ::reviewOptions(LeadDocument): array; ::canReview(User): bool. Review consumes status, invoice_id, payment_mode(new/existing), amount, paid_at, reference, finance_payment_id, note. Summary returns total/confirmed/remaining/currency/paid. Review options include only same-case invoices and compatible receipts.

- [x] Write and run failing tests for partial then full payment, multiple invoices, supporting attachment reuse, unauthorized generic status calls, duplicate/replay/reference validation, currency, legacy preservation, failed audit rollback and deletion guards.
- [x] Implement migration/relationships, transaction/locks, per-invoice settlement and metadata. Delegate every proof status update before generic document side effects. Preserve existing non-proof document flow. Add a scoped GET /clients/{lead}/payment-proofs/{document}/review-options for shared review metadata.
- [x] Run focused tests and existing financial/engagement tests; inspect output. Commit with the integrated feature.

### Task 2: Client upload retention and staff serialization

Files: EngagementSigningController, Portal/ImmigrationController, Immigration/CaseProfileController, EmailAutomationSweep and tests in MultiplePaymentProofTest.php.

Interfaces: Existing POST /engagement/{token}/proof-of-payment accepts files[1..10] plus invoice_id. Existing Inertia document/proof props gain proof_review metadata and invoice payment summary, without replacing existing fields.

- [x] Write/run failing tests for retained batch uploads, invalid whole batches, pack isolation, invoice association, file cleanup, staff queue containing all files, client summaries and partial onboarding/overdue behavior.
- [x] Implement atomic private uploads and pack-scoped associations; expose all proofs and compatible review metadata; derive paid state from invoice settlement.
- [x] Run the focused suite plus relevant existing tests. Commit with the integrated feature.

### Task 3: Existing UI additions and instructions

Files: new resources/js/components/payments/ProofReviewModal.jsx; modify track/EngagementPack.jsx, portal/immigration/Invoice.jsx, case-profile/tabs/DocumentsTab.jsx and FinancialsTab.jsx. Add browser interaction checks and docs/multiple-payment-proofs-team-guide.md.

Interfaces: ProofReviewModal({proof,leadId,invoiceId,onClose,onReviewed}) loads scoped options and posts existing proof status endpoint. Existing styles/frame/table retained. Client input accepts multiple, posts invoice_id and retains status/validation feedback.

- [x] Write/run browser tests for batch selection, correct per-file review payload, rejection reason, existing receipt linking, errors, confirmed read-only state and keyboard behavior, with responsive layout source review.
- [x] Implement the approved additions using existing styles. Mount shared review from both current eye/verify actions and show reviewer/currency metadata. Write client and Finance steps.
- [x] Build production assets and inspect desktop with synthetic data and review responsive source; fix material issues in one batch. Commit verified UI.

### Task 4: Release and ticket

- [ ] Run complete backend suite and relevant frontend checks; inspect migration behavior and obtain an independent code/UI review. Fix material findings with regression tests.
- [ ] Integrate latest production source, create PR, pass CI, deploy, and perform read-only authenticated smoke checks.
- [ ] Fill TKT-WEILQE resolution, root cause, verification, references, follow-up and documentation; send solution/steps, publish sanitized guide and mark Done. Verify saved state and screenshot.
