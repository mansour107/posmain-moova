<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';

class RecipePilotEvidenceService
{
    private const BASE_MARKERS = [
        'Recipe Pilot Evidence: pass',
        'Recipe schema migrated or verified: pass',
        'Recipe runtime preflight reviewed: pass',
        'Recipe operational dashboard reviewed: pass',
        'Recipe stock reconciliation reviewed: pass',
        'POS/table recipe smoke passed: pass',
        'Recipe rollback flags documented: pass',
    ];

    private const RESERVATION_MARKERS = [
        'Recipe Pilot Evidence: pass',
        'Recipe schema migrated or verified: pass',
        'Recipe runtime preflight reviewed: pass',
        'Recipe operational dashboard reviewed: pass',
        'Recipe stock reconciliation reviewed: pass',
        'Recipe reservation lifecycle smoke passed: pass',
        'Recipe rollback flags documented: pass',
    ];

    private const BASE_DETAILS = [
        'Recipe schema evidence',
        'Recipe runtime preflight evidence',
        'Pilot fixture verification evidence',
        'Recipe operational dashboard evidence',
        'Recipe stock reconciliation evidence',
        'POS/table smoke evidence',
        'Migrated runtime write smoke evidence',
        'Recipe report export and role QA evidence',
        'Modifier substitution recipe evidence',
        'Production batch evidence',
        'Waste and stock adjustment evidence',
        'Paid refund/void evidence',
        'Recipe rollback evidence',
    ];

    private const RESERVATION_DETAILS = [
        'Recipe schema evidence',
        'Recipe runtime preflight evidence',
        'Pilot fixture verification evidence',
        'Recipe operational dashboard evidence',
        'Recipe stock reconciliation evidence',
        'Recipe reservation evidence',
        'Recipe rollback evidence',
    ];

    private const BASE_CHECKS = [
        'Recipe management UI smoke',
        'Modifier substitution recipe UI smoke',
        'Recipe report export and role QA smoke',
        'Production batch UI smoke',
        'Waste and stock adjustment UI smoke',
        'POS/table lifecycle smoke',
        'Migrated runtime write smoke',
        'Paid refund/void smoke',
    ];

    private const RESERVATION_CHECKS = [
        'Recipe reservation lifecycle smoke',
    ];

    private const BASE_RUNTIME_PROOFS = [
        'POS takeaway cashier payment runtime proof' => [
            'tests/sync/pos_takeaway_order_service_test.php',
            'pos-takeaway-order-service-ok',
        ],
        'POS takeaway invoice handler runtime proof' => [
            'tests/sync/pos_takeaway_invoice_handler_test.php',
            'pos-takeaway-invoice-handler-ok',
        ],
        'POS table save recipe endpoint runtime proof' => [
            'tests/sync/pos_table_save_recipe_endpoint_runtime_test.php',
            'pos-table-save-recipe-endpoint-runtime-ok',
        ],
        'POS table cancel recipe endpoint runtime proof' => [
            'tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php',
            'pos-table-cancel-recipe-endpoint-runtime-ok',
        ],
        'POS table payment recipe endpoint runtime proof' => [
            'tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php',
            'pos-table-payment-recipe-endpoint-runtime-ok',
        ],
        'POS split payment recipe endpoint runtime proof' => [
            'tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php',
            'pos-split-payment-recipe-endpoint-runtime-ok',
        ],
        'Isolated cashier browser fixture smoke proof' => [
            'tests/sync/recipe_cashier_browser_fixture_smoke_test.php',
            'recipe-cashier-browser-fixture-smoke-ok',
        ],
        'Modifier substitution management endpoint runtime proof' => [
            'tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php',
            'recipe-modifier-substitution-management-endpoint-runtime-ok',
        ],
        'Production endpoint runtime proof' => [
            'tests/sync/recipe_production_endpoint_runtime_test.php',
            'recipe-production-endpoint-runtime-ok',
        ],
        'Waste and stock adjustment endpoint runtime proof' => [
            'tests/sync/inventory_adjustment_endpoint_runtime_test.php',
            'inventory-adjustment-endpoint-runtime-ok',
        ],
        'Paid refund/void endpoint runtime proof' => [
            'tests/sync/recipe_paid_reversal_endpoint_runtime_test.php',
            'recipe-paid-reversal-endpoint-runtime-ok',
        ],
    ];

