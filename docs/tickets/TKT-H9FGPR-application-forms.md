# TKT-H9FGPR - Education Application Forms

## Findings
Reviewed fetched main 6dbf7b3d and open PRs on 17 November 2026. The Education profile academic section has CV, offers, certificates, transcripts, English results, intent and tuition proof, but no Application Forms row. Existing staff checklist uploads already support arbitrary stable keys and multiple files; no new storage or route is required.

## Resolution
Add Application Forms under Offer and Academic Documents in Education student profile Documents, using stable key acad.application_forms and existing ordinary upload/view/download/review/thread controls. Display the row for the Education portal, including Education staff using the existing admin profile route. Preserve it in the Education view of students also handed to Immigration. Append through a pure Education-only section adapter without changing the shared catalogue or its objects, existing key/order, other portal views, client mandatory requirements, Immigration visa checklists or section order.

No database migration, backend application change, permission change, client notification or automatic document move is introduced. Repeated uploads retain individual files and original filenames through the existing upload flow. Historical unclassified files/custom rows are not guessed or reassigned. This adds a staff filing category; it does not create a new compulsory client requirement or automatically classify previously uploaded application forms.

## Verification
15 backend tests / 104 assertions passed across ApplicationFormDocumentsTest, EducationChecklistTest, CustomDocumentTest and InternalNoteProgramsTest. New verification covers repeated actual application-form uploads, profile retrieval under the literal checklist key, private download, retained approved transcript metadata/bytes, unchanged required tracker keys and client owner versus unrelated-client denial. New catalogue checks verify correct section/ordinary row, preserved existing keys/order, no shared mutation, exact non-Education catalogue preservation and no duplicate rows. Included in CI. Full CI outcome is recorded in the PR/handover report.

## Acceptance
Keep In review until Sir reviews/merges/deploys and Emma confirms the row works. On staging with a synthetic student and Education test account, open Students > profile > Documents > Offer and Academic Documents, upload two Application Forms and confirm view/download and existing document controls. Check another portal's checklist remains unchanged. No live client records were altered. TKT-BR9LRC remains separately paused for Eireen's GST clarification.
