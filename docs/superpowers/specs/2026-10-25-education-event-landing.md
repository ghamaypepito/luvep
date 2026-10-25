# Approved Education landing page

The user approved the interactive sample after reviewing the journey, images, spacing, automatic appearance, EP logo, welcome email and next actions, and explicitly requested implementation and deployment on 25 October 2026.

Public URL: `/education/register` within the existing Laravel portal. Reuse the approved white/charcoal surfaces with green accents, EP mark without a backing, campus/student photos, and responsive spacing. No staff workflow, example accounts, demo chat or review labels appear publicly.

Journey: goal → event name/date/12-hour time/AM-PM/time zone, origin country (editable default Philippines), current school/university, study timeline and education level → first/last name, email, phone and contact consent. Destination is New Zealand. Collect actual input; do not prefill fictional applicant/event details.

Each registration is stored separately from its lead to retain multiple school visits. Reuse case-insensitive LeadIntakeService matching; preserve existing owner/status/event history, do not silently convert applicants into students. A configured Education owner receives new leads; notify the configured recipients. Typed event names remain unverified and are reviewed by the Education team.

Welcome email: branded student banner, submitted event details, login/profile, public website and contact links. Use existing queue and message logs. Staff get a portal notification plus email linking to the Education lead. Duplicate submission UUIDs must not duplicate leads, registration rows, emails or alerts. Persist lead before sending. Consent, validation, CSRF, rate limits and a honeypot guard the public endpoint. Escape all user input in email and staff views.

Success: next-step action, adviser contact and normal account login. Next-step questions persist to the submitting session's registration and staff lead note. Login uses the existing approval/invitation flow. Contact uses the configured monitored inbox, phone and Messenger rather than an invented live chat system.

Approach: use a focused Laravel controller, registration model/table and queued notification delivery, with a standalone Blade page plus scoped static CSS/JavaScript. This avoids changing shared React bootstrapping or deploying unrelated work. Native public routes and authentication stay within portal capabilities. A separate hosted mockup would not satisfy the requested backend integration.

Confirmed routing: assign new leads to Emma Ceballo (live user 2), notify Emma and Dinah Suarin (live user 7); preserve returning lead ownership. Deployment uses the existing Luvep VPS, with a source backup and additive migration.
