# Planned tickets: second batch

User authorization: work remaining Planned tickets and add descriptions explaining resolutions. Preserve confidentiality; notify senders; keep ambiguous items in review.

## Existing capabilities verified and documented live

- #39 age in Profile Information: browser verified stored age; tests confirm DOB precedence.
- #51 five-program proposal/shortlist limit: five accepted, six rejected, unauthorized agent mutations denied.
- #64 template CC: current field, validation and email envelope tested with fake mail; no live delivery claimed.
- #141 Post Study: original screenshot matched program144; current overview lives in description and post_study is empty. Independent fields tested; no new production change claimed.
- #145 task assignment email/in-app notifications: current create/new-assignee behavior tested with fake mail; self-assignment excluded.

## Data correction completed

- #178 cleared only visa_processing_fee on206 program catalogue records; zero nonempty values remain. Original id/fee values backed up under /root/luvep-ticket178-20261021 with root-only permissions. Other costs and issued documents were not altered.

## Code release

- #13 default missing visa type to Student Visa during Education handoff, preserve existing visa choice and student visibility.
- #68 resolve missing dependent display names from explicit linked cases; restrict agent links to owned cases and redact stale inaccessible linked dependents before identity/document serialization.
- #72 expose admin Reject file with reason in active checklist menu.
- #105 expose Delete file with confirmation and scoped endpoint in active checklist menu.
- #162 requested address on newly rendered written immigration agreements. Existing signed/issued documents are not rewritten.
- #169 private tenant upload/list/download for internal accommodation staff and admins; strict tenant/document relation and denied external portal grants. Storage under deployment-protected storage/app/private/tenant-documents, no public serving. No Drive migration.
- #170 all weekday payment options plus Fortnightly; preserve legacy schedules.
- #85 staff Immigration composer using existing authorized messaging endpoints, retain agent restrictions.
- #40/#79 Sales document management and Education notes verified in actual browser workflow.

## Review retained

- #15 intended assessment form unclear; education enrolment already accepts CV, free assessment does not. Asked sender for link.
- #154 exact phone form/country/error unclear; asked sender to identify.
- #171 phone exists in student432 but not separate case633; no identity merge or copying without confirmed relationship. Asked sender to confirm records privately.

## Verification

Combined affected PHP tests initially141 pass /904 assertions, plus browser tenant upload/download and age display. Document end-to-end flow identified viewport overflow in expanded file menu; fix and rerun before release. Independent review caught stale cross-agent dependent identity/document disclosure; HTTP negative and allowed-link tests now cover redaction. Existing broader-suite failures in unrelated checklist, bulk-email/template and case-loading tests remain outside this release; no all-suite-green claim. Regression fixture category/rental_mode mismatches corrected only where needed for touched feature coverage.

Completed tickets receive an appended resolution description, attached report, support reply and sender notification; only verified releases move to Done.

Final verification:158 PHP tests /957 assertions pass (3 PHPUnit deprecations);9 browser component tests pass including malicious inbound email, outbound sandbox and tiny-view menus;3 end-to-end browser tests pass for age, lead documents and tenant documents. Production build passes with existing chunk warnings. Final independent review confirms both dependent disclosure and email-rendering blockers are addressed.