    private const RESERVATION_RUNTIME_PROOFS = [
        'Recipe reservation lifecycle runtime proof' => [
            'tests/sync/recipe_reservation_lifecycle_runtime_test.php',
            'recipe-reservation-lifecycle-runtime-ok',
        ],
    ];

    private const DETAIL_TOKEN_REQUIREMENTS = [
        'Recipe schema evidence' => [
            ['tools/run_migrations.php --dry-run', '0 pending'],
            ['tools/run_migrations.php --dry-run', 'pending_count=0'],
        ],
        'Recipe runtime preflight evidence' => [
            ['tools/recipe_runtime_preflight.php --json', 'ready_for_recipe_operator_qa'],
        ],
        'Pilot fixture verification evidence' => [
            ['tools/recipe_pilot_fixture.php --verify --json', 'fixture_ready_for_operator_qa'],
        ],
        'Recipe operational dashboard evidence' => [
            ['recipe_operational_dashboard.php', 'issue_total=0'],
            ['recipe_operational_dashboard.php', 'zero blockers'],
        ],
        'Recipe stock reconciliation evidence' => [
            ['recipe_stock_reconciliation.php', 'reconciliation CSV'],
        ],
        'POS/table smoke evidence' => [
            ['POS order', 'table order'],
            ['cashier-browser', 'table order'],
        ],
        'Migrated runtime write smoke evidence' => [
            ['tools/recipe_migrated_write_smoke.php', 'idempotency_replayed', 'recipe_consumption'],
            ['tools/recipe_migrated_write_smoke.php', 'positive', 'movement cost'],
            ['tools/recipe_migrated_write_smoke.php', 'stock_preflight', 'ok'],
            ['recipe-migrated-write-smoke', 'consumed recipe usage', 'movement'],
        ],
        'Recipe reservation evidence' => [
            ['tests/sync/recipe_reservation_lifecycle_runtime_test.php', 'recipe-reservation-lifecycle-runtime-ok'],
            ['stock_reservations', 'qty_reserved'],
            ['reservation lifecycle', 'qty_reserved'],
        ],
        'Recipe report export and role QA evidence' => [
            ['tools/recipe_report_export_smoke.php', 'CSV export'],
            ['recipe-report-export-smoke', 'CSV export'],
        ],
        'Modifier substitution recipe evidence' => [
            ['tools/recipe_management_surface_smoke.php', 'modifier substitution'],
            ['modifier substitution', 'oat milk'],
            ['recipe_manage.php', 'oat milk'],
        ],
        'Production batch evidence' => [
            ['tools/recipe_stock_operations_surface_smoke.php', 'production'],
            ['recipe_production.php', 'batch'],
            ['production batch', 'batch'],
        ],
        'Waste and stock adjustment evidence' => [
            ['tools/recipe_stock_operations_surface_smoke.php', 'inventory_adjustments.php', 'waste', 'stock adjustment'],
            ['inventory_adjustments.php', 'waste movement', 'stock adjustment'],
            ['InventoryAdjustmentService', 'waste movement', 'stock adjustment'],
        ],
        'Paid refund/void evidence' => [
            ['tools/recipe_paid_reversal_surface_smoke.php', 'paid order'],
            ['ajax/refund_order.php', 'paid order'],
        ],
        'Recipe rollback evidence' => [
            ['POSMAIN_RECIPE_MODE=off', 'rollback'],
        ],
        'Recipe COGS accountant evidence' => [
            ['journal', 'accountant'],
        ],
        'Recipe availability and menu sync evidence' => [
            ['recipe_pos_grid_availability_endpoint_runtime_test.php', 'menu availability revision'],
            ['recipe_moova_menu_sync_payload_endpoint_runtime_test.php', 'recipe availability'],
            ['tools/recipe_pos_grid_availability_surface_smoke.php', 'POS grid availability'],
            ['menu availability revision', 'Moova'],
        ],
        'Moova/Cofe recipe replay evidence' => [
            ['recipe_moova_replay_runtime_test.php', 'Moova replay'],
            ['recipe_moova_menu_sync_payload_endpoint_runtime_test.php', 'recipe-moova-menu-sync-payload-endpoint-runtime-ok'],
            ['Moova replay', 'Cofe'],
        ],
        'Manager recipe stock override evidence' => [
            ['ajax/manager_approval.php', 'approval'],
            ['Manager recipe stock override', 'approval'],
            ['tools/recipe_manager_override_surface_smoke.php', 'manager override'],
        ],
        'Hosted/cloud runtime schema evidence' => [
            ['tools/recipe_hosted_schema_preflight.php', 'target(s)'],
            ['Hosted/cloud runtime schema evidence', 'target(s)'],
        ],
    ];

