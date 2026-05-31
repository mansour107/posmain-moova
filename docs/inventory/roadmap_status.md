# Inventory Roadmap Status

Generated: 2026-05-31

Scope: current implementation status for `/Users/ab.mansour1agmail.com/Desktop/inventory plan.txt`.

This file is a control sheet, not a runtime feature. It summarizes which phases have source-level evidence, which phases still require live database/browser proof, and which legacy pieces must not be removed yet.

## Current Verdict

- Phase 0 write-path discovery is source-proven by `docs/inventory/write_path_map.md` and `tests/sync/inventory_write_path_map_contract_test.php`.
- The current repository contains Inventory module contracts and implementation evidence through Phase 17, plus Phase 18 stock-level policy work.
- Cutover is not proven complete because the current local DB check is blocked.
- Legacy stock endpoint retirement is source-proven: the base schema no longer creates the old `fat_details` stock triggers and known standalone legacy stock endpoints now return retired responses or route to supported handlers. Existing database trigger removal still requires reachable DB reconciliation/apply proof.
- UI work that has already landed is Arabic-first and operator-facing, but browser proof must be rerun on a reachable local DB before claiming production readiness.

## Latest Readiness Signals

| Gate | Command | Current signal |
| --- | --- | --- |
| Write-path proof | `php tests/sync/inventory_write_path_map_contract_test.php` | Passes source-line proof and sweep checks. |
| Inventory contract sweep | `for f in $(rg --files tests/sync \| rg '^tests/sync/inventory_.*\.php$' \| sort); do php "$f"; done` | Source contracts pass; DB-backed tests skip when local DB is unavailable. |
| Operational health | `php tools/inventory_operational_health_check.php --json` | Blocked by `inventory_operational_health_database_unreachable`; still reports `required_index_count = 9` and endpoint security checks. |
| Cutover readiness | `php tools/inventory_cutover_readiness.php --json` | Blocked by `inventory_cutover_readiness_database_unreachable`. |
| Legacy retirement | `php tools/inventory_legacy_retirement_check.php --json` | Source check passes with retired endpoint controls and no pending source blockers; existing DB trigger state still requires live DB proof. |
| Production readiness aggregate | `php tools/inventory_production_readiness.php --json` | Blocked until cutover, operational health, recipe runtime, legacy retirement, and in-app browser/operator QA evidence all pass; missing browser proof reports `browser_operator_qa_evidence_missing`; reviewed cutover evidence can be forwarded with the same decision/acceptance flags as the cutover gate. |

## Phase Matrix

