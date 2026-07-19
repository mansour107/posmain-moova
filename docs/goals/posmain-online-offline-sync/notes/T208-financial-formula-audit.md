# T208 Financial Formula Audit

## Outcome

The aggregate-only hosted audit completed across all four active tenant databases. It confirms that the safe correction is to preserve historical NULLs and widen legacy precision, not to backfill every affected row. It also confirms that the durable order/restore contract must be corrected before promotion because it currently loses header tax/profit and coerces missing money to zero.

Protected evidence:

- `/var/www/posmain/staging/posmain-sync-20260716T181749Z/financial-formula-audit.json`
- SHA-256: `9a90a9a7f2b6b2333fa510b656622978e57b7cd6c727d06825b6552cb85c1f1f`
- Helper and report mode: `0600`
- No row IDs, row contents, credentials, or secrets are present.

## Missing `fat_total`

Only ten rows are affected, and every one is a non-order `pro_tybe=1` financial/payment header:

| Tenant | Rows | `pro_value` only | Detail candidate | No candidate |
|---|---:|---:|---:|---:|
| shop2 | 4 | 4 | 0 | 0 |
| QA | 6 | 6 | 0 | 0 |

Using `pro_value` would be unsafe. In QA's known non-NULL `pro_tybe=1` population, 0 of 54 `fat_total` values equal `pro_value`; these document types do not follow the POS-order total invariant. The missing values must remain NULL. The migration must not normalize the entire polymorphic `ot_head` table merely to impose a POS-oriented non-null rule.

For comparison, the actual POS-order class validates strongly:

- Focus House `pro_tybe=9`: 6,542/6,542 known totals equal `pro_value` at four decimals.
- shop2 `pro_tybe=9`: 84/87 equal `pro_value`; 17/25 with active details equal detail total.
- QA `pro_tybe=9`: 282/285 equal `pro_value`; 267/281 with active details equal detail total.

The minority historical mismatches further support preserving recorded source values rather than recomputing the whole table.

## Missing `profit`

Header profit is reconstructable from active line profit only for rows that actually have active lines:

| Tenant / class | NULL rows | Active-line candidate | No active detail |
|---|---:|---:|---:|
| shop2 POS (`pro_tybe=9`) | 82 | 21 | 61 |
| Focus House POS (`pro_tybe=9`) | 6,542 | 6,442 | 100 |
| QA POS (`pro_tybe=9`) | 270 | 266 | 4 |
| QA other document classes | 62 | 2 | 60 |

Known-header validation supports the POS formula: shop2 has 4/4 comparable POS headers matching active-line profit, and QA has 15/15 matching within legacy float representation noise. Nevertheless, no backfill is required for sync safety. Keeping the header nullable preserves provenance, while line profit remains available for remote monitoring and a later reviewed repair manifest could populate only proven POS candidates.

## Missing `fat_tax`

All 6,966 missing-tax rows have:

- zero nonzero tax-rate indicators,
- zero complete posted-tax candidates,
- zero partial posted-tax candidates,
- no posted-tax evidence.

That proves only that tax cannot be reconstructed from certified snapshots; it does not prove that the historical source value was zero. All affected tax values must remain NULL. Report code may display/aggregate unknown as zero, but durable source and restore payloads must retain the unknown state.

## Precision result

All inspected legacy header money fits `DECIMAL(19,4)` with no overflow or measured four-decimal rounding. This removes the material three-row QA header-profit rounding that the current `DECIMAL(19,2)` target would cause.

Legacy `fat_details.profit` is FLOAT in Focus House and QA. Conversion to decimal necessarily removes binary representation noise:

- Four-decimal target maximum delta: approximately `0.000002686`.
- Six-decimal target maximum delta: approximately `0.000000466`.
- No overflow at either target.

The six-decimal target keeps the nearest value within half of one millionth and matches the application's six-decimal quantity/cost calculations. It is the least-lossy practical exact-decimal boundary for line profit. Header profit can also use six decimals without changing any measured hosted value.

## Sync/restore contract findings

The staged code inspection proves:

- `PosOrderSnapshotBuilder::decimalString()` converts NULL to `0.00` before the event is durably stored.
- The order payload omits header `fat_tax` and header `profit`.
- The `cloud_orders` schema and `CloudOrderSnapshotService` omit first-class header `fat_tax` and `profit`.
- `CloudLegacyPosMirrorService`, used by guarded branch restore, neither inserts nor updates header `fat_tax` or `profit` and uses zero-coercing decimal conversion for other missing money.
- The current router database has no live `cloud_orders` table yet, so adding these columns to the initial cloud schema plus an idempotent additive upgrade is backward-compatible and can be tested before first production creation.

Thus a successful current sync/restore can still be financially incomplete. This must be corrected locally and covered by snapshot-to-cloud-to-empty-branch round-trip tests before any hosted schema apply or promotion.

## Immutability receipt

- Live commit remained `0183eb57ac949497d23c62cbbcd7f145a3e32c0b`, dirty count 0.
- Nginx, PHP 8.5 FPM, and MariaDB remained active.
- All four tenant migration ledgers remained exactly one row with no `status` column.
- No disposable restore database exists.
- No live schema, row, code, configuration, service, queue, worker, or symlink was changed.

## Decision boundary

Do not apply or promote yet. The next Judge should authorize a small local contract correction that:

1. preserves nullable historical header total/tax/profit rather than generating NULL-to-zero statements;
2. uses four decimals for legacy header money and six decimals for line/header profit where needed;
3. preserves NULL in durable order snapshots;
4. includes header tax/profit in cloud projection and guarded restore;
5. proves round-trip fidelity and retains stale-event/idempotency protections.
