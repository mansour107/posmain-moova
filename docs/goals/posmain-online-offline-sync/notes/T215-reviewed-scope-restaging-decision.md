# T215 reviewed-scope restaging decision

## Decision

The reviewed exact-label correction passes its local gate and preserves every existing migration safeguard. Authorize one fresh protected restaging Worker limited to immutable artifact verification and read-only per-tenant additive plus reviewed-rewrite dry-runs. Do not take live just-in-time backups, apply migrations, promote code, stop services, or change workers yet.

## Required proof

- Build a new timestamped artifact from the unchanged base commit plus the current exact overlay.
- Compare it with T212. Expected functional changes are limited to `SyncMigrationPlan.php`, `run_migrations.php`, `sync_migration_plan_test.php`, and goal state/evidence; every other delta must stop the Worker.
- Verify every hosted overlay hash and PHP lint.
- Run the migration-plan runtime proof and the focused schema/order financial suites from the immutable artifact.
- For every active tenant, rerun the exact 113-label additive dry-run and require the resulting remaining set to be exactly the previously audited 35 rewrites.
- For every active tenant, run `--scope=reviewed --labels=<exact 35>` dry-run and require selected=35, deferred=113 against the pre-migration current schema.
- Reject any ambiguous, destructive, unknown, duplicate, newly introduced, missing, financial-normalization, or checksum-drift label.
- Prove staged production automatic pull/publish remains off.
- Reconfirm the live root, ledgers, services, queues, and disposable database state remain unchanged.

The T212 restore rehearsal and 140-instance data audit remain valid because the database state and all schema-generation/financial-contract file hashes must be proven unchanged in this Worker. Fresh live backups and a new restore rehearsal belong immediately before the later production write, not this read-only stage.