| Phase | Roadmap area | Current evidence | Status |
| --- | --- | --- | --- |
| 0 | Discovery and write-path map | `implementation_discovery.md`, `write_path_map.md`, `current_schema_findings.md`, `inventory_write_path_map_contract_test.php` | Source-proven; live DB proof must be rerun before behavior changes. |
| 1 | Flags/no-op services | `phase1_noop_contracts.md`, `InventoryFeatureFlags`, scope/decimal/permission services | Implemented as disabled-by-default scaffolding. |
| 2 | Additive schema | `phase2_schema_contracts.md`, `SyncSchemaManager`, `inventory_phase2_schema_contract_test.php` | Additive plan/source proof exists; DB apply proof skips when DB is unavailable. |
| 3 | Ledger/balance service | `phase3_ledger_contracts.md`, `InventoryLedgerService`, `InventoryBalanceService` | Service contract exists; DB-backed ledger proof requires reachable DB. |
| 4 | Shadow bridge | `phase4_shadow_bridge_contracts.md`, `InventoryInvoiceBridge` | Shadow/bridge ownership is documented; old behavior remains active. |
| 5 | Reconciliation | `phase5_reconciliation_contracts.md`, reconciliation check/repair tools | Read-only proof layer exists; live reconciliation must be clean or explicitly accepted before cutover. |
| 6 | Purchasing | `phase6_purchase_bridge_contracts.md`, purchasing page/endpoints/services/tests | Purchase receiving/PO flows exist; supplier catalog remains intentionally not added. |
| 7 | Counts | `phase7_count_contracts.md`, count pages/endpoints/service/tests | Count workflow exists; DB-backed close/reversal proof requires reachable DB. |
| 8 | Transfers | `phase8_transfer_contracts.md`, transfer pages/endpoints/service/tests | Transfer workflow exists; browser evidence is historical and must be rerun before production signoff. |
| 9 | Waste and adjustments | `phase9_adjustment_contracts.md`, `inventory_adjustments.php`, `InventoryAdjustmentService` | Inventory module owns waste/adjustments; old recipe waste surface has been retired. |
| 10 | Production | `phase10_production_contracts.md`, `recipe_production.php`, production services/tests | Production links recipe/BOM with inventory; DB-backed endpoint proof requires reachable DB. |
| 11 | POS availability/reservations | `phase11_pos_availability_contracts.md`, recipe reservation/availability services/tests | Reservation/availability contracts exist; strict-stock rollout remains pilot-gated. |
| 12 | Accounting pilot | `phase12_accounting_contracts.md`, accounting service/reconciliation tests | Accounting pilot exists; reconciliation must be clean or explicitly accepted before trigger retirement. |
| 13 | Reports/dashboard | `phase13_reports_contracts.md`, `inventory_reports.php`, `inventory_dashboard.php` | Read-only manager reports exist with Arabic labels and cost gates. |
| 14 | Historical migration | `phase14_migration_contracts.md`, migration/backfill/rebuild tools | Tools are guarded; apply requires backup and reviewed differences. |
| 15 | Cutover | `phase15_cutover_contracts.md`, cutover readiness tool | Not complete; current readiness is blocked by unavailable DB. |
| 16 | Legacy retirement | `phase16_legacy_retirement_contracts.md`, retirement checks/tools | Source-level retirement passes; existing DB trigger retirement apply still needs reachable DB, reconciliation, and backup proof. |
| 17 | Hardening/performance | `phase17_hardening_contracts.md`, operational health tool/service | Source controls exist; DB index inspection is blocked until DB is reachable. |
| 18 | Stock levels/replenishment policy | `phase18_stock_level_contracts.md`, stock-level page/service/tests | Policy workflow exists without moving stock; DB-backed service proof requires reachable DB. |

## Production Readiness Aggregate

`php tools/inventory_production_readiness.php --json` is the single honest final gate for production signoff. It is read-only and combines:

- `php tools/inventory_legacy_retirement_check.php --json`
- `php tools/inventory_cutover_readiness.php --json`
- `php tools/inventory_operational_health_check.php --json`
- `php tools/recipe_runtime_preflight.php --json`
- explicit in-app browser/operator QA evidence via `--browser-evidence=/absolute/path/to/evidence.json`

When migration or reconciliation findings have been formally reviewed, the
aggregate forwards the same cutover evidence flags to
`inventory_cutover_readiness.php`: `--decisions-file`, `--acceptance-file`,
`--allow-accepted-reconciliation`, `--rebuild-acceptance-file`,
`--allow-accepted-rebuild-differences`, `--accounting-acceptance-file`,
`--allow-accepted-accounting-reconciliation`, and `--skip-accounting-gate`.

The aggregate must stay red while any live gate is unavailable, while `POSMAIN_INVENTORY_LEDGER_MODE` is not `live`, or while browser evidence is missing. It does not apply migrations, retire triggers, flip flags, repair data, write stock, or create browser evidence for itself.

## Phase Evidence Index

