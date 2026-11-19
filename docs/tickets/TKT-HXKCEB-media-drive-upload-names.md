# TKT-HXKCEB — MediaDrive names on staff document uploads

Staff uploading a renamed local copy of a MediaDrive file should see the Drive filename instead of a newly generated checklist name. Matching is automatic and uses the upload's SHA256 and byte size within that same client's Drive root and subfolders. Only one distinct matching name is accepted; no hit or ambiguous names retain the existing upload/checklist naming behavior. Names that differ only by letter case are distinct.

`MediaDrive\UploadNames` uses the existing `Access` policy to require an active actor with access to that client's Drive. Names use the same basename/control-character cleanup as Drive uploads. This lookup only sets the document display name. It does not attach the Drive asset, replace its bytes, reuse its storage path, alter approvals, or grant access. Checklist copies continue to have independent private storage paths, even when their display names match.

Ordinary lead-portal uploads, public tracking-code uploads, public application uploads and booking uploads retain their prior behavior and do not search private Drive filenames. The shared checklist helper returns immediately without a Drive lookup for lead users. This deliberately avoids exposing unshared staff file names through an exact-byte filename probe; it is not universal client/public upload renaming.

Upload call-path audit:

| Path | Behavior |
| --- | --- |
| Staff checklist, requested-file and share upload | Exact-name resolver before existing naming |
| Immigration Other, staff dependant, outcome/decline and RFI/medical uploads | Same authorized resolver |
| Scoped sub-agent uploads | Same resolver; existing Drive actor/client scope applies |
| Explicit MediaDrive attachment | Existing reference and canonical name preserved |
| Lead checklist and replacement via shared helper | No Drive lookup for client actors; existing fallback naming |
| Lead general/dependant, public tracking upload/replacement, public pre-screening, registration/free-assessment enrolment, booking | Prior naming retained; no resolver invoked, including when staff happen to be logged in on the public form |
| Generated document formats/invoices/agreements/forms, composed signed agreements and payment evidence | Existing generated/evidence naming retained; not ordinary local case-document matching |
| Intake migration | Retains its existing source/import naming; not a staff local upload |

Tests in `tests/Feature/MediaDrive/UploadNamesTest.php` exercise the actual staff checklist, request, share and Other routes; independent private copies; nested roots; duplicate same names; ambiguity; foreign cases; exact bytes; inactive/unscoped actors; client/no-actor privacy; and sanitized legacy names. Existing MediaDrive attachment and upload suites protect explicit references and the extracted name sanitizer.

The change adds no UI, schema, migration, notification, or background backfill. It applies when uploading a new ordinary staff document; it does not rename existing records.
