# T219 Focus House branch activation decision

## Verdict

Continue, but do not drain or seed yet. T218 proves the hosted application and all tenant schemas are healthy at the reviewed release. The remaining critical path is an isolated Focus House branch sender on this Mac.

The currently installed `com.posmain.branch-worker` launch service is not Focus House: its protected environment targets kody2 and Railway, automatic cloud pull is enabled in that unrelated profile, it has no live process, and launchd reports repeated failed runs. It must not be edited, stopped, unloaded, repurposed or used as evidence for Focus House.

The ordinary Focus House checkout currently resolves database `focushouse`, but without its database runtime available it does not resolve a usable branch UUID, Hetzner URL or branch secret. Both automatic reverse-direction flags resolve false in the current release. Docker Desktop is stopped, so the local `127.0.0.1:3307` Focus House database and the previously observed queue cannot be refreshed in this Judge.

## Fresh hosted evidence

The T218 post-start read-only receipt is current after promotion:

- Focus House migrations: zero pending; 149 applied receipts; zero started rows.
- Inbox: 47,553 processed and 275 exact duplicates; latest event 2026-07-04 20:21:22; zero open conflicts.
- Projections: 6,542 orders, 13,604 lines, 5,678 order payments, 5,676 payment receipts, 23 tables and 375 menu items.
- `cloud_shifts` is still empty.
- 26,470 reverse event rows are already acknowledged; automatic cloud pull/publish remains disabled.

The QA tenant has ten cloud shift projections under the same deployed code, which proves the hosted shift path can populate. Focus House's empty shift projection is therefore a branch activation/seed gap, not another hosted migration blocker.

## Stale evidence that must be refreshed before sending

T196 observed 47,828 synced rows, nine zero-attempt pending `table.updated` rows and seven old dead `order.saved` rows marked superseded. Those counts are useful expected values, not authorization. The next Worker must start or otherwise reach the existing Focus House database, prove the branch identity matches the active hosted Focus House route, and refresh status/type/id/time/attempt/lock/error classification before any claim or retry.

The seven superseded dead rows remain permanently excluded from ordinary drain unless a later explicit forensic decision proves otherwise.

## Authorized next Worker

Authorize one bounded Focus House worker-provisioning and canary-preflight task:

1. Preserve and fingerprint the unrelated kody2/Railway launch service and environment without revealing secrets.
2. Restore access to the existing local Focus House runtime without rebuilding, clearing or replacing its database.
3. Take a fresh protected local database backup and verify it before any queue claim.
4. Refresh the local branch identity, exact queue classification, schema readiness and reverse-direction flags read-only.
5. Create a distinct ignored mode-0600 Focus House worker environment and a distinct launchd label, PID, status and log paths. Reuse the already encrypted hosted Focus House secret through an SSH-protected transfer or rotate it atomically on both ends; never print it or place it in Git.
6. Run strict preflight and authenticated pairing/status checks only. The service must initially be unloaded, and the job set must be restricted to `sync_outbox`.
7. If identity, secret, URL, schema, backup, worker isolation and queue classification all pass, run one bounded canary cycle with batch size one. Verify one normal current pending event becomes accepted/duplicate/stale and the matching hosted inbox/projection advances without conflict or unrelated reverse activity.
8. Stop after canary evidence. Continuous service enablement, remaining backlog drain, authoritative bulk seed, and manual restore certification require the next Judge.

## Hard stops

- Local database cannot be reached as the existing `focushouse` dataset or needs reinitialization.
- Local branch UUID does not match the active hosted Focus House route.
- A usable protected secret cannot be transferred/rotated and pairing-tested without exposure.
- The new service overlaps the unrelated label, env, PID, status, logs, database or branch identity.
- Automatic cloud pull or reverse publish is enabled.
- Any pending/dead row differs materially from its expected classification, is locked/in-flight, or can be newer than the hosted projection.
- The canary would select a dead/superseded row or any event other than an explicitly reclassified normal pending row.
- Fresh backup, strict preflight or authenticated pairing fails.

This slice avoids regressions by proving identity and authentication before queue mutation, preserving the unrelated worker, limiting the first sender run to one ordinary branch-to-cloud row, keeping reverse sync disabled, and leaving bulk seed/recovery behind separate reconciliation gates.
