# Education Landing Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Publish the approved school-event landing page with real intake, routing, welcome email and staff notifications.

**Architecture:** Add a separate registration model/table, controller and Blade page to the existing Laravel app. Reuse unified lead intake and existing mail logging/queue. Existing login, contact and booking remain authoritative.

**Tech Stack:** Laravel 12, PHP 8.3+, Blade, native browser JavaScript/CSS, MySQL/Redis.

**Spec:** `changes/education-landing/design.md`

## Global Constraints
- Automatic device appearance, white/charcoal surfaces and green accents; official transparent EP logo.
- Philippines defaults but remains editable; school and country are origin information. Destination is New Zealand.
- Preserve returning lead ownership and progress. Never grant portal access from public registration.
- No public internal workflow or demonstration content; no invented messaging service.
- Store each school-event registration separately, idempotent by UUID; validate before persistence and send after commit.

## Review Focus
- Duplicate or concurrent submits must not repeat outreach.
- Existing email must not leak private lead/account data or reset progress.
- Invalid time, missing consent and cross-session preparation updates must fail.
- Failed queued delivery must preserve the registration and be retryable.
- Long names/school/event text must wrap and be escaped in HTML.

### Task 1: Registration and outreach
Files: create `EducationLandingController`, `EducationEventRegistration`, an additive migration, `EducationRegistrationReceived` notification, welcome Blade email, and feature tests.
- [x] Write feature tests for GET, validation, intake, returning lead preservation, idempotency, outreach and session-scoped preparation. Run and observe missing-route failures.
- [x] Implement endpoints and persistence; configure owner via existing settings; queue welcome email with MessageLog, notify staff in-app/email after commit.
- [x] Run focused tests and PHP lint. Inspect resulting rows, queue and URLs.

### Task 2: Approved public interface
Files: create `resources/views/education/landing.blade.php`, `public/education-landing/landing.css`, `landing.js` and approved images.
- [x] Implement the three steps with local validation, loading/error/retry handling and fresh submission UUID.
- [x] Connect success, preparation, login and contact actions to real endpoints/URLs; remove all mock workflow/profile/chat content.
- [x] Verify complete flow locally at desktop/mobile widths and automatic appearance.

### Task 3: Deploy and verify
- [x] Run regression tests and fresh review. Confirm staff recipient choice and configure it.
- [x] Back up affected server files, deploy only this change, run additive migration and clear relevant caches.
- [x] Verify public URL, assets, validation and mail/notification configuration; use synthetic tests without contacting real applicants.
- [x] Return the verified URL and any remaining limitation.

## Verification record

- Focused Education/event tests: 16 tests, 84 assertions passed (PHP 8.5 dependency deprecations remain).
- Vite build passed; deployment feature uses standalone Blade/static assets and does not replace shared bundles.
- Browser completed local registration, persisted follow-up questions, and verified Messenger/email/login destinations.
- Full suite: 1,291 tests, 6,688 assertions, 3 errors and 51 failures outside the Education feature; see baseline-failures below. Existing project documentation already notes legacy test failures.
- Fresh review found concurrent email-limit and queue-recovery gaps; limit check/hit now share the email lock, and a persisted retry scanner plus queued welcome failure callback recover pending outreach.

### Baseline-failures

