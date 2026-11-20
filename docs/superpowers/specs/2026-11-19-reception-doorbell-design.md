# Reception doorbell — TKT-QNFQOS

The user approved one Reception QR, in-app notifications to active Immigration managers, no visitor details and repeat-ring protection. The office confirms its physical entrance before printing the sign.

Use the existing Laravel database notification inbox and installed qrcode.react library. A manager/admin setup page creates one unlisted public link; merely opening either page never rings. A visitor explicitly presses Ring reception. A successful ring writes one notification per active Immigration manager, with no email or visitor data. Show an honest unavailable response if there are no recipients. A shared atomic 60-second cooldown and per-IP route throttle limit repeats.

Alternatives: email adds an unapproved channel; a separate reception/inbox system duplicates existing notifications. Neither is needed. Staff use the existing inbox/read controls; no visitor identity, camera, audio, access grants or inferred office hours are added.

Test invalid links, manager/admin setup versus unauthorized roles, no-recipient behavior, exact notification recipients/channel, failure rollback and repeated rings. Verify setup and public page live without ringing real staff.
