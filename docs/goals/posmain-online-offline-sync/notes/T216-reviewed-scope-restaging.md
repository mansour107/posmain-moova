# T216 reviewed-scope restaging

## Result

The reviewed-scope sync artifact is freshly staged and passed its local, hosted, exact-delta, per-tenant migration-selection, production-direction, and final live-immutability gates.

No live backup, code promotion, schema change, application-row write, migration-ledger write, configuration change, service restart, worker action, queue action, or credential change occurred.

## Immutable artifact

- Build ID: `posmain-sync-20260717T084710Z`.
- Local base commit: `67c4848985e81ec7e92eb03664967043bb75bfff`.
- Protected hosted path: `/var/www/posmain/staging/posmain-sync-20260717T084710Z`, mode `0700`.
- Payload SHA-256: `7030725a1e345fd7cca956223a0f4aac8939031e7642f64090c1fee2b855a8d3`, finalized only after the uploaded temporary file matched this checksum.
- Overlay manifest SHA-256: `af44430f101f6f97ff7061635e116d09436743e0bcb78e317764a1355af69c07`.
- Overlay: 75 tracked modifications, 65 non-ignored untracked files, 140 total, zero tracked deletions.
- Every hosted overlay hash matched and all 99 overlay PHP files linted.
- The staged production sync profile contract passed.

Local verification passed:

- all 99 overlay PHP lints and the complete worktree diff check;
- disposable-MySQL reviewed migration-plan runtime contract;
- 31 focused PHPUnit tests / 450 assertions covering schema, order projection, projection-version fencing, branch polling and cloud reports;
- guarded financial restore, order/fulfillment restore, and fulfillment-sync atomicity standalone proofs.

## Exact delta from T212

The new overlay adds exactly four goal receipts (`T212` through `T215`), removes nothing, and changes exactly:

- `classes/Sync/SyncMigrationPlan.php`;
- `tools/run_migrations.php`;
- `tests/sync/sync_migration_plan_test.php`;
- goal state.

Eleven schema, financial snapshot, cloud projection, guarded restore, polling, report and atomicity implementation/test files are byte-identical to T212. The expected added/changed lists have SHA-256 identities `ab3b6953444a77433acc3d383d659135d3bf9a40f6dffabb3a65f2c1e2d8f128` and `1752e52d9a6d47e1514e0d75f9620e839ebd042e0a26db4e6f5416581fad989f`; the unchanged financial-contract hash list is `2b11fc47a46d672ecab3ea31f9917d425bf74eb053cd9e205a183d9d07a69998`.

## Exact hosted migration dry runs

The router has zero pending statements and exposes four active tenants. Every tenant independently produced the same fail-closed partition:

| Tenant | Pending | Exact additive dry run | Exact reviewed dry run | Destructive | Ambiguous |
| --- | ---: | ---: | ---: | ---: | ---: |
| kody2 | 148 | 113 selected / 35 deferred | 35 selected / 113 deferred | 0 | 0 |
| shop2 | 148 | 113 selected / 35 deferred | 35 selected / 113 deferred | 0 | 0 |
| focushouse | 148 | 113 selected / 35 deferred | 35 selected / 113 deferred | 0 | 0 |
| QA campaign | 148 | 113 selected / 35 deferred | 35 selected / 113 deferred | 0 | 0 |

The additive command received the exact 113-label additive set. The reviewed command received the exact 35-label rewrite set. Both command forms were `--dry-run`; no broad unlabeled scope was used. Every pending-plan hash and migration-ledger state matched before and after both dry runs. There are zero `ot_head` total/tax/profit normalization statements.

`migration-review.json` SHA-256: `f3bda620666e6b1727ab578685cdfe87ab2159eb192e294c6f77f67a772964cd`.

## Final live state

- `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false` under the hosted configuration and staged production profile.
- Each tenant still has 148 pending statements partitioned 113 additive / 35 rewrite / zero destructive / zero ambiguous.
- Each migration ledger remains exactly one row and lacks the later `status` column, proving no migration apply occurred.
- Live tracked root remains clean at `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- nginx, MariaDB, and PHP 8.5 FPM remain active.
- The inspection and dry-run code had no worker or queue mutation path; existing worker and queue state was not changed.

## Regression controls

- Artifact transfer used a temporary filename, exact checksum comparison, restrictive mode, and atomic rename.
- The accepted delta is an exact allowlist; any unexplained implementation or deletion would have failed the stage.
- Migration selection remained labels-explicit and preserved canonical planner order.
- Ambiguous and destructive SQL counts are zero; backup, checksum and destructive opt-in gates were not bypassed.
- No historical total, tax or profit was fabricated or normalized.
- No automatic cloud-to-branch behavior was enabled.

## Recommendation

The reviewed exact-label blocker is cleared. A separate production Judge may now authorize only a bounded maintenance Worker with fresh just-in-time verified backups, exact manifest-bound code, exact 113-additive then exact 35-reviewed label application, runtime-path preservation, maintenance isolation, immediate post-apply validation, and hard rollback conditions.
