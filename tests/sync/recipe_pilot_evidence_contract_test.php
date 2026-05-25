<?php

$root = dirname(__DIR__, 2);
$tool = recipePilotEvidenceSource($root . '/tools/recipe_pilot_evidence.php');
$service = recipePilotEvidenceSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$readiness = recipePilotEvidenceSource($root . '/classes/Recipe/RecipeRolloutReadinessService.php');
$doc = recipePilotEvidenceSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($root . '/tools/recipe_pilot_evidence.php') . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);
recipePilotEvidenceAssert($helpCode === 0, 'pilot evidence help should exit cleanly');
recipePilotEvidenceAssert(strpos($help, '--template') !== false, 'help should document template mode');
recipePilotEvidenceAssert(strpos($help, '--validate') !== false, 'help should document validate mode');
recipePilotEvidenceAssert(strpos($help, '--list-markers') !== false, 'help should document marker listing');
recipePilotEvidenceAssert(strpos($help, 'every generated marker starts as pending') !== false, 'help should warn templates do not pass readiness');
recipePilotEvidenceAssert(strpos($help, 'evidence recipe mode and any provided POS tenant/branch/store scope') !== false, 'help should warn validation is scoped to mode and pilot scope');
recipePilotEvidenceAssert(strpos($help, 'Evidence completed at UTC timestamp') !== false, 'help should warn validation uses completed-at timestamp');
recipePilotEvidenceAssert(strpos($help, 'non-placeholder evidence detail lines') !== false, 'help should warn that detail lines are required');
recipePilotEvidenceAssert(strpos($help, 'token groups for high-risk detail lines') !== false, 'help should explain detail proof token groups');
recipePilotEvidenceAssert(strpos($help, 'evidence command hints') !== false, 'help should explain evidence command hints');
recipePilotEvidenceAssert(strpos($help, 'checked operator QA checklist lines') !== false, 'help should warn that checklist lines are required');
recipePilotEvidenceAssert(strpos($help, 'isolated runtime proof command results') !== false, 'help should warn that runtime proof command results are required');
recipePilotEvidenceAssert(strpos($help, 'proof command path and success marker') !== false, 'help should require both runtime proof command path and success marker');
recipePilotEvidenceAssert(strpos($help, 'does not connect to the database') !== false, 'help should document no DB access');

