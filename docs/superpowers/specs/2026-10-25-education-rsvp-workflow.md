# Education RSVP workflow

## Approved public experience
The public /education/rsvp page lists Event Date + School for seven named institutions. Clicking a row opens “I will attend” with full name, email and optional contact number. “Confirm attendance” saves the RSVP and queues its confirmation email. All visits use the same flow, including partnership meetings. No public partnership selector, dummy recognition or preview controls.

Use the EP mark and green accent on a white base, automatic dark appearance, changing editorial photographs, restrained transitions and reduced-motion support. Use official school logos when available; otherwise use a circular school-name badge. /education/register remains the separate programme registration flow.

## Schedules and consent
Seed one event per school from the supplied itinerary. Proposed dates stay private and every schedule starts unconfirmed. Public information and receipts say TBC. Education/admin staff can edit and explicitly confirm date/time/timezone/location, close intake or cancel an event. Closing intake preserves communications to existing attendees. Only confirmed exact future starts, verified email and reminder consent permit 24h/2h reminders. Schedule revisions cancel stale notices; intake-only edits do not create duplicate revisions. Store contact consent and optional future-invite consent separately. No SMS delivery is claimed.

## Programme pipeline and journey
An RSVP creates no programme lead, pipeline entry or account. Programme registration creates or matches the existing Education lead; new leads are assigned to Emma Ceballo and returning ownership/stage is preserved. Verified exact normalized full-name/email RSVP matches are linked to the unique registered lead, regardless of visit type. Ambiguous matches remain unlinked. Add original RSVP timestamp and attendance state to staff notes and the lead-scoped journey history. RSVP means intent to attend; staff explicitly record actual attendance.

Confirmation email includes a signed verification link. Returning recognition requires that email proof in the current session and exact name/email; the API returns only a boolean. An arbitrary visitor cannot look up contact membership.

## Notifications and reliability
Notify Emma and Dinah using the existing configured Education recipients. Durable unique receipts cover confirmation email, staff alerts, Slack, schedule updates, cancellation and reminders. Bounded retries retain failed deliveries. Separate per-channel dispatch limits prevent Slack backlog from starving emails.

Slack: Luv’EP <>Pxlcode (T0CAUU9KLSE), #general (C0CAUU9RAQJ). Dedicated EP Event Notifications app A0CC05EU389 has only incoming-webhook posting permission and no message-reading permission. User explicitly approved creation and authorization. The webhook is stored encrypted in Luvep, never in source or responses. Alerts say “[Name] RSVP’d to this event! 🎉” or “[Name] registered for this event! 🎉”, event context and a protected staff link. No email/phone in Slack. Infrastructure can support other programmes later; this release connects Education only.

## Release boundaries
Reuse Laravel roles, mail configuration, MessageLog and Redis queue. Enforce CSRF, throttling, honeypot, UUID replay checks and event/email deduplication. Apply a targeted additive migration/deployment, preserving live customizations and the existing registration dispatcher. Add a scoped RSVP dispatcher cron. Keep existing PR #15 updated. Verify locally with disposable data and fake/log delivery; use rolled-back production checks with all outbound transports faked. Do not send fake candidate alerts to the team.
