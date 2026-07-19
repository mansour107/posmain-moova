# T210 Financial Contract Correction

## Outcome

Implemented the bounded version-4 financial-fidelity correction locally. No hosted system was accessed or changed during this Worker.

The durable POS order path now preserves unknown financial values as `NULL`, carries header tax and profit through automatic branch upload and guarded manual restore, and retains the audited four/six-decimal source precision.

## Implemented contract

- POS order snapshots now use schema version 4.
- Header money is encoded at four decimals; header profit at six decimals.
- Quantity, unit price/cost and line profit are encoded at six decimals.
- `fat_tax` and header `profit` are present in the durable order payload.
- Missing/non-numeric source values remain `NULL`; they are no longer converted to zero.
- Cloud order projection stores nullable header money, four-decimal tax and six-decimal profit.
- Cloud order lines store nullable six-decimal quantities, prices, cost and profit.
- Guarded restore writes header tax/profit when the target schema supports them and remains compatible with older target schemas that lack those optional columns.
- Existing schema-v1/v2/v3 events remain accepted. Automatic cloud-to-branch mutation remains disabled.

## Migration correction

- `ot_head.fat_total` and `ot_head.fat_tax` target `DECIMAL(19,4) NULL DEFAULT NULL`.
- `ot_head.profit` targets `DECIMAL(19,6) NULL DEFAULT NULL`.
- No NULL-to-zero migration is generated for those fields.
- `fat_details.profit` targets `DECIMAL(19,6)` instead of the lossy two-decimal target.
- New cloud projection tables use the lossless nullable definitions.
- Existing cloud projection tables receive additive tax/profit columns plus separately classified rewrite statements for precision/nullability changes.

The local read-only migration CLI reported 28 pending changes: 10 additive and 18 deferred rewrites. It selected `cloud_orders.add_fat_tax` and `cloud_orders.add_profit` as additive, and correctly classified all projection precision/nullability and legacy financial changes as rewrites. No affected NULL-normalization statement appeared.

## Verification

Green gates:

- PHP syntax and scoped diff checks for every changed implementation/test file.
- Focused migration/upload/projection suite: 24 tests, 389 assertions, no warnings.
- End-to-end branch outbox to cloud projection suite after added NULL/fidelity coverage: 6 tests, 56 assertions.
- Guarded financial restore: exact four-decimal tax, six-decimal profit, old-event NULL compatibility, idempotency and financial-bundle conflicts.
- Older target-schema order/fulfillment restore remains green with optional financial columns absent.
- Branch cloud polling: 4 tests, 49 assertions.
- Cloud report service: 2 tests, 40 assertions.
- Fulfillment atomicity, category restore, variant restore and migration-plan runtime contracts pass.
- Goal Maker checker and all touched-file syntax/diff checks pass.

Adjacent red gates reproduced independently and were not changed in this slice:

- `remaining_write_surfaces_outbox_test` expects a literal `recordOrderSnapshot` call inside the clean router endpoint `ajax/save_order.php`; the endpoint currently delegates through `pos_api_dispatch`.
- `branch_cloud_runtime_test::testBranchWorkerKeepsNetworkFailuresRetryable` expects `failed` but observes `synced` when run alone.

These should be judged separately before final production certification; neither failure is in the changed financial snapshot/projection/restore path.

## Regression controls

- No historical total, tax or profit was backfilled.
- No hosted code, schema, data, service, worker, queue or configuration was touched.
- No automatic reverse-sync behavior was introduced.
- Existing stale-event, financial-bundle and guarded restore rules remain in force.
- Test fixture identities were made unique so repeated runs do not leave collision-prone residue.
