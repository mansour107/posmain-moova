<?php

$root = dirname(__DIR__, 2);
$tool = recipeRolloutReadinessSource($root . '/tools/recipe_rollout_readiness.php');
$service = recipeRolloutReadinessSource($root . '/classes/Recipe/RecipeRolloutReadinessService.php');
$pilotEvidence = recipeRolloutReadinessSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipeRolloutReadinessSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($root . '/tools/recipe_rollout_readiness.php') . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeRolloutReadinessAssert($helpCode === 0, 'readiness help should exit cleanly');
recipeRolloutReadinessAssert(strpos($help, '--pilot-evidence-file=') !== false, 'help should document pilot evidence file');
recipeRolloutReadinessAssert(strpos($help, '--allow-full-mode') !== false, 'help should document full-mode override');
recipeRolloutReadinessAssert(strpos($help, 'without applying migrations, changing flags, expiring reservations, requeueing sync, or writing stock/accounting rows') !== false, 'help should document non-destructive behavior');

exec('php ' . escapeshellarg($root . '/tools/recipe_rollout_readiness.php') . ' --json --skip-db', $jsonLines, $jsonCode);
$payload = json_decode(implode("\n", $jsonLines), true);
recipeRolloutReadinessAssert($jsonCode === 2, 'skip-db readiness should fail closed');
recipeRolloutReadinessAssert(is_array($payload), 'skip-db readiness should emit JSON');
recipeRolloutReadinessAssert(in_array('database_check_skipped', $payload['blockers'] ?? [], true), 'skip-db readiness should block rollout');

