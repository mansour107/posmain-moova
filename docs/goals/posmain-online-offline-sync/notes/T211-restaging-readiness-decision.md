# T211 corrected restaging readiness decision

## Decision

The version-4 financial-fidelity correction is ready for a fresh protected restage. Authorize one bounded hosted Worker to build and verify a new immutable artifact, repeat the protected restore rehearsal, and run exact all-tenant migration dry-runs and rewrite audits. Do not apply migrations, promote code, change live configuration, or mutate live sync queues.

## Why the correction is ready

- The focused migration, snapshot, cloud-projection, outbox, and guarded-restore suites pass.
- Source `NULL` remains unknown instead of becoming zero, header tax/profit round-trip, and four/six-decimal precision is retained.
- Older event and older target-schema restore contracts remain compatible.
- The local migration planner adds the new cloud header fields and defers precision/nullability rewrites explicitly; it emits no total/tax/profit `NULL` normalization.
- The correction can be isolated using the same immutable-base-plus-manifest-bound-overlay process proven in T204. Secret, environment, upload, log, backup, cache, dependency, and repository metadata paths remain excluded.

## Independent red-gate classification

### Remaining-write-surface static assertion

Nonblocking for protected restaging. The assertion looks for the literal `recordOrderSnapshot` call in `ajax/save_order.php`, while that endpoint now delegates through `pos_api_dispatch`. The actual transactional POS outbox end-to-end contract passes. This is a stale source-shape assertion, not a missing automatic-upload path.

### Branch retry scheduling assertion

Nonblocking for protected restaging. The test-run metrics show the intended event was claimed once and failed once, but a continuously running local `local_sync_worker_supervisor.php` shares the test branch identity and subsequently claims the same retryable row. The observed final `synced` status is test-environment interference, not evidence that `BranchSyncWorker` loses retry/backoff behavior. Final certification must isolate or pause that local supervisor before running this contract; this Judge does not stop or modify it.

Neither failure touches the corrected financial payload, stale-event ordering, idempotent cloud projection, or guarded restore implementation.

## T212 protected Worker boundary

Permitted:

- create one new timestamped immutable build and one new directory under `/var/www/posmain/staging`;
- record the local base commit, exact overlay file list, per-file hashes, payload hash, and manifest hash;
- reject tracked deletions and any `.env`, secret/key, upload, log, backup, cache, `vendor`, `node_modules`, or `.git` path;
- compare the new overlay against T204 and explain every added, changed, or removed release file;
- hash-verify all extracted overlay files and lint every PHP file in the staged overlay;
- run focused migration, snapshot, projection, outbox, stale/idempotency, and guarded-restore suites from the immutable artifact;
- restore only the protected T198 backup into a uniquely named disposable database, compare schema and exact row counts, run integrity checks, then drop only that disposable database;
- run read-only migration discovery for the router and every active tenant, followed by labels-explicit additive dry-runs and non-additive rewrite audits;
- prove no pending migration normalizes `ot_head.fat_total`, `ot_head.fat_tax`, or `ot_head.profit` from `NULL` to a fabricated value;
- prove staged production direction policy forces automatic cloud-to-branch publishing and pulling off;
- leave protected evidence only inside the new staging directory.

Forbidden:

- applying a migration to any active database;
- writing `/var/www/posmain/current`, live `.env`, live code, live database rows, or sync queue state;
- changing symlinks, nginx, PHP-FPM, MariaDB, workers, services, credentials, or runtime settings;
- invoking a mutating live HTTP endpoint;
- promoting or reusing the T204 artifact.

The Worker must stop with evidence for a later Judge. A separate Judge must approve any maintenance migration or promotion after reviewing the fresh artifact, restore result, exact per-tenant labels, rewrite safety, and rollback/post-apply checks.
