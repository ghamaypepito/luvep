# TKT-T8AVF2: GENDRIX - Google Review connection

Reviewed 16 November 2026 against main 0549d9cc. Status: In review / live configuration and provider verification outstanding.

## Finding

The integration is implemented in source, but live operation is not verified by this review. The attached ticket records missing refresh token/account/location configuration on 21 October. That is historical evidence, not a fresh inspection of today's server environment. No production credentials, configuration values, live API, database or reviews were accessed.

Existing paths:
- `app/Console/Commands/GoogleBusinessAuth.php`: owner-assisted OAuth setup using business.manage scope. The command can print credentials; only the authorised operator should use it in a restricted terminal. Do not paste its full output into a ticket or shared report.
- `app/Console/Commands/GoogleTestReviews.php`: connectivity diagnostic.
- `app/Console/Commands/SyncGoogleReviews.php`: token refresh and paginated v4 reviews import, including --dry. New imports are source=google, unpublished and department=both. Blank-text ratings are skipped. Existing external IDs refresh provider content while preserving moderation/publication/department/featured fields.
- Unique (source, external_id) database index prevents duplicate automatic import rows for the same identifier. Manually entered rows can have no external ID, so manually entered copies need review during initial reconciliation.
- `routes/console.php`: daily sync at 05:00 Pacific/Auckland, without overlapping. This confirms the schedule declaration, not that server cron ran it successfully.
- `UserReviewController::publicPayload`: only published reviews appear in public payloads.
- `/admin/user-reviews`: existing staff review screen and manual import option. Manual import has a publish choice defaulting to true, so it must not be described as identical to the automatic unpublished import path. No review was imported or published by this investigation.

## Reproduced code defects and separate branch

A regression test on main failed because google:test-reviews returned success when location configuration was missing and the actual reviews endpoint was never called. The diagnostic could also infer an account rather than verify the configured business, allow an excessive page size, and describe every reviews 429 as proof of missing approval. Sync accepted HTTP200 token responses without checking for a valid access token.

The proposed change is limited to the two console commands:
- Missing intended account or location returns failure/unverified. Successful OAuth or account listing alone is insufficient.
- Validate a non-empty string access token before making the reviews request.
- Bound diagnostic page size to 1..50.
- Report rejection/rate limiting without claiming that a status alone proves approval was denied. Remove the stale hardcoded support-case instruction.
- On success, instruct the operator to verify dry sync, moderation and scheduled execution. Do not suggest the already-existing sync still needs building.

No portals, routes, moderation defaults, database schema, credentials or scheduler frequency are changed by this proposal. This does not enable the external connection or resolve the original ticket by itself.

## Verification

Local: 19 tests / 61 assertions passed across GoogleReviewIntegrationTest, existing UserReviewModerationTest and ProgramReviewFromCardTest. HTTP responses, credentials and review records were synthetic. Stray HTTP requests were blocked.

Coverage: missing configuration, explicit target account/location, actual endpoint success, rate-limit reporting, page-size cap, malformed token response, dry-run pagination without writes, repeated-import deduplication, blank-text skip, preservation of published/featured/moderated records and denied provider calls. Existing department moderation/access checks passed.

The missing-location regression failed before the command change and passed afterward. Full GitHub build/regression results are recorded in the PR after execution. No successful live Google connection, production sync or subsequent scheduled run is claimed.

## Owner/operator completion plan

1. The Google Business Profile owner confirms the intended profile/location and the authorised Google Cloud project. Complete or verify OAuth and required API access. Google documents that the reviews-list operation requires a verified location.
2. An authorised operator securely verifies the presence of GOOGLE_BUSINESS_CLIENT_ID, GOOGLE_BUSINESS_CLIENT_SECRET, GOOGLE_BUSINESS_REFRESH_TOKEN, GOOGLE_BUSINESS_ACCOUNT_ID and GOOGLE_BUSINESS_LOCATION_ID on the intended server. Do not replace existing credentials blindly or send secrets through tickets. The owner supplies the account/location through the authorised setup.
3. Refresh the application configuration through the established deployment process. Run google:test-reviews. Inspect the actual configured reviews endpoint result, not just a zero exit status from an older diagnostic.
4. If access fails, distinguish authentication, API enablement, profile permissions and quota. HTTP429 is a quota response; zero quota indicates access has not been granted according to Google. Do not infer an approval decision from any single 403/429 alone.
5. Run google:sync-reviews --dry, confirm the intended business and candidate counts, reconcile manual imports, then authorise a controlled import. New automatic imports must remain unpublished until staff choose to publish.
6. Verify imported items in the staff review screen, review privacy and department display, and confirm at least one subsequent scheduled run via server execution evidence. A cron entry alone is insufficient.
7. Mark Done only after the intended location sync and subsequent scheduled sync succeed, and staff confirm the expected review workflow. Otherwise keep In review with the specific external/configuration blocker.

## Official references checked

Google reviews list endpoint, verified-location requirement, OAuth scopes and maximum page size: https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews/list

Google quota and approval distinction: https://developers.google.com/my-business/content/limits

Google API prerequisites: https://developers.google.com/my-business/content/prereqs

## Update for Sir Ghamay

The Google Review sync/moderation/display code already exists. The ticket's October notes record incomplete server configuration, but I have not reverified today's live connection. I reproduced and fixed misleading diagnostic success when the reviews request is skipped, plus related token/target/quota checks, in a separate review branch. Local synthetic tests passed. Please review the branch and have the authorised profile owner/operator confirm setup, run the diagnostic and controlled sync, then verify the scheduled run. Keep the ticket In review until that evidence is available.