recipeRolloutReadinessAssert(strpos($tool, 'RecipeRolloutReadinessService') !== false, 'tool should delegate to readiness service');
recipeRolloutReadinessAssert(strpos($service, 'RecipeOperationalDashboardService') !== false, 'readiness service should reuse operational dashboard signals');
recipeRolloutReadinessAssert(strpos($service, 'recipe_schema_missing_') !== false, 'readiness service should block missing schema');
recipeRolloutReadinessAssert(strpos($service, 'full_mode_requires_explicit_allow_full_mode') !== false, 'readiness service should block accidental full mode');
recipeRolloutReadinessAssert(strpos($service, 'public_cost_payloads_enabled') !== false, 'readiness service should block public cost payloads');
recipeRolloutReadinessAssert(strpos($service, 'RecipeRuntimePreflightService') !== false, 'readiness service should delegate runtime preflight validation');
recipeRolloutReadinessAssert(strpos($service, "'runtime_preflight'") !== false, 'readiness service should include runtime preflight results');
recipeRolloutReadinessAssert(strpos($service, 'recipe_runtime_preflight_not_ready') !== false, 'readiness service should fail closed when runtime preflight fails');
recipeRolloutReadinessAssert(strpos($service, "'external_order_line_map'") !== false, 'readiness service should require the external order-line identity schema');
recipeRolloutReadinessAssert(strpos($service, 'recipe_dashboard_check_skipped_until_schema_ready') !== false, 'readiness service should skip dashboard queries until schema is ready');
foreach ([
    'cogs_account_id',
    'raw_inventory_account_id',
    'prepared_inventory_account_id',
    'packaging_inventory_account_id',
    'waste_expense_account_id',
    'production_variance_account_id',
] as $accountKey) {
    recipeRolloutReadinessAssert(strpos($service, $accountKey) !== false, 'readiness service should require recipe accounting account: ' . $accountKey);
}
recipeRolloutReadinessAssert(strpos($service, 'invalid_recipe_inventory_movements') !== false, 'readiness service should block invalid inventory movement rows');
recipeRolloutReadinessAssert(strpos($service, 'pending_menu_availability_sync') !== false, 'readiness service should block pending menu availability sync when recipe Moova sync is enabled');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_requires_recipe_availability') !== false, 'readiness service should block recipe Moova sync without recipe availability enabled');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_requires_menu_sync_enabled') !== false, 'readiness service should block recipe Moova sync without menu sync enabled');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_requires_outbox_or_cloud_publish') !== false, 'readiness service should block recipe Moova sync without an outbox/cloud publish transport');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_branch_role') !== false, 'readiness service should block recipe Moova outbox sync outside branch role');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_branch_uuid') !== false, 'readiness service should block recipe Moova outbox sync without branch uuid');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_cloud_base_url') !== false, 'readiness service should block recipe Moova outbox sync without cloud base URL');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_branch_sync_secret') !== false, 'readiness service should block recipe Moova outbox sync without branch sync secret');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_branch_sync_enabled') !== false, 'readiness service should block recipe Moova outbox sync without branch sync enabled');
recipeRolloutReadinessAssert(strpos($service, 'recipe_moova_sync_outbox_requires_sync_worker_enabled') !== false, 'readiness service should block recipe Moova outbox sync without sync worker enabled');
recipeRolloutReadinessAssert(strpos($service, 'runtime_bcmath_missing') !== false && strpos($service, 'recipe_runtime_bcmath_missing') !== false, 'readiness service should block active rollout when the current PHP runtime lacks bcmath');
recipeRolloutReadinessAssert(strpos($service, 'NegativeStockSalePolicyService') !== false, 'readiness service should resolve the durable branch negative-stock policy');
recipeRolloutReadinessAssert(strpos($service, "'negative_stock_sale_policy'") !== false, 'readiness service should expose the resolved negative-stock policy');
recipeRolloutReadinessAssert(strpos($service, 'reserve_only_requires_recipe_reservations') !== false, 'readiness service should block reserve_only when reservations are disabled');
recipeRolloutReadinessAssert(strpos($service, 'full_requires_recipe_reservations') !== false, 'readiness service should block full mode when reservations are disabled');
recipeRolloutReadinessAssert(strpos($service, 'consume_pilot_requires_recipe_consumption') !== false, 'readiness service should block consume_pilot when consumption is disabled');
recipeRolloutReadinessAssert(strpos($service, 'accounting_pilot_requires_recipe_accounting') !== false, 'readiness service should block accounting_pilot when accounting is disabled');
recipeRolloutReadinessAssert(strpos($service, 'availability_pilot_requires_recipe_availability') !== false, 'readiness service should block availability_pilot when availability is disabled');
recipeRolloutReadinessAssert(strpos($service, '$blockers[] = \'pilot_mode_without_explicit_pilot_scope\'') !== false, 'readiness service should block pilot modes without an explicit pilot scope');
recipeRolloutReadinessAssert(strpos($service, 'private function pilotEvidenceScope(RecipeFeatureFlags $flags, array $options): array') !== false, 'readiness service should derive pilot evidence scope from flags and CLI options');
recipeRolloutReadinessAssert(strpos($service, '$flags->appConfig()[\'branch\']') !== false, 'readiness service should fall back to configured branch identity for evidence scope');
recipeRolloutReadinessAssert(strpos($service, 'POSMAIN_POS_TENANT') === false || strpos($doc, 'POSMAIN_POS_TENANT') !== false, 'readiness docs should describe POS tenant evidence-scope fallback when code supports it');
recipeRolloutReadinessAssert(strpos($service, 'POSMAIN_RECIPE_PILOT_POS_BRANCH') === false || strpos($doc, 'POSMAIN_RECIPE_PILOT_POS_BRANCH') !== false, 'readiness docs should describe recipe pilot branch evidence-scope fallback when code supports it');
recipeRolloutReadinessAssert(strpos($service, 'RecipePilotEvidenceService') !== false, 'readiness service should delegate pilot evidence validation');
recipeRolloutReadinessAssert(strpos($service, 'recipe_runtime_preflight.php --json') !== false, 'readiness service should point operators to runtime preflight command');
recipeRolloutReadinessAssert(strpos($service, 'recipe_cashier_browser_fixture.php --smoke --json') !== false, 'readiness service should point operators to isolated cashier-browser fixture smoke');
recipeRolloutReadinessAssert(strpos($service, 'temporary local browser fixture database, dropped on exit') !== false, 'readiness service should label cashier-browser fixture writes as temp-only');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_fixture.php --json') !== false, 'readiness service should point operators to pilot fixture dry-run command');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_fixture.php --apply --json') !== false, 'readiness service should point operators to explicit pilot fixture apply command');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_fixture.php --verify --json') !== false, 'readiness service should point operators to read-only pilot fixture verification command');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_evidence.php --template') !== false, 'readiness service should point operators to evidence template command');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_evidence_bundle.php --json') !== false, 'readiness service should point operators to draft evidence bundle command');
recipeRolloutReadinessAssert(strpos($service, 'not valid for rollout until browser/operator action lines are completed and validated') !== false, 'readiness service should label draft evidence bundle as non-final');
recipeRolloutReadinessAssert(strpos($service, 'recipe_pilot_evidence.php --validate') !== false, 'readiness service should point operators to evidence validation command');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'Recipe availability and menu sync smoke passed: pass') !== false, 'pilot evidence service should require availability evidence marker');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'Recipe reservation lifecycle smoke passed: pass') !== false, 'pilot evidence service should require reservation evidence marker for reserve-only');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'Recipe reservation evidence') !== false, 'pilot evidence service should require reservation evidence detail for reserve-only');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'Recipe reservation lifecycle runtime proof') !== false, 'pilot evidence service should require reservation runtime proof for reserve-only');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'recipe_pilot_evidence_details_missing') !== false, 'pilot evidence service should require non-placeholder evidence details');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'recipe_pilot_evidence_checks_missing') !== false, 'pilot evidence service should require checked operator QA scenarios');
recipeRolloutReadinessAssert(strpos($pilotEvidence, 'recipe_pilot_evidence_runtime_proofs_missing') !== false, 'pilot evidence service should require isolated runtime proof results');

