# T225 Focus House automatic activation

## Result

Pass. The isolated Focus House service is loaded, continuously running, survived an isolated restart, and drained exactly the remaining eight authorized shop-to-cloud events. Local and hosted receipts reconcile exactly with zero conflicts. No reverse job or unrelated service was touched.

## Activation and service health

- Loaded only `com.posmain.focushouse-branch-worker` from the validated dedicated plist.
- Initial service PID 59218, run one: strict preflight passed and the only job was `sync_outbox`.
- First cycle claimed eight, synced eight, failed zero, dead zero, HTTP 200, `live_apply`.
- Later cycles claimed zero and remained healthy with no failure since start.
- Isolated `launchctl kickstart -k` changed only the new service to PID 60838/run two. Post-restart strict preflight passed and repeated empty cycles remained healthy.
- Environment hash remains `547d12bf442ee9eb27d73b0563fb1e6f693f5e8678cb2e8bb7b42771d2c8217d`; plist hash remains `a85504c77495281965770802dcd261a7d9d83cf3093730d4af257cd369e35aed`.
- Error log is empty. Worker logs contain zero occurrences of the branch secret, no fatal/uncaught messages, and the apparent `warning` matches are only the JSON field `warnings: []`.

## Local drain reconciliation

- Queue is now 47,837 synced, zero pending, zero failed, and seven dead/superseded.
- Rows 47837-47845 each synced exactly once with attempts one, no errors, locks or retry timestamps.
- The seven dead rows remain attempts one and were never claimable/replayed.

## Hosted reconciliation

- Hosted tables 28-36 each have revision 1,783,360,119.
- For all nine, cloud table payload hash equals revision-cursor hash and processed inbox hash.
- All nine inbox receipts are `processed`, have no error, and point to the exact local event UUID.
- Summary checks are 9/9 revision matches, 9/9 cursor/hash matches, 9/9 processed inbox matches, 9/9 inbox/projection hash matches, zero errors and zero conflicts.

## Reverse-direction safety follow-up

Resolved runtime configuration remains safe: strict preflight reports cloud pull false; the service can execute only `sync_outbox`; production profile forces cloud pull and cloud-to-branch publish false.

The deeper audit found stale raw `sync_runtime_settings` values from 2026-06-23: local has `POSMAIN_CLOUD_PULL_ENABLED=1`, while hosted has both pull and reverse publish set to 1. The production profile currently overrides these to false, so no reverse job ran. T226 must normalize the stored values to zero as defense in depth before manual restore certification.

The implementation avoids regressions through a unique service identity, strict schema/config preflight every cycle, revision-guarded hosted projection, exact queue scoping, persisted health status and explicit exclusion of all automatic reverse jobs.
