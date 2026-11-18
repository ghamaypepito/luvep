# Regression contracts for the current portal

The 54 failures identified during the navigation audit came from tests that retained superseded contracts or invalid fixtures. Repairing them preserves the current application behavior. No runtime controller, policy, service, database schema or permission change is required.

| Area | Baseline failures | Current contract and evidence |
| --- | ---: | --- |
| Communication | 13 | Template library CRUD and bulk selection are shared across staff portals (`051e70a5`, `d9210cb1`). Manual per-lead sending retains department/channel restrictions. Tracker welcome is sent explicitly, never automatically at intake (`d93dee39`). Document rejection uses `document_reupload` (`a90cc5fe`). Migrations install shared templates that fixtures must preserve. |
| Case checklist and data | 17 | Staff case checklists include a required `svf` for known, missing and unknown visas, subject to existing staff visibility controls. Status assertions select the intended document by key. Intake candidates include the explicit assessment link and name-filtered email matches; the newest usable candidate wins, with an unambiguous name-only fallback. A shared inbox alone never establishes identity. |
| Workflow and reporting | 5 | Assessment reuse requires matching full name, protecting family inboxes (`7a1669e5`). Education uses School Enrolment (`b4fe54ae`), and handoff preserves the student flag (`759921b2`). Pure immigration cases stay out of the student register (`bae0c269`). Adviser catalogue CRUD follows the existing expanded policy (`fba4a056`), while advice approval still requires a current licence. |
| Client tracker and documents | 19 | Immigration cases use the authenticated client portal; the public tracker excludes them. File uploads use the private disk. Validation addresses the multipart `files` collection. Catalogued client visa checklists include the universal form, and modern visa items carry separate category fields. Case agreement visibility and staff-view privacy are tested through the authenticated client surface. |

The grouped baseline was reproduced on `f70cb1120d01c81ebc709e5225fe4fd69ef5d2d2` using the affected test files before corrections. The affected cases are corrected without skips or incomplete-test markers; renamed checks describe the current behavior. Additional checks cover public-case denial, private storage, cross-client access, shared-email identity, required forms, deliberate email delivery and unprivileged template/catalogue access.

PR validation now runs the complete PHP suite rather than a selected subset, followed by the existing frontend and PDF worker checks. Production deployment remains unchanged.

## Validation

The complete local PHP suite passes **1,462 tests with 8,463 assertions**, zero failures and zero errors. Existing PHP 8.5 and PHPUnit deprecation notices remain. All ten frontend scripts, the PDF worker build check and the production Vite build pass.

Focused groups passed 66 communication tests, 31 checklist/case-data tests, 41 workflow tests and 41 tracker/privacy tests. The privacy/storage/scope tests also rejected three deliberately introduced local regressions: exposing a case through its public code, storing an upload on the public disk, and selecting another case through a forged client query. Runtime source files were restored byte-for-byte after each check; the final change contains no application behavior edits.

Independent review found no material assertion or permission regression. A minor intake-resolution documentation error was corrected to reflect newest-candidate selection and the existing name-only fallback.
