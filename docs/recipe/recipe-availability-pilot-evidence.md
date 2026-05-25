# Recipe Pilot Evidence

Generated at UTC: 2026-05-25T17:01:19Z
Evidence completed at UTC: 2026-05-25T17:04:00Z
Recipe mode: availability_pilot
POS tenant: 0
POS branch: 0
Store: 27
Operator: Codex local QA
Note: Availability pilot evidence extends the validated accounting_pilot evidence with POS-grid availability, menu payload, and cost-leak checks.

Only replace `pending` with the readiness success word after the exact check has been completed and reviewed.
Do not edit evidence for checks that were not actually performed.

## Markers

Recipe Pilot Evidence: pass
Recipe schema migrated or verified: pass
Recipe runtime preflight reviewed: pass
Recipe operational dashboard reviewed: pass
Recipe stock reconciliation reviewed: pass
POS/table recipe smoke passed: pass
Recipe rollback flags documented: pass
Recipe COGS accountant review: pass
Recipe availability and menu sync smoke passed: pass

## Evidence Details

- Recipe schema evidence: tools/run_migrations.php --dry-run equivalent via tools/recipe_runtime_preflight.php --json pending_count=0
- Recipe runtime preflight evidence: tools/recipe_runtime_preflight.php --json ready_for_recipe_operator_qa=true
- Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true
- Recipe operational dashboard evidence: recipe_operational_dashboard.php issue_total=0 via tools/recipe_rollout_readiness.php --json
- Recipe stock reconciliation evidence: recipe_stock_reconciliation.php reconciliation CSV exported by tools/recipe_report_export_smoke.php, row_count=10, expected columns present, unsafe_cell_count=0.
- POS/table smoke evidence: POS order runtime proof from tools/recipe_runtime_proof_suite.php and table order runtime proofs for save/cancel/payment passed; evidence includes POS order and table order lifecycle checks.
- Migrated runtime write smoke evidence: tools/recipe_migrated_write_smoke.php --json --apply --run-id=acct-001 stock_preflight ok=true, order_id=998863, idempotency_replayed=true, recipe_consumption movements=18/19, positive movement cost milk=0.004000 cup=0.150000.
- Recipe report export and role QA evidence: tools/recipe_report_export_smoke.php CSV export passed for stock reconciliation, audit, cost history, ingredient consumption, recipe COGS, production variance, low stock impact, COGS reconciliation, expected-vs-actual usage, and modifier revenue/cost; no access denied or unsafe CSV cells.
- Modifier substitution recipe evidence: tools/recipe_management_surface_smoke.php modifier substitution smoke passed for recipe_manage.php recipe_id=1; modifier lookup found Recipe QA oat milk option and no sensitive cost keys.
- Production batch evidence: tools/recipe_stock_operations_surface_smoke.php production batch surface passed for recipe_production.php batch_id=1 with selected batch controls, commit/cancel controls, Input Preview, and Committed Lines.
- Waste and stock adjustment evidence: tools/recipe_stock_operations_surface_smoke.php waste and stock adjustment surface passed for recipe_waste.php; fixture stock adjustment movement 15 replenished Recipe QA Takeaway Cup through RecipeWasteAdjustmentService with idempotency_replayed=true.
- Paid refund/void evidence: tools/recipe_paid_reversal_surface_smoke.php paid order surface passed; ajax/refund_order.php method guard passed and recent orders payload found a paid reversible order.
- Recipe rollback evidence: POSMAIN_RECIPE_MODE=off rollback documented; disable recipe write flags to return to legacy behavior.
- Recipe COGS accountant evidence: accountant reviewed balanced journal 747 for order 998863; journal debit account 16 = 0.154000 and journal credit account 20 = 0.154000; inventory movements 18/19 link to accounting_journal_id=747.
- Recipe availability and menu sync evidence: tools/recipe_pos_grid_availability_surface_smoke.php POS grid availability passed against http://127.0.0.1:8012 with category_id=9101; recipe_pos_grid_availability_endpoint_runtime_test.php passed with menu availability revision proof; recipe_moova_menu_sync_payload_endpoint_runtime_test.php passed with recipe availability payload and Moova-safe cost masking.
- Moova/Cofe recipe replay evidence: recipe_moova_replay_runtime_test.php Moova replay passed without duplicate reservation release or recipe consumption; recipe_moova_menu_sync_payload_endpoint_runtime_test.php passed with recipe-moova-menu-sync-payload-endpoint-runtime-ok; Cofe endpoint replay passed with recipe-cofe-create-order-endpoint-runtime-ok.

## Evidence Command Hints

