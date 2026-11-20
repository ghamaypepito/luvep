# TKT-IKIBDU — retain the Immigration case when Education resumes

On 19 November 2026 the user confirmed the intended outcome: **retain the same Immigration case, documents and visa outcome while Education resumes work**. This is not conversion reversal.

## Problem and fix

Some older students qualify for Immigration Cases through their Education handoff stage rather than an explicit case flag. Previously, changing that stage to School Enrolment, Course Ongoing or Completed removed the only membership signal, so the existing case disappeared from Cases.

The existing Lead update safeguard now retains membership for Education stage changes from an existing handoff stage. It leaves the client ID, Immigration stage, visa type/outcome, assignee, conversion timestamps, documents, notes and sent messages unchanged. It neither creates a new conversion nor resends handoff notifications. A deliberately changed case flag remains respected.

The fix does not promote ordinary Education students or implement an undo-conversion operation. No migration, backfill, role change or live-client correction is required.

## Validation

Independent testing reproduced the legacy disappearance before the fix. The refreshed candidate, integrated with main b26e936e, passed 21 tests / 502 assertions plus two independent explicit-flag checks / 40 assertions. A separate reviewer approved the narrow retention behavior.

Regression coverage exercises Students inline, profile-header stage and edit-form paths to School Enrolment, Course Ongoing and Completed. Synthetic cases retain the same ID, Education and Immigration visibility, private files, visa outcome, assignment, conversion metadata, timeline and sent-message history. Negative checks cover ordinary students, unrelated edits, external-user denial and explicit case-flag changes. Mail/notifications are faked.

Required full CI and deployment results belong in PR95 and the ticket resolution. Do not claim deployment from this document alone.

## Staff procedure

1. Open the existing student in Education.
2. Set the Education stage appropriate to the resumed work.
3. Continue using the same Immigration case and its existing documents/outcome.

Do not create a duplicate client or use conversion reversal for ordinary Education work. The user-approved acceptance is retention; no reversal rules are inferred.
