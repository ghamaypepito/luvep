# Initial Consultation arrangement — TKT-IGQPQ2

The Initial Consultation Agreement & Invoice builder supports Online Consultation and Face-to-Face Consultation. Online requires an HTTPS Google Meet link. Face-to-face requires a consultation address. Only the selected detail is submitted and saved; server validation excludes the inactive field.

Initial Consultation currency can be USD or NZD. New agreements and their paired invoices default to NZD in both the builder and server generation. Staff can explicitly select USD. Reopened drafts and issued documents retain their saved currency; the numeric fee is not converted. Currency is saved with the fee snapshot and used consistently by the agreement, invoice and signing view. OIA remains NZD.

The live preview, generated agreement and invoice include the selected arrangement. Saved draft settings and the agreement payload snapshot preserve it through reopening, email review and client signing. Existing agreements without a mode retain their original online wording when signed. No migration or historical document rewrite is required.

When a selected client has no email address, the builder explains why Verify & Email is disabled. Add the email to the client record, refresh the Engagement page and reopen the saved draft. This guidance applies to case and pre-screening clients. The draft can still be generated and saved without a client email.

The agreement flows section 5 directly after section 4 without an extra forced page break. Section 4’s heading stays with its paragraph. Existing saved PDFs keep their current bytes; regenerate and save an unsent draft to apply the corrected pagination.

The reviewed client email includes the arrangement, date, time and time zone. Existing HTML fragments, Markdown and complete designer email templates retain their format. Generate and Save as Draft send nothing; sending still requires the existing reviewed email action.

Regression coverage: `tests/Feature/Immigration/EngagementWorkflowTest.php` covers conditional validation, invalid/oversized inputs, inactive-detail exclusion, previews, PDFs, fake mail delivery, custom email formats and signing. `tests/Frontend/consultation-mode.cjs` covers actual React interactions, draft restoration and absent-mode compatibility, and is wired into PR validation.

Rollback: revert the feature commit and rebuild assets. Existing saved face-to-face drafts should remain unissued until the feature is restored; do not regenerate those drafts under the previous online-only implementation.
