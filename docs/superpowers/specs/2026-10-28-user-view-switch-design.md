# Super Admin user-view switch — approved direction

Purpose: reproduce what a particular Luvep user can see, including that user's menus, module grants, record scope, and approval-button visibility.

## Approaches

1. **Read-only user preview (not selected).** Uses the selected user's actual authorization on page reads. Blocks submissions, approvals, uploads, and messages. The real signed-in identity stays the Super Admin. This fits the requested troubleshooting use.
2. **Act as user (selected by user).** Allows real actions with an audit trail that names the Super Admin and effective user.

## Proposed interface

A **View as user** button in the portal's top bar next to the account controls. It opens a searchable picker showing active users' names and roles. While viewing a user, show a persistent **Viewing Dinah Suarin · Education · Live actions** banner with **Switch user** and **Return to Super Admin** controls. On narrow screens retain a compact labeled control. Explain that preview applies to the current browser session, including other tabs.

## Behavior and constraints

- Super Admin only; use existing user accounts and grants without changing them.
- Preview the selected user's role, module access, and record scope on each request.
- Keep the original authenticated session identity, with a request-scoped preview identity only.
- Recheck both the Super Admin and target account on every request. End preview if either loses eligibility.
- Allow real actions using only the selected user's existing permissions. Record attempted actions before execution and their response status afterwards; exclude request contents/secrets. Attach both identities to existing activity logs. Block authentication changes and nested previews; require the current view token on writes to prevent stale tabs acting as the wrong person. Keep exit available on denied pages.
- Record start, switch, and exit in the existing activity log, attributed to the Super Admin.
- Do not nest with the existing HR/client previews. Coordinate entry/exit explicitly.
- No password collection, permanent login impersonation, or permission changes.

## Verification

Test Super Admin-only entry/search, exact Education module grants, denied routes, client/agent record boundaries, allowed authorized actions, denied unauthorized actions and stale-tab writes, actor preservation, exit, account revocation, and existing HR-preview compatibility. Verify picker, banner, switching, and exit in the browser without submitting real client actions.

Status: user selected real actions with an audit trail on 28 October 2026. Dinah was subsequently changed to Admin by the user; preserve that role. Approval shortcuts must use module grants rather than Education role alone.
