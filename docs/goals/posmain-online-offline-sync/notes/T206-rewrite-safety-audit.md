# T206 Rewrite Safety Audit

## Outcome

Hard stop. The read-only hosted audit covered all 96 rewrite labels across the four active tenant schemas, and the live system remained unchanged. Eight historical financial NULL-normalization labels require an explicit reconstruction decision, and two QA decimal rewrites would coerce existing values by rounding. These ten labels must not be applied as currently written.

## Protected evidence

- Staged report: `/var/www/posmain/staging/posmain-sync-20260716T181749Z/rewrite-safety.json`
- SHA-256: `bae457fdc3c23e22e01b2af831e332cd72c09219d33865caa8fe2d901784609f`
- Expected labels: 96
- Audited labels: 96
- Coverage complete: yes
- `data_pass_maintenance_required`: 86
- `judge_data_repair_decision_required`: 8
- `blocked_data_conversion`: 2

Per-tenant coverage was exact: kody2 22/22, shop2 25/25, Focus House 24/24, and QA 25/25.

## Financial semantic blockers

The existing generic NULL-to-zero migration is not safe for historical order finance. The audit found:

| Tenant | Label | NULL rows | Related-data indicator |
|---|---|---:|---:|
| shop2 | `ot_head.normalize_fat_total_nulls` | 4 | 4 |
| shop2 | `ot_head.normalize_fat_tax_nulls` | 86 | 1 |
| shop2 | `ot_head.normalize_profit_nulls` | 82 | 79 |
| Focus House | `ot_head.normalize_fat_tax_nulls` | 6,542 | 314 |
| Focus House | `ot_head.normalize_profit_nulls` | 6,542 | 6,436 |
| QA | `ot_head.normalize_fat_total_nulls` | 6 | 6 |
| QA | `ot_head.normalize_fat_tax_nulls` | 338 | 2 |
| QA | `ot_head.normalize_profit_nulls` | 332 | 268 |

The related-data indicators prove that at least some missing header values coexist with business detail from which a value may be reconstructable. Writing zero without validating the authoritative calculation would silently change the meaning of recorded sales and profit history.

## Numeric conversion blockers

No decimal overflow was found, but two QA labels would round existing values:

| Tenant | Label | Rows rounded | Maximum absolute delta | Overflow rows |
|---|---|---:|---:|---:|
| QA | `fat_details.modify_profit_decimal19_2` | 3 | 0.0040026855468795475 | 0 |
| QA | `ot_head.modify_profit_decimal19_2` | 3 | 0.0040 | 0 |

Even though the deltas are small, automatic coercion is not accepted for financial history without proving the application-level calculation and header/detail reconciliation rule.

## Remaining rewrite evidence

The other 86 labels passed their data-fit checks: no overflow, incompatible enum value, invalid drawer reference, or other measured data-shape blocker was found. They still require a maintenance window because MariaDB may rebuild tables or take metadata locks for `ALTER ... MODIFY` operations.

At the audit snapshot MariaDB 11.8.6 reported:

- Long transactions at or above 30 seconds: 0
- Pending metadata locks: 0
- Tables currently in use: 0
- Disposable restore database present: no

This is only a point-in-time idle signal, not authorization for online DDL. The tenant schemas must still be treated as active and migrated only under a bounded maintenance procedure with verified backup/restore and post-migration checks.

## Immutability receipt

- Live root commit remained `0183eb57ac949497d23c62cbbcd7f145a3e32c0b` with dirty count 0.
- Each live migration ledger remained at exactly one row and still lacked the new `status` column, proving no migration apply occurred.
- Nginx, PHP-FPM, and MariaDB remained active.
- The temporary restore database remained absent.
- The staged profile still resolved the cloud role with both automatic reverse-sync flags false.
- No live schema, row, code, configuration, service, worker, queue, or symlink was changed.

## Decision boundary

Do not apply additive or rewrite migrations and do not promote the staged build yet. The next Judge must define a read-only formula-validation task that compares candidate header reconstructions against known-good non-NULL orders before any code or data repair is proposed. The repair must preserve unknown values when no authoritative reconstruction is available; it must never silently substitute zero or round historical money merely to satisfy a target column definition.
