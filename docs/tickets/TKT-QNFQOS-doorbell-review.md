# TKT-QNFQOS: QR Code Door Bell Set Up

Review date: 15 November 2026. Source baseline: cdae3ba43447106fe7af7e020d0cfb45d1a31cf5.
Status: In review - feature not found in the reviewed main branch; requirements and live acceptance pending.

## Findings

The attachment describes a proposed visitor workflow, rather than evidence of a deployed feature. Searches of tracked routes, controllers, notification classes, configuration, frontend pages and tests found no doorbell/ring/acknowledgement implementation. Searches of fetched branch names and available commit messages also found no matching doorbell work; this does not prove that no unpublished work exists.

Existing reusable infrastructure:
- resources/js/pages/admin/TeamCards.jsx generates QR codes for public staff profile URLs. These are calling cards, not entrance doorbells.
- app/Notifications/TaskAssigned.php demonstrates database notifications.
- app/Http/Controllers/NotificationController.php scopes notification reads and changes to their recipient.
- app/Support/NotificationFormatter.php maps notification payloads to the common inbox UI.

No live visitor/staff acceptance test was performed. Source inspection cannot prove the current production deployment state. In particular, an in-app database notification alone is not proof of an immediate audible, push or background alert.

## Decisions needed before implementation

Confirm with the current business owner:
1. Actual entrance(s), display names and timezone. The ticket's reception/back/meeting-room URLs are examples only.
2. Named authorised reception recipients, ownership of staffing, and the required alert channel. Portal inbox, email, browser push, Teams, Slack and WhatsApp are alternatives, not all agreed requirements.
3. Operating hours, off-hours message and a real fallback contact/process.
4. Simple ring and acknowledgement versus chat, audio or video. Recommend a small ring/acknowledgement first release for discussion; this has not yet been approved.
5. Whether a visitor name/company is necessary, and how long arrival records should be retained.

## Proposed implementation after scope confirmation

- Keep the feature disabled until valid location, recipients and hours are configured. No real door, recipient, address or fallback number should be invented.
- Public mobile page for an enabled location; scanning or loading the page must not ring automatically. Require an explicit POST with normal CSRF protection, server-side rate limits and replay/double-tap protection.
- Store each accepted ring as its own arrival event, including server timestamp and location. Use a dedicated table, not client/lead records. Keep public responses free of internal identities and data.
- Notify only configured active staff. Deduplicate delivery per arrival/recipient. Track dispatch failure separately from staff acknowledgement; do not claim someone was alerted or is coming on an unsuccessful delivery.
- Restrict staff arrival viewing and acknowledgement to configured reception staff (with an explicit admin policy). Recheck permission at every read and write; access to a portal alone must not grant reception access.
- Give the visitor a narrowly scoped, expiring unguessable status capability for their own ring. No public arrival list, sequential-ID lookup, staff names or client records.
- Acknowledge atomically once; visitor sees the agreed acknowledgement only after staff actually responds. Handle expiry, unavailable staff and off-hours clearly.
- Reuse the selected notification channel deliberately. If using the portal inbox, test delivery latency/refresh behaviour; do not advertise sound, background push or guaranteed real-time delivery without implementing and testing it.
- Generate entrance QR artwork only after the correct deployed URL and location are accepted. This scope does not unlock doors or implement intercom calls.

## Required verification

Backend: disabled and invalid setup; unknown/closed location; input validation; CSRF; rate limiting; duplicate submissions; per-recipient deduplication; delivery failure; restricted arrival/status access; expired tokens; atomic acknowledgement; timezone/hour boundaries.
Frontend: logged-out mobile layout, explicit ring action, pending/error states, no false acknowledgement, staff response and visitor status refresh.
Staging: approved reception test accounts and a logged-out phone, actual selected notification channel, duplicate scans, off-hours and unavailable-staff handling. Run existing regression checks to assess effects on other portals.

## Resolution report

Not resolved. No runtime changes, migrations, visitor data collection, live notifications or configuration changes have been made by this review. This branch contains the review and implementation proposal only; it is not a functional doorbell fix. No runtime tests were claimed for documentation-only changes.

Keep In review until the scope is confirmed, implementation is reviewed and deployed through the normal process, and an approved entrance-to-staff acknowledgement test succeeds. Do not mark Done merely because a QR generator or generic notification system exists.