foreach (['shell_exec', 'passthru', 'system('] as $unsafeNeedle) {
    recipeRolloutReadinessAssert(strpos($tool, $unsafeNeedle) === false, 'tool must not execute shell commands internally: ' . $unsafeNeedle);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeRolloutReadinessAssert(strpos($tool, $writeNeedle) === false, 'tool must remain read-only: ' . $writeNeedle);
    recipeRolloutReadinessAssert(strpos($service, $writeNeedle) === false, 'service must remain read-only: ' . $writeNeedle);
}

foreach ([
    'Recipe Pilot Evidence: pass',
    'Evidence completed at UTC',
    'Recipe schema migrated or verified: pass',
    'Recipe runtime preflight reviewed: pass',
    'Recipe operational dashboard reviewed: pass',
    'Recipe stock reconciliation reviewed: pass',
    'Recipe reservation lifecycle smoke passed: pass',
    'POS/table recipe smoke passed: pass',
    'Recipe rollback flags documented: pass',
    'Recipe COGS accountant review: pass',
    'Recipe availability and menu sync smoke passed: pass',
] as $marker) {
    recipeRolloutReadinessAssert(strpos($doc, $marker) !== false, 'readiness doc missing evidence marker: ' . $marker);
}

foreach ([
    'Recipe schema evidence',
    'Recipe runtime preflight evidence',
    'Recipe operational dashboard evidence',
    'Recipe stock reconciliation evidence',
    'Recipe reservation evidence',
    'POS/table smoke evidence',
    'Recipe report export and role QA evidence',
    'Modifier substitution recipe evidence',
    'Production batch evidence',
    'Waste and stock adjustment evidence',
    'Paid refund/void evidence',
    'Recipe COGS accountant evidence',
    'Recipe availability and menu sync evidence',
    'Moova/Cofe recipe replay evidence',
    'Hosted/cloud runtime schema evidence',
] as $detail) {
    recipeRolloutReadinessAssert(strpos($doc, $detail) !== false, 'readiness doc missing evidence detail: ' . $detail);
}

foreach ([
    'Recipe management UI smoke',
    'Modifier substitution recipe UI smoke',
    'Recipe report export and role QA smoke',
    'Production batch UI smoke',
    'Waste and stock adjustment UI smoke',
    'POS/table lifecycle smoke',
    'Recipe reservation lifecycle smoke',
    'Paid refund/void smoke',
    'Recipe accounting journal review',
    'Recipe availability POS and menu sync smoke',
    'Moova/Cofe recipe replay smoke',
] as $check) {
    recipeRolloutReadinessAssert(strpos($doc, $check) !== false, 'readiness doc missing operator QA check: ' . $check);
}

recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_paid_reversal_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated paid reversal endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real operator orders') !== false, 'rollout doc should distinguish endpoint runtime test from live cashier QA');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_takeaway_order_service_test.php') !== false, 'rollout doc should document isolated POS takeaway cashier payment runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_takeaway_invoice_handler_test.php') !== false, 'rollout doc should document handler-level POS takeaway cashier payment runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_table_save_recipe_endpoint_runtime_test.php') !== false, 'rollout doc should document table save recipe endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'sets `qty_reserved=2.000000`') !== false, 'rollout doc should state the table save reservation proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php') !== false, 'rollout doc should document table cancel recipe endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'ajax/update_table_status.php') !== false, 'rollout doc should document table status clear recipe endpoint runtime coverage');
recipeRolloutReadinessAssert(strpos($doc, 'table-status-clear idempotency keys') !== false, 'rollout doc should state table status clear idempotency replay coverage');
recipeRolloutReadinessAssert(strpos($doc, 'cancel clears `qty_reserved=0.000000`') !== false, 'rollout doc should state the table cancel reservation release proof');
recipeRolloutReadinessAssert(strpos($doc, 'no `recipe_consumption` is written') !== false, 'rollout doc should state the table cancel proof does not consume ingredients');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php') !== false, 'rollout doc should document table payment recipe endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real table orders') !== false, 'rollout doc should distinguish table payment runtime test from live table QA');
recipeRolloutReadinessAssert(strpos($doc, 'reduces ingredient item `12` from `10.000000` to `8.000000`') !== false, 'rollout doc should state the table payment recipe consumption proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php') !== false, 'rollout doc should document split payment recipe endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'leaves `qty_reserved=2.000000`') !== false, 'rollout doc should state the split payment remaining reservation proof');
recipeRolloutReadinessAssert(strpos($doc, 'consume only the paid split quantity') !== false, 'rollout doc should state the split payment consumption proof');
recipeRolloutReadinessAssert(strpos($doc, 'tools/recipe_cashier_browser_fixture.php --json') !== false, 'rollout doc should document isolated cashier-browser add/pay fixture');
recipeRolloutReadinessAssert(strpos($doc, 'ingredient item `12` moved from `10.000000` to `9.000000`') !== false, 'rollout doc should record current isolated cashier-browser recipe consumption evidence');
recipeRolloutReadinessAssert(strpos($doc, 'one `order.refunded` event and one completed idempotency row') !== false, 'rollout doc should record isolated cashier-browser refund mutation replay evidence');
recipeRolloutReadinessAssert(strpos($doc, 'one `order.voided` event, one completed idempotency row, `isdeleted=1`, and a released table') !== false, 'rollout doc should record isolated cashier-browser void mutation replay evidence');
recipeRolloutReadinessAssert(is_file($root . '/tools/recipe_cashier_browser_fixture.php'), 'isolated cashier-browser fixture tool should exist');
recipeRolloutReadinessAssert(strpos(recipeRolloutReadinessSource($root . '/tools/recipe_cashier_browser_fixture.php'), 'local_temp_db_only') !== false, 'cashier-browser fixture should identify that it only uses a temp DB');
recipeRolloutReadinessAssert(strpos(recipeRolloutReadinessSource($root . '/tools/recipe_cashier_browser_fixture.php'), 'refund_mutation_ok') !== false, 'cashier-browser fixture should assert the temp refund mutation proof');
recipeRolloutReadinessAssert(strpos(recipeRolloutReadinessSource($root . '/tools/recipe_cashier_browser_fixture.php'), 'void_mutation_ok') !== false, 'cashier-browser fixture should assert the temp void mutation proof');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real cashier orders') !== false, 'rollout doc should distinguish POS takeaway payment test from live cashier QA');
recipeRolloutReadinessAssert(strpos($doc, 'one consumed recipe usage, one recipe consumption movement, and one ingredient stock deduction') !== false, 'rollout doc should state the POS takeaway payment idempotency proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/inventory_adjustment_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated Inventory module waste/adjustment endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real operator stock') !== false, 'rollout doc should distinguish waste endpoint runtime test from live operator stock QA');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_production_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated production endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real production stock') !== false, 'rollout doc should distinguish production endpoint runtime test from live production QA');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated POS availability endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real cashier orders') !== false, 'rollout doc should distinguish POS availability endpoint runtime test from live cashier QA');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_moova_replay_runtime_test.php') !== false, 'rollout doc should document isolated Moova/Cofe replay runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real Moova orders') !== false, 'rollout doc should distinguish Moova replay runtime test from live Moova/Cofe QA');
recipeRolloutReadinessAssert(strpos($doc, 'without duplicate reservation release or recipe consumption') !== false, 'rollout doc should state the Moova replay idempotency proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated Moova menu payload endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use live Moova menu consumers') !== false, 'rollout doc should distinguish Moova menu payload runtime test from live Moova menu QA');
recipeRolloutReadinessAssert(strpos($doc, 'uses the Moova link scope') !== false, 'rollout doc should state the Moova menu scope proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated legacy Cofe endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real Cofe orders') !== false, 'rollout doc should distinguish Cofe endpoint runtime test from live Moova/Cofe QA');
recipeRolloutReadinessAssert(strpos($doc, 'one consumed recipe usage, one recipe consumption movement, and one ingredient stock deduction') !== false, 'rollout doc should state the Cofe endpoint replay idempotency proof');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php') !== false, 'rollout doc should document isolated modifier substitution management endpoint runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'does not use real recipe rows') !== false, 'rollout doc should distinguish modifier substitution management runtime test from live recipe QA');
recipeRolloutReadinessAssert(strpos($doc, 'removes regular milk and adds oat milk') !== false, 'rollout doc should state the modifier substitution management proof');
recipeRolloutReadinessAssert(strpos($doc, 'Required Isolated Runtime Proofs') !== false, 'rollout doc should require isolated runtime proof evidence');
recipeRolloutReadinessAssert(strpos($doc, 'Recipe reservation lifecycle runtime proof') !== false, 'rollout doc should name reservation runtime proof evidence');
recipeRolloutReadinessAssert(strpos($doc, 'tests/sync/recipe_reservation_lifecycle_runtime_test.php') !== false, 'rollout doc should document isolated reservation lifecycle runtime test');
recipeRolloutReadinessAssert(strpos($doc, 'Modifier substitution management endpoint runtime proof') !== false, 'rollout doc should name modifier substitution runtime proof evidence');
recipeRolloutReadinessAssert(strpos($doc, 'tools/recipe_runtime_proof_suite.php --json') !== false, 'rollout doc should document the isolated runtime proof suite');

echo "recipe-rollout-readiness-contract-ok\n";

function recipeRolloutReadinessSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeRolloutReadinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
