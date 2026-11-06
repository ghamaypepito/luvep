# Require RSVP contact number

Requested: contact number in public RSVP must be required.

Changed the form label from optional to required, added HTML required, and changed backend phone validation from nullable to required|string|max:40. Existing browser form.reportValidity handles the required field. Existing records and schema untouched.

Validation: regression failed first (blank accepted201 instead of422), then32RSVP/managed-schedule tests passed,196assertions, with existing PHP8.5 deprecations. Missing,null,empty and whitespace-only numbers reject without creating RSVP,outreach orlead; valid contact saves. Updated older test fixtures to include a number so their original replay/hidden-visit assertions remain meaningful.

Live source at13c018888883ec32b6614b63c333693866931b01 matched2420161 hashes. Published exactly two scoped files with only phone changes; retained all other current live content/behavior. Exact deployment patch alongside this note. Targeted staged required/valid tests passed:2tests,23assertions. PHP syntax and whitespace checks passed. Backup:/home/deploy/education-rsvp-required-phone-backups/20261106-021241.

Fresh live browser selected the public Davao event and verified label Contact number(required),required=true and empty validity.valueMissing=true. No contact details entered or RSVP submitted. Screenshot:education-rsvp-preview/rsvp-contact-number-required-live.png.

Source integration remains pending existing draftPR15; no merge or broad deploy performed.