- Tests\Feature\Track\UploadChecklistTest::test_07_uploading_sets_lead_document_checklist_key_to_the_picked_item
- Tests\Feature\Track\UploadChecklistTest::test_09_staff_approval_flips_checklist_item_to_approved_on_track
- Tests\Feature\Track\UploadChecklistTest::test_14_declined_outcome_appends_alternative_step_to_journey
- Tests\Unit\Services\CaseChecklistServiceTest::test_01_for_case_returns_visa_type_checklist_when_visa_type_matches
- Tests\Unit\Services\CaseChecklistServiceTest::test_02_for_case_returns_empty_when_no_visa_type
- Tests\Unit\Services\CaseChecklistServiceTest::test_03_for_case_returns_empty_when_visa_type_does_not_match
- Tests\Unit\Services\CaseChecklistServiceTest::test_04_for_case_uses_lead_override_when_set
- Tests\Unit\Services\CaseChecklistServiceTest::test_05_for_case_drops_malformed_items_defensively
- Tests\Unit\Services\CaseChecklistServiceTest::test_07_with_statuses_attaches_approved_status_with_upload_timestamp
- Tests\Unit\Services\CaseChecklistServiceTest::test_08_with_statuses_attaches_rejected_status_with_note
- Tests\Unit\Services\CaseChecklistServiceTest::test_09_with_statuses_picks_most_recent_document_per_key
- Tests\Unit\Services\CaseChecklistServiceTest::test_11_grouped_by_category_honours_explicit_category_field
- Tests\Unit\Services\CaseChecklistServiceTest::test_12_progress_counts_required_only
- Tests\Feature\Communication\BulkEmailTest::test_index_lists_scoped_templates_and_recipients
- Tests\Feature\Communication\BulkEmailTest::test_cannot_use_another_departments_template
- Tests\Feature\Communication\DepartmentTemplateTest::test_portal_lists_only_its_own_templates
- Tests\Feature\Communication\DepartmentTemplateTest::test_admin_browsing_a_portal_sees_that_departments_scope
- Tests\Feature\Communication\DepartmentTemplateTest::test_portal_create_forces_own_department
- Tests\Feature\Communication\DepartmentTemplateTest::test_staff_cannot_touch_another_departments_template
- Tests\Feature\Communication\DepartmentTemplateTest::test_immigration_subrole_resolves_to_immigration_department
- Tests\Feature\Communication\LeadMessageTest::test_staff_sees_only_own_and_shared_email_templates
- Tests\Feature\Communication\MailableMigrationTest::test_tracker_welcome_uses_template_when_seeded
- Tests\Feature\Communication\MailableMigrationTest::test_falls_back_to_legacy_mailable_when_no_template
- Tests\Feature\Communication\MailableMigrationTest::test_doc_rejected_template_includes_reason
- Tests\Feature\Communication\MessageTemplateAdminTest::test_admin_can_list_templates
- Tests\Feature\Education\EducationReportTest::test_english_and_immigration_students_are_tracked_under_their_own_statuses
- Tests\Feature\Immigration\AdviceBearingLicenceTest::test_adviser_cannot_edit_visa_type_prices
- Tests\Feature\Immigration\AssessmentPipelineTest::test_14_convert_finds_existing_lead_by_email
- Tests\Feature\Immigration\AttentionTest::test_views_never_reach_a_client_facing_payload
- Tests\Feature\Immigration\CaseDocumentsTabTest::test_documents_tab_renders_checklist_for_known_visa_type
- Tests\Feature\Immigration\CaseDocumentsTabTest::test_documents_tab_shows_fallback_when_visa_type_unknown
- Tests\Feature\Immigration\CaseDocumentsTabTest::test_documents_tab_progress_counts_required_items_only
- Tests\Feature\Immigration\CaseProfileDataLoadingTest::test_assessment_converted_case_loads_intake_data
- Tests\Feature\Immigration\CaseProfileDataLoadingTest::test_work_intake_resolves_with_correct_type
- Tests\Feature\Immigration\CaseProfileDataLoadingTest::test_checklist_loads_from_visa_type
- Tests\Feature\Immigration\CaseProfileDataLoadingTest::test_checklist_falls_back_to_empty_when_no_visa_type_match
- Tests\Feature\Leads\DepartmentStageTest::test_education_stage_update_targets_the_right_column
- Tests\Feature\Leads\DepartmentStageTest::test_education_handoff_stage_auto_promotes_to_immigration
- Tests\Feature\Track\TrackerDocumentTest::test_upload_respects_mime_validation
- Tests\Feature\Track\TrackerDocumentTest::test_replace_deletes_old_file_and_notifies
- Tests\Feature\Track\TrackerEmailTest::test_lead_creation_with_email_queues_tracker_welcome
- Tests\Feature\Track\UploadChecklistTest::test_04_track_payload_carries_full_visa_checklist_for_immigration_case
- Tests\Feature\Track\UploadChecklistTest::test_04b_each_checklist_item_carries_the_shape_the_picker_consumes
- Tests\Feature\Track\UploadChecklistTest::test_05_non_immigration_lead_gets_visa_null_so_modal_falls_back_to_doc_types
- Tests\Feature\Track\UploadChecklistTest::test_06b_immigration_lead_with_unknown_visa_type_still_renders_with_empty_checklist
- Tests\Feature\Track\UploadChecklistTest::test_08_uploaded_doc_flips_matching_checklist_item_to_submitted
- Tests\Feature\Track\UploadChecklistTest::test_08b_upload_to_unknown_checklist_key_does_not_flip_any_item
- Tests\Feature\Track\UploadChecklistTest::test_10_track_page_renders_with_tabbed_layout_component
- Tests\Feature\Track\UploadChecklistTest::test_11_track_page_payload_carries_data_for_every_tab
- Tests\Feature\Track\UploadChecklistTest::test_12_journey_carries_completed_current_and_pending_steps
- Tests\Feature\Track\UploadChecklistTest::test_13_journey_first_step_is_current_when_nothing_started
- Tests\Feature\Tracker\AgreementSigningTest::test_agreement_does_not_show_on_tracker_when_draft
- Tests\Feature\Tracker\AgreementSigningTest::test_agreement_shows_on_tracker_when_sent
- Tests\Feature\Tracker\AgreementSigningTest::test_agreement_does_not_show_when_voided

## Deployment result

Published at https://luvep.com/education/register. Production uses Emma Ceballo as the owner for new leads and both Emma and Dinah Suarin as recipients; returning ownership is preserved. A production transaction verified assignment, both notifications, welcome queue/log, and country; all QA rows were rolled back and mail/notifications were faked. A targeted deploy-user cron runs only the Education retry command every minute. Existing live route changes were preserved by an insertion hook and backed up before installation.
