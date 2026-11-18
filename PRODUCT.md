# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Lu veP employees, their Department Heads, Operations reviewers and authorized HR/Management staff. External clients and agents must not inherit employee portal access.

## Product Purpose

A dedicated HR portal at hr.luvep.com, reusing the main Luvep application's accounts and HR records. Employees manage their own profile, leave and daily time records; HR manages people operations and approvals.

## Operating Context

The user confirmed Department Head leave decisions with HR oversight, seamless sign-in from luvep.com, clock and break controls on both dashboards, explicit return from breaks, and a reminder before scheduled finish. NZ and PH schedules exist in the current application.

## Capabilities and Constraints

Laravel 12, React 19 and Inertia, deployed on Hostinger from ghamaypepito/luvep. Reuse existing DTR and profile features. Employee records and confidential files are restricted by default; the user will refine role permissions later. The overall written design is approved; product implementation has not started. Manager/Admin notifications use in-app and email delivery. Admins can open an audited, read-only employee preview from their avatar menu.

## Brand Commitments

Keep Luvep branding. The user requests a professional corporate HR portal and enhanced existing employee interfaces. Existing staff UI and the supplied green-branded forms are visual evidence.

## Evidence on Hand

Source review at 2bc05a073932d3c32f9e59d4f114c283c1e8f73e; supplied leave forms, leave policy and DTR documents; existing staff profile cards, DTR controllers and layouts. Preview data must be explicitly synthetic. No verified payroll or leave balance ledger is available.

## Product Principles

- Employees see their own authorized information.
- One account and one source of truth across both portals.
- Server-recorded time and decisions remain auditable.
- Daily staff actions take priority over decorative reporting.

## Open Decisions

The expanded HR module scope and visual direction are approved. Exact HR grants, employee roster and reviewer assignments remain to be configured before launch. The implementation plan awaits review and execution-method selection.