    public function validate(RecipeFeatureFlags $flags, string $path, int $maxAgeHours = 24, array $expectedScope = []): array
    {
        $path = trim($path);
        $maxAgeHours = $this->intInRange($maxAgeHours, 0, 8760);
        $expectedScope = $this->expectedScope($expectedScope);

        if (!$this->isRequired($flags)) {
            return [
                'ok' => true,
                'required' => false,
                'message' => 'Pilot evidence is not required for off/schema/read-only/shadow checks.',
            ];
        }

        if ($path === '') {
            return [
                'ok' => false,
                'required' => true,
                'max_age_hours' => $maxAgeHours,
                'blocker' => 'recipe_pilot_evidence_file_not_provided',
                'message' => 'Pass --pilot-evidence-file=/absolute/path/to/evidence.md before enabling active recipe reservation/consumption/accounting/availability rollout.',
            ];
        }
        if (!is_file($path) || !is_readable($path)) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_file_unreadable',
                'message' => 'The recipe pilot evidence file does not exist or is not readable.',
            ];
        }

        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_file_empty',
                'message' => 'The recipe pilot evidence file is empty.',
            ];
        }

        $evidenceMode = $this->modeFromEvidence($content);
        if ($evidenceMode !== $flags->mode()) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_mode_mismatch',
                'expected_mode' => $flags->mode(),
                'evidence_mode' => $evidenceMode,
                'message' => 'The recipe pilot evidence file mode does not match the current recipe rollout mode.',
            ];
        }

        $scopeMismatches = $this->scopeMismatches($content, $expectedScope);
        if ($scopeMismatches) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_scope_mismatch',
                'expected_scope' => $expectedScope,
                'scope_mismatches' => $scopeMismatches,
                'message' => 'The recipe pilot evidence file scope does not match the current recipe rollout scope.',
            ];
        }

        $requiredMarkers = $this->requiredMarkers($flags);
        $missingMarkers = [];
        foreach ($requiredMarkers as $marker) {
            if (strpos($content, $marker) === false) {
                $missingMarkers[] = $marker;
            }
        }
        if ($missingMarkers) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_markers_missing',
                'missing_markers' => $missingMarkers,
                'required_markers' => $requiredMarkers,
                'message' => 'The recipe pilot evidence file is missing required pass markers.',
            ];
        }

        $requiredDetails = $this->requiredDetails($flags);
        $missingDetails = $this->missingDetails($content, $requiredDetails);
        if ($missingDetails) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_details_missing',
                'missing_details' => $missingDetails,
                'required_details' => $requiredDetails,
                'detail_token_requirements' => $this->requiredDetailTokenGroups($flags),
                'message' => 'The recipe pilot evidence file is missing required non-placeholder evidence details.',
            ];
        }

        $requiredChecks = $this->requiredChecks($flags);
        $missingChecks = $this->missingChecks($content, $requiredChecks);
        if ($missingChecks) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_checks_missing',
                'missing_checks' => $missingChecks,
                'required_checks' => $requiredChecks,
                'message' => 'The recipe pilot evidence file is missing required checked operator QA scenarios.',
            ];
        }

        $requiredRuntimeProofs = $this->requiredRuntimeProofs($flags);
        $missingRuntimeProofs = $this->missingRuntimeProofs($content, $requiredRuntimeProofs);
        if ($missingRuntimeProofs) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_runtime_proofs_missing',
                'missing_runtime_proofs' => $missingRuntimeProofs,
                'required_runtime_proofs' => $requiredRuntimeProofs,
                'message' => 'The recipe pilot evidence file is missing required isolated runtime proof command results.',
            ];
        }

        $completedAt = $this->completedAtFromEvidence($content);
        if ($completedAt === null) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_completed_at_missing',
                'message' => 'The recipe pilot evidence file must include a non-placeholder Evidence completed at UTC timestamp.',
            ];
        }
        if ($completedAt === false) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_completed_at_invalid',
                'message' => 'The recipe pilot evidence completed-at timestamp is not a valid UTC timestamp.',
            ];
        }

        $now = time();
        $completedAtUnix = $completedAt->getTimestamp();
        $completedAgeSeconds = max(0, $now - $completedAtUnix);
        if ($completedAtUnix > $now + 300) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_completed_at_future',
                'completed_at_utc' => $completedAt->format('Y-m-d\TH:i:s\Z'),
                'message' => 'The recipe pilot evidence completed-at timestamp is in the future.',
            ];
        }
        if ($maxAgeHours > 0 && $completedAgeSeconds > $maxAgeHours * 3600) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_completed_at_too_old',
                'completed_at_utc' => $completedAt->format('Y-m-d\TH:i:s\Z'),
                'completed_age_seconds' => $completedAgeSeconds,
                'max_age_hours' => $maxAgeHours,
                'message' => 'The recipe pilot evidence completed-at timestamp is older than the allowed rollout evidence age.',
            ];
        }

        $modifiedAt = (int) filemtime($path);
        $ageSeconds = max(0, time() - $modifiedAt);
        if ($maxAgeHours > 0 && $ageSeconds > $maxAgeHours * 3600) {
            return [
                'ok' => false,
                'required' => true,
                'path' => $path,
                'blocker' => 'recipe_pilot_evidence_file_too_old',
                'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
                'age_seconds' => $ageSeconds,
                'max_age_hours' => $maxAgeHours,
                'message' => 'The recipe pilot evidence file is older than the allowed rollout evidence age.',
            ];
        }

        return [
            'ok' => true,
            'required' => true,
            'path' => $path,
            'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
            'age_seconds' => $ageSeconds,
            'completed_at_utc' => $completedAt->format('Y-m-d\TH:i:s\Z'),
            'completed_age_seconds' => $completedAgeSeconds,
            'max_age_hours' => $maxAgeHours,
            'required_mode' => $flags->mode(),
            'required_scope' => $expectedScope,
            'required_markers' => $requiredMarkers,
            'required_details' => $requiredDetails,
            'detail_token_requirements' => $this->requiredDetailTokenGroups($flags),
            'required_checks' => $requiredChecks,
            'required_runtime_proofs' => $requiredRuntimeProofs,
        ];
    }

    public function template(RecipeFeatureFlags $flags, array $context = []): string
    {
        $scope = [
            'pos_tenant' => $context['pos_tenant'] ?? '',
            'pos_branch' => $context['pos_branch'] ?? '',
            'store_id' => $context['store_id'] ?? '',
            'operator' => $context['operator'] ?? '',
            'note' => $context['note'] ?? '',
        ];

        $lines = [
            '# Recipe Pilot Evidence',
            '',
            'Generated at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Evidence completed at UTC: pending',
            'Recipe mode: ' . $flags->mode(),
            'POS tenant: ' . (string) $scope['pos_tenant'],
            'POS branch: ' . (string) $scope['pos_branch'],
            'Store: ' . (string) $scope['store_id'],
            'Operator: ' . (string) $scope['operator'],
            'Note: ' . (string) $scope['note'],
            '',
            'Only replace `pending` with the readiness success word after the exact check has been completed and reviewed.',
            'Do not edit evidence for checks that were not actually performed.',
            '',
            '## Markers',
            '',
        ];

        foreach ($this->requiredMarkers($flags) as $marker) {
            $lines[] = str_replace(': pass', ': pending', $marker);
        }

        $lines[] = '';
        $lines[] = '## Evidence Details';
        $lines[] = '';
        foreach ($this->requiredDetails($flags) as $detail) {
            $lines[] = '- ' . $detail . ': pending';
        }
        $lines[] = '';
        $lines[] = '## Evidence Command Hints';
        $lines[] = '';
        $lines[] = 'These lines are hints only. They do not count as completed evidence until the matching detail line above is replaced with a real reviewed result.';
        foreach ($this->evidenceCommandHints($flags) as $label => $hint) {
            $lines[] = '- ' . $label . ': ' . $hint;
        }
        $lines[] = '';
        $lines[] = '## Operator QA Checklist';
        $lines[] = '';
        foreach ($this->requiredChecks($flags) as $check) {
            $lines[] = '- [ ] ' . $check;
        }
        $lines[] = '';
        $lines[] = '## Isolated Runtime Proofs';
        $lines[] = '';
        foreach ($this->requiredRuntimeProofs($flags) as $label => $tokens) {
            $command = $tokens[0] ?? '';
            $lines[] = '- ' . $label . ': pending (run: php ' . $command . ')';
        }
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    public function requiredMarkers(RecipeFeatureFlags $flags): array
    {
        $markers = $flags->mode() === 'reserve_only' ? self::RESERVATION_MARKERS : self::BASE_MARKERS;

        if ($this->reservationEvidenceEnabled($flags)) {
            $markers[] = 'Recipe reservation lifecycle smoke passed: pass';
        }
        if ($this->accountingEvidenceEnabled($flags)) {
            $markers[] = 'Recipe COGS accountant review: pass';
        }
        if ($this->availabilityEvidenceEnabled($flags)) {
            $markers[] = 'Recipe availability and menu sync smoke passed: pass';
        }

        return $markers;
    }

    public function requiredDetails(RecipeFeatureFlags $flags): array
    {
        $details = $flags->mode() === 'reserve_only' ? self::RESERVATION_DETAILS : self::BASE_DETAILS;

        if ($this->reservationEvidenceEnabled($flags)) {
            $details[] = 'Recipe reservation evidence';
        }
        if ($this->accountingEvidenceEnabled($flags)) {
            $details[] = 'Recipe COGS accountant evidence';
        }
        if ($this->availabilityEvidenceEnabled($flags)) {
            $details[] = 'Recipe availability and menu sync evidence';
        }
        if ($flags->isMoovaSyncEnabled()) {
            $details[] = 'Moova/Cofe recipe replay evidence';
        }
        if ($this->managerOverrideEnabled($flags)) {
            $details[] = 'Manager recipe stock override evidence';
        }
        if ($this->isHostedRuntime($flags)) {
            $details[] = 'Hosted/cloud runtime schema evidence';
        }

        return $details;
    }

    public function requiredDetailTokenGroups(RecipeFeatureFlags $flags): array
    {
        $requirements = [];
        foreach ($this->requiredDetails($flags) as $detail) {
            $groups = self::DETAIL_TOKEN_REQUIREMENTS[$detail] ?? [];
            if (!$groups) {
                continue;
            }
            $requirements[$detail] = array_map(static function ($group): array {
                $group = is_array($group) ? $group : [$group];

                return array_values(array_map('strval', $group));
            }, $groups);
        }

        return $requirements;
    }

    public function evidenceCommandHints(RecipeFeatureFlags $flags): array
    {
        $hints = [
            'Recipe schema evidence' => 'php tools/run_migrations.php --dry-run',
            'Recipe runtime preflight evidence' => 'php tools/recipe_runtime_preflight.php --json',
            'Pilot fixture verification evidence' => 'php tools/recipe_pilot_fixture.php --verify --json',
            'Recipe operational dashboard evidence' => 'Open recipe_operational_dashboard.php and confirm issue_total=0 / zero blockers for the pilot scope.',
            'Recipe stock reconciliation evidence' => 'Open recipe_stock_reconciliation.php and export/review the reconciliation CSV for the pilot scope.',
            'POS/table smoke evidence' => 'Record one cashier-browser POS order and one table order against prepared pilot recipe items.',
            'Migrated runtime write smoke evidence' => 'php tools/recipe_migrated_write_smoke.php --json --apply --run-id=<unique-local-run-id>, then record stock_preflight ok=true, idempotency_replayed=true, recipe_consumption movements, and positive movement cost.',
            'Recipe reservation evidence' => 'php tests/sync/recipe_reservation_lifecycle_runtime_test.php, then record the reviewed POS/table reservation add, edit, cancel, and qty_reserved result for the pilot scope.',
            'Recipe report export and role QA evidence' => 'php tools/recipe_report_export_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json',
            'Modifier substitution recipe evidence' => 'php tools/recipe_management_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --recipe-id=<fixture-recipe-id> --json, then record the Recipe QA oat milk modifier substitution browser result.',
            'Production batch evidence' => 'php tools/recipe_stock_operations_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --batch-id=<draft-batch-id> --json, then record the reviewed Recipe QA batch result.',
            'Waste and stock adjustment evidence' => 'php tools/recipe_stock_operations_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json, then record the inventory_adjustments.php operator review plus one reviewed waste movement and one stock adjustment result.',
            'Paid refund/void evidence' => 'php tools/recipe_paid_reversal_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --json, then record the paid order browser QA result.',
            'Recipe rollback evidence' => 'Document rollback by setting POSMAIN_RECIPE_MODE=off and disabling active recipe write flags.',
            'Isolated runtime proofs' => $this->runtimeProofSuiteCommandHint($flags),
        ];

        if ($this->accountingEvidenceEnabled($flags)) {
            $hints['Recipe COGS accountant evidence'] = 'Record accountant review of balanced recipe COGS/inventory journals for the pilot scope.';
        }
        if ($this->availabilityEvidenceEnabled($flags)) {
            $hints['Recipe availability and menu sync evidence'] = 'php tools/recipe_pos_grid_availability_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --category-id=<pilot-category> --json';
        }
        if ($flags->isMoovaSyncEnabled()) {
            $hints['Moova/Cofe recipe replay evidence'] = 'Run the Moova/Cofe pilot replay smoke and include tools/recipe_runtime_proof_suite.php output with Moova replay, Moova menu payload, and Cofe proof markers.';
        }
        if ($this->managerOverrideEnabled($flags)) {
            $hints['Manager recipe stock override evidence'] = 'php tools/recipe_manager_override_surface_smoke.php --base-url=http://127.0.0.1:8010 --cookie-file=/absolute/path/to/cookies.txt --category-id=<pilot-category> --json';
        }
        if ($this->isHostedRuntime($flags)) {
            $hints['Hosted/cloud runtime schema evidence'] = 'php tools/recipe_hosted_schema_preflight.php --json --target=<hosted-or-routed-shop-target>';
        }

        $required = array_flip($this->requiredDetails($flags));
        $ordered = [];
        foreach ($hints as $label => $hint) {
            if ($label === 'Isolated runtime proofs' || isset($required[$label])) {
                $ordered[$label] = $hint;
            }
        }

        return $ordered;
    }

    private function runtimeProofSuiteCommandHint(RecipeFeatureFlags $flags): string
    {
        $parts = ['php tools/recipe_runtime_proof_suite.php'];
        if ($this->availabilityEvidenceEnabled($flags)) {
            $parts[] = '--include-availability';
        }
        if ($this->managerOverrideEnabled($flags)) {
            $parts[] = '--include-manager-override';
        }
        if ($flags->isMoovaSyncEnabled()) {
            $parts[] = '--include-moova-sync';
        }
        $parts[] = '--json';

        return implode(' ', $parts);
    }

    public function requiredChecks(RecipeFeatureFlags $flags): array
    {
        $checks = $flags->mode() === 'reserve_only' ? self::RESERVATION_CHECKS : self::BASE_CHECKS;

        if ($this->reservationEvidenceEnabled($flags)) {
            $checks[] = 'Recipe reservation lifecycle smoke';
        }
        if ($this->accountingEvidenceEnabled($flags)) {
            $checks[] = 'Recipe accounting journal review';
        }
        if ($this->availabilityEvidenceEnabled($flags)) {
            $checks[] = 'Recipe availability POS and menu sync smoke';
        }
        if ($flags->isMoovaSyncEnabled()) {
            $checks[] = 'Moova/Cofe recipe replay smoke';
        }
        if ($this->managerOverrideEnabled($flags)) {
            $checks[] = 'Manager recipe stock override smoke';
        }

        return $checks;
    }

    public function requiredRuntimeProofs(RecipeFeatureFlags $flags): array
    {
        $proofs = $flags->mode() === 'reserve_only' ? self::RESERVATION_RUNTIME_PROOFS : self::BASE_RUNTIME_PROOFS;

        if ($this->reservationEvidenceEnabled($flags)) {
            $proofs += self::RESERVATION_RUNTIME_PROOFS;
        }
        if ($this->availabilityEvidenceEnabled($flags)) {
            $proofs['POS grid availability endpoint runtime proof'] = [
                'tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php',
                'recipe-pos-grid-availability-endpoint-runtime-ok',
            ];
        }
        if ($this->managerOverrideEnabled($flags)) {
            $proofs['Manager recipe stock override endpoint runtime proof'] = [
                'tests/sync/recipe_manager_override_endpoint_runtime_test.php',
                'recipe-manager-override-endpoint-runtime-ok',
            ];
        }
        if ($flags->isMoovaSyncEnabled()) {
            $proofs['Moova menu sync payload endpoint runtime proof'] = [
                'tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php',
                'recipe-moova-menu-sync-payload-endpoint-runtime-ok',
            ];
            $proofs['Moova/Cofe replay runtime proof'] = [
                'tests/sync/recipe_moova_replay_runtime_test.php',
                'recipe-moova-replay-runtime-ok',
            ];
            $proofs['Legacy Cofe endpoint runtime proof'] = [
                'tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php',
                'recipe-cofe-create-order-endpoint-runtime-ok',
            ];
        }

        return $proofs;
    }

    public function isRequired(RecipeFeatureFlags $flags): bool
    {
        return in_array($flags->mode(), ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true);
    }

    private function isHostedRuntime(RecipeFeatureFlags $flags): bool
    {
        $appConfig = $flags->appConfig();
        $role = strtolower(trim((string) ($appConfig['role'] ?? '')));
        if (in_array($role, ['cloud', 'fake_cloud'], true)) {
            return true;
        }

        return $this->boolValue($appConfig['router']['enabled'] ?? false);
    }

    private function reservationEvidenceEnabled(RecipeFeatureFlags $flags): bool
    {
        return $flags->mode() !== 'reserve_only' && $flags->isReservationEnabled();
    }

    private function managerOverrideEnabled(RecipeFeatureFlags $flags): bool
    {
        $config = $flags->config();

        return $this->boolValue($config['allow_negative_stock_with_approval'] ?? false)
            && $this->availabilityEvidenceEnabled($flags)
            && in_array($flags->mode(), ['availability_pilot', 'full'], true);
    }

    private function accountingEvidenceEnabled(RecipeFeatureFlags $flags): bool
    {
        $config = $flags->config();

        return $this->boolValue($config['accounting'] ?? false)
            && in_array($flags->mode(), ['accounting_pilot', 'availability_pilot', 'full'], true);
    }

    private function availabilityEvidenceEnabled(RecipeFeatureFlags $flags): bool
    {
        $config = $flags->config();

        return $this->boolValue($config['availability'] ?? false)
            && in_array($flags->mode(), ['availability_pilot', 'full'], true);
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function missingDetails(string $content, array $requiredDetails): array
    {
        $missing = [];
        foreach ($requiredDetails as $detail) {
            $pattern = '/^\s*[-*]?\s*' . preg_quote($detail, '/') . '\s*:\s*(.+?)\s*$/mi';
            if (!preg_match($pattern, $content, $match)) {
                $missing[] = $detail;
                continue;
            }

            if ($this->isPlaceholderDetail((string) ($match[1] ?? ''))) {
                $missing[] = $detail;
                continue;
            }

            if (!$this->detailTokenRequirementSatisfied($detail, (string) ($match[1] ?? ''))) {
                $missing[] = $detail;
            }
        }

        return $missing;
    }

    private function detailTokenRequirementSatisfied(string $detail, string $value): bool
    {
        $tokens = self::DETAIL_TOKEN_REQUIREMENTS[$detail] ?? [];
        if (!$tokens) {
            return true;
        }

        foreach ($tokens as $tokenGroup) {
            $tokenGroup = is_array($tokenGroup) ? $tokenGroup : [$tokenGroup];
            $allMatched = true;
            foreach ($tokenGroup as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                if (strpos($value, $token) === false) {
                    $allMatched = false;
                    break;
                }
            }
            if ($allMatched) {
                return true;
            }
        }

        return false;
    }

    private function isPlaceholderDetail(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === ''
            || in_array($value, ['pending', 'pass', 'passed', 'todo', 'tbd', 'n/a', 'na', 'none', '-'], true);
    }

    private function modeFromEvidence(string $content): string
    {
        if (!preg_match('/^\s*Recipe mode\s*:\s*([A-Za-z0-9_:-]+)\s*$/mi', $content, $match)) {
            return '';
        }

        return trim((string) ($match[1] ?? ''));
    }

    /**
     * @return DateTimeImmutable|false|null null when missing/placeholder, false when malformed.
     */
    private function completedAtFromEvidence(string $content)
    {
        $value = $this->evidenceLineValue($content, 'Evidence completed at UTC');
        if ($this->isPlaceholderDetail($value)) {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC')
        );
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }

        return $date;
    }

    private function expectedScope(array $scope): array
    {
        $expected = [];
        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $key) {
            if (!array_key_exists($key, $scope)) {
                continue;
            }
            $value = $scope[$key];
            if ($value === null || $value === '' || (int) $value < 0) {
                continue;
            }
            $expected[$key] = (string) (int) $value;
        }

        return $expected;
    }

    private function scopeMismatches(string $content, array $expectedScope): array
    {
        $labels = [
            'pos_tenant' => 'POS tenant',
            'pos_branch' => 'POS branch',
            'store_id' => 'Store',
        ];
        $mismatches = [];
        foreach ($expectedScope as $key => $expected) {
            $label = $labels[$key] ?? $key;
            $actual = $this->evidenceLineValue($content, $label);
            if ($actual !== $expected) {
                $mismatches[$key] = [
                    'expected' => $expected,
                    'evidence' => $actual,
                ];
            }
        }

        return $mismatches;
    }

    private function evidenceLineValue(string $content, string $label): string
    {
        if (!preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.*?)\s*$/mi', $content, $match)) {
            return '';
        }

        return trim((string) ($match[1] ?? ''));
    }

    private function missingChecks(string $content, array $requiredChecks): array
    {
        $missing = [];
        foreach ($requiredChecks as $check) {
            $checkboxPattern = '/^\s*[-*]\s*\[[xX]\]\s*' . preg_quote($check, '/') . '\s*$/mi';
            $passPattern = '/^\s*[-*]?\s*' . preg_quote($check, '/') . '\s*:\s*pass\s*$/mi';
            if (!preg_match($checkboxPattern, $content) && !preg_match($passPattern, $content)) {
                $missing[] = $check;
            }
        }

        return $missing;
    }

    private function missingRuntimeProofs(string $content, array $requiredRuntimeProofs): array
    {
        $missing = [];
        foreach ($requiredRuntimeProofs as $label => $tokens) {
            $pattern = '/^\s*[-*]?\s*' . preg_quote((string) $label, '/') . '\s*:\s*(.+?)\s*$/mi';
            if (!preg_match($pattern, $content, $match)) {
                $missing[] = (string) $label;
                continue;
            }

            $value = trim((string) ($match[1] ?? ''));
            if ($this->isPlaceholderDetail($value)) {
                $missing[] = (string) $label;
                continue;
            }

            $allTokensMatched = true;
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                if (strpos($value, $token) === false) {
                    $allTokensMatched = false;
                    break;
                }
            }
            if (!$allTokensMatched) {
                $missing[] = (string) $label;
            }
        }

        return $missing;
    }

    private function intInRange($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
