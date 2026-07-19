# T223 Focus House worker canary

## Result

Pass. A fully isolated Focus House worker profile authenticated to the active hosted route and delivered exactly one verified current table event. The hosted inbox, revision cursor and table projection reconcile to the same event UUID and payload hash with zero conflicts. Continuous service activation remains intentionally deferred to T224.

## Protected worker identity

- Environment: `.env.focushouse-branch-worker`, Git-ignored, mode `0600`, 695 bytes.
- It resolves only `focushouse` on `127.0.0.1:3307`, branch fingerprint `02b9d3f52166556c`, and `https://erp.withmoova.com`.
- The existing hosted encrypted secret was transferred through root-only temporary files. Only fingerprint `9aa58f9bbbd68ba8` and length 43 were displayed; all transfer helpers and temporary plaintext files were deleted locally and hosted.
- Automatic cloud pull, cloud-to-branch publish, Moova polling/apply, menu sync and image sync are disabled in this profile.
- Strict preflight passed with role branch, zero schema pending, no warnings, configured UUID/URL/secret, worker enabled and cloud pull false.
- Authenticated empty-batch pairing probe returned active router route, hosted database `focushouse`, hosted schema ready and zero pending.

## Isolated service definition

- Installed but not loaded: `~/Library/LaunchAgents/com.posmain.focushouse-branch-worker.plist`, mode `0644`, 1,574 bytes, valid plist.
- It uses only `tools/local_sync_worker_supervisor.php --strict --only=sync_outbox` with the isolated env and distinct PID, status and log paths.
- `launchctl` confirms the service is not loaded, so no continuous drain occurred in this task.
- Existing unrelated plist hash remains `287988158e2dc6c5df7ed5ab547e3a48a2d68baa1494d72cab2aea6d3953be86` and `.env.branch-worker` remains `b3b808646871a1a452b2e129566ecff6b4b789ff6a7bf9f527278f1ded124a03`.

## Canary selection and result

- Selected row 47837 only: zero attempts, unlocked, `table.updated`, table 28, event UUID `5d9ff40b-6900-42e2-b165-7781bc207f5f`, payload hash `8bc871fdaaf03b5632dd64b3ffb1ac54c9cf8ee73a16c1fe820dd9fba145e06d`.
- Its snapshot exactly matched the current local table name, state, active order and revision 1,783,360,119.
- Hosted table 28 was older at revision 1,782,722,851 and no hosted revision cursor existed, so the canary could not overwrite newer data.
- The one-cycle worker claimed one, synced one, failed zero, dead zero, HTTP 200, mode `live_apply`.
- Local row 47837 is synced at attempt one. Rows 47838-47845 remain pending with zero attempts and no locks. All seven explicitly superseded rows remain dead at one attempt.
- Hosted has exactly one processed inbox receipt for the canary, one matching revision cursor, matching table projection revision/hash, and zero conflicts.

The canary proves authentication, routing, one-row claiming, hosted apply, revision protection and local receipt handling without enabling reverse sync or touching any unrelated queue/service.
