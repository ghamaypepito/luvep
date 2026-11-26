# Education queue verification — 26 November 2026

Reviewed the live System Tickets queue and matching Kent PRs. Nine actionable tickets moved Open → In Progress → In Review using the normal ticket controls, preserving owners; school workspace and partner tracker remain New feature. Final Done requires exact release verification and built-in resolution reports. No emails or client/permission/signature changes are part of this release.

## Candidate

Base `f06dc6c3`; tested application revision `ebb37ff8`. Integrates Kent PRs #194, #203, #206 and #207 with corrective commits. Already deployed #202 (Communications CC), #200 (partner tracker) and #190 (cost headings) were independently retested and reused.

| Ticket | Verified outcome |
| --- | --- |
| TKT-7IDHHF | Authoritative department stages on staff Education affiliate referrals; both departments only for catalogue-defined Student Visa applicants. |
| TKT-BMIO71 | Contact activity block removed; accomplishment count cards precede the recording form. |
| TKT-CISQ4T | Existing top-of-report PersonalReport placement retained and independently checked; no redundant reorder. |
| TKT-RDXLQU | Exactly sixteen ordered presentation cards; supplemental stage drilldowns retained separately; unknown arrival data is not invented. |
| TKT-U6IWDA | Existing optional CC for Communications new/reply mail validates and deduplicates while retaining the client To. Fake transport only. |
| TKT-IZIQR5 | Contact Attempted and Contacted for Booking immediately follow New Leads. |
| TKT-SOO0VL | Existing partner activity, protected source facts/files, deadlines, filters and Excel/PDF exports pass. |
| TKT-5N1LBR | School enrolments, correspondence/private files, catalogue supplements and exact intakes pass. Failed storage, reassignment, migration reapply and failed-deletion file retention fixed. |
| TKT-OPWWAA | Six headings match the ticket PDF; actual generated PDF readable; entered amounts, FX and old saved PDF bytes preserved. |

## Independent gates

Independent tester did not author the changes. Exact candidate passed 117 PHP tests / 1,902 assertions, six actual React harnesses, two independently authored CC/render tests and the production build. Separate Standards and Spec reviews each returned zero actionable findings. The apparent date/campus filter gap was checked against the original ticket: dates/campus are required information, while required filters are intake/programme/enrolment status and programme/intake period.

Root verification: 68 focused PHP tests / 830 assertions; 86 frontend scripts; 27 PDF/dependency/VIF contract tests; 65 workspace JS tests. Full regression/hosted CI and deployment remain required release gates. A symlink-induced local PDF-worker manifest path mismatch was resolved by using an isolated dependency copy; production worker bytes/extension checks pass unchanged.

## Remaining In Review

- TKT-W3FKMH: retain separate records under the user's explicit decision pending identity confirmation.
- TKT-RKQ1MO: original registration URL/client/failing step is unidentified.
- TKT-1FFVFG: original missing profile/filename and requested checklist evidence are absent.
- TKT-WDZE1V: manual-stage versus converted-record heading intent remains unconfirmed.

These evidence limits are not resolved by passing software tests. No guessed record/file move, identity merge or conversion decision is authorized by this release.
