# HR automatic clock-out

New live clock-ins snapshot a fixed limit of 36,000 working seconds. Recorded breaks do not count toward this limit. Existing shifts without this snapshot, closed historical shifts and manual/legacy DTRs are preserved. This rollout scope was confirmed by the user on 21 November 2026.

The server closes a due shift at its actual ten-hour work cutoff in UTC, including across local midnight and daylight-saving changes. It updates the live DTR time-out, releases the active-shift guard, increments the clock version and records immutable `clock.auto_clock_out` audit metadata under System, including the cutoff and processing times. A late action cannot extend work; the normal reviewed correction process remains available. The existing completed-workday restriction prevents another clock-in on that work date.

`php artisan hr:auto-clock-out --dry-run` counts due eligible shifts without writes. `php artisan hr:auto-clock-out` enforces the limit. Both automatic and employee clock changes use the same employee row lock, so repeated or overlapping runs close each shift once. The command is disabled when HR is disabled. It sends no emails and does not submit or approve DTRs.

The Laravel schedule declares a minute-by-minute run. Production deploys also install a dedicated cron using `scripts/install-hr-clock-maintenance.sh`, replacing only its own `# luvep-hr-auto-clock-out` entry. This does not activate the general scheduler or other communication jobs. Cron output is in `storage/logs/hr-auto-clock-out.log`. An overlapping scheduler invocation is harmless because persistence is locked and idempotent.

Processing occurs on the next minute tick; the saved clock-out time is the exact cutoff rather than the later processing time. Employee and team timers stop extrapolating at the limit while waiting for the confirmed server state. GET/state and employee previews remain read-only. HR audit shows System as the actor; employees see an automatic clock-out notice after confirmation. Already closed corrected records retain their actual approved intervals rather than a display cap.

## Verification and rollback

Test cutoff boundaries, recorded and running breaks, delayed jobs, UTC/NZ daylight-saving transitions, retries/version conflicts, dry runs and historical preservation. Check cron installation twice with unrelated entries retained. Verify in an isolated browser fixture, and use only read-only/dry-run checks in production.

To roll back, remove only the cron line marked `# luvep-hr-auto-clock-out`, revert the release and redeploy. There is no schema migration. Preserve already recorded clock-outs and their audits; authorized HR reviewers can correct actual intervals with a reason. Do not replay or rewrite historical records during rollback.
