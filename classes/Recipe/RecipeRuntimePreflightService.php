<?php

require_once __DIR__ . '/../Sync/SchemaManager.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/../Inventory/NegativeStockSalePolicyService.php';

class RecipeRuntimePreflightService
{
    private string $root;

    private const REQUIRED_RECIPE_TABLES = [
        'recipe_headers',
        'recipe_lines',
        'recipe_variant_lines',
        'recipe_cost_snapshots',
        'recipe_order_line_usage',
        'inventory_movements',
        'inventory_item_balances',
        'stock_reservations',
        'production_batches',
        'production_batch_lines',
        'recipe_audit_log',
        'recipe_availability_cache',
        'external_order_line_map',
    ];

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?: dirname(__DIR__, 2), DIRECTORY_SEPARATOR);
    }

    public function check(mysqli $conn, RecipeFeatureFlags $flags, array $options = []): array
    {
        $blockers = [];
        $warnings = [];

        $checks = [
            'runtime_dependencies' => $this->runtimeDependencyCheck(),
            'schema' => $this->schemaCheck($conn),
            'feature_flags' => $this->featureFlagCheck($conn, $flags),
            'operator_surfaces' => $this->operatorSurfaceCheck(),
            'source_guards' => $this->sourceGuardCheck(),
            'report_links' => $this->reportLinkCheck(),
            'operator_tools' => $this->operatorToolCheck(),
        ];

        foreach ($checks as $section) {
            foreach ($section['blockers'] ?? [] as $blocker) {
                $blockers[] = (string) $blocker;
            }
            foreach ($section['warnings'] ?? [] as $warning) {
                $warnings[] = (string) $warning;
            }
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_diff(array_unique($warnings), $blockers));

        return [
            'ok' => $blockers === [],
            'ready_for_recipe_operator_qa' => $blockers === [],
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $flags->mode(),
            'checks' => $checks,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function runtimeDependencyCheck(): array
    {
        $extensions = [
            'bcmath' => function_exists('bcadd'),
        ];
        $missing = [];
        foreach ($extensions as $extension => $loaded) {
            if (!$loaded) {
                $missing[] = $extension;
            }
        }

        return [
            'ok' => $missing === [],
            'extensions' => $extensions,
            'missing_extensions' => $missing,
            'blockers' => in_array('bcmath', $missing, true) ? ['recipe_runtime_bcmath_missing'] : [],
            'warnings' => [],
        ];
    }

    private function schemaCheck(mysqli $conn): array
    {
        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements($conn);
        $missingRecipeTables = [];
        foreach (self::REQUIRED_RECIPE_TABLES as $table) {
            if (!$this->tableExists($conn, $table)) {
                $missingRecipeTables[] = $table;
            }
        }

        $blockers = [];
        if ($missingRecipeTables) {
            $blockers[] = 'recipe_runtime_schema_missing_tables';
        }
        if ($pending) {
            $blockers[] = 'recipe_runtime_schema_pending_migrations';
        }

        return [
            'ok' => $blockers === [],
            'required_recipe_tables' => self::REQUIRED_RECIPE_TABLES,
            'missing_recipe_tables' => $missingRecipeTables,
            'pending_count' => count($pending),
            'pending_labels' => array_keys($pending),
            'blockers' => $blockers,
            'warnings' => [],
        ];
    }

    private function featureFlagCheck(mysqli $conn, RecipeFeatureFlags $flags): array
    {
        $config = $flags->config();
        $mode = $flags->mode();
        $blockers = [];
        $warnings = [];

        if ($mode === 'off') {
            $warnings[] = 'recipe_runtime_preflight_mode_off';
        }
        if (in_array($mode, ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)) {
            $warnings[] = 'recipe_runtime_preflight_active_mode_use_pilot_evidence_gate';
        }
        if ($this->boolValue($config['cost_public_payloads'] ?? false)) {
            $warnings[] = 'recipe_runtime_preflight_public_cost_payloads_enabled';
        }
        foreach ($this->activeModeFlagBlockers($config, $mode) as $blocker) {
            $blockers[] = $blocker;
        }
        if ($this->pilotModeWithoutExplicitScope($config, $mode)) {
            $blockers[] = 'recipe_runtime_pilot_mode_without_explicit_pilot_scope';
        }
        $negativeStockPolicy = (new NegativeStockSalePolicyService($flags->appConfig()))->resolve($conn);
        foreach ($this->stockPolicyBlockers($config, $mode, $negativeStockPolicy) as $blocker) {
            $blockers[] = $blocker;
        }

        return [
            'ok' => $blockers === [],
            'mode' => $mode,
            'enabled' => $flags->isEnabled(),
            'reservations_enabled' => $this->boolValue($config['reservations'] ?? false),
            'consumption_enabled' => $this->boolValue($config['consumption'] ?? false),
            'availability_enabled' => $this->boolValue($config['availability'] ?? false),
            'accounting_enabled' => $this->boolValue($config['accounting'] ?? false),
            'moova_sync_enabled' => $flags->isMoovaSyncEnabled(),
            'negative_stock_sale_policy' => $negativeStockPolicy,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function activeModeFlagBlockers(array $config, string $mode): array
    {
        $blockers = [];
        $consumptionBlockers = [
            'consume_pilot' => 'recipe_runtime_consume_pilot_requires_recipe_consumption',
            'accounting_pilot' => 'recipe_runtime_accounting_pilot_requires_recipe_consumption',
            'availability_pilot' => 'recipe_runtime_availability_pilot_requires_recipe_consumption',
            'full' => 'recipe_runtime_full_requires_recipe_consumption',
        ];
        $accountingBlockers = [
            'accounting_pilot' => 'recipe_runtime_accounting_pilot_requires_recipe_accounting',
            'full' => 'recipe_runtime_full_requires_recipe_accounting',
        ];
        $availabilityBlockers = [
            'availability_pilot' => 'recipe_runtime_availability_pilot_requires_recipe_availability',
            'full' => 'recipe_runtime_full_requires_recipe_availability',
        ];
        $reservationBlockers = [
            'reserve_only' => 'recipe_runtime_reserve_only_requires_recipe_reservations',
            'full' => 'recipe_runtime_full_requires_recipe_reservations',
        ];

        if (isset($reservationBlockers[$mode]) && !$this->boolValue($config['reservations'] ?? false)) {
            $blockers[] = $reservationBlockers[$mode];
        }
        if (isset($consumptionBlockers[$mode]) && !$this->boolValue($config['consumption'] ?? false)) {
            $blockers[] = $consumptionBlockers[$mode];
        }
        if (isset($accountingBlockers[$mode]) && !$this->boolValue($config['accounting'] ?? false)) {
            $blockers[] = $accountingBlockers[$mode];
        }
        if (isset($availabilityBlockers[$mode]) && !$this->boolValue($config['availability'] ?? false)) {
            $blockers[] = $availabilityBlockers[$mode];
        }

        return $blockers;
    }

    private function pilotModeWithoutExplicitScope(array $config, string $mode): bool
    {
        if (!in_array($mode, ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'], true)) {
            return false;
        }

        $pilot = is_array($config['pilot'] ?? null) ? $config['pilot'] : [];
        $pilotBranch = trim((string) ($pilot['pos_branch'] ?? ''));

        return $pilotBranch === ''
            && $this->intList($pilot['item_ids'] ?? []) === []
            && $this->intList($pilot['category_ids'] ?? []) === [];
    }

    private function stockPolicyBlockers(array $config, string $mode, string $policy): array
    {
        $blockers = [];
        $blocksNegativeStock = $policy === NegativeStockSalePolicyService::BLOCK;
        $policyRelevant = !in_array($mode, ['off', 'schema_only', 'read_only'], true);
        $availabilityConfigured = $this->boolValue($config['availability'] ?? false);
        $availabilityEffective = $availabilityConfigured && in_array($mode, ['availability_pilot', 'full'], true);

        $modeAlreadyRequiresAvailability = in_array($mode, ['availability_pilot', 'full'], true);
        if ($policyRelevant && $blocksNegativeStock && !$availabilityConfigured && !$modeAlreadyRequiresAvailability) {
            $blockers[] = 'recipe_runtime_strict_stock_requires_recipe_availability';
        }
        if ($policyRelevant && $blocksNegativeStock && $availabilityConfigured && !$availabilityEffective && !$modeAlreadyRequiresAvailability) {
            $blockers[] = 'recipe_runtime_strict_stock_requires_effective_recipe_availability';
        }

        return $blockers;
    }

    private function operatorSurfaceCheck(): array
    {
        $files = [
            'recipe_editor.php',
            'recipe_manage.php',
            'recipe_production.php',
            'inventory_adjustments.php',
            'recipe_stock_reconciliation.php',
            'recipe_audit_report.php',
            'recipe_operations_report.php',
            'recipe_operational_dashboard.php',
            'ajax/refund_order.php',
            'ajax/manager_approval.php',
            'ajax/recipe_editor_lookup.php',
        ];

        return $this->filePresenceCheck('operator_surfaces', $files);
    }

    private function sourceGuardCheck(): array
    {
        $expectations = [
            'recipe_manage.php' => [
                'require_login()',
                'posmain_recipe_manage_can_edit',
                'recipe_permissions.php',
                'posmain_recipe_can_view_costs',
                "require_csrf('recipe_editor')",
                'RecipeEditorMutationService',
            ],
            'recipe_production.php' => [
                'require_login()',
                'posmain_recipe_production_can_view',
                'posmain_recipe_production_can_view_cost',
                'recipe_permissions.php',
                'posmain_recipe_can_view_costs',
                'if ($canViewProductionCost)',
                "require_csrf('recipe_production')",
                'ProductionBatchMutationService',
            ],
            'inventory_adjustments.php' => [
                "require_permission('inventory.edit'",
                "csrf_meta_tag('inventory_adjustment'",
                'InventoryReasonCodeService',
                'InventoryScopeResolver',
                'RecipeWasteAdjustmentReadService',
                'ajax/inventory_adjustment.php',
                'inventoryAdjustmentReasonCode',
                'inventoryAdjustmentPhoto',
            ],
            'recipe_editor.php' => [
                'require_login()',
                'posmain_recipe_editor_can_view',
                'recipe_permissions.php',
                'posmain_recipe_can_view_costs',
                'RecipeEditorReadService',
            ],
            'recipe_stock_reconciliation.php' => [
                'require_login()',
                'posmain_recipe_reconciliation_can_view',
                'recipe_permissions.php',
                'csv_export.php',
                'RecipeReconciliationService',
            ],
            'recipe_audit_report.php' => [
                'require_login()',
                'posmain_recipe_audit_can_view',
                'recipe_permissions.php',
                'posmain_recipe_can_view_sensitive_reports',
                'csv_export.php',
                'RecipeAuditService',
            ],
            'recipe_operations_report.php' => [
                'require_login()',
                'recipe_permissions.php',
                'posmain_recipe_can_view_costs',
                'csv_export.php',
                'RecipeOperationsReportService',
            ],
            'recipe_operational_dashboard.php' => [
                'require_login()',
                'recipe_permissions.php',
                'posmain_recipe_can_view_sensitive_reports',
                'RecipeOperationalDashboardService',
            ],
            'includes/recipe_permissions.php' => [
                'posmain_recipe_has_stock_sensitive_access',
                'posmain_recipe_can_view_costs',
                'add_stock',
                'edit_stock',
            ],
            'includes/recipe_report_permissions.php' => [
                'recipe_permissions.php',
                'posmain_recipe_report_link_permissions',
                'posmain_recipe_report_can_view_sales_reconciliation',
                'auth_guard_session_has_permission',
            ],
            'reports.php' => [
                'recipe_report_permissions.php',
                'posmain_recipe_report_link_permissions',
                "\$recipeReportLinks['stock_reconciliation']",
                "\$recipeReportLinks['audit']",
                "\$recipeReportLinks['editor']",
                "\$recipeReportLinks['manage']",
                "\$recipeReportLinks['production']",
                "\$recipeReportLinks['waste']",
                "\$recipeReportLinks['operations']",
                "\$recipeReportLinks['dashboard']",
            ],
            'sales-reports.php' => [
                'recipe_report_permissions.php',
                'posmain_recipe_report_can_view_sales_reconciliation',
                '$canViewRecipeSalesReconciliation',
            ],
            'ajax/refund_order.php' => [
                "require_csrf('pos_browser')",
                'require_pos_lane_permission(',
                'reversePaidOrder',
                'IdempotencyService',
            ],
            'ajax/manager_approval.php' => [
                "require_csrf('pos_browser')",
                'pos.recipe_stock_override',
                'manager_approval',
            ],
            'ajax/recipe_editor_lookup.php' => [
                'require_login()',
                'RecipeEditorLookupService',
            ],
        ];

        $missing = [];
        $checked = [];
        foreach ($expectations as $path => $snippets) {
            $source = $this->source($path);
            if ($source === null) {
                $missing[] = [
                    'file' => $path,
                    'snippet' => '*file missing*',
                ];
                continue;
            }
            foreach ($snippets as $snippet) {
                if (strpos($source, $snippet) === false) {
                    $missing[] = [
                        'file' => $path,
                        'snippet' => $snippet,
                    ];
                }
            }
            $checked[] = $path;
        }

        return [
            'ok' => $missing === [],
            'checked_files' => $checked,
            'missing_snippets' => $missing,
            'blockers' => $missing ? ['recipe_runtime_source_guards_missing'] : [],
            'warnings' => [],
        ];
    }

    private function reportLinkCheck(): array
    {
        $expectations = [
            'reports.php' => [
                'recipe_stock_reconciliation.php',
                'recipe_audit_report.php',
                'recipe_editor.php',
                'recipe_manage.php',
                'recipe_production.php',
                'inventory_adjustments.php',
                'recipe_operations_report.php',
                'recipe_operational_dashboard.php',
            ],
            'sales-reports.php' => [
                'recipe_stock_reconciliation.php',
            ],
        ];

        $missing = [];
        foreach ($expectations as $path => $links) {
            $source = $this->source($path);
            if ($source === null) {
                $missing[] = [
                    'file' => $path,
                    'link' => '*file missing*',
                ];
                continue;
            }
            foreach ($links as $link) {
                if (strpos($source, $link) === false) {
                    $missing[] = [
                        'file' => $path,
                        'link' => $link,
                    ];
                }
            }
        }

        return [
            'ok' => $missing === [],
            'missing_links' => $missing,
            'blockers' => $missing ? ['recipe_runtime_report_links_missing'] : [],
            'warnings' => [],
        ];
    }

    private function operatorToolCheck(): array
    {
        $files = [
            'tools/recipe_rollout_readiness.php',
            'tools/recipe_pilot_evidence.php',
            'tools/recipe_pilot_evidence_bundle.php',
            'tools/recipe_hosted_schema_preflight.php',
            'tools/recipe_runtime_proof_suite.php',
            'tools/recipe_operator_surface_smoke.php',
            'tools/recipe_management_surface_smoke.php',
            'tools/recipe_stock_operations_surface_smoke.php',
            'tools/recipe_report_export_smoke.php',
            'tools/recipe_cashier_browser_fixture.php',
            'tools/recipe_pos_grid_availability_surface_smoke.php',
            'tools/recipe_paid_reversal_surface_smoke.php',
            'tools/recipe_manager_override_surface_smoke.php',
            'tools/recipe_pilot_fixture.php',
            'tools/recipe_fixture_stock_adjustment.php',
            'tools/recipe_migrated_write_smoke.php',
            'tools/recipe_refresh_availability.php',
            'tools/recipe_expire_reservations.php',
        ];
        $presence = $this->filePresenceCheck('operator_tools', $files);
        $unsafe = [];
        foreach ($files as $file) {
            $source = $this->source($file);
            if ($source === null) {
                continue;
            }
            foreach (['shell_exec', 'passthru', 'system('] as $needle) {
                if (strpos($source, $needle) !== false) {
                    $unsafe[] = [
                        'file' => $file,
                        'needle' => $needle,
                    ];
                }
            }
        }

        $blockers = $presence['blockers'];
        if ($unsafe) {
            $blockers[] = 'recipe_runtime_operator_tool_unsafe_shell';
        }

        return [
            'ok' => $blockers === [],
            'files' => $presence['files'],
            'missing_files' => $presence['missing_files'],
            'unsafe_shell_calls' => $unsafe,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
        ];
    }

    private function filePresenceCheck(string $key, array $files): array
    {
        $present = [];
        $missing = [];
        foreach ($files as $file) {
            if (is_file($this->path($file))) {
                $present[] = $file;
            } else {
                $missing[] = $file;
            }
        }

        return [
            'ok' => $missing === [],
            'files' => $present,
            'missing_files' => $missing,
            'blockers' => $missing ? ['recipe_runtime_' . $key . '_missing'] : [],
            'warnings' => [],
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function source(string $relativePath): ?string
    {
        $path = $this->path($relativePath);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $source = file_get_contents($path);

        return is_string($source) ? $source : null;
    }

    private function path(string $relativePath): string
    {
        return $this->root . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function intList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ints = [];
        foreach ($value as $item) {
            $int = (int) $item;
            if ($int > 0) {
                $ints[] = $int;
            }
        }

        return array_values(array_unique($ints));
    }
}
