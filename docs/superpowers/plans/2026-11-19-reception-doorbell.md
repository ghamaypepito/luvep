# Reception Doorbell Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for this approved scope.

**Goal:** Let a visitor scan the Reception QR and explicitly alert active Immigration managers in-app.

**Architecture:** Extend existing settings and Laravel database notifications. Reuse qrcode.react for the staff sign; no new database table or dependency.

**Tech Stack:** Laravel, Inertia, React, qrcode.react.

**Spec:** docs/superpowers/specs/2026-11-19-reception-doorbell-design.md

## Global Constraints

No visitor details or email. Existing manager/admin authorization. Setup and GET never ring. Office confirms entrance before printing.

## Review Focus

Invalid/disabled links; unauthorized setup; no active managers; concurrent repeated scans; partial notification failure.

## Task: Existing reception notification flow

- [ ] Write failing ReceptionDoorbellTest covering access, explicit rings, recipients, cooldown, empty recipient set and failed notification rollback.
- [ ] Add ReceptionDoorbellController, ReceptionDoorbellRang database notification and public/staff routes.
- [ ] Add accessible public ring page and staff QR/download page; link staff setup only for managers/admins.
- [ ] Run focused PHP checks, actual React interaction checks and build.
- [ ] Obtain independent testing and separate review, release through CI, verify live pages without sending a real ring, then save the ticket resolution.