exec('php ' . escapeshellarg($root . '/tools/recipe_pilot_evidence.php') . ' --template --json', $templateLines, $templateCode);
$templatePayload = json_decode(implode("\n", $templateLines), true);
recipePilotEvidenceAssert($templateCode === 0, 'template should exit cleanly');
recipePilotEvidenceAssert(is_array($templatePayload), 'template should emit JSON');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Recipe Pilot Evidence: pending') !== false, 'template should generate pending markers');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Evidence completed at UTC: pending') !== false, 'template should generate pending completed-at timestamp');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Recipe schema evidence: pending') !== false, 'template should generate pending detail lines');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Recipe runtime preflight reviewed: pending') !== false, 'template should generate runtime preflight marker');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Recipe runtime preflight evidence: pending') !== false, 'template should generate runtime preflight detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Pilot fixture verification evidence: pending') !== false, 'template should generate pilot fixture verification detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Migrated runtime write smoke evidence: pending') !== false, 'template should generate migrated runtime write smoke detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), '- [ ] Recipe management UI smoke') !== false, 'template should generate unchecked QA checklist lines');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), '- [ ] Migrated runtime write smoke') !== false, 'template should generate migrated runtime write smoke QA checklist line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Modifier substitution recipe evidence: pending') !== false, 'template should generate modifier substitution detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Production batch evidence: pending') !== false, 'template should generate production batch detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Waste and stock adjustment evidence: pending') !== false, 'template should generate waste/adjustment detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Paid refund/void evidence: pending') !== false, 'template should generate paid refund/void detail line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), '- [ ] Modifier substitution recipe UI smoke') !== false, 'template should generate modifier substitution QA checklist line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Modifier substitution management endpoint runtime proof: pending') !== false, 'template should generate pending runtime proof lines');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Isolated cashier browser fixture smoke proof: pending') !== false, 'template should generate pending cashier-browser fixture proof line');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'tests/sync/recipe_cashier_browser_fixture_smoke_test.php') !== false, 'template should name the cashier-browser fixture smoke proof command');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php') !== false, 'template should name the modifier substitution runtime proof command');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'Recipe Pilot Evidence: pass') === false, 'template should not contain completed pass marker');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), '## Evidence Command Hints') !== false, 'template should include command hints section');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'These lines are hints only') !== false, 'template should warn hints do not complete evidence');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'php tools/recipe_runtime_preflight.php --json') !== false, 'template should include runtime preflight command hint');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'php tools/recipe_runtime_proof_suite.php') !== false, 'template should include runtime proof suite command hint');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'php tools/recipe_management_surface_smoke.php') !== false, 'template should include management surface smoke command hint');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'php tools/recipe_stock_operations_surface_smoke.php') !== false, 'template should include stock operations surface smoke command hint');
recipePilotEvidenceAssert(strpos((string) ($templatePayload['content'] ?? ''), 'php tools/recipe_migrated_write_smoke.php') !== false, 'template should include migrated write smoke command hint');
recipePilotEvidenceAssert(($templatePayload['required_mode'] ?? null) === ($templatePayload['mode'] ?? null), 'template JSON should expose the required evidence mode');
recipePilotEvidenceAssert(isset($templatePayload['required_scope']) && is_array($templatePayload['required_scope']), 'template JSON should expose the required evidence scope');
recipePilotEvidenceAssert(in_array('Recipe schema evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose required detail labels');
recipePilotEvidenceAssert(in_array('Recipe runtime preflight evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose runtime preflight detail label');
recipePilotEvidenceAssert(in_array('Pilot fixture verification evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose pilot fixture verification detail label');
recipePilotEvidenceAssert(in_array('Migrated runtime write smoke evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose migrated runtime write smoke detail label');
recipePilotEvidenceAssert(isset($templatePayload['detail_token_requirements']['Pilot fixture verification evidence']), 'template JSON should expose detail token requirements');
recipePilotEvidenceAssert(in_array('fixture_ready_for_operator_qa', $templatePayload['detail_token_requirements']['Pilot fixture verification evidence'][0] ?? [], true), 'template JSON should expose fixture verification result token');
recipePilotEvidenceAssert(isset($templatePayload['evidence_command_hints']['Recipe runtime preflight evidence']), 'template JSON should expose command hints');
recipePilotEvidenceAssert(strpos($templatePayload['evidence_command_hints']['Isolated runtime proofs'] ?? '', 'tools/recipe_runtime_proof_suite.php') !== false, 'template JSON should expose proof-suite hint');
recipePilotEvidenceAssert(in_array('Modifier substitution recipe evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose modifier substitution detail label');
recipePilotEvidenceAssert(in_array('Production batch evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose production batch detail label');
recipePilotEvidenceAssert(in_array('Waste and stock adjustment evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose waste/adjustment detail label');
recipePilotEvidenceAssert(in_array('Paid refund/void evidence', $templatePayload['required_details'] ?? [], true), 'template JSON should expose paid refund/void detail label');
recipePilotEvidenceAssert(in_array('Recipe management UI smoke', $templatePayload['required_checks'] ?? [], true), 'template JSON should expose required QA checklist labels');
recipePilotEvidenceAssert(in_array('Migrated runtime write smoke', $templatePayload['required_checks'] ?? [], true), 'template JSON should expose migrated runtime write smoke QA checklist label');
recipePilotEvidenceAssert(in_array('Modifier substitution recipe UI smoke', $templatePayload['required_checks'] ?? [], true), 'template JSON should expose modifier substitution QA checklist label');
recipePilotEvidenceAssert(isset($templatePayload['required_runtime_proofs']['Modifier substitution management endpoint runtime proof']), 'template JSON should expose required runtime proof labels');

recipePilotEvidenceAssert(strpos($readiness, 'RecipePilotEvidenceService') !== false, 'rollout readiness should delegate pilot evidence validation');
recipePilotEvidenceAssert(strpos($service, 'Recipe availability and menu sync smoke passed: pass') !== false, 'pilot evidence service should own availability marker');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_details_missing') !== false, 'pilot evidence service should reject markers without details');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_checks_missing') !== false, 'pilot evidence service should reject evidence without checked QA scenarios');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_runtime_proofs_missing') !== false, 'pilot evidence service should reject evidence without isolated runtime proof results');
recipePilotEvidenceAssert(strpos($service, '$allTokensMatched') !== false, 'pilot evidence service should require all runtime proof tokens');
recipePilotEvidenceAssert(strpos($service, 'Recipe runtime preflight reviewed: pass') !== false, 'pilot evidence service should require runtime preflight marker');
recipePilotEvidenceAssert(strpos($service, 'Recipe runtime preflight evidence') !== false, 'pilot evidence service should require runtime preflight detail');
recipePilotEvidenceAssert(strpos($service, 'Pilot fixture verification evidence') !== false, 'pilot evidence service should require pilot fixture verification detail');
recipePilotEvidenceAssert(strpos($service, 'Recipe reservation lifecycle smoke passed: pass') !== false, 'pilot evidence service should require reservation evidence marker for reserve-only mode');
recipePilotEvidenceAssert(strpos($service, 'Recipe reservation evidence') !== false, 'pilot evidence service should require reservation evidence detail for reserve-only mode');
recipePilotEvidenceAssert(strpos($service, 'Recipe reservation lifecycle runtime proof') !== false, 'pilot evidence service should require reservation runtime proof for reserve-only mode');
recipePilotEvidenceAssert(strpos($service, 'recipe_reservation_lifecycle_runtime_test.php') !== false, 'pilot evidence service should require reservation runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'recipe-reservation-lifecycle-runtime-ok') !== false, 'pilot evidence service should require reservation runtime proof success marker');
recipePilotEvidenceAssert(strpos($service, 'reservationEvidenceEnabled') !== false, 'pilot evidence service should require reservation evidence when reservations are enabled outside reserve-only mode');
recipePilotEvidenceAssert(strpos($service, 'DETAIL_TOKEN_REQUIREMENTS') !== false, 'pilot evidence service should enforce specific token requirements for high-value details');
recipePilotEvidenceAssert(strpos($service, 'requiredDetailTokenGroups') !== false, 'pilot evidence service should expose required detail proof token groups');
recipePilotEvidenceAssert(strpos($service, 'evidenceCommandHints') !== false, 'pilot evidence service should expose evidence command hints');
recipePilotEvidenceAssert(strpos($service, 'runtimeProofSuiteCommandHint') !== false, 'pilot evidence service should build proof-suite command hints from active flags');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_pilot_fixture.php --verify --json') !== false, 'pilot evidence service should require the fixture verify command proof token');
recipePilotEvidenceAssert(strpos($service, 'fixture_ready_for_operator_qa') !== false, 'pilot evidence service should accept the fixture-ready verify result token');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_runtime_preflight.php --json') !== false, 'pilot evidence service should require runtime preflight command proof token');
recipePilotEvidenceAssert(strpos($service, 'Migrated runtime write smoke evidence') !== false, 'pilot evidence service should require migrated runtime write smoke detail');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_migrated_write_smoke.php') !== false, 'pilot evidence service should require migrated write smoke command proof token');
recipePilotEvidenceAssert(strpos($service, "'stock_preflight', 'ok'") !== false, 'pilot evidence service should require migrated write smoke stock preflight proof token');
recipePilotEvidenceAssert(strpos($service, 'idempotency_replayed') !== false, 'pilot evidence service should require migrated write smoke replay proof token');
recipePilotEvidenceAssert(strpos($service, 'recipe_consumption') !== false, 'pilot evidence service should require migrated write smoke consumption proof token');
recipePilotEvidenceAssert(strpos($service, 'Migrated runtime write smoke') !== false, 'pilot evidence service should require migrated write smoke QA checklist');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_pos_grid_availability_surface_smoke.php') !== false, 'pilot evidence service should accept POS grid availability surface smoke evidence command');
recipePilotEvidenceAssert(strpos($service, 'recipe_production.php') !== false, 'pilot evidence service should require production batch proof token');
recipePilotEvidenceAssert(strpos($service, 'recipe_waste.php') !== false, 'pilot evidence service should require waste/adjustment proof token');
recipePilotEvidenceAssert(strpos($service, 'ajax/refund_order.php') !== false, 'pilot evidence service should require paid reversal proof token');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_hosted_schema_preflight.php') !== false, 'pilot evidence service should require hosted schema proof token');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_mode_mismatch') !== false, 'pilot evidence service should reject evidence from a different recipe mode');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_scope_mismatch') !== false, 'pilot evidence service should reject evidence from a different pilot scope');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_completed_at_too_old') !== false, 'pilot evidence service should reject old completed-at timestamps');
recipePilotEvidenceAssert(strpos($service, 'recipe_pilot_evidence_completed_at_missing') !== false, 'pilot evidence service should reject missing completed-at timestamps');
recipePilotEvidenceAssert(strpos($service, 'Recipe report export and role QA evidence') !== false, 'pilot evidence service should require report export and role QA detail');
recipePilotEvidenceAssert(strpos($service, 'Modifier substitution recipe evidence') !== false, 'pilot evidence service should require realistic modifier substitution recipe detail');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_management_surface_smoke.php') !== false, 'pilot evidence service should accept management surface smoke evidence command');
recipePilotEvidenceAssert(strpos($service, 'Production batch evidence') !== false, 'pilot evidence service should require production batch detail');
recipePilotEvidenceAssert(strpos($service, 'Waste and stock adjustment evidence') !== false, 'pilot evidence service should require waste/adjustment detail');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_stock_operations_surface_smoke.php') !== false, 'pilot evidence service should accept stock operations surface smoke evidence command');
recipePilotEvidenceAssert(strpos($service, 'Paid refund/void evidence') !== false, 'pilot evidence service should require paid refund/void detail');
recipePilotEvidenceAssert(strpos($service, 'requiredRuntimeProofs') !== false, 'pilot evidence service should expose required runtime proofs');
recipePilotEvidenceAssert(strpos($service, 'Isolated cashier browser fixture smoke proof') !== false, 'pilot evidence service should require isolated cashier-browser fixture smoke proof');
recipePilotEvidenceAssert(strpos($service, 'recipe_cashier_browser_fixture_smoke_test.php') !== false, 'pilot evidence service should require cashier-browser fixture smoke proof command');
recipePilotEvidenceAssert(strpos($service, 'recipe-cashier-browser-fixture-smoke-ok') !== false, 'pilot evidence service should require cashier-browser fixture smoke proof success marker');
recipePilotEvidenceAssert(strpos($service, 'POS table save recipe endpoint runtime proof') !== false, 'pilot evidence service should require table save recipe endpoint runtime proof');
recipePilotEvidenceAssert(strpos($service, 'pos_table_save_recipe_endpoint_runtime_test.php') !== false, 'pilot evidence service should require table save endpoint runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'pos-table-save-recipe-endpoint-runtime-ok') !== false, 'pilot evidence service should require table save endpoint runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'POS table cancel recipe endpoint runtime proof') !== false, 'pilot evidence service should require table cancel recipe endpoint runtime proof');
recipePilotEvidenceAssert(strpos($service, 'pos_table_cancel_recipe_endpoint_runtime_test.php') !== false, 'pilot evidence service should require table cancel endpoint runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'pos-table-cancel-recipe-endpoint-runtime-ok') !== false, 'pilot evidence service should require table cancel endpoint runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'POS table payment recipe endpoint runtime proof') !== false, 'pilot evidence service should require table payment recipe endpoint runtime proof');
recipePilotEvidenceAssert(strpos($service, 'pos_table_payment_recipe_endpoint_runtime_test.php') !== false, 'pilot evidence service should require table payment endpoint runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'pos-table-payment-recipe-endpoint-runtime-ok') !== false, 'pilot evidence service should require table payment endpoint runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'POS split payment recipe endpoint runtime proof') !== false, 'pilot evidence service should require split payment recipe endpoint runtime proof');
recipePilotEvidenceAssert(strpos($service, 'pos_split_payment_recipe_endpoint_runtime_test.php') !== false, 'pilot evidence service should require split payment endpoint runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'pos-split-payment-recipe-endpoint-runtime-ok') !== false, 'pilot evidence service should require split payment endpoint runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'recipe_modifier_substitution_management_endpoint_runtime_test.php') !== false, 'pilot evidence service should require modifier substitution runtime proof');
recipePilotEvidenceAssert(strpos($service, 'recipe_paid_reversal_endpoint_runtime_test.php') !== false, 'pilot evidence service should require paid reversal runtime proof');
recipePilotEvidenceAssert(strpos($service, 'Moova/Cofe recipe replay evidence') !== false, 'pilot evidence service should require Moova/Cofe replay detail when sync is enabled');
recipePilotEvidenceAssert(strpos($service, 'Moova menu sync payload endpoint runtime proof') !== false, 'pilot evidence service should require Moova menu payload runtime proof when sync is enabled');
recipePilotEvidenceAssert(strpos($service, 'recipe_moova_menu_sync_payload_endpoint_runtime_test.php') !== false, 'pilot evidence service should require Moova menu payload runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'recipe-moova-menu-sync-payload-endpoint-runtime-ok') !== false, 'pilot evidence service should require Moova menu payload runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'Manager recipe stock override evidence') !== false, 'pilot evidence service should require manager override detail when negative-stock approval is enabled');
recipePilotEvidenceAssert(strpos($service, 'tools/recipe_manager_override_surface_smoke.php') !== false, 'pilot evidence service should accept manager override surface smoke evidence command');
recipePilotEvidenceAssert(strpos($service, 'Manager recipe stock override endpoint runtime proof') !== false, 'pilot evidence service should require manager override endpoint runtime proof when negative-stock approval is enabled');
recipePilotEvidenceAssert(strpos($service, 'recipe_manager_override_endpoint_runtime_test.php') !== false, 'pilot evidence service should require manager override runtime proof command');
recipePilotEvidenceAssert(strpos($service, 'recipe-manager-override-endpoint-runtime-ok') !== false, 'pilot evidence service should require manager override runtime success marker');
recipePilotEvidenceAssert(strpos($service, 'Hosted/cloud runtime schema evidence') !== false, 'pilot evidence service should require hosted schema detail for hosted/router rollout');
recipePilotEvidenceAssert(strpos($service, "'fake_cloud'") !== false, 'pilot evidence service should treat fake_cloud as hosted/cloud evidence scope');
recipePilotEvidenceAssert(strpos($service, 'Paid refund/void smoke') !== false, 'pilot evidence service should require refund/void QA checklist');
recipePilotEvidenceAssert(strpos($service, 'Manager recipe stock override smoke') !== false, 'pilot evidence service should require manager override QA checklist when negative-stock approval is enabled');
recipePilotEvidenceAssert(strpos($tool, 'posmain_db_connect') === false, 'pilot evidence tool must not connect to runtime DB');
recipePilotEvidenceAssert(strpos($tool, 'db_bootstrap') === false, 'pilot evidence tool must not load DB bootstrap');
recipePilotEvidenceAssert(strpos($tool, 'detail_token_requirements') !== false, 'pilot evidence tool should expose detail proof token requirements');
recipePilotEvidenceAssert(strpos($tool, 'recipePilotEvidenceScopeFromOptions(array $options, ?RecipeFeatureFlags $flags = null)') !== false, 'pilot evidence tool should derive evidence scope from CLI options and feature flags');
recipePilotEvidenceAssert(strpos($tool, '$flags->appConfig()[\'branch\']') !== false, 'pilot evidence tool should fall back to configured branch identity for evidence scope');
recipePilotEvidenceAssert(strpos($tool, '$flags->config()[\'pilot\']') !== false, 'pilot evidence tool should fall back to configured recipe pilot branch for evidence scope');
recipePilotEvidenceAssert(strpos($readiness, 'pilotEvidenceScope(RecipeFeatureFlags $flags, array $options)') !== false, 'readiness service should use the same flags-aware evidence-scope model');

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'RecipeReservationService'] as $forbiddenNeedle) {
    recipePilotEvidenceAssert(strpos($tool, $forbiddenNeedle) === false, 'tool must not touch runtime write surface: ' . $forbiddenNeedle);
    recipePilotEvidenceAssert(strpos($service, $forbiddenNeedle) === false, 'service must not touch runtime write surface: ' . $forbiddenNeedle);
}

