# Staff note and client conversation posting repair

## Report and diagnosis

The team reported being unable to post notes or client messages; Nova was the latest affected staff member. Read-only production checks confirmed that the active staff account matching Nova has the `immigration_manager` role. That role's unconverted Leads pipeline was affected by both the missing note route and the narrower discussion permission check. No account roles or grants were changed.

Reproduced defects:

- Immigration and adviser lead-list note forms POST to portal-relative `/leads/{id}/notes` URLs that were not registered.
- Existing internal-note authorization allowed Education's lists but rejected Immigration's shared Leads and Students lists. Client conversation authorization also rejected Education's unconverted leads and ignored explicitly granted staff portals.
- The template message panel submits a selected template without custom body/subject fields; the endpoint incorrectly required those fields, then re-resolved the selected template by key instead of retaining its exact identity. The picker also offered templates outside the permitted department/channel, and missing recipient details could yield a false success.
- Assessment notes and the shared JSON message helper used a stale HTML CSRF token alongside the current cookie. Laravel prioritizes the stale header. HTTP and network failures were silently swallowed.
- The shared thread Reply button could not open a reply composer for case, stage, gate or step threads without a caller-supplied anchor. Document fallback merely focused a new root composer rather than creating a child reply.
- Conversation load failures appeared as “No messages yet”, failed mailbox sync could claim success, and email queue failures could be reported as sent. Inbox failures also risked clearing the draft and marking the client answered.

## Scope

`LeadDiscussionAccess` governs only internal notes and individual conversation/compose endpoints. It uses existing portal access and the same record scopes as the portal lists. The older `LeadAccess` contract for conversions and bulk communication remains unchanged.

| Team role / surface | Discussion scope |
| --- | --- |
| Admin and Super Admin | All existing client records |
| Sales | Unconverted Leads pipeline |
| Education | Leads pipeline and shared Students register |
| Immigration, Manager and Adviser | Leads pipeline, shared Students register and immigration cases |
| English | English learners |
| Accommodation | Accommodation clients |
| Finance / other internal staff with additional portal grants | Records covered by their already granted supported client portals |
| Agent and Sub-agent internal notes | Their own referrals / parent agent referrals; existing scoped routes remain enforced |
| External client and revoked client | No staff notebook or staff conversation access, even with stray portal grants |

Internal notes remain team-only. Client emails require a deliberate send. Case-thread replies inherit the existing thread audience and anchor; a reply does not turn an internal discussion into a client-visible one. Other department records remain denied. Licence-gated immigration approval, conversion permissions, task participant checks and ticket ownership rules are unchanged.

The wider audit checked proposal notes, agreement notes, document discussions, assessment notes, accommodation notes, task comments and ticket conversations. Existing task/ticket ownership restrictions were confirmed as intentional. Finance task comments are available to authorized participants. No test messages were sent to clients and no client records were changed during testing.

## Validation

- Original defects reproduced with failing synthetic tests before fixes.
- Complete PHP suite: **1,544 tests, 9,004 assertions; zero failures or errors**. Existing PHP 8.5 and PHPUnit deprecation notices remain.
- All 12 frontend CJS scripts passed, plus the task-comment, case-thread reply and PDF worker Node tests.
- New coverage includes the role/portal/record matrix, blocked cross-department and external access, exact selected templates and requested channels, failed email queue handling, rotated CSRF tokens, visible errors, draft retention and true threaded replies for every supported anchor.
- Production frontend build passed. Existing large-chunk advisory remains.
- PR CI now runs the new frontend regression checks as well as the complete PHP suite.
