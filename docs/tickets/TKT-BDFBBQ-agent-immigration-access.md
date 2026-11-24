# TKT-BDFBBQ: ANGELIKA - Tarun as Agent and Immigration

Reviewed 16 November 2026 against main 0549d9cc.
Status: In review / administrator duty and account confirmation needed.

## Finding

The original request is only Tarun As Agent and Immigration. It does not specify whether Tarun should work his own referred cases, internally assigned cases or every Immigration case. The supplied October support guide explicitly leaves that approval unresolved. No live Tarun account, grants, records or credentials were inspected or changed, so this review cannot confirm his current setup or close the ticket.

Existing mechanisms:
- User has one primary role. portal_access can grant extra department entry without changing that role. User::canAccessPortal and the portal switcher support such grants.
- Module Management is super-admin gated. Its module grant and extra-portal grant are separate controls.
- AgentController::cases scopes the canonical Cases query to agent_id=current agent. The existing referral_cases module exposes the Agent Cases workspace.
- Agent case routes combine portal:agent, module:referral_cases and lead.scope. EnsureLeadInPortalScope checks the referred owner on each record route. A single-file download wrapper separately checks the document's lead owner.
- This module includes permitted case edits, documents and other management routes. Some actions retain staff/licence requirements. It is not merely a read-only list.
- An extra Immigration portal grant is not an own-referral or assigned-only permission set. The ordinary Immigration Cases entry calls the department cases payload without the agent ownership closure. Do not use the extra portal as a shortcut when the approved duty is own referrals only.
- Agent ownership (lead.agent_id), internal ownership/assignment and a displayed assignee name are different concepts. An assignment label does not itself establish the intended permission policy.

## Reproduced disclosure issue and narrow proposed branch

The Module Management referral_cases description says Read-only while existing routes allow management actions. A synthetic integration test confirms that an enabled agent can update priority on their own case. The displayed description therefore understates what a grant permits.

The branch corrects only that permission description and adds meaningful regression tests of the existing referral boundary. No role, grant, scope, route or licence gate changes. It does not implement dual-role identity or automatically enable Tarun's account.

## Verification

19 focused backend tests / 159 assertions passed across ReferralCaseAccessTest, existing ModuleManagementTest, AdminAssignLeadAgentTest and DependentDisplayNameTest.

Verified with synthetic users/cases/files:
- Granted agent sees only own referred cases and can open the owned profile.
- An existing permitted priority edit succeeds on the owned case and fails on another agent's case.
- Private file download succeeds for the owner and fails for an unrelated agent.
- Missing module denies case list/profile/write and the default Agent role cannot enter Immigration Cases.
- Agent cannot self-grant the module.
- A super admin can grant the existing module without changing the primary Agent role or granting the Immigration portal.
- Existing dependent-link isolation, referral-agent assignment validation and Module Management access checks pass.

Mail/notifications were faked and unexpected HTTP blocked. No real permission was granted, no live record was tested, and no external message was sent. Full CI results are recorded in the PR after execution. Test results establish source behaviour under synthetic conditions, not that Tarun has the correct live grants.

## Proposed resolution by approved duty

Own referred cases: the existing Agent role plus explicitly approved referral_cases module may be sufficient. Keep the department-wide Immigration portal separate. An authorised super admin verifies the correct account and agent_id ownership, then performs staged owner/unrelated-record acceptance checks before applying any live grant.

Internally assigned cases only: first define the assignment relationship and required operations. The own-referrals module is not equivalent to internal assigned-case access. A My queue display/filter is not proof of server-enforced assigned-only permissions. Implement an explicit scoped policy only after the requirement is agreed.

All Immigration cases as internal staff: require explicit administrator approval of internal duties and breadth of access. Verify the appropriate staff role, referral attribution and advice-bearing requirements. Do not change the role to Admin or add broad portal access merely to make screens appear.

No option has been selected or applied from this review. Preserve existing referral links, documents, audit and financial history when evaluating any later account change.

## Completion evidence

1. Administrator confirms the exact account privately, approved duties, read/write actions and record scope.
2. Select the appropriate existing configuration or approve a specific new permission policy. The description correction alone does not fulfil a requested account change.
3. On isolated staging, test the intended records and operations plus unrelated records, direct URLs, writes and private downloads under the correct account/role. Confirm denied actions and licence gates remain effective.
4. An authorised operator applies only the approved live configuration and records its approval. Obtain acceptance from the current business owner before marking Done.

If the old request is no longer required, record an explicit business cancellation instead of claiming a technical fix. Keep In review while duties/current access are unknown.

## Suggested message for Sir Ghamay

I reviewed TKT-BDFBBQ. The system already supports an Agent Cases module for the agent's own referrals, with permitted case-management actions. It also supports extra department portal grants, but these are a different and broader permission decision. I have not checked or changed Tarun's live account. I prepared a small correction because the module description incorrectly says Read-only, plus tests confirming own-referral access and unrelated-record denial. Please confirm whether Tarun needs own referred cases, internally assigned cases or all Immigration cases, and which actions are approved. The ticket should remain In review until the correct configuration and acceptance are confirmed.