| Phase | Contract document | Focused test entry point |
| --- | --- | --- |
| 0 | `docs/inventory/phase0_completion_audit.md` | `tests/sync/inventory_write_path_map_contract_test.php` |
| 1 | `docs/inventory/phase1_noop_contracts.md` | `tests/sync/inventory_phase1_noop_contract_test.php` |
| 2 | `docs/inventory/phase2_schema_contracts.md` | `tests/sync/inventory_phase2_schema_contract_test.php` |
| 3 | `docs/inventory/phase3_ledger_contracts.md` | `tests/sync/inventory_phase3_ledger_service_test.php` |
| 4 | `docs/inventory/phase4_shadow_bridge_contracts.md` | `tests/sync/inventory_phase4_invoice_bridge_test.php` |
| 5 | `docs/inventory/phase5_reconciliation_contracts.md` | `tests/sync/inventory_reconciliation_check_contract_test.php` |
| 6 | `docs/inventory/phase6_purchase_bridge_contracts.md` | `tests/sync/inventory_phase6_receiving_service_test.php`, `tests/sync/inventory_phase6_purchase_order_service_test.php` |
| 7 | `docs/inventory/phase7_count_contracts.md` | `tests/sync/inventory_phase7_count_service_test.php` |
| 8 | `docs/inventory/phase8_transfer_contracts.md` | `tests/sync/inventory_phase8_transfer_service_test.php` |
| 9 | `docs/inventory/phase9_adjustment_contracts.md` | `tests/sync/inventory_phase9_adjustment_service_test.php`, `tests/sync/inventory_adjustment_endpoint_runtime_test.php` |
| 10 | `docs/inventory/phase10_production_contracts.md` | `tests/sync/inventory_phase10_production_surface_contract_test.php`, `tests/sync/recipe_production_endpoint_runtime_test.php` |
| 11 | `docs/inventory/phase11_pos_availability_contracts.md` | `tests/sync/inventory_phase11_pos_availability_contract_test.php` |
| 12 | `docs/inventory/phase12_accounting_contracts.md` | `tests/sync/inventory_phase12_accounting_service_test.php` |
| 13 | `docs/inventory/phase13_reports_contracts.md` | `tests/sync/inventory_phase13_reports_contract_test.php`, `tests/sync/inventory_phase13_reports_service_test.php` |
| 14 | `docs/inventory/phase14_migration_contracts.md` | `tests/sync/inventory_phase14_migration_tools_contract_test.php`, `tests/sync/inventory_phase14_migration_service_test.php` |
| 15 | `docs/inventory/phase15_cutover_contracts.md` | `tests/sync/inventory_phase15_cutover_contract_test.php`, `tests/sync/inventory_phase15_cutover_service_test.php` |
| 16 | `docs/inventory/phase16_legacy_retirement_contracts.md` | `tests/sync/inventory_phase16_legacy_retirement_contract_test.php` |
| 17 | `docs/inventory/phase17_hardening_contracts.md` | `tests/sync/inventory_phase17_hardening_contract_test.php` |
| 18 | `docs/inventory/phase18_stock_level_contracts.md` | `tests/sync/inventory_phase18_stock_level_service_test.php` |

## Do Not Treat As Complete Yet

- Do not run existing-DB `fat_details` trigger retirement apply without current reconciliation, accounting reconciliation, and backup proof.
- Do not re-enable retired legacy stock endpoint files; stale clients should receive the retired response or use supported inventory/POS handlers.
- Do not turn off the `myitems.itmqty` compatibility mirror until reconciliation and browser proof are current.
- Do not claim production-ready Foodics-level inventory until cutover readiness, legacy retirement readiness, accounting reconciliation, and in-app browser smoke tests are green on the intended DB.

## Next Safe Work Without DB

- Improve source contracts and readiness reporting.
- Improve Arabic operator UX without changing payload contracts or stock write ownership.
- Keep documenting every intentional write/read-path change in `write_path_map.md` and the relevant phase contract.

## Work That Must Wait For DB

- Live ledger cutover.
- Historical migration apply.
- Legacy trigger retirement apply.
- Final deletion of legacy stock-write surfaces.
- Production readiness claims.
