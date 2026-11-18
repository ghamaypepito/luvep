# TKT-WJCKOL — SIT and manually entered agent/referee names

Priority: High. Proposed status: In review, pending review, staging acceptance and deployment.
Branch: `codex/wjckol-student-referral-names`, based on main `eb8802a2`.

## Findings

The screenshot shows the shared Add/Edit Student modal used by Education. Its Referring agent dropdown is populated with actual users whose role is agent. Those IDs are saved in agent_id, which also determines which recruiting agent can see a student. Adding a dummy SIT user just to provide a label would mix attribution with account ownership and is unnecessary.

The existing Lead.referral string already accepts a manual referee name (up to 191 characters). The student modal already seeds this value, includes it in drafts and submits it; create, edit, existing-person search and linking already support it. The missing piece is a visible input. Additionally, the list and detail drawer currently choose agent_name OR referral, hiding the manual name when an account is also linked.

## Proposed change

- Retain the existing account dropdown, labelled Linked recruiting agent, with its existing account values and validation.
- Expose Agent / referee name as an optional text field. Offer SIT as a datalist suggestion and one-click preset, while allowing a manually entered name. Treat SIT as a name here, not a new login or permission grant. If the business later wants a real SIT recruiting account, that is a separate account-management decision.
- Preserve the selected account when choosing SIT or entering/clearing a name. Display both values when they differ; avoid duplicating the same name when both match. Existing account-only and name-only records remain readable.
- Use the existing persistence and validation; no controller, model, database schema, authorisation, agent scoping or email automation changes are needed.
- The shared Students presentation also shows the name on already-authorised read-only recruiting views. Those users still cannot edit, and the agent_id-based record scope is unchanged. Other portal handlers are untouched.

## Validation

Passed **18 backend tests, 214 assertions** across StudentReferralNameTest, StudentMultiSchoolTest, StudentCourseStartTest, StudentOnHoldTest and StudentStaysVisibleUnderImmigrationTest. Checks cover SIT on creation with no new User, custom-name update and clear, preservation of linked account and department data for unchanged fields, existing-person search/linking, length/type validation, denied external/ungranted writes, and agent record scoping even when another agent is named SIT.

The real React modal interaction test passed SIT/manual entry, unchanged agent selection in submitted payloads, validation-error retention, saved-name reopening, clearing, new-student drafts and existing-person linking. Combined name display cases passed. git diff --check passed. The new frontend test is included in the full GitHub validation workflow; its build/regression result is recorded in the PR.

The initial fixtures were corrected to include program_text (which the real form always sends) and explicitly grant the referral_students module to the agent test accounts. Production access rules were not relaxed.

## Staging acceptance

1. Open a synthetic existing student with a linked recruiting agent. Choose SIT, save and reload; verify both values remain visible and the linked account is unchanged.
2. Enter a different referee name, save, close and reopen. Clear it and verify the linked recruiting agent is still selected.
3. Create a test student with SIT and no agent account; confirm no new User or permission grant appears.
4. Link an existing synthetic person and verify their saved name/account are loaded and retained correctly.
5. Verify authorised recruiting-agent read-only views remain scoped to their own students. Another account named SIT must not gain access because the referee text says SIT.
6. Check the form on a narrow viewport, list/drawer display, existing student school/stage operations and requester approval.

No database migration or backfill. Sir Ghamay reviews and deploys through the normal staging/release process; this proposal has not changed production. If integrated with PR #74, retain both its Education stage changes and this referral field in the shared modal.

## Resolution report draft

Issue summary: Education Students needs SIT as an available referrer name and a way to manually enter an agent/referee.

Root cause: The visible selector only lists real recruiting accounts; the existing free-text referral field has no input, and its value is hidden when agent_name is present.

Resolution: Proposed branch adds a SIT name preset and manual referee input using existing storage, and displays account/name together. Linked account ownership remains separate. Pending review, staging acceptance and deployment.

Verification: Automated backend/frontend checks above passed; full CI evidence is attached to the PR. Live requester acceptance is pending.

References: TKT-WJCKOL; branch `codex/wjckol-student-referral-names`.

Follow-up: Sir Ghamay reviews the PR and arranges staging verification with Emma before merge/deployment. Keep High / In review until released and confirmed.

Documentation summary: Visible manual referrer names and SIT preset; existing storage, recruiting-account linkage and access rules preserved.

## Message for Sir Ghamay

Hi Sir, I prepared TKT-WJCKOL (High) on `codex/wjckol-student-referral-names`. The selector currently lists actual agent accounts, although the backend already supports a free-text referee name. This proposal exposes that field, adds a SIT preset and shows both account/name when present, without creating a SIT login or changing linked agent access. The 18 backend tests / 214 assertions and real modal interaction tests passed. No migration is needed. Please review the PR/full CI and have Emma verify it on staging before release.
