# TKT-WX9SLV - Automatic age from Education profile birthdate

## Findings
Reviewed fetched main 6dbf7b3d and open PRs on 18 November 2026. Lead.age already derives whole years from a saved DOB and is serialized to the profile. However, the Personal Information section copies that saved age into its initial form snapshot and displays it while DOB is edited. The displayed age can therefore remain blank/stale until save/refresh. The screenshot is consistent with this edit-state gap, but the original live client record was not inspected and a saved-data failure was not independently established.

## Resolution
In Education profile contexts, calculate the read-only Age display from the current form DOB immediately. Use a server-provided date-only reference day matching the application's current server-age convention, and UTC calendar-date parsing to avoid browser-local date shifts. Invalid/future dates display no computed age; zero is displayed correctly. Preserve an imported age when the original record has no DOB. Existing server validation and saved-age accessor stay authoritative, and the new computed display does not write age or change DOB automatically.

The reference-day prop is supplied only for the Education portal and the existing admin profile route used by Education-role staff. Other profile contexts retain their existing age display. No migration, permission change, identity correction, mail or global age-accessor change. The date of birth remains entered/confirmed by staff; this request is interpreted as automatically calculating age, not generating a birthdate from age.

## Verification
30 backend tests / 212 assertions passed across EducationAgeTest and PersonalProfileTest. Tests verify Education-only reference date, saved DOB-derived age, ignored submitted age values, preserved legacy stored age, invalid/future DOB rejection, birthdays/leap-year boundaries and broader personal-field persistence.

The actual Age component interaction suite passed: live DOB changes without save, zero, clear/cancel, legacy fallback, future/invalid dates, date boundaries and no new requests or computed input field. The suite is included in CI. Full build/backend/frontend CI outcome is recorded in the PR and handover report.

## Release acceptance
Keep In review until reviewed, merged, deployed and Emma confirms. On staging with a synthetic Education student, edit DOB without saving and confirm Age updates, cancel and confirm original age returns, save a valid date and refresh to confirm the server-derived age matches. Check zero/missing dates, validation errors and a non-Education profile. The reference day updates on page load/refresh; no midnight timer is introduced. No live client records were changed. Passing automated tests reduces regression risk but is not a guarantee for every production condition.
