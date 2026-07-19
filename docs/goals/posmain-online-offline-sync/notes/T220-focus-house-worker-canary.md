# T220 Focus House worker canary

## Result

Stopped safely before provisioning or canary. The existing Focus House database and queue were recovered read-only, but the database is 28 migrations behind the release. The T220 schema-readiness hard stop therefore applies. No secret was transferred, no Focus House worker file/service was created, no queue row was claimed, and hosted data was not changed.

## Existing local dataset

- Docker Desktop was initially stopped. The compose file references the same external MariaDB volume `cbbe79275d8c14e7f3bdac929a87976f10b1302e569d9472ab21600145c82dbb`; only the existing `posmain-mysql` container was started.
- The reopened database is `focushouse` with 215 base tables.
- Its branch fingerprint resolves to the active Hetzner host `erp.withmoova.com`; role is `branch`.
- Branch UUID and cloud URL resolve from the database, while no usable local branch secret resolves.
- `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false`.

## Refreshed queue classification

- 47,828 synced rows.
- Nine pending `table.updated` / `table` rows, IDs 47837–47845, created 2026-07-06 20:48:39, attempts zero, no active locks and no retry delay.
- Seven dead `order.saved` / `order` rows, attempts one, all with the exact reason `superseded by migration rebuild snapshot`; they remain excluded from replay.
- No failed rows were present.

This exactly refreshes the T196 classification, but no pending row is authorized while schema parity is missing.

## Protected backup

- Local protected directory: `/private/tmp/posmain-focushouse-sync-t220`, mode `0700`.
- Backup: `focushouse-preworker.sql`, mode `0600`, 257,130,969 bytes.
- SHA-256: `2dedf405ec35f126d95cfce02e2f0946bd92581b0e5e9c58d8a6dde775384150`.
- The host backup wrapper failed before output because host `mysqldump` is not installed. The successful dump used the existing MariaDB container's `mariadb-dump` with single transaction, routines, triggers and events; container and copied hashes matched exactly.

## Exact schema blocker

The current release planner reports 28 pending statements:

- ten additive statements: two archive/repair tables, `sync_projection_versions`, four `sync_revision` columns, cloud order tax/profit columns, and the negative-stock policy setting;
- eighteen reviewed rewrites: widened nullable cloud order/order-line financial columns plus account balance, legacy line profit, and legacy order total/tax/profit precision/nullability.

The additive dry-run selected exactly ten and deferred exactly eighteen rewrites. No broad or destructive apply was attempted.

## Worker isolation

- Existing unrelated plist SHA-256: `287988158e2dc6c5df7ed5ab547e3a48a2d68baa1494d72cab2aea6d3953be86`.
- Existing unrelated `.env.branch-worker` SHA-256: `b3b808646871a1a452b2e129566ecff6b4b789ff6a7bf9f527278f1ded124a03`.
- Both existing files were read-only and unchanged.
- `.env.focushouse-branch-worker` and `com.posmain.focushouse-branch-worker.plist` remain absent.

## Next required action

Authorize a local Focus House migration task with a restore rehearsal, stopped POS/worker isolation, exact ten-additive then exact eighteen-reviewed allowlists, post-migration row/NULL/precision/integrity checks, and rollback to the verified backup. Worker secret transfer, service creation and canary resume only after that migration passes.

This stop avoids unexpected behavior by refusing to let new code claim old-schema events, preserving the unrelated worker, keeping reverse directions disabled, and leaving every queue row untouched.
