# T226 reverse-setting normalization decision

## Decision

Go for one exact configuration-normalization Worker. It may set only `POSMAIN_CLOUD_PULL_ENABLED=0` and `POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED=0` through `SyncRuntimeSettings::savePartial()` in the local and hosted Focus House databases. It must not restart workers, alter any identity/credential/forward-sync setting, or touch queues/projections.

## Evidence and safety

- Resolved local and hosted production configuration already forces both values false. The running service explicitly executes only `sync_outbox`; no reverse job is running.
- Raw local state has pull `1` and no publish row. Raw hosted state has pull `1` and publish `1`, all non-secret UI rows from 2026-06-23.
- `savePartial()` schema-checks first, allowlists boolean keys, normalizes values to `0`/`1`, and upserts only the provided allowlisted keys. It does not touch role, branch UUID, URL, encrypted secret, queues or projections.
- The old raw values are explicit and can be transactionally restored if exact post-verification fails.

## Required gates

1. Capture exact pre-change rows and forward worker/queue health.
2. Use one transaction per database and `savePartial()` with only the two reverse keys.
3. Read back both raw values as `0`, non-secret, without changing any other runtime-setting row fingerprint.
4. Confirm resolved config remains false, automatic worker stays running/healthy, queue stays zero pending/failed with seven dead, and hosted projection/inbox counts do not change.
5. Roll back the transaction or restore the explicit old values if any check fails.

This removes a dormant re-enablement path while preserving all working forward synchronization.