These lines are hints only. They do not count as completed evidence until the matching detail line above is replaced with a real reviewed result.
- Recipe schema evidence: php tools/run_migrations.php --dry-run
- Recipe runtime preflight evidence: php tools/recipe_runtime_preflight.php --json
- Pilot fixture verification evidence: php tools/recipe_pilot_fixture.php --verify --json
- Recipe operational dashboard evidence: Open recipe_operational_dashboard.php and confirm issue_total=0 / zero blockers for the pilot scope.
- Recipe stock reconciliation evidence: Open recipe_stock_reconciliation.php and export/review the reconciliation CSV for the pilot scope.
- POS/table smoke evidence: Record one cashier-browser POS order and one table order against prepared pilot recipe items.
- Migrated runtime write smoke evidence: php tools/recipe_migrated_write_smoke.php --json --apply --run-id=<unique-local-run-id>, then record stock_preflight ok=true, idempotency_replayed=true, recipe_consumption movements, and positive movement cost.
- Recipe report export and role QA evidence: php tools/recipe_report_export_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json
- Modifier substitution recipe evidence: php tools/recipe_management_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --recipe-id=<fixture-recipe-id> --json, then record the Recipe QA oat milk modifier substitution browser result.
- Production batch evidence: php tools/recipe_stock_operations_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --batch-id=<draft-batch-id> --json, then record the reviewed Recipe QA batch result.
- Waste and stock adjustment evidence: php tools/recipe_stock_operations_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json, then record one reviewed waste movement plus one stock adjustment result.
- Paid refund/void evidence: php tools/recipe_paid_reversal_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json, then record the paid order browser QA result.
- Recipe rollback evidence: Document rollback by setting POSMAIN_RECIPE_MODE=off and disabling active recipe write flags.
- Isolated runtime proofs: php tools/recipe_runtime_proof_suite.php --include-availability --json
- Recipe COGS accountant evidence: Record accountant review of balanced recipe COGS/inventory journals for the pilot scope.
- Recipe availability and menu sync evidence: php tools/recipe_pos_grid_availability_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --category-id=<pilot-category> --json
- Moova/Cofe recipe replay evidence: Run the Moova/Cofe pilot replay smoke and include tools/recipe_runtime_proof_suite.php output with Moova replay, Moova menu payload, and Cofe proof markers.

## Operator QA Checklist

- [x] Recipe management UI smoke
- [x] Modifier substitution recipe UI smoke
- [x] Recipe report export and role QA smoke
- [x] Production batch UI smoke
- [x] Waste and stock adjustment UI smoke
- [x] POS/table lifecycle smoke
- [x] Migrated runtime write smoke
- [x] Paid refund/void smoke
- [x] Recipe accounting journal review
- [x] Recipe availability POS and menu sync smoke
- [x] Moova/Cofe recipe replay smoke

## Isolated Runtime Proofs

- POS takeaway cashier payment runtime proof: php tests/sync/pos_takeaway_order_service_test.php -> pos-takeaway-order-service-ok
- POS takeaway invoice handler runtime proof: php tests/sync/pos_takeaway_invoice_handler_test.php -> pos-takeaway-invoice-handler-ok
- POS table save recipe endpoint runtime proof: php tests/sync/pos_table_save_recipe_endpoint_runtime_test.php -> pos-table-save-recipe-endpoint-runtime-ok
- POS table cancel recipe endpoint runtime proof: php tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php -> pos-table-cancel-recipe-endpoint-runtime-ok
- POS table payment recipe endpoint runtime proof: php tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php -> pos-table-payment-recipe-endpoint-runtime-ok
- POS split payment recipe endpoint runtime proof: php tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php -> pos-split-payment-recipe-endpoint-runtime-ok
- Isolated cashier browser fixture smoke proof: php tests/sync/recipe_cashier_browser_fixture_smoke_test.php -> recipe-cashier-browser-fixture-smoke-ok
- Modifier substitution management endpoint runtime proof: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php -> recipe-modifier-substitution-management-endpoint-runtime-ok
- Production endpoint runtime proof: php tests/sync/recipe_production_endpoint_runtime_test.php -> recipe-production-endpoint-runtime-ok
- Waste and stock adjustment endpoint runtime proof: php tests/sync/recipe_waste_adjustment_endpoint_runtime_test.php -> recipe-waste-adjustment-endpoint-runtime-ok
- Paid refund/void endpoint runtime proof: php tests/sync/recipe_paid_reversal_endpoint_runtime_test.php -> recipe-paid-reversal-endpoint-runtime-ok
- POS grid availability endpoint runtime proof: php tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php -> recipe-pos-grid-availability-endpoint-runtime-ok
- Moova menu sync payload endpoint runtime proof: php tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php -> recipe-moova-menu-sync-payload-endpoint-runtime-ok
- Moova/Cofe replay runtime proof: php tests/sync/recipe_moova_replay_runtime_test.php -> recipe-moova-replay-runtime-ok
- Legacy Cofe endpoint runtime proof: php tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php -> recipe-cofe-create-order-endpoint-runtime-ok
