# Phase 4 Focused Midscale Tests - 2026-05-14

## Purpose

Refresh focused Phase 4/midscale product evidence without editing code, applying migrations to current `kody2`, saving POS orders, or changing runtime configuration.

## Safety Classification

The `tests/sync/phase4_*.php` set is source-contract and disposable-schema/service coverage. The disposable tests create `posmain_phase4_*` databases and drop them in `finally` blocks. No current `kody2` migration or production-like seed was applied.

## Batch Command

```sh
tmpout=/tmp/posmain-phase4-test-output.txt
: > "$tmpout"
failed=0
for f in $(rg --files tests/sync | rg 'phase4_' | sort); do
  printf '%s\t' "$f"
  POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php "$f" > "$tmpout" 2>&1
  code=$?
  if [ "$code" -eq 0 ]; then
    tail -n 1 "$tmpout"
  else
    echo "FAIL code=$code"
    cat "$tmpout"
    failed=1
  fi
done
exit "$failed"
```

## Results

All focused Phase 4 tests passed:

```text
phase4-browser-print-audit-service-ok db=posmain_phase4_print_audit_44064
phase4-cashier-advanced-controls-contract-ok
phase4-cashier-line-note-contract-ok
phase4-decimal-stock-inventory-ok db=posmain_phase4_decimal_stock_44070
phase4-drawer-payment-integration-ok db=posmain_phase4_drawer_payment_44072
phase4-drawer-session-service-ok db=posmain_phase4_drawer_sessions_44078
phase4-item-availability-order-blocking-ok db=posmain_phase4_availability_block_44080
phase4-item-availability-service-ok db=posmain_phase4_item_availability_44082
phase4-manager-approval-integration-contract-ok
phase4-manager-approval-service-ok db=posmain_phase4_manager_approval_44086
phase4-merge-table-endpoint-contract-ok
phase4-midscale-schema-migration-ok db=posmain_phase4_schema_44090
phase4-modifier-line-note-service-ok disabled db=posmain_phase4_modifiers_44092
phase4-move-table-endpoint-contract-ok
phase4-nutrition-profile-service-ok disabled db=posmain_phase4_nutrition_44096
phase4-order-print-payload-service-ok db=posmain_phase4_print_payload_44099
phase4-payment-method-service-ok db=posmain_phase4_payment_methods_44102
phase4-print-job-service-ok db=posmain_phase4_print_jobs_44105
phase4-print-template-audit-contract-ok
phase4-print-template-payload-contract-ok
phase4-restaurant-report-contract-service-ok
phase4-search-availability-endpoint-contract-ok
phase4-shift-drawer-reconciliation-service-ok db=posmain_phase4_shift_reconcile_44117
phase4-table-merge-service-ok db=posmain_phase4_table_merge_44119
phase4-table-merge-ui-contract-ok
phase4-table-move-ui-contract-ok
phase4-table-transfer-service-ok db=posmain_phase4_table_transfer_44133
phase4-z-close-drawer-contract-ok
phase4-z-report-drawer-contract-ok db=posmain_phase4_z_report_44138
```

Temporary schema cleanup check:

```sh
docker exec posmain-mysql mariadb -uroot -N -e "SHOW DATABASES LIKE 'posmain_phase4_%';"
```

Output: no rows.

Current POS baseline after tests:

```sh
curl -s -o /tmp/posmain-after-phase4-tests-http.txt -w '%{http_code}\n' http://127.0.0.1:8010/index.php
```

Result:

```text
200
```

## What This Covers

- Phase 4 additive schema and legacy-table migration path when expected legacy anchor columns exist.
- Advanced cashier controls and line-note source contracts.
- Decimal stock and item availability behavior.
- Drawer sessions, drawer payments, shift drawer reconciliation, and Z close/report contracts.
- Payment method service.
- Print audit, print jobs, and print payload/template contracts.
- Manager approval service and integration contracts.
- Table merge/move/transfer service and UI/endpoint contracts.
- Modifier line notes and nutrition profile service paths.
- Restaurant report contract.
- Search availability endpoint contract.

## Important Boundary

This does not clear the previously recorded minimal-schema fixture blocker:

- `table_order_counter_smoke_test.php` still fails when the reduced `ot_head` fixture lacks `table_id`.
- `uuid_backfill_smoke_test.php` still fails when the reduced `tables` fixture lacks `table_case`.

The green Phase 4 batch proves the intended Phase 4 path works against representative legacy anchors, while the separate smoke failures prove `SyncSchemaManager` is still not robust to intentionally minimal fixture schemas.

## Verdict

This focused Phase 4/midscale slice passed.

Overall P0-P6 strict certification remains blocked by failures outside this green slice:

- `js/pos_auto_lock.js` syntax failure.
- Pending `order_fulfillment` migration on current `kody2`.
- Persistent Moova `/readyz` is degraded with `redis=false`.
- Moova widget contract test failure.
- Write-surface inventory test failure.
- Minimal schema fixture smoke failures.
- Cashier-facing Moova degraded-state UX still presents empty-queue copy while runtime health is red.
