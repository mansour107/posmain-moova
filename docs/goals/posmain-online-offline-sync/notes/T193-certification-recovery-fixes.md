# T193 Judge: Certification Recovery Fixes

## Decision

Keep the inbox ledger and current asymmetric policy. Fix the two certification failures with two narrow defensive boundaries:

1. Restore export must exclude inbox rows whose persisted `result_json.status` is `stale`. Live receipt intentionally records stale version-gated events as `sync_inbox.status=processed` because the current enum has no stale value; exporting every processed row therefore replays an event that the hosted projector explicitly refused. Apply the same eligibility predicate in inbox existence, page scan, and has-more queries. Keep normal `processed` rows and `duplicate` rows so exact business replay and typed operational history remain available.
2. Moova shop-link metadata may sync, but `moova_device_token_hash` may not cross the event boundary. Remove it from branch snapshots and legacy cloud-snapshot exports, and defensively force an empty local value before hosted or recovery upsert. Recovery leaves the integration unpaired until an operator installs a fresh token through the normal secure pairing path.

This is backward compatible with already stored hosted events: the restore-export filter reads their stored decision result, and the defensive Moova exporter/projector strips hashes from old payloads or old cloud rows without rewriting the ledger.

## Why this is smaller than source restructuring

Routing core phases to cloud projection tables and operational phases to the inbox would require coordinated source selection and cursor semantics across the restore client and exporter. Filtering events the hosted projection marked stale preserves the existing append-only ordering, pagination, typed operational bundles, exact duplicates, and legacy snapshot fallback while removing only events already judged unsafe to apply.

## Worker contract

- Change the three sync-inbox eligibility queries together.
- Treat invalid or missing result JSON as eligible for compatibility; exclude only an explicit top-level `status=stale` decision.
- Never exclude `duplicate` rows solely because their latest result says duplicate; an exact resend can update the original applied inbox row to duplicate.
- Strip the Moova token hash at producer, legacy export, and projector boundaries.
- Do not change schemas, event identities, public responses, worker direction, restore authorization, live data, or deployment state.
- Add focused export and Moova sanitization proof, then rerun the disposable certification and typed adjacent gates.
