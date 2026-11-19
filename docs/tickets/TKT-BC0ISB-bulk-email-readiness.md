# TKT-BC0ISB - Education Bulk Email send readiness

The screenshot shows Send now already selected and the blue Send action disabled. The campaign-name field appears to contain its existing placeholder, June re-engagement, rather than an entered name. The exact live draft is unverified, but missing campaign name reproducibly disables Send despite selected recipients, with no explanation of the missing requirement.

Add Education-email-only readiness feedback: clearly mark the internal campaign name required, explain that Send now selects timing while the blue Send submits, list missing name/template/recipient/schedule requirements, and show starting/ready states. Retain server validation errors inline with the draft so a failed request can be corrected and retried. Apply the existing160-character campaign-name limit in that Education input.

The existing canSend condition and backend validation/dispatch, templates, recipient eligibility/filtering, consent checks and scheduling behavior remain unchanged. No automatic campaign name, auto-send, migration or permissions change. SMS and other departments do not receive the new Education help/errors. No live campaigns or messages are created in verification.

Verification extends the actual shared-page education-email.cjs interaction test with blank-name/selected-recipients, timing selection, enabled send, missing schedule and failed-send draft/retry cases, alongside existing Compose/recipient/list scenarios. Existing BulkEmail backend/service tests and complete PR CI required. Exact live cause and requester acceptance remain pending deployed verification.

After deployment, Emma should enter a campaign name, choose a template and recipients, select Send now, review the readiness message and use the blue Send action only for her intended campaign. Do not submit a real campaign just to diagnose the UI. Any remaining failure needs its exact inline error and campaign context; investigate queue/delivery separately from a disabled button.
