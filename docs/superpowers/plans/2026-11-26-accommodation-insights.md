# Accommodation Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Deliver the approved Dashboard and Reports design with accurate, traceable Accommodation data.

**Architecture:** Reuse RoomInventory and ReportWeek. Add one Accommodation read model for daily task buckets and month-scoped viewing bookings. Both existing controllers pass this model to shared React insight components; existing weekly-note writes remain unchanged.

**Tech Stack:** Laravel12, React19, Inertia, Tailwind/CSS; no new dependency or schema.

**Spec:** docs/superpowers/specs/2026-11-26-accommodation-insights-design.md

## Global Constraints

- Missing figures remain unknown; current occupancy is not a historical weekly metric.
- Preserve role gates, weekly notes, property/tenant editing and room reconciliation behavior.
- Use the existing font and green-and-white reference composition, responsive keyboard-accessible controls.
- Synthetic records and fake mail only for tests.

## Review Focus

- New Zealand month/week boundaries: daily counts reconcile exactly to period totals.
- Incomplete room coverage: charts and percentages do not imply complete vacancy data.
- Zero data: render0 accurately and offer normal navigation without invented events.
- Viewing cancellation/other departments: exclude unrelated/cancelled bookings.
- Mobile/keyboard: chart alternatives, named controls, usable calendar and no page overflow.

### Task1: Accurate read model

Files: app/Services/Accommodation/AccommodationInsights.php; AccommodationController.php; WeeklyReportController.php; tests/Feature/AccommodationInsightsTest.php.
Consumes RoomInventory::dashboard() and ReportWeek dates/tasks. Produces insights with as_of, period, daily_tasks and calendar month/bookings.

- [ ] Write and run failing tests for department/date scope, cancellation, zero buckets and current inventory agreement.
- [ ] Implement bounded source queries and controller props, retaining existing note contracts and honest errors.
- [ ] Run focused backend regressions and commit.

### Task2: Approved Dashboard and Reports

Files: Dashboard.jsx; Reports.jsx; shared components/accommodation/Insights.jsx and accommodation-insights.css; tests/Frontend/accommodation-insights.cjs.
Consumes existing inventoryStats and task report plus Task1 insights. Produces summary strip, occupancy bars, actual viewing calendar, daily task chart and preserved notes/inventory navigation.

- [ ] Write and run failing mounted React tests for zero/unknown counts, calendar month controls and inventory filtering.
- [ ] Implement reference-led responsive UI with semantic chart alternatives and visible focus.
- [ ] Run React checks/build; inspect desktop/mobile in a real browser and fix material issues.
- [ ] Commit, independently test and separately review exact candidate, then run exact-head CI and normal staging/production release gates.
