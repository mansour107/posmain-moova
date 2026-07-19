# T224 Focus House automatic activation decision

## Decision

Go for one bounded activation Worker. It may load only `com.posmain.focushouse-branch-worker`, drain the remaining eight zero-attempt table events through the sync-outbox-only profile, and prove steady-state/restart health. It may not touch the unrelated worker, replay dead rows, seed data, run any other job, or enable automatic hosted-to-branch behavior.

## Evidence

- Canary row 47837 has one local synced receipt and exactly one hosted processed inbox row. Event UUID, payload hash, revision cursor and table projection match exactly; hosted conflict count is zero.
- Rows 47838-47845 remain pending, attempts zero, unlocked and unchanged. Their snapshots still exactly equal current local tables 29-36, including table state and active order.
- Every remaining local revision is 1,783,360,119. Hosted revisions range from 1,782,723,007 through 1,783,192,898, so every local event is newer. No hosted revision cursor exists for those eight aggregates.
- The strict profile resolves only Focus House, reports zero schema pending and no warnings, and disables cloud pull, reverse publish, Moova, menu and image jobs.
- The service program explicitly selects `--only=sync_outbox`; its env, label, PID, status and log paths are isolated. It is valid and currently unloaded.
- The seven dead rows are not claimable by the worker and remain explicitly superseded.

## Activation gates

1. Recheck service/env fingerprints, exact 8-pending/7-dead queue and disabled directions immediately before load.
2. Bootstrap only the new Focus House label. Do not unload, kickstart or edit `com.posmain.branch-worker`.
3. Require the first successful cycle to claim no more than the eight remaining current events; stop the new service immediately on any failure/conflict/dead result.
4. Reconcile all eight local rows to one hosted processed inbox row each, matching revision cursor and cloud table payload hash, with zero new conflicts.
5. Require zero pending/failed rows, the same seven dead/superseded rows, and a healthy later empty cycle.
6. Kickstart only the new Focus House label once to prove restart persistence, then require strict preflight and an empty healthy cycle again.
7. Confirm cloud pull and reverse publish remain false locally/hosted, the unrelated worker fingerprints remain exact, and logs/status contain no secret.

This authorization turns on only the production direction requested by the user: automatic shop-to-cloud preservation. Cloud-to-shop recovery remains manual and separately guarded.
