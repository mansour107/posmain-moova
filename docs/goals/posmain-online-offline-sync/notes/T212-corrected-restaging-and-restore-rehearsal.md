# T212 corrected restaging and restore rehearsal

## Result

The corrected sync checkout is staged as a fresh immutable artifact. Artifact verification, focused local artifact tests, protected backup restore rehearsal, router/all-tenant migration dry-runs, complete rewrite safety audit, financial NULL-preservation checks, production direction policy, and final hosted immutability checks all passed.

No live code, schema, application row, migration ledger, configuration, symlink, service, worker, queue, or credential was changed.

## Immutable artifact

- Build ID: `posmain-sync-20260716T191833Z`.
- Local base commit: `67c4848985e81ec7e92eb03664967043bb75bfff`.
- Hosted protected path: `/var/www/posmain/staging/posmain-sync-20260716T191833Z`, mode `0700`.
- Payload: 32,100,094 bytes, mode `0600`, SHA-256 `239516c33f23fb81a800010f9f658af4fbf68434eb0906f41fbebf009cbf0cb4`.
- Overlay: 75 tracked modifications, 61 non-ignored untracked files, 136 total, zero tracked deletions.
- Overlay manifest SHA-256: `73871d914abaf14bb2282b1f14bd2b30372420397fa34ed78b340901d0184a7e`.
- All 136 overlay hashes matched after hosted extraction; all 99 overlay PHP files linted.
- No forbidden environment, key, upload, log, backup, cache, dependency, or repository-metadata path was admitted; no private-key marker was present.

Compared with T204, the overlay added exactly eight T204-T211 evidence/decision notes, removed nothing, and changed exactly the four financial sync implementation files, five focused tests, and goal state. Every delta is explained by the T205-T211 correction sequence.

The extracted immutable artifact passed locally:

- 99/99 PHP lints;
- 37 focused PHPUnit tests / 506 assertions covering schema, snapshot, cloud projection, outbox, projection ordering/idempotency, worker polling, and cloud reporting;
- guarded financial restore, fulfillment restore, fulfillment sync atomicity, and migration-plan standalone contracts.

## Protected restore rehearsal

The protected T198 `kody2` backup SHA-256 remained `81059a3ada208569da50eb61cfeb163893d3bbf29ebea3218533ae23dad017af`.

Only a rewritten protected copy was imported into `posmain_restore_rehearsal_20260716_191833`; exactly two quoted source database identifiers were replaced and no source identifier remained. Results:

- 192 source objects and 192 restored objects;
- 192 base tables;
- 989 exact source rows and 989 exact restored rows;
- matching object set;
- zero exact row-count mismatches;
- zero `SHOW CREATE TABLE` hash mismatches;
- zero `CHECK TABLE ... QUICK` integrity failures;
- disposable database removed and independently verified absent.

`restore-comparison.json` SHA-256: `8b6406bcc3f7fa3d5b610422f3be7a928d1733e0efc7ecb1f4a1240d5767f1bc`.

## Corrected migration evidence

The router has zero pending statements. Four active tenants were discovered through the router. Each tenant has the same exact corrected plan:

| Tenant | Pending | Additive | Rewrite | Destructive | Ambiguous | Explicit dry-run |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| kody2 | 148 | 113 | 35 | 0 | 0 | pass |
| shop2 | 148 | 113 | 35 | 0 | 0 | pass |
| focushouse | 148 | 113 | 35 | 0 | 0 | pass |
| QA campaign | 148 | 113 | 35 | 0 | 0 | pass |

Every dry-run used the full explicit 113-label additive set. No active schema was changed.

The corrected financial labels include additive `cloud_orders.fat_tax` and `cloud_orders.profit`, nullable four-decimal cloud/header money rewrites, nullable six-decimal cloud line profit, six-decimal legacy line profit, and nullable four/six-decimal legacy order total/tax/profit. Across all four tenants there are zero pending `ot_head` UPDATE normalizations for `fat_total`, `fat_tax`, or `profit`; unknown historical values remain unknown.

`migration-review.json` SHA-256: `e10c789e31aa8f35023a6ed8e4a7ccc910c86ed57b6c1ca98b87dc39b82aa023`.

## Rewrite safety

All 140 rewrite instances (35 per tenant) were audited read-only and covered:

- 140 `data_pass_maintenance_required`;
- zero unsupported, overflow, NULL-loss, invalid-enum, type, or unapproved row-rewrite blockers;
- zero transactions older than 30 seconds, zero pending metadata locks, and zero tables in use during the audit;
- no exploratory DDL executed.

Legacy floating-point `fat_details.profit` has bounded conversion to six decimals in three tenants: 2 rows in shop2, 823 in Focus House, and 11 in QA, with maximum delta below `0.000000466`. This is inside the approved half-micro-unit T208 bound and has zero overflow. All other decimal rewrites have zero value-changing rounding.

`rewrite-safety.json` SHA-256: `b2884c68be6b07da86f384123fbe3240dfa27c9dc49567255a31fea56d0789b1`.

## Direction policy and live immutability

- Staged production profile test passed and forces `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false` even when stale true input is supplied.
- Hosted role resolves `cloud`; live stale pull input is overridden with the expected warning.
- Every active tenant migration ledger remains exactly one row and still lacks the new `status` column, proving no migration apply occurred.
- Live root remains clean at `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- nginx, PHP 8.5 FPM, and MariaDB remain active.
- The disposable restore database is absent.
- Existing local and hosted workers/queues were not changed.

`final-readonly.json` SHA-256: `4be0c6feeb6bcaa0d553856c9c6d6109a83e9b42c97fe939cbeb71b85307ab84`.

## Recommendation

The artifact and migration data are ready for a separate production Judge. Any live Worker must take fresh just-in-time backups, verify them before writes, apply exact manifest-bound code and exact migration labels in an explicit maintenance sequence, keep automatic reverse sync off, and run immediate post-apply schema/data/health/queue checks with a hard rollback stop. Do not combine that decision with this read-only restaging task.
