# SDD ledger — plan: docs/superpowers/plans/2026-11-02-add-client-pathway.md

- Approved design: external docs/superpowers/specs/2026-11-02-add-client-pathway-design.md.
- Baseline: 9206ab58, latest existing committed application source. Clean existing isolated worktree reused on codex/add-client-pathway.
- Execution: inline; user approved implementation. No further approval needed for the reversible changes authorized by that approval.
- Pre-flight: Task 2 consumes GET/POST and metadata defined in Task 1. Task 3 verifies both. Interfaces agree.
- Ruling: Education uses a shared assigned_to owner, not a dedicated adviser field. Do not offer an Education adviser selector, because changing it would overwrite the source department's owner. English and Immigration use their existing destination-specific assignee lists.

- Task 1: complete. New route tests first failed with 404; 15 focused backend tests now pass, including replay safety, audit-failure rollback, team notification deduplication, and existing configured automation. Added the actual Education list assertion to the ticket scenario.
- Task 2: complete. Browser tests first failed with the missing component, then exposed focus restoration and end-of-dialog tab behavior. Both fixes are covered; seven Chrome interaction/layout tests pass with production CSS at 1280px and 390px.
- Regression verification: 42 backend tests / 330 assertions passed before the additional Education-list assertion; final verification follows review.
- Production asset build passed. PHP formatting passed. The design detector found no warnings in the new component; warnings in existing large profile components are unchanged incumbent styles outside this feature.
- Ruling: Active pathways in another department show progress without a navigation link for users who cannot enter that department. This preserves portal permissions; those users cannot open the other team's portal directly.
- Ruling: Initialization uses saveQuietly plus explicit history, staff activity, and semantic audit. A handoff must not trigger visa-stage emails or infer a fresh lodgement date. Existing configured Immigration conversion automation runs once after commit.
- Limitation: row locking is implemented for production MySQL, but local automated transaction tests use SQLite and do not prove simultaneous MySQL requests. Replay tests verify the idempotent branch.

- Final independent review: no Critical, Important, or Minor actionable findings; UI disposition pass at desktop and 390px. The reviewer read the code/diff and supplied evidence; it did not rerun tests. No findings were deferred.
- Final full-suite verification: `/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=1024M vendor/bin/phpunit --no-progress` completed in 3m55s: 1,576 tests, 9,288 assertions, zero failures/errors; three PHPUnit deprecations. The first full run stopped at PHP's default 128MB limit in DomPDF; the successful run peaked at 364MB. No product memory setting changed.
- Final production build: passed after the header action group was bounded to its available width; Vite retained its existing large-chunk advisory.
- Formatting: Pint passed on the three added PHP files (the Education-list assertion received only chain-indentation formatting after review). `git diff --check` passed.
- Browser evidence: seven Chrome checks passed using synthetic responses and the production stylesheet. Screenshots: /tmp/client-pathway-screens/add-pathway-1280.png and /tmp/client-pathway-screens/add-pathway-390.png. The browser tests isolate the real component and substitute Inertia transport. Backend tests exercise real route, authorization, transaction, persisted records, notifications, and the Education list response. A live authenticated browser-to-production handoff was not performed.
- Final review boundaries: complete existing profile-header composition was not screenshot-reviewed; only the added dialog was captured. Existing profile layout is preserved, with header actions allowed to wrap within available width. Existing database-notification links retain their permitted admin-profile chrome.
- Ruling tradeoffs: Education has no separate adviser selector because its owner is shared; this version cannot assign an independent Education owner. Cross-department active pathways show progress without an unauthorized portal link, so a source-team user needs the destination team to open its portal. Quiet initialization prevents visa-stage effects; future observer changes must be evaluated explicitly for this pathway entry point.
- Task 3: complete. Keep the branch and existing isolated worktree for review. No push, merge, deployment, new portal credentials, or real duplicate reconciliation was performed.

- Release validation caught an administrator Open Immigration pathway URL using an unregistered /admin/immigration/cases prefix. Added test_admin_can_open_the_existing_immigration_pathway_using_a_real_registered_route, observed 404, then changed URL generation to the registered portal.immigration.cases.profile named route. Admins have access to this portal. Focused pathway/access tests verify the fix; the release CI reruns on the new commit.
- Production release is authorized by the user's explicit Deploy, verify, and resolve response. PR: https://github.com/ghamaypepito/luvep/pull/31.
