# T198 Staging and Backup Receipt

## Result

Backups and isolated staging succeeded, but migration apply and code promotion are blocked. No live code, configuration, service, symlink, queue or database row was changed.

Build ID: `posmain-sync-20260716T174744Z`.

## Private rollback backups

Created `/var/backups/posmain/posmain-sync-20260716T174744Z` as mode `0700`. Every contained file is mode `0600`; a second pass recomputed every hash and reported `backup_verify=pass`, six protected payload files, and `320429843` total payload bytes.

| Scope | Database | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| Router | `posmain_router` | 1,295,036 | `8b136d09cbb42d895e6e62e8f4f220bb25087f3627faee9bcd92e4883768c06b` |
| Tenant 1 | `kody2` | 606,326 | `81059a3ada208569da50eb61cfeb163893d3bbf29ebea3218533ae23dad017af` |
| Tenant 3 | `posmain_shop2` | 7,934,240 | `08caae1197d88bcc7dd4e530f5ff7de11a175de176484c5aed80dcc282b1eb75` |
| Tenant 4 | `focushouse` | 298,822,314 | `3b5f73d7b03dd0810f69b42db2b445b4e24d51749f2ad0d0dc24f1fa86b93164` |
| Tenant 5 | `posmain_qa_qa_campaign_hosted_20260625_105426_c4be` | 11,769,473 | `e26c8f7bb0aeb90b6a5e3f3c7e2da0afe643c9c0bbfc1dd921a4fdbcff54eeb9` |

The protected live-config backup is 2,454 bytes, mode `0600`, SHA-256 `41ae1a0b70f59853a7779db679fc0dcb79ee3b11570b26589c4320d9b8f11c57`. Credentials were obtained by the application and passed to `mariadb-dump` through its process environment, never command arguments or output.

All dumps exited zero and are nonempty. MariaDB emitted a warning stream for each dump; the helper deliberately recorded only its presence and did not persist or print its content. A disposable restore rehearsal remains required before relying on these backups for a later production mutation.

## Manifest-bound isolated release

- Local base commit: `67c4848985e81ec7e92eb03664967043bb75bfff`.
- Hosted base commit: `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- Overlay: 73 tracked modifications and 44 non-ignored untracked files; no tracked deletions.
- No overlay path matched `.env`, secret, upload, log, backup, cache, vendor, `node_modules` or `.git` runtime patterns.
- The exact `git archive HEAD` base contains the tracked non-secret placeholders `.env.example` and `logs/.gitkeep`; no live `.env` or runtime log was copied.
- Archive SHA-256: `c2045ad940a29b64adbe58ca24b66cbfa54caa9069afa94cdafd29c57cce6931`.
- Manifest SHA-256: `2172a90878c522342117c03ada56ba6f3046a0a0463b08ebd97aa5c41c9ad84f`.
- Staged path: `/var/www/posmain/staging/posmain-sync-20260716T174744Z`.
- All 117 overlay hashes were recomputed against the extracted staged files: `overlay_verify=pass`.
- Key staged hashes equal local hashes for `BranchSyncWorker.php`, `SyncProjectionVersionGuard.php` and `BranchRestoreFromHostedService.php`.
- All 94 changed/untracked PHP files linted successfully.

GNU tar ignored macOS `com.apple.provenance` extended-header metadata during extraction. File-content hashes remained exact, including all 117 overlay paths, so this is packaging noise rather than a content ambiguity.

## Read-only router and tenant dry-runs

Router upgrade discovery is clear: zero pending router statements.

| Tenant | Pending | Additive | Rewrite | Destructive | Ambiguous | Ledger shape |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| `kody2` | 133 | 111 | 22 | 0 | 0 | incompatible; missing `status` |
| `posmain_shop2` | 136 | 111 | 25 | 0 | 0 | incompatible; missing `status` |
| `focushouse` | 135 | 111 | 24 | 0 | 0 | incompatible; missing `status` |
| QA tenant | 136 | 111 | 25 | 0 | 0 | incompatible; missing `status` |

The complete label, classification and SQL SHA-256 report is protected at `/var/www/posmain/staging/posmain-sync-20260716T174744Z/migration-dry-run.json`, mode `0600`, SHA-256 `8af85b1f52fb1098da2d4c75155e14b6fc4d276a673bf0453cf6cb6fcac64079`.

The rewrite families include decimal/enum/nullability changes across `acc_head`, `fat_details`, `ot_head`, `order_payments`, `drawer_sessions`, `inventory_item_balances`, `myitems` and `drawer_movements`. Some tenants additionally require null-normalization updates. No destructive statement or stored-checksum mismatch was found, but the rewrite count itself triggers the T198 hard stop.

Every tenant's legacy `schema_migrations` table has one row and is missing `status`. The current runner adds `status`, but its subsequent record path also expects `metadata_json`; that column exists. The staged preflight therefore correctly treats the current shape as apply-incompatible until the runner's tracking-table upgrade is safely exercised and verified before business migrations.

## Policy and live immutability

- Hosted staged config resolves role `cloud`, `cloud_to_branch_publish_enabled=false`, but `cloud_pull_enabled=true`. This violates the approved manual-only recovery policy and blocks promotion.
- Active root remained a clean checkout at `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`.
- `SyncProjectionVersionGuard.php` remains absent from the active root, proving the staged file was not promoted.
- Nginx, PHP-FPM and MariaDB remained active.
- Disk remains healthy at about 29 GB free.
- The unrelated local `kody2`/Railway supervisor and all Focus House queues were untouched.

## Recommendation

Do not apply the broad 133–136-statement tenant plans as one production step. The next Judge should split schema parity into a narrowly verified migration-runner compatibility fix plus a minimum sync-only additive tranche, keep rewrite migrations behind separate data validation and maintenance planning, and require `cloud_pull_enabled=false` before promotion. A disposable restore rehearsal of the fresh dumps should precede any live apply.
