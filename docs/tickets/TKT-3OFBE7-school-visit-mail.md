# TKT-3OFBE7: Education school-visit bulk email

Reviewed 16 November 2026 against main 8f15cdfb. Keep In review pending release and acceptance.

## Finding

Education Bulk Mail already exists, but its recipient pool uses Leads and the general Events table (`Lead.event_id`). School visit RSVPs and programme registrations instead reference `education_visits` in independent tables. An unlinked RSVP can have no Lead at all. The general All events picker therefore does not establish an audience for Jose Maria College Foundation, Inc. or Tagum City College of Science and Technology Foundation, Inc.

Sir's merged PR87 explicitly states that the school-visit mailing audience remains separate pending recipient and consent requirements: https://github.com/ghamaypepito/luvep/pull/87 . Its New Leads and template fixes do not implement this audience.

The supplied screenshot also shows a portal-access template with a blank password. It is not evidence that all these registrants have portal accounts. The new flow excludes templates containing account/password or other unsupported placeholders, rather than inventing credentials or converting registrants.

## Proposed implementation

Education > Emails > Bulk Mail gains a School visit bulk email link to `/portal/education/school-mail`. Only active Education staff and Admin/Super Admin can read or queue this audience. Existing general Bulk Mail, Sales, Immigration, SMS and recipient permissions are unchanged.

- Select one or more actual visits from the Education schedule. Same-school activities remain distinct. Both named colleges already appear in the schedule seed, but live rows/counts were not accessed or verified.
- Choose All registered or Recorded attended RSVPs. Registered includes non-cancelled RSVPs and programme registrations with explicit visit links. Recorded attended uses RSVP status only and labels automatic attendance. The existing system automatically marks some past visits attended; this is not independent evidence of physical attendance.
- Choose follow-up or newsletter/promotion. Check the existing corresponding opt-in. Verified RSVP email is required. Programme registration requires recorded consent and the relevant form opt-in or matching non-exited confirmed subscription. Conservative combined records with missing/conflicting preferences remain excluded. No permission override or consent backfill is provided.
- Exclude known bounces/unsubscribe replies consistently with existing Education outreach rules. Deduplicate addresses across selected sources and visits. Different names on the same address require review rather than mixed personalisation.
- Select eligible recipients and a compatible active email template, preview a sample and confirm the recipients/purpose before queueing. Supported variables are displayed. Each recipient receives a private individual message. No extra template To/Cc/Bcc recipients are added.
- Snapshot template content/branding, selected filters, addresses and source references in dedicated Education mailing tables. Never create or modify Leads, statuses or client credentials to send a school email.
- Worker rechecks current sender access, source membership, name/address and email permissions immediately before sending. Changed or ineligible sources are skipped. Template content remains the staff-reviewed snapshot. Visit variables use the first matching source for a deduplicated address, as the preview explains.
- Record per-recipient pending/sending/sent/skipped/failed history with a MessageLog reference. Sent means accepted by the configured mail transport, not guaranteed inbox delivery. Message logs use a raw recipient with no Lead ID to avoid ID collisions with unrelated clients.
- A per-recipient lock and durable pre-send claim prevent ordinary job redelivery from automatically resending. Worker tries=1, timeout=60s, lock=75s (below the default queue retry-after=90s). Production worker/SMTP timeout and a shared lock-capable cache must match these assumptions. SMTP acceptance followed by a lost response cannot provide exactly-once delivery; uncertain sends require review.
- Dispatch only after the mailing transaction commits. Dispatch failures leave pending records recoverable with `php artisan education:dispatch-school-mail --mailing=ID`. This only requeues untouched pending records, never failed/sending/sent recipients. It is an operator recovery command, not an automatically scheduled production action.

## Boundaries and pending choices

The ticket says both registered and attendees. We asked which audience and purpose Emma needs; both explicit choices are available in the proposal pending her confirmation. No real campaign was created or sent. No live recipient list, credentials, queue or mail settings were accessed.

Historic programme registrations without `education_visit_id` remain excluded. Do not guess membership from email domain, similar school/client names or a general event. Confirm/match any affected records separately before data changes. Legacy missing email permissions remain excluded until a valid consent process records them. This proposal does not authorise overriding missing consent.

This first release queues immediate school mailings, not scheduled mailings. Existing general Bulk Mail scheduling remains unchanged. The new page has a separate history and no automatic merge with lead campaign history.

## Verification

Local focused regression run: 40 tests / 256 assertions across the new school mail tests, existing Communication and Sales bulk email, Education public email choices and automatic attendance.

Frontend interaction tests passed for actual SchoolMail and existing Education Compose/Bulk Email components. They cover disabled recipients, sample escaping, explicit review, scoped POST data, and invalidating a selection when audience filters change. New interaction test is included in CI.

No actual SMTP delivery, staging UI, live school counts or real recipient consent was verified. Complete GitHub regression/build results are recorded in the PR, not assumed here.

## Staging / completion checklist

1. Sir reviews the branch, additive two-table migration and queue/cache/SMTP settings. Never merge/deploy as part of this investigation.
2. On isolated staging, use approved Education and denied-role accounts with synthetic visits/registrants and a mail capture transport. Test both schools together, one school, changed preferences, duplicate submissions/jobs and worker/transport failures. Verify the queued jobs start after commit and per-recipient history is correct.
3. Verify existing general Bulk Mail and the other portal email screens still behave correctly. Inspect private recipient access and template sender/reply-to.
4. Emma confirms Registered versus recorded attendance, purpose, approved message/template, expected recipients and any excluded legacy records. Review exact counts on the authorised live screen without sending a campaign as a test.
5. After Sir's deployment, confirm the approved Education workflow and document acceptance before marking Done. Deployment and an actual mailing require their normal authorised process.

## Update for Sir Ghamay

TKT-3OFBE7 is not covered by the general Bulk Mail changes in PR87. I traced the gap to general Lead/Event recipients versus separate Education visit registrations/RSVPs. The proposal adds an Education-only school-visit mailing page with explicit audience/purpose, existing permission checks, review, deduplication and per-recipient delivery history. It preserves the other portals' existing flows. Local backend and frontend checks passed; final CI, staging and Emma's audience/template acceptance remain separate. No real emails were sent.
