# Urgent incident — English agreement access

Reported by Kent during calls with Emma/Eireen, 20 November 2026 (Asia/Manila). No ticket number supplied. Separate from Immigration Written Agreement PR #173.

## Problem and evidence

An Education English agreement email directs the client to the public application tracker. The client screenshot shows the tracker recognising the code and explaining that the record is now managed in the secure client portal. The public tracker deliberately excludes Immigration cases. Therefore an English agreement cannot be opened by the former password-free tracker path after conversion, even though the agreement itself has not acquired a PDF password.

Source review confirmed that approval and manual Notify use the generic consultancy-agreement email with tracker links. Resetting a password does not correct that email workflow. Screen-control helpers were unavailable, so the exact live client's record/email was not edited or independently compared. This fix addresses the reproduced class of failure with synthetic Immigration-client records.

## Resolution

- Add signed, document-specific links for issued English agreements. They require no client account or password, expire after seven days, and grant access only to that English PDF and its signing action.
- Keep the existing public Immigration tracker restriction. Do not change tracking codes, case flags, portal accounts, passwords, or staff permissions.
- Approval emails pass the exact newly issued English document to configured automations and the built-in template fallback. Existing link variables are overridden only for that agreement email.
- Staff's existing Email lead / Notify action carries the selected document ID and validates that it belongs to the chosen client. Already-issued PDFs can receive a fresh link without regeneration or a new agreement row.
- Reuse the existing client signature modal and immutable Education-agreement signing service. Preserve issued PDF pages/fees, append a signature confirmation page, keep an audit timestamp/IP, and reject stale document hashes and duplicate signatures.
- Validate signatures and deny unsigned/tampered/expired URLs, hidden/rejected/non-English files and unrelated document IDs. Child PDF/sign links keep the original expiry. Downloads are private/no-store and responses suppress referrer transmission and indexing.
- Preserve other proposal/consultancy email behavior. The mail class's optional flag defaults off, including older serialized queue jobs.

## Validation

Synthetic SQLite records, fake private storage/mail and generated test signatures only. Tests cover no-account preview/PDF access, actual signed-PDF generation with original-page preservation, immutable hash checks, shared signed state, duplicate signing, restricted case tracker, URL tampering/expiry/revocation, exact-document approval/automation emails, fallback resend without regeneration, unrelated-document denial and failure preserving the issued PDF. React tests use the actual client signature modal and staff agreement page to verify selected-document submission, consent/signature payload and retry/signed states. Final counts and clean-install CI evidence are recorded on the PR and local resolution report.

## Deployment and immediate client recovery

Sir Ghamay reviews/merges and deploys PHP, routes and frontend assets together; refresh route caches and ensure the email queue is running. No migration required. Refresh Education > Proposal & Agreements > Agreements, locate the correct client and verify the existing English PDF, then choose Email lead and Send notification. This resends a new document link; old emails retain their tracker URL. Do not regenerate or re-sign an already-signed agreement merely to restore access.

Test a synthetic converted client opening the email in a signed-out mobile/browser session, viewing the PDF, signing once and reopening the signed copy. Confirm public case tracking and unrelated-document access remain blocked. Ask Emma/Eireen and the client to confirm the deployed result before considering the incident resolved.

Configured templates must use their link variables; a separately hard-coded historical URL needs correction through the normal template workflow. Seven-day links can be renewed with the same Notify action; hiding/rejecting/deleting the document revokes its access earlier.
