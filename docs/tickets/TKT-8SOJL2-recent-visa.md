# TKT-8SOJL2 — Recent visa / eVisa upload category

Staff can choose **Add Recent visa / eVisa category** on a case’s Documents tab when the checklist has no such row. The control explains that the category is optional and visible to this client. It creates an empty upload target on that case only, without selecting or moving an existing file. The authenticated client Documents screen can then accept an upload against that row. Public Immigration tracking codes continue to deny case data and uploads.

The existing **Move to Recent visa / eVisa** action remains available when staff have separately identified a file’s purpose. Creating the requested category does not require guessing which file belongs there. Use Recent visa / eVisa rather than the superseded Visa History label.

## Behavior and access

The category action uses the same active internal Immigration role guard and locked case record as classification. Creation and its `case.document.category_added` audit event are idempotent. Existing rows, including hidden rows, are unchanged on retry. Existing checklist planning notes and resolved catalogue items are retained. The action changes only the case checklist; other cases, the global visa catalogue and required-document progress are unchanged. No email or notification is sent by category creation.

Creation shares its row-building logic with Move. Move retains its original hidden-by-default behavior and existing hidden-file visibility guard. The explicit empty-category action creates a visible row. If a missing category already has `recent_visa` files or a hidden visibility setting, staff review is required before adding the visible target, so existing files are not inadvertently published. Document approvals, filenames, paths, notes and review metadata are untouched.

## Verification

`RecentVisaCategoryTest` exercises the empty row, authenticated client visibility/upload, unchanged files/catalogue/other cases/required progress, audit idempotence, hidden-row retry, existing-file/visibility safeguards and denied roles. The focused category, classification, visa-progress, alias and client-upload suites pass **41 tests /750 assertions**. The original classification privacy tests remain required.

`tests/Frontend/recent-visa-category.cjs` exercises both the action component and the real Documents tab wiring: clear visibility copy, empty request, duplicate-submit guard, error/retry, refreshing/removing the control, empty checklists and opening the new category. `tests/Frontend/recent-visa.cjs` protects the existing Move dialog. The Vite production build, PHP syntax, new PHP-file style and diff checks pass. Test databases/files and email are synthetic; no production record was modified by implementation.

## Release and ticket disposition

Independent testing and review, required CI and deployed UI verification must pass before closure. For the reported case, authorised staff can create and reopen the empty Recent visa / eVisa category through the normal Documents control. Verify it is the optional client upload target and that existing document metadata and visibility remain unchanged. Category creation alone does not classify an existing file or confirm its content. Record the actual deployed revision and observed category result in the private ticket resolution; keep client links and file evidence there.
