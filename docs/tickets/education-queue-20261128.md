# Education ticket review — 28 November 2026

Current main base: `b4c7e245`. Kent PR224 (`e2427900`) and PR231 (`694ce37c`) merge cleanly. Existing owners are retained. The original checkout is untouched; tests use isolated synthetic data and fake mail. No real email, client merge, document approval, signature or permission changes are authorized by this pass.

## TKT-S6L24N — English Archive/Restore

The original attachment shows Education → Students → English and asks for Archive under Actions. PR224 adds a confirmed archive action, a searchable Archived English students page and Restore. The queue-specific timestamp hides the student from the Education English queue/count/search while preserving lead data, Education/Immigration membership, other portals, files, stages and activity timestamps. Updates use the existing record lock and audit log; repeats are idempotent. Only active Education/Admin sessions can manage it; extra portal access and View-as do not grant write rights.

Independent review/testing reproduced a rollout issue: code is deployed before migrations, so an English-only student disappeared when the new marker column was missing. The controller now checks schema availability before adding the archive predicate, disables archive management until installed, and returns controlled503 responses on new archive endpoints. Existing Students remains available. A regression runs the real migration down/up around mixed and English-only fixtures, confirms both stay visible, and verifies no write/audit occurs before installation.

One additive migration creates an indexed nullable timestamp on leads. It adds no varchar and performs no historical backfill. The standard down migration removes the queue marker; ordinary code rollback need not run down or delete client records. Archive/Restore tests must remain synthetic; no existing production student is to be archived for verification.

## TKT-RDXLQU — cards before notes follow-up

The16-card order was previously fixed and released. PR231 moves the main Education section directly below report date controls/error messages and above Weekly notes and My Education accomplishments. The card order, totals, contact/internal-note sources, unknown arrival handling, supplemental drilldowns and filters are unchanged. Personal accomplishment totals still precede their recording form. Earlier resolution evidence is preserved while this follow-up is In Progress.

## Cases for requester review

The original requests, attachments and full conversations were reread; no new requester evidence exists since26November. Four tickets retain In Review / Needs your attention / Requester with precise28November updates:

- TKT-WDZE1V: clarify manual Visa Process stage versus converted Students/Cases visibility or acceptance of current headings. No conversion is inferred.
- TKT-W3FKMH: retain separate records under the user's explicit decision; identity corroboration and conflicting-field decisions remain required.
- TKT-RKQ1MO: original registration URL, client/profile and failing step/error are absent. An incomplete earlier status note was clarified; prior deployed generic fixes do not identify the historical incident.
- TKT-1FFVFG: original missing profile/filename/upload details and requested checklist evidence are absent. No guessed private-file restoration or move.

Independent exact-revision tests, different reviewers, full regression/CI and production verification remain required before the two working tickets return to Done. Built-in ticket resolutions record the final release evidence. Declined and unrelated department work is excluded.
