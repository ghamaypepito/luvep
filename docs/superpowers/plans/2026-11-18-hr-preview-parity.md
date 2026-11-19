# HR approved preview parity Implementation Plan

> Use superpowers:subagent-driven-development and independent code plus screenshot review before merge.

**Goal:** Faithfully port the approved HR preview into the live HR frontend with actual data and all existing functions.
**Architecture:** Dashboard owns the preview overview composition. A persistent clock provider reuses existing clock behavior. A shared team reader avoids duplicate authorization/query logic. Layout owns primary navigation/section tabs; existing feature pages remain functional underneath.
**Tech Stack:** Laravel, Inertia React, existing CSS/Urbanist/Lucide/hooks, PHP/JS checks, CUA browser screenshots.
**Spec:** docs/superpowers/specs/2026-11-18-hr-preview-parity.md

## Global Constraints
HR only. Approved prototype is visual authority. No invented staff/clock/leave data, privilege grants, migrations or production test writes. Preserve every added HR feature and non-HR clock callers. Actual auth and data may differ from sample preview, not layout fidelity. User explicitly authorized testing/review followed by deployment in this request.

## Review Focus
Missing preview structure hidden by similar colors; lost links/actions in regrouping; duplicate clock sessions/reminders or stale transition handling; Head/Admin privacy scope; mobile/keyboard overflow; fake or timezone-shifted counts.

## Tasks
- [ ] Backend: extend DashboardController ownSummary week_reports/pending_changes/week_from/week_to; overview {mode,timezone,today,server_now}; teamOverview {members,summary,reviews,asOf}|null. Reuse/extract TeamWorkspace reader for TeamController + Dashboard, preserving scopes. Existing profile/upcomingLeave retained. Own PHP services/controllers and tests; shared HrContext additions root.
- [ ] Overview + clock: Dashboard.jsx faithful preview employee/Admin/Head composition, calendar/leave/Head rail; ClockWidget Hero and persistent exported HrClockProvider using existing hook, modal and reminder semantics, standalone other portals preserved. Own overview components/provider/clock and frontend tests. Root owns CSS/layout; coordinate class names and geometry.
- [ ] Shell/navigation: port prototype top strip/sidebar bottom identity/primary links, grouped work/information/team tabs, preserve advanced tools/settings and detail headings/actions. Remove duplicate per-page MyInfoTabs in production through consistent layout ownership (update tests). Root owns layout/shared props/navigation/CSS/page navigation adjustments.
- [ ] Verify smallest HR/legacy/frontend checks and build; synthetic local browser full flows. Compare same-size source and implementation screenshots for Garden/Split employee/admin, DTR and My Info; independent visual and code review; CI, merge/deploy, live read-only verification.
