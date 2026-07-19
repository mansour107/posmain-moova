# T218 production hosted deployment

## Result

Pass. The hosted checkout, router and four active tenant databases were backed up, restore-rehearsed, migrated with the exact T216 label sets, promoted to the exact release commit, and returned to service with all post-deployment gates passing. No rollback was required.

This completes hosted code/schema parity only. Focus House still needs its dedicated automatic branch worker, controlled current backlog drain, authoritative initial seed for newly covered domains, and restore/reconciliation certification before the whole offline/cloud goal is production-ready.

## Exact release

- Branch: `codex/posmain-sync-production-20260717`
- Commit: `ec0c76047a295ccb8a155fe41e843eda68e5c12a`
- Commit message: `Harden offline cloud synchronization`
- Release boundary: exactly 103 non-goal T216 paths; goal receipts were excluded.
- Hosted promotion: detached checkout in `/var/www/posmain/current`.
- All 103 promoted files matched the protected T216 payload; 99 changed PHP files passed hosted lint.
- Ignored `.env`, `uploads`, `logs`, `backup`, and `var` metadata and content manifests remained exactly unchanged through promotion.

## Fresh protected backups and rehearsal

- Maintenance ID: `posmain-sync-prod-20260717T094817Z`.
- Protected directory: `/var/backups/posmain/posmain-sync-prod-20260717T094817Z`, mode `0700`; backup files and receipts use mode `0600`.
- Router and all four tenant dumps were non-empty and SHA-256 verified before and after dumping; exact schema/row fingerprints did not move during backup.
- The live `.env`, runtime paths, and complete repository Git bundle were backed up and verified.
- The kody2 dump was restored into a uniquely named disposable database and matched exactly: 192 objects, 192 base tables, 989 rows, zero schema mismatches, zero row mismatches, and zero integrity failures. The disposable database was removed and its absence verified.
- A first backup attempt failed safely on an unsupported dump option before any database write. It was abandoned; the successful maintenance set above was created from scratch.

## Maintenance isolation

- PHP 8.5 FPM was stopped before migration and remained stopped through database validation, code promotion, runtime comparison, lint, and production-profile checks.
- nginx and MariaDB stayed active.
- There were zero long transactions, metadata-lock waits, table-lock states, or hosted sync-worker processes before the first write.
- Automatic `cloud_pull_enabled` and `cloud_to_branch_publish_enabled` remained false.

## Exact migration

- Additive phase: exactly 113 labels applied to each tenant; exactly 35 reviewed rewrites remained; each ledger had 114 rows and zero `started` entries.
- Fresh post-additive dumps were created and verified for all four tenants before rewrites.
- Reviewed phase: exactly 35 reviewed labels applied to each tenant without destructive opt-in; zero pending labels remained; each ledger had 149 applied rows and zero `started` entries.
- Additive receipt SHA-256: `ac4d083e95138e50667957c8f117383484329abd5f2ad311a672ccfabc0b1f23`.
- Post-additive backup receipt SHA-256: `1be523ea04b09a1f413631fd766ed33af2f702856721c4fe263ceeb2a9f38062`.
- Reviewed receipt SHA-256: `06f1ecc94d68a8cc1fd7b8a47d4edde6d833ef98a581f1dd785da82634893fb3`.

The first additive invocation failed closed before calling the migration runner because the maintenance helper mistakenly classified all 148 pending statements as if they belonged to the additive phase. All four ledgers were then rechecked at their exact pre-migration state: one legacy row and no status column. The original helper was archived, and the only correction was to classify and checksum the already-selected phase statements. Original SHA-256 `1cba7131a76707d069f01361d7ffc73d688e771543ed83d3e73427c6726f89cf`; corrected SHA-256 `398e5015922df22dc6928171d786376a1ec45a694869b2fc0b1009cc7b10bd20`. The corrected helper linted cleanly before the successful retry.

## Post-migration fidelity

The protected post-migration validator passed before code promotion:

- zero pending migrations on all tenants;
- 149 unique applied migration versions and zero incomplete/malformed receipts per tenant;
- every base table passed `CHECK TABLE QUICK`;
- business row totals exactly matched the pre-maintenance totals after excluding the migration ledger: kody2 988, shop2 3,496, Focus House 173,297, QA 6,820;
- all 35 reviewed columns per tenant matched target precision, scale, enum, unsignedness and nullability;
- audited table row counts, historical NULL counts and enum value counts were exactly preserved.

Validation receipt SHA-256: `0c767de40118f8db01ad3422d8c6c224094a84864c1f766f33bbb98e31a5a25e`.

## Production restart and hosted health

- nginx, MariaDB, and PHP 8.5 FPM are active.
- Public endpoint matrix equals the pre-maintenance baseline:
  - `api/health.php` 200
  - `api/ready.php` 200
  - sync receive GET 405
  - unauthenticated restore export 400
  - unauthenticated sync status 403
  - `version.json` 200
- Production profile contracts passed. Hosted role is `cloud`, production mode is enabled, and both automatic reverse directions are disabled.
- Fresh PHP-FPM/nginx error-journal counts and application fatal-pattern counts were zero.
- Live code reported zero pending migrations, 149 applied ledger rows per tenant, and zero open sync conflicts.
- Post-start sync-health receipt SHA-256: `ac8bbaef8321633b1c46aa5eabe73ced041c4315ef2b9297f9067b0f29f7e87d`.

Current hosted evidence also identifies the remaining end-to-end gap. Focus House has 47,553 processed and 275 duplicate inbound events, with order, line, payment, receipt, table and menu projections populated and no open conflicts. Its latest accepted event is still 2026-07-04, and `cloud_shifts` remains empty. The QA tenant proves the deployed shift projection path can populate (`cloud_shifts=10`), so Focus House needs its dedicated current branch worker and controlled initial/current-domain synchronization rather than another hosted schema change.

## Regression avoidance

- No mixed old-code/new-schema state was served; PHP-FPM stayed stopped until schema, data, code, runtime and profile checks passed.
- No broad or destructive migration scope was used.
- Historical financial NULLs, precision, row counts and enum distributions were explicitly checked after rewrites.
- Runtime data and configuration were preserved byte-for-byte through checkout.
- Automatic cloud-to-branch behavior was never enabled.
- No local Focus House or unrelated kody2/Railway worker, queue event, or dead row was changed during this hosted deployment.
