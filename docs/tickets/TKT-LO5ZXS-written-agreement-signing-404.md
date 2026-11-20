# TKT-LO5ZXS — Written Agreement preview/signing 404

Requester: Nova Santillan (Immigration Manager). Priority: urgent. Status: fix prepared for review; deployment and original-client confirmation pending.

## Findings

The screenshots show the 404 inside the document preview while the signature controls remain visible. That matches the tracker-style signing modal, rather than the standalone engagement-pack signing form.

The signed-in client portal builds its tracker from the public tracker payload. It already rewrites document view/download links to authenticated portal routes, but it leaves preview/sign links pointing at `/track/{code}/documents/{id}/...`. Those public routes deliberately reject Immigration cases. Consequently the portal page can load while its agreement preview and submission return 404. A failing synthetic regression reproduced the incorrect URL contract before the fix.

This is a confirmed code defect in the signed-in portal path. Kent clarified during his call with Eireen that both the signed-in portal and emailed-link entry must work, including preview before signing and the state after submission. Both paths are covered in the regression tests. No real client was asked to sign for diagnostics. No signature, token or confidential client document is included in this report.

## Resolution

- Rewrite preview and sign links in the authenticated tracker payload, alongside existing download links.
- Add authenticated, owner-scoped preview and sign endpoints within the existing lead-portal middleware, including its read-only staff-preview protection. The server obtains the lead from the signed-in user, not a submitted lead ID or tracking code.
- Reuse existing rendering and signature persistence logic. Keep public tracker resolution unchanged: public case codes still cannot preview or sign Immigration documents.
- Reject cross-client, hidden, unshared, rejected and known unissued-pack document access. Require consent and a bounded PNG/JPEG signature for portal signing. Serialize signing with the existing engagement case-lock key and reject an already-signed document.
- Use stored engagement payload snapshots where present so preview and signing retain issued terms. Legacy documents without snapshots keep their existing fallback behavior.
- Preserve Education's issued-PDF signing path; its authenticated preview redirects to the existing private download and its signature still uses the issued hash.

There is no schema migration, new staff role grant, account merge, production data correction or notification-setting change. Existing emailed engagement routes and Accommodation signing are not changed. An already-signed agreement should not be signed again.

## Validation

Tests use synthetic SQLite records, fake private storage and fake mail. New coverage exercises the actual authenticated tracker payload, preview, signature submission, signed state, duplicate prevention, correct owner, guest/staff/unrelated denial, hidden/rejected/unshared files, public case denial, consent/signature validation, failure not marking the agreement signed, real PDF generation, issued snapshot forwarding, existing public non-case preview and Education issued-file preservation. Existing engagement workflow, staff signature, template-link and Education signature tests are included. Final counts and full clean-install CI evidence are recorded in the PR/resolution report.

The real PDF test uses a generated image labelled SYNTHETIC TEST, not a client's signature. The renderer and signing request are exercised without production data.

## Rollout and acceptance

Sir Ghamay reviews and merges, deploys routes/application code together, and refreshes any cached routes. No migration required. With a synthetic Immigration client account, refresh the portal page, open Written Agreement and verify the actual document loads; draw/sign, tick consent, submit and confirm the signed PDF and signed state persist. Confirm another client and anonymous case-code requests are denied. Verify the separate emailed engagement and Education paths remain functional.

Ask Nova to confirm the client's deployed result before marking Done. A fresh portal page is required so a previously loaded tab does not retain the old public URLs. The emailed path is tested through the current engagement link, including actual PDF signing and the matching signed state in the portal. If a particular historical email link still fails, inspect that exact token/document privately; passing synthetic tests cannot verify every previously issued link.

## Message for Sir Ghamay

Sir, TKT-LO5ZXS has a reproduced portal route mismatch: the signed-in tracker rewrites downloads but still sends agreement preview/sign requests to public tracker routes, which reject Immigration cases. The branch supplies authenticated owner-scoped preview/sign routes and rewrites both links, preserving the public case restriction. It reuses existing signing and checks consent, ownership, hidden/rejected files and duplicate signing. Kent confirmed both portal and emailed-link entry must work. Tests cover both paths, real signed-PDF generation, shared signed state and duplicate rejection across entry paths, plus Education issued-file preservation. Please review, merge and deploy, including route-cache refresh. Nova's original-client confirmation is still needed before Done.
