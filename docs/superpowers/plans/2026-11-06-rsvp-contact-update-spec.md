# RSVP contact-number follow-up

Send one operational email to each existing, consented, non-cancelled RSVP identity with a blank phone. Do not resend when the command is repeated. Use the existing portal mailer/outreach log and retries. Skip requests if a phone is supplied before delivery. No marketing content, new registrations, Slack alerts, or new accounts.

Email subject: Add your contact number for EP Education event updates. Explain that the team needs a contact or WhatsApp number to reach the attendee about schedule and venue. Provide an Add my contact number button. Reply-To Emma at emma@luvep.com. Use a secure link rather than automatically processing email replies.

Signed seven-day links show a minimal EP form without login or existing contact details. Bind links to the RSVP's public ID and current email hash. Require CSRF, signature/expiry validation and throttling on updates. Save a required phone only. Update other blank RSVP phone fields only for the exact same normalized email/name. Fill a blank linked applicant phone only when its email and name match; preserve existing applicant numbers, programme stages, consent and attendance. Record an audit entry without logging phone values.

Include the new routes/templates in normal production source before sending real links. Keep the larger legacy draft PR separate. Recipient information and signed URLs must not be printed in tool output or reports.
