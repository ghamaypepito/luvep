# RSVP editor verification

Focused suite: 46 tests / 277 assertions passed, four existing PHP8.5 PDO deprecations. Full suite:1321 tests /6881 assertions, 3 errors and51 failures; all54 names match the prior release baseline. No new full-suite failure names.

Local browser verified: text/photo section publish, image upload preview, Add schedule for a new school, new school option in registration, automatic matching date/time and advance to applicant details. Desktop editor inspected; mobile override returned desktop dimensions (1280px), so no claim of real mobile browser verification. Responsive CSS/source and mechanical detector checked; detector returned no findings.

Independent review found visit_id=0 could skip lookup and produce500. Reproduced RED, added min:1 plus regression, confirmed GREEN422 with zero leads, and reviewer confirmed resolution.

## Existing full-suite failures/errors

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

## Production release
Code `2420161` deployed as17 scoped files after live-hash preflight, preserving route customizations. Backup `/home/deploy/education-rsvp-editor-backups/20261025-073528` includes source and the previous registration table schema. Ran only migration `2026_10_25_070000_link_education_registrations_to_visits`; no event seed, existing schedule, staff-recipient or cron changes. Dedicated media folders use the existing www-data worker group; configured server limits already support5MB photos.

Rolled-back live checks passed: content image upload served200; edited content rendered; content changes created no attendee notices; new schedule appeared in registration's school options; selected-event snapshot matched trusted catalogue data; TBC registration stored no invented date/time; replay survived event cancellation; new lead assigned Emma (id2); recipients remained ids2/7; verified RSVP journey linked. Mail, queue and notifications faked, unexpected HTTP prevented except a separate public image GET. Original content restored, temporary media and all verification event/RSVP/lead records removed. Existing user records untouched.

Live manager refreshed without discarding unsaved edits. Proof screenshot `education-rsvp-preview/live-content-editor.png` contains only the editor, excluding private attendance data. Draft PR15 retains the release and existing full-site source-drift caution.