foreach ([
    'tools/recipe_pilot_evidence.php --template',
    'tools/recipe_pilot_evidence.php --validate',
    'tools/recipe_runtime_proof_suite.php --json',
    'Template generation is intentionally not enough to pass readiness',
    'Evidence completed at UTC',
    'Recipe schema evidence',
    'Recipe runtime preflight evidence',
    'Pilot fixture verification evidence',
    'Migrated runtime write smoke evidence',
    'tools/recipe_migrated_write_smoke.php',
    'tools/recipe_pilot_fixture.php --verify --json',
    'Recipe reservation evidence',
    'Recipe reservation lifecycle runtime proof',
    'recipe_reservation_lifecycle_runtime_test.php',
    'High-risk detail lines',
    'evidence command hints',
    'tools/recipe_pos_grid_availability_surface_smoke.php',
    'tools/recipe_management_surface_smoke.php',
    'tools/recipe_stock_operations_surface_smoke.php',
    'Recipe report export and role QA evidence',
    'Modifier substitution recipe evidence',
    'Production batch evidence',
    'Waste and stock adjustment evidence',
    'Paid refund/void evidence',
    'Modifier substitution management endpoint runtime proof',
    'Isolated cashier browser fixture smoke proof',
    'recipe_cashier_browser_fixture_smoke_test.php',
    'recipe_modifier_substitution_management_endpoint_runtime_test.php',
    'Manager recipe stock override evidence',
    'tools/recipe_manager_override_surface_smoke.php',
    'Manager recipe stock override endpoint runtime proof',
    'recipe_manager_override_endpoint_runtime_test.php',
    'Recipe management UI smoke',
    'Migrated runtime write smoke',
    'Modifier substitution recipe UI smoke',
    'Paid refund/void smoke',
    'Moova/Cofe recipe replay smoke',
    'Moova menu sync payload endpoint runtime proof',
    'recipe_moova_menu_sync_payload_endpoint_runtime_test.php',
    'Manager recipe stock override smoke',
    'Hosted/cloud runtime schema evidence',
] as $needle) {
    recipePilotEvidenceAssert(strpos($doc, $needle) !== false, 'rollout doc missing pilot evidence guidance: ' . $needle);
}

echo "recipe-pilot-evidence-contract-ok\n";

function recipePilotEvidenceSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePilotEvidenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
