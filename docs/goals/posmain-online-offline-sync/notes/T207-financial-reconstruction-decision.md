# T207 Financial Reconstruction Decision

## Decision

Do not repair the affected values to zero and do not apply the current financial rewrites. Authorize one aggregate-only, read-only hosted validation that tests the actual application formulas against existing non-NULL orders and measures a lossless four-decimal migration alternative.

The source trace also found a sync-contract defect independent of the migration: `PosOrderSnapshotBuilder::decimalString()` converts missing values to `0.00`, and the order payload omits header `fat_tax` and `profit`. `cloud_orders` likewise has no first-class header tax/profit columns. A restore could therefore receive a syntactically valid snapshot that has already lost the distinction between unknown and zero. The later code correction must preserve source NULL in the durable event payload and add tax/profit coverage without weakening existing financial-bundle validation.

## Source-grounded meanings

### `fat_total` / `pro_value`

Current table-order recalculation sets both fields to the sum of active `fat_details.det_value`. Current takeaway/delivery and legacy invoice create/update paths write `pro_value` and `fat_total` from the same posted header total. Delivery then derives net as total minus discount plus delivery/other charge. Legacy split-payment discount distribution can rewrite detail values and both header total fields together.

Therefore:

- `pro_value` is the strongest same-row candidate for a missing `fat_total` because the write paths assign them together.
- Sum of active detail `det_value` is a second independent candidate, but is not universally equal for every historical workflow unless discounts/charges and legacy behavior are accounted for.
- A missing total may be repaired only where a validated discriminator identifies one unambiguous candidate; otherwise NULL must be preserved.

### `profit`

Current `PosOrderService`, `TableOrderService`, the newer `PosOrderMutationService`, and both legacy invoice handlers all refresh header profit from the sum of active `fat_details.profit`. Line profit is quantity multiplied by unit sale price minus cost, and legacy split-discount flow deducts allocated discount from line profit before summing it.

Therefore sum of active detail profit is the authoritative candidate, but it must first be validated against non-NULL headers by tenant and order type. Orders with no active details, mixed deletion state, or a nonmatching known header remain unknown rather than being forced to zero.

### `fat_tax`

Current POS order paths explicitly write tax as zero, and the posted-finance report describes VAT as inactive while tax remains disabled. However, historical NULL cannot be assumed to mean zero: newer posted tax snapshots may exist, historical configurations may differ, and the current snapshot omits header tax altogether.

Tax repair is allowed only from an authoritative posted-tax sum or another formula proven against existing non-NULL taxed orders. Absence of tax evidence is not evidence of zero; preserving NULL is the safe fallback.

## Sync and reporting consumers

- `PosOrderSnapshotBuilder` currently formats missing money as zero and rounds it to two decimals before durable outbox storage.
- The same builder includes line profit but not header tax or header profit.
- `CloudOrderSnapshotService` and `CloudLegacyPosMirrorService` coerce absent numeric values to `0.0000` for projection storage.
- `cloud_orders` has total/net/discount/payment fields but no header tax/profit columns; restore relies primarily on stored event JSON.
- Cloud and posted financial reports use `COALESCE(..., 0)` for aggregation. That can be a display/report policy, but must not mutate or erase source-data provenance.
- Financial reconciliation already compares header net/tax with posted line snapshots, which supports a proof-based repair boundary instead of generic NULL normalization.

## Lossless schema direction to validate

The two QA blockers exist because the current migration narrows legacy profit to two decimal places. The application and existing database schema historically retain four-decimal line/header values, while posted financial snapshots provide the certified two-decimal document layer. The next audit must test `DECIMAL(19,4)` for legacy `ot_head` and `fat_details` monetary source fields and retain the existing nullable state where historical NULL exists. This would widen capacity without silently rounding or inventing values; it is not authorized until the hosted values are proven to fit exactly.

## T208 read-only validation contract

For each active tenant, report aggregate counts only:

1. For non-NULL `fat_total`, compare to `pro_value` and active-line `SUM(det_value)` at exact four-decimal and rounded two-decimal levels, grouped by order type/status/deletion class without exposing row values.
2. For NULL `fat_total`, count rows with a non-NULL `pro_value`, a detail-sum candidate, agreement between candidates, disagreement, or no candidate.
3. For non-NULL `profit`, compare with active-line `SUM(profit)` at exact four-decimal and rounded two-decimal levels; for NULL profit, count candidate available, no active detail, and ambiguous/mismatch classes.
4. For NULL `fat_tax`, count authoritative posted-tax availability and agreement; separately identify rows with nonzero tax percentage or other tax evidence. Do not classify no evidence as zero.
5. Re-test all affected legacy source-money columns against `DECIMAL(19,4)` for overflow and rounding delta, including the six QA rows blocked at scale two.
6. Report whether current snapshot/projection columns can preserve every source NULL and four-decimal value. This portion is code/schema metadata evidence, not data mutation.

No row identifiers, row contents, DDL, data repair, migration apply, promotion, service/config/queue action, or lock acquisition is allowed.

## Next boundary

If T208 proves deterministic reconstruction classes and lossless four-decimal targets, the next Worker may implement a small migration/snapshot contract correction with focused tests. Any residual ambiguous historical rows must remain nullable and be reported, not silently repaired. If the candidate formulas conflict materially, require an owner-reviewed repair manifest rather than guessing.
