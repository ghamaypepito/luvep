# Affiliate administration and onboarding

User extension to TKT-LBKTOL. The EP affiliate portal already has network insights, attributable client registration, private versioned agreements and signing. Extend those owners instead of building a separate partner system.

## Agreed behavior

- Super Admin and Admin have default access to the affiliate management workspace, its partner list, statistics and agreements. Other roles cannot access this administrative surface.
- Both roles can disable/reactivate and archive/restore partners from the affiliate workspace. Disable blocks portal login and public referral submissions. Removal means reversible archival, preserving attributed leads and agreement documents. Changes are audited. Archived partners have a separate list/view and history remains privately readable.
- Public applicants can apply with name, email, phone, country/location and optional company. They verify their email and await Admin review. Submission is not an approval or a client lead.
- User-confirmed activation rule: **After Admin approval and both signatures.** Pending applicants cannot sign in to the full partner portal, create referral leads, or obtain an active referral link. Existing affiliate accounts, including the Ghamay sample, are not retroactively blocked by this new-applicant rule.
- Admin reviews an email-verified application, approves or declines, and prepares a version of the existing affiliate agreement with agreed terms. Approval cannot overwrite or change the role of an existing account with the same email. A pending affiliate account is inactive until activation conditions pass.
- The applicant receives a private, expiring email link to review/sign the selected issued agreement before account activation. Use Laravel signed routes and the existing signature/PDF service. Bind signatures to the exact application, user and agreement version, reject stale/superseded/draft/expired documents, require explicit terms acceptance, and preserve issued terms/PDFs.
- Company signing uses the existing authorized admin agreement controls. Either signature order is supported. After the second signature, activate exactly once only when the application remains approved and the selected issued version matches. Later signing, retries or old links must never reactivate an administratively disabled/archived account.
- Completed agreements and all versions remain in Affiliate Agreements, privately viewable/downloadable after disable/archive. An applicant can download its own signed document. Login setup uses the existing password reset process; no plaintext passwords in responses, logs or email.

## Implementation decisions

Use one AffiliateApplication record for pending personal/contact data, normalized unique email, public UUID, verification/review/activation timestamps, reviewer and associated user/agreement. Keep application status distinct from the user's reversible disabled/archived state. Before approval, do not reserve or mutate a User account. Existing duplicate applications return generic confirmation without altering details or ownership. Rate-limit both form submissions and email resends; do not disclose whether an email belongs to an existing account.

Use expiring signed private application/status/document/sign routes. A signed application URL is scoped to that applicant and agreement; it is not a portal session or general account-access token. Set no-store/private cache and no-referrer headers on sensitive public signing pages. Do not put PII in URL paths or query values.

Centralize onboarding activation eligibility in its domain owner, and call it from agreement generation/signature completion and generic User Management activation paths. Reuse existing User archival/audit patterns. Lifecycle actions must check expected current state, authorize the actor and target, and retain data. Keep lock ordering consistent with agreement service's user-before-agreement locks. Use queued mail after transaction commit and preserve explicit send/delivery status. Verification links and activation messages must never activate users directly.

Admin UI reuses AffiliateAdminLayout and the existing EP white/green/lime theme. Add Applications navigation, application queue/detail, lifecycle status/filter/actions and application-to-partner links. Public application and private progress/sign pages use EP branding and the shared AgreementSignModal/PDF flow. Reuse the existing chart/reporting logic. No new dependency or global portal redesign.

## Evidence and non-production limits

Use the existing Taste Vault EP dashboard references, already opened for this extension. Do not create real applications, send real email, sign real agreements, change current browser impersonation, or disable/archive existing accounts for QA. Test with synthetic local records and fake mail. Verify the deployed forms and menus read-only. Release only after independent testing, separate review, required CI and production verification. Preserve and recheck existing agreement PDF hashes.

## Approaches

Extending the current module and agreement engine fits the approved portal and preserves one audit/history chain. A second standalone signup/contract subsystem would duplicate these rules. Immediate signup/activation is excluded by the confirmed approval-and-both-signatures rule.
