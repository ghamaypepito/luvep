# RSVP editor implementation plan

Spec: docs/superpowers/specs/2026-10-25-rsvp-content-manager.md
Execution: native in existing isolated Education branch, under the user's ongoing implementation authorization. Preserve draft PR15 and targeted deployments.

## Review focus
- Whitelisted plain text and upload-only image paths; escaped output; independent section writes never overwrite unrelated sections.
- New schedules create distinct visits and do not overwrite existing history or send false notices.
- Managed programme event selection is server-authoritative; TBC is not a fabricated date; UUID replay and legacy submissions remain valid.
- Content updates do not touch schedule revisions/outreach; preserve media when no upload occurs or another field fails validation.
- Staff roles stay scoped; Slack secrets never enter public content or previews.

## Tasks
1. Content service and guarded endpoints. Files: Services/EducationRsvpContent.php, admin/public controllers, public view, content route; Feature/EducationRsvpContentTest.php. Interfaces: defaults/schema/get/section saving. Per-section app_settings JSON, one public upload per save, audit and preserved fallback. Prove failing tests then implement.
2. Shared schedule create/edit and registration matching. Files: admin controller/routes; new single migration for registration FK/nullable snapshots; EducationLandingController, EducationEventRegistration, landing-form and JS; Feature/EducationManagedScheduleTest.php. Interfaces: POST new visit, public school→schedule selection, managed visit_id maps to trusted snapshot. Test new/same school, confirmed/TBC/cancelled, tampering and legacy regressions.
3. Manager layout. Files: rsvp-admin Blade, new scoped admin CSS/JS. Organize top-to-bottom, section image previews and text forms, Add schedule, complete school editors, attendance/filtering, delivery last. Keep role gates and old routes. Preserve field values/open section on validation, accessible controls and automatic theme.
4. Verify and release. Run focused Education/event regressions, JS/PHP checks, mechanical design scan and bounded browser inspection. Fresh independent review, fix material findings. Commit/push, update PR15, back up live files, apply only new migration, targeted patch/deploy, verify rollback-only managed registration/content upload and public/staff routes, show screenshot and live URLs.

## Completed
All four tasks implemented and verified. See 2026-10-25-rsvp-editor-verification.md for the focused/full-suite comparison, independent review resolution, browser checks, scoped migration/deployment and rollback-only production results.
