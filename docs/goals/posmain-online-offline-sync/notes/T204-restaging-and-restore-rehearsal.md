# T204 fresh staging and restore rehearsal

## Result

Fresh artifact staging, backup restore rehearsal, and explicit all-tenant additive dry-runs passed. Promotion and migration apply remain hard-stopped because current sync runtime code also depends on 22–25 non-additive rewrite migrations per tenant.

No active code, schema, data row, service, queue, symlink, credential, or configuration was changed.

## Fresh isolated artifact

- Build ID: `posmain-sync-20260716T181749Z`.
- Local base commit: `67c4848985e81ec7e92eb03664967043bb75bfff`.
- Hosted live base remained: `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`, clean.
- Overlay: 75 tracked modifications plus 53 non-ignored untracked files; 128 total; no tracked deletions.
- The overlay safety filter admitted no `.env`, secret/key, upload, log, backup, cache, vendor, node_modules, or `.git` runtime path.
- Payload SHA-256: `23480db8f7ac7253bb66a8ad4b70381f1957e346362c43b00f4fd87521155cc2`.
- Overlay manifest SHA-256: `318995e554e5655f8806660b1d3df80ed8e1d1166ac76f0ae322abec7bd9ef9a`.
- Hosted path: `/var/www/posmain/staging/posmain-sync-20260716T181749Z`, mode `0700`; top-level files mode `0600`.
- All 128 overlay files hash-verified after extraction; all 99 overlay PHP files linted.
- Local and staged hashes match for the migration planner, runner, production profile, and both focused tests.

## Protected backup restore rehearsal

The protected T198 `kody2` dump hash matched `81059a3ada208569da50eb61cfeb163893d3bbf29ebea3218533ae23dad017af`.

The first attempt stopped before database creation because the dump contains its own `CREATE DATABASE` and `USE` statements. The corrected rehearsal copied the dump only into the protected staging directory, rewrote exactly two quoted `` `kody2` `` database identifiers to the unique disposable name, proved no quoted live identifier remained, imported only that rewritten copy, and removed it after use.

Comparison result:

- 192 source/restored objects and 192 base tables;
- 989 exact source rows and 989 exact restored rows;
- matching object set;
- zero exact row-count mismatches;
- zero `SHOW CREATE TABLE` hash mismatches;
- zero `CHECK TABLE ... QUICK` integrity failures;
- disposable database removed and independently verified absent.

Evidence: `restore-comparison.json`, SHA-256 `19d936aba389cf77657e1c7af0abeaa68a8c65441eeca8de9405bdfc2eeb6973`.

## Migration review

The router has zero pending upgrades. Four active tenants were discovered through the router and inspected read-only with the staged planner/runner.

| Tenant | Pending | Additive | Rewrite | Explicit labels dry-run | Required non-additive |
| --- | ---: | ---: | ---: | ---: | ---: |
| kody2 | 133 | 111 | 22 | pass, 111 labels | 22 |
| shop2 | 136 | 111 | 25 | pass, 111 labels | 25 |
| focushouse | 135 | 111 | 24 | pass, 111 labels | 24 |
| QA | 136 | 111 | 25 | pass, 111 labels | 25 |

The prerequisite set was not inferred from label names. Every pending statement was mapped to its base table, matched against literal current `classes/Sync` runtime references, and closed transitively over SQL foreign-key dependencies. That code-grounded pass selected all 111 additive candidates and also identified every rewrite as touching a sync-referenced table. Each final additive dry-run used an explicit `--labels=` list and selected no rewrite, destructive, or ambiguous statement.

The rewrite families are decimal precision/nullability changes across order, payment, ledger, drawer, inventory-balance, and item tables; up to three NULL-normalization updates; `myitems` enum expansion; and drawer movement nullability/enum changes. These are required for exact current runtime parity and cannot be silently ignored.

Evidence: `migration-review.json`, SHA-256 `99d0131ce444b5e581b7014bdc0f3f92c3186a184abf9860cc9c85bf43358e12`.

## Direction policy and live immutability

- Staged production config resolves role `cloud` with both `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false`; the stale live true input is overridden with a warning.
- Every active tenant migration ledger still has exactly one row and still lacks `status`, proving no apply occurred.
- The disposable database is absent.
- Live current root remains clean at `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- nginx, PHP-FPM, and MariaDB remain active.
- Focus House queues and the unrelated local kody2/Railway worker were untouched.

## Recommendation

Do not apply or promote yet. Run a read-only per-tenant rewrite-safety audit: current column definitions, row/table sizes, NULL-normalization counts, enum values, numeric ranges, and maintenance/lock implications. A later Judge can then split additive creation from validated rewrites and define rollback/maintenance sequencing.
