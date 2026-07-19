# T221 Focus House local migration decision

## Decision

Go for one isolated local-schema Worker. It may restore-rehearse the existing protected dump and apply only the exact ten additive statements followed by the exact eighteen reviewed rewrites. It may not provision a worker, transfer a secret, claim or alter a queue row, enable either reverse direction, seed data, or touch the unrelated kody2/Railway service.

## Evidence

- The protected dump is mode `0600`, 257,130,969 bytes, and its host/container SHA-256 is `2dedf405ec35f126d95cfce02e2f0946bd92581b0e5e9c58d8a6dde775384150`.
- The live `focushouse` database had no other active database connection during the audit.
- Fresh planner runs select exactly ten additive statements and eighteen reviewed statements, with zero destructive or ambiguous SQL.
- The additive set creates three supporting tables, adds four `sync_revision` columns, adds cloud tax/profit columns, and adds one nullable stock-policy setting.
- The reviewed set contains only explicit `ALTER TABLE ... MODIFY COLUMN` statements. Cloud projections affected by thirteen of them currently have zero rows.
- Populated financial rewrites are range-safe: `acc_head.balance` maximum absolute value is 2,147,483,647; `fat_details.profit` is 2,935; `ot_head.fat_total` is 60,000; `ot_head.fat_tax` is zero; and `ot_head.profit` is 2,900. No value approaches the new integer limits.
- Required non-null columns `acc_head.balance` and `fat_details.profit` contain no NULLs. Row baselines are explicit for every affected populated table.

## Exact migration allowlists

Additive:

1. `legacy_closed_orders_archive`
2. `data_repair_runs`
3. `inventory_counts.add_sync_revision`
4. `inventory_purchase_orders.add_sync_revision`
5. `production_batches.add_sync_revision`
6. `sync_projection_versions`
7. `cloud_orders.add_fat_tax`
8. `cloud_orders.add_profit`
9. `settings.add_negative_stock_sale_policy`
10. `drawer_sessions.add_sync_revision`

Reviewed:

1. `cloud_orders.modify_pro_value_decimal19_4_nullable`
2. `cloud_orders.modify_fat_total_decimal19_4_nullable`
3. `cloud_orders.modify_fat_net_decimal19_4_nullable`
4. `cloud_orders.modify_fat_disc_decimal19_4_nullable`
5. `cloud_orders.modify_paid_amount_decimal19_4_nullable`
6. `cloud_orders.modify_remaining_amount_decimal19_4_nullable`
7. `cloud_order_lines.modify_qty_in_decimal19_6_nullable`
8. `cloud_order_lines.modify_qty_out_decimal19_6_nullable`
9. `cloud_order_lines.modify_price_decimal19_6_nullable`
10. `cloud_order_lines.modify_cost_price_decimal19_6_nullable`
11. `cloud_order_lines.modify_discount_decimal19_4_nullable`
12. `cloud_order_lines.modify_det_value_decimal19_4_nullable`
13. `cloud_order_lines.modify_profit_decimal19_6_nullable`
14. `acc_head.modify_balance_decimal24_6`
15. `fat_details.modify_profit_decimal19_6`
16. `ot_head.modify_fat_total_decimal19_4_nullable`
17. `ot_head.modify_fat_tax_decimal19_4_nullable`
18. `ot_head.modify_profit_decimal19_6_nullable`

## Mandatory execution gates

1. Recheck the dump hash and restore it into a disposable database. Compare table inventory and core row counts with `focushouse`, then remove the disposable database.
2. Prove the Focus House PHP/worker is stopped, there are no active `focushouse` sessions or long transactions, and the exact dry runs still produce 10/18 with no label drift.
3. Capture pre-change row counts, column definitions, NULL counts, range checks, and deterministic value fingerprints for every rewritten financial column.
4. Apply the ten exact additive labels with the verified pre-worker dump. Re-run the planner; it must show exactly the eighteen reviewed labels and nothing else.
5. Take a second protected backup after the additive phase. Apply only the eighteen exact reviewed labels using that backup and without destructive opt-in.
6. Require zero pending migrations, successful `CHECK TABLE ... QUICK` for all base tables, unchanged business row counts/value fingerprints/NULL counts, exact target column definitions, unchanged queue classifications, and disabled reverse directions.
7. If any gate fails before synchronization resumes, keep workers stopped and restore the full pre-worker dump rather than attempting an ad hoc repair.

This increment avoids mixed-schema synchronization and isolates schema risk from credentials, services, queues and hosted data.
