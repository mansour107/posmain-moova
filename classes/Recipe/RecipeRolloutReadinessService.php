<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeOperationalDashboardService.php';
require_once __DIR__ . '/RecipePilotEvidenceService.php';
require_once __DIR__ . '/RecipeRuntimePreflightService.php';

class RecipeRolloutReadinessService
{
    private const REQUIRED_SCHEMA_TABLES = [
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

    private RecipePilotEvidenceService $pilotEvidence;
    private RecipeRuntimePreflightService $runtimePreflight;

    public function __construct(?RecipePilotEvidenceService $pilotEvidence = null, ?RecipeRuntimePreflightService $runtimePreflight = null)
    {
        $this->pilotEvidence = $pilotEvidence ?: new RecipePilotEvidenceService();
        $this->runtimePreflight = $runtimePreflight ?: new RecipeRuntimePreflightService();
    }

    public function check(mysqli $conn, RecipeFeatureFlags $flags, array $options = []): array
    {
        $blockers = [];
        $warnings = [];
        $limit = $this->limit($options['limit'] ?? 100);
        $allowFullMode = !empty($options['allow_full_mode']);
        $allowCostPublicPayloads = !empty($options['allow_cost_public_payloads']);

        $checks = [
            'schema' => $this->schemaCheck($conn),
            'configuration' => $this->configurationCheck($flags, $allowFullMode, $allowCostPublicPayloads),
        ];

        foreach (['schema', 'configuration'] as $checkKey) {
            foreach ($checks[$checkKey]['blockers'] ?? [] as $blocker) {
                $blockers[] = (string) $blocker;
            }
            foreach ($checks[$checkKey]['warnings'] ?? [] as $warning) {
                $warnings[] = (string) $warning;
            }
        }

        $checks['runtime_preflight'] = $this->runtimePreflight->check($conn, $flags);
        if (empty($checks['runtime_preflight']['ok'])) {
            $blockers[] = 'recipe_runtime_preflight_not_ready';
        }
        foreach ($checks['runtime_preflight']['blockers'] ?? [] as $blocker) {
            $blockers[] = (string) $blocker;
        }
        foreach ($checks['runtime_preflight']['warnings'] ?? [] as $warning) {
            $warnings[] = (string) $warning;
        }

        $dashboard = ['summary' => []];
        if ($this->schemaReadyForDashboard($checks)) {
            $dashboard = (new RecipeOperationalDashboardService())->dashboard($conn, $flags, [
                'pos_tenant' => $this->nullableScopeInt($options['pos_tenant'] ?? null),
                'pos_branch' => $this->nullableScopeInt($options['pos_branch'] ?? null),
                'store_id' => $this->nullableScopeInt($options['store_id'] ?? null),
                'limit' => $limit,
            ]);
            $checks['dashboard'] = $this->dashboardCheck($dashboard, $flags);
        } else {
            $checks['dashboard'] = $this->dashboardSkippedUntilSchemaReady();
        }
        foreach ($checks['dashboard']['blockers'] as $blocker) {
            $blockers[] = (string) $blocker;
        }
        foreach ($checks['dashboard']['warnings'] as $warning) {
            $warnings[] = (string) $warning;
        }

        $evidenceFile = trim((string) ($options['pilot_evidence_file'] ?? ''));
        $maxEvidenceAgeHours = $this->intInRange($options['max_pilot_evidence_age_hours'] ?? 24, 0, 8760);
        $checks['pilot_evidence'] = $this->pilotEvidence->validate(
            $flags,
            $evidenceFile,
            $maxEvidenceAgeHours,
            $this->pilotEvidenceScope($flags, $options)
        );
        if (empty($checks['pilot_evidence']['ok']) && !empty($checks['pilot_evidence']['blocker'])) {
            $blockers[] = (string) $checks['pilot_evidence']['blocker'];
        }
        if (!empty($checks['pilot_evidence']['warning'])) {
            $warnings[] = (string) $checks['pilot_evidence']['warning'];
        }

        $checks['operator_commands'] = $this->operatorCommandTemplates();

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_diff(array_unique($warnings), $blockers));
        $ready = empty($blockers);

        return [
            'ok' => $ready,
            'ready_for_recipe_rollout' => $ready,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $flags->mode(),
            'checks' => $checks,
            'dashboard_summary' => $dashboard['summary'],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function schemaCheck(mysqli $conn): array
    {
        $existing = [];
        $missing = [];

        foreach (self::REQUIRED_SCHEMA_TABLES as $table) {
            if ($this->tableExists($conn, $table)) {
                $existing[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return [
            'ok' => $missing === [],
            'required_tables' => self::REQUIRED_SCHEMA_TABLES,
            'existing_tables' => $existing,
            'missing_tables' => $missing,
            'blockers' => array_map(static function (string $table): string {
                return 'recipe_schema_missing_' . $table;
            }, $missing),
            'warnings' => [],
        ];
    }

    private function configurationCheck(RecipeFeatureFlags $flags, bool $allowFullMode, bool $allowCostPublicPayloads): array
    {
        $config = $flags->config();
        $mode = $flags->mode();
        $blockers = [];
        $warnings = [];

        if ($mode !== 'off' && !$this->boolValue($config['enabled'] ?? false)) {
            $blockers[] = 'recipes_enabled_flag_off_for_non_off_mode';
        }
        if ($mode === 'full' && !$allowFullMode) {
            $blockers[] = 'full_mode_requires_explicit_allow_full_mode';
        }
        foreach ($this->activeModeFlagBlockers($config, $mode) as $blocker) {
            $blockers[] = $blocker;
        }
        $availabilityConfigured = $this->boolValue($config['availability'] ?? false);
        $availabilityEffective = $availabilityConfigured && in_array($mode, ['availability_pilot', 'full'], true);

        if ($flags->isStrictStockEnabled() && !$availabilityConfigured) {
            $blockers[] = 'strict_stock_requires_recipe_availability';
        }
        if ($flags->isStrictStockEnabled() && $availabilityConfigured && !$availabilityEffective) {
            $blockers[] = 'strict_stock_requires_effective_recipe_availability';
        }
        if ($this->negativeStockApprovalRequestedForAvailabilityMode($config, $mode) && !$availabilityConfigured) {
            $blockers[] = 'recipe_negative_stock_approval_requires_recipe_availability';
        }
        if ($flags->isStrictStockEnabled() && $this->boolValue($config['allow_negative_stock_with_approval'] ?? false)) {
            $blockers[] = 'recipe_negative_stock_approval_conflicts_with_strict_stock';
        }
        if ($this->boolValue($config['cost_public_payloads'] ?? false) && !$allowCostPublicPayloads) {
            $blockers[] = 'public_cost_payloads_enabled';
        }
        if ($this->recipeMoovaSyncRequestedForAvailabilityMode($config, $mode)) {
            if (!$this->boolValue($config['availability'] ?? false)) {
                $blockers[] = 'recipe_moova_sync_requires_recipe_availability';
            }
            $appConfig = $flags->appConfig();
            if (empty($appConfig['sync']['menu_sync_enabled'])) {
                $blockers[] = 'recipe_moova_sync_requires_menu_sync_enabled';
            }
            if (empty($appConfig['sync']['outbox_enabled']) && !$this->cloudToBranchPublishEnabled($appConfig)) {
                $blockers[] = 'recipe_moova_sync_requires_outbox_or_cloud_publish';
            }
            foreach ($this->branchOutboxTransportBlockers($appConfig) as $blocker) {
                $blockers[] = $blocker;
            }
        }

        if ($this->boolValue($config['consumption'] ?? false) && !in_array($mode, ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)) {
            $warnings[] = 'consumption_flag_set_but_mode_does_not_consume';
        }
        if ($this->productionVariancePolicyRequiresAccounting($config, $mode)) {
            $blockers[] = 'recipe_production_variance_policy_requires_accounting';
        }
        if ($this->boolValue($config['accounting'] ?? false)) {
            foreach ($this->requiredAccountingAccountKeys() as $accountKey) {
                if ((int) (($config['accounts'] ?? [])[$accountKey] ?? 0) <= 0) {
                    $blockers[] = 'recipe_account_missing_' . $accountKey;
                }
            }
        }

        $pilot = $config['pilot'] ?? [];
        $pilotBranch = trim((string) ($pilot['pos_branch'] ?? ''));
        $pilotItems = $this->intList($pilot['item_ids'] ?? []);
        $pilotCategories = $this->intList($pilot['category_ids'] ?? []);
        if (in_array($mode, ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'], true)
            && $pilotBranch === ''
            && !$pilotItems
            && !$pilotCategories
        ) {
            $blockers[] = 'pilot_mode_without_explicit_pilot_scope';
        }

        return [
            'ok' => $blockers === [],
            'mode' => $mode,
            'recipe_enabled_effective' => $flags->isEnabled(),
            'allow_full_mode' => $allowFullMode,
            'allow_cost_public_payloads' => $allowCostPublicPayloads,
            'pilot_scope' => [
                'pos_branch' => $pilotBranch,
                'item_count' => count($pilotItems),
                'category_count' => count($pilotCategories),
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function dashboardCheck(array $dashboard, RecipeFeatureFlags $flags): array
    {
        $summary = $dashboard['summary'] ?? [];
        $blockers = [];
        $warnings = [];

        $hardBlockers = [
            'stale_reservations' => 'stale_recipe_reservations',
            'negative_balances' => 'negative_recipe_inventory_balances',
            'invalid_inventory_movements' => 'invalid_recipe_inventory_movements',
            'recipe_setup_issues' => 'active_recipe_setup_issues',
            'movement_write_gaps' => 'recipe_usage_missing_consumption_movements',
        ];
        foreach ($hardBlockers as $summaryKey => $blocker) {
            if ((int) ($summary[$summaryKey] ?? 0) > 0) {
                $blockers[] = $blocker;
            }
        }

        if ((int) ($summary['missing_cost_snapshots'] ?? 0) > 0) {
            if (in_array($flags->mode(), ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)) {
                $blockers[] = 'active_recipes_missing_cost_snapshots';
            } else {
                $warnings[] = 'active_recipes_missing_cost_snapshots';
            }
        }

        if ((int) ($summary['availability_cache_gaps'] ?? 0) > 0) {
            if ($this->boolValue($flags->config()['availability'] ?? false)) {
                $blockers[] = 'recipe_availability_cache_gaps';
            } else {
                $warnings[] = 'recipe_availability_cache_gaps';
            }
        }

        if ((int) ($summary['menu_sync_outbox_issues'] ?? 0) > 0) {
            if ($flags->isMoovaSyncEnabled()) {
                $blockers[] = 'failed_menu_availability_sync';
            } else {
                $warnings[] = 'failed_menu_availability_sync';
            }
        }

        if ((int) ($summary['pending_menu_sync_outbox'] ?? 0) > 0) {
            if ($flags->isMoovaSyncEnabled()) {
                $blockers[] = 'pending_menu_availability_sync';
            } else {
                $warnings[] = 'pending_menu_availability_sync';
            }
        }

        if ((int) ($summary['runtime_bcmath_missing'] ?? 0) > 0) {
            if ($flags->isEnabled() || $flags->mode() !== 'off') {
                $blockers[] = 'recipe_runtime_bcmath_missing';
            } else {
                $warnings[] = 'recipe_runtime_bcmath_missing';
            }
        }

        return [
            'ok' => $blockers === [],
            'summary' => $summary,
            'health' => $dashboard['health'] ?? [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function cloudToBranchPublishEnabled(array $config): bool
    {
        return in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true)
            && !empty($config['sync']['cloud_to_branch_publish_enabled']);
    }

    private function branchOutboxTransportBlockers(array $config): array
    {
        if (empty($config['sync']['outbox_enabled']) || $this->cloudToBranchPublishEnabled($config)) {
            return [];
        }

        $blockers = [];
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $blockers[] = 'recipe_moova_sync_outbox_requires_branch_role';
        }
        if (trim((string) ($config['branch']['uuid'] ?? '')) === '') {
            $blockers[] = 'recipe_moova_sync_outbox_requires_branch_uuid';
        }
        if (trim((string) ($config['branch']['cloud_base_url'] ?? '')) === '') {
            $blockers[] = 'recipe_moova_sync_outbox_requires_cloud_base_url';
        }
        if (trim((string) ($config['sync']['branch_secret'] ?? '')) === '') {
            $blockers[] = 'recipe_moova_sync_outbox_requires_branch_sync_secret';
        }
        if (empty($config['sync']['branch_sync_enabled'])) {
            $blockers[] = 'recipe_moova_sync_outbox_requires_branch_sync_enabled';
        }
        if (empty($config['sync']['worker_enabled'])) {
            $blockers[] = 'recipe_moova_sync_outbox_requires_sync_worker_enabled';
        }

        return $blockers;
    }

    private function productionVariancePolicyRequiresAccounting(array $config, string $mode): bool
    {
        $policy = strtolower(trim((string) ($config['production_variance_policy'] ?? 'adjust_unit_cost')));
        if ($policy !== 'post_variance') {
            return false;
        }
        if (!$this->boolValue($config['consumption'] ?? false)) {
            return false;
        }
        if (!in_array($mode, ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)) {
            return false;
        }

        return !$this->boolValue($config['accounting'] ?? false)
            || !in_array($mode, ['accounting_pilot', 'availability_pilot', 'full'], true);
    }

    private function activeModeFlagBlockers(array $config, string $mode): array
    {
        $blockers = [];
        $consumptionBlockers = [
            'consume_pilot' => 'consume_pilot_requires_recipe_consumption',
            'accounting_pilot' => 'accounting_pilot_requires_recipe_consumption',
            'availability_pilot' => 'availability_pilot_requires_recipe_consumption',
            'full' => 'full_requires_recipe_consumption',
        ];
        $accountingBlockers = [
            'accounting_pilot' => 'accounting_pilot_requires_recipe_accounting',
            'full' => 'full_requires_recipe_accounting',
        ];
        $availabilityBlockers = [
            'availability_pilot' => 'availability_pilot_requires_recipe_availability',
            'full' => 'full_requires_recipe_availability',
        ];
        $reservationBlockers = [
            'reserve_only' => 'reserve_only_requires_recipe_reservations',
            'full' => 'full_requires_recipe_reservations',
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

    private function recipeMoovaSyncRequestedForAvailabilityMode(array $recipeConfig, string $mode): bool
    {
        return $this->boolValue($recipeConfig['moova_sync'] ?? false)
            && in_array($mode, ['availability_pilot', 'full'], true);
    }

    private function negativeStockApprovalRequestedForAvailabilityMode(array $recipeConfig, string $mode): bool
    {
        return $this->boolValue($recipeConfig['allow_negative_stock_with_approval'] ?? false)
            && in_array($mode, ['availability_pilot', 'full'], true);
    }

    private function dashboardSkippedUntilSchemaReady(): array
    {
        return [
            'ok' => false,
            'skipped' => true,
            'reason' => 'recipe_schema_not_ready',
            'summary' => [],
            'health' => [],
            'blockers' => [],
            'warnings' => ['recipe_dashboard_check_skipped_until_schema_ready'],
        ];
    }

    private function schemaReadyForDashboard(array $checks): bool
    {
        if (empty($checks['schema']['ok'])) {
            return false;
        }

        $runtimeSchema = $checks['runtime_preflight']['checks']['schema'] ?? null;
        if (is_array($runtimeSchema) && empty($runtimeSchema['ok'])) {
            return false;
        }

        return true;
    }

    private function operatorCommandTemplates(): array
    {
        return [
            'migration_dry_run' => [
                'command' => 'php tools/run_migrations.php --dry-run',
                'writes' => false,
            ],
            'reconciliation_report' => [
                'command' => 'Open recipe_stock_reconciliation.php and export the pilot branch CSV.',
                'writes' => false,
            ],
            'operational_dashboard' => [
                'command' => 'Open recipe_operational_dashboard.php for the pilot tenant/branch/store.',
                'writes' => false,
            ],
            'pilot_fixture_dry_run' => [
                'command' => 'php tools/recipe_pilot_fixture.php --json',
                'writes' => false,
            ],
            'pilot_fixture_apply' => [
                'command' => 'php tools/recipe_pilot_fixture.php --apply --json',
                'writes' => true,
                'writes_only' => 'named local/staging recipe QA fixture rows',
            ],
            'pilot_fixture_verify' => [
                'command' => 'php tools/recipe_pilot_fixture.php --verify --json',
                'writes' => false,
            ],
            'fixture_stock_adjustment' => [
                'command' => 'php tools/recipe_fixture_stock_adjustment.php --json --apply --run-id=<unique-local-run-id> --barcode=RQA-CUP --qty=3 --store-id=<pilot-store-id>',
                'writes' => true,
                'writes_only' => 'one guarded local/staging Recipe QA fixture stock adjustment through InventoryAdjustmentService',
            ],
            'pilot_evidence_template' => [
                'command' => 'php tools/recipe_pilot_evidence.php --template --output=/absolute/path/to/recipe-pilot-evidence.md',
                'writes' => true,
                'writes_only' => 'operator evidence file',
            ],
            'pilot_evidence_draft_bundle' => [
                'command' => 'php tools/recipe_pilot_evidence_bundle.php --json --output=/absolute/path/to/recipe-pilot-evidence.md',
                'writes' => true,
                'writes_only' => 'draft operator evidence file; not valid for rollout until browser/operator action lines are completed and validated',
            ],
            'pilot_evidence_validate' => [
                'command' => 'php tools/recipe_pilot_evidence.php --validate --file=/absolute/path/to/recipe-pilot-evidence.md',
                'writes' => false,
            ],
            'runtime_preflight' => [
                'command' => 'php tools/recipe_runtime_preflight.php --json',
                'writes' => false,
            ],
            'isolated_cashier_browser_fixture' => [
                'command' => 'php tools/recipe_cashier_browser_fixture.php --smoke --json',
                'writes' => true,
                'writes_only' => 'temporary local browser fixture database, dropped on exit',
            ],
            'migrated_runtime_write_smoke' => [
                'command' => 'php tools/recipe_migrated_write_smoke.php --json --apply --run-id=<unique-local-run-id>',
                'writes' => true,
                'writes_only' => 'one guarded local/staging QA takeaway order plus recipe usage, movements, balances, and idempotency evidence',
            ],
            'rollback_flags' => [
                'command' => 'Set POSMAIN_RECIPE_MODE=off and disable recipe write flags to roll back live recipe behavior.',
                'writes' => false,
            ],
        ];
    }

    private function requiredAccountingAccountKeys(): array
    {
        return [
            'cogs_account_id',
            'raw_inventory_account_id',
            'prepared_inventory_account_id',
            'packaging_inventory_account_id',
            'waste_expense_account_id',
            'production_variance_account_id',
        ];
    }

    private function pilotEvidenceScope(RecipeFeatureFlags $flags, array $options): array
    {
        $scope = [];
        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $key) {
            if (!array_key_exists($key, $options) || $options[$key] === '' || $options[$key] === null || (int) $options[$key] < 0) {
                continue;
            }
            $scope[$key] = (int) $options[$key];
        }

        $appBranch = $flags->appConfig()['branch'] ?? [];
        if (!array_key_exists('pos_tenant', $scope)
            && is_array($appBranch)
            && array_key_exists('pos_tenant', $appBranch)
            && $appBranch['pos_tenant'] !== null
            && $appBranch['pos_tenant'] !== ''
            && (int) $appBranch['pos_tenant'] >= 0
        ) {
            $scope['pos_tenant'] = (int) $appBranch['pos_tenant'];
        }

        if (!array_key_exists('pos_branch', $scope)) {
            $pilotBranch = trim((string) (($flags->config()['pilot'] ?? [])['pos_branch'] ?? ''));
            if ($pilotBranch !== '' && (int) $pilotBranch >= 0) {
                $scope['pos_branch'] = (int) $pilotBranch;
            }
        }
        if (!array_key_exists('pos_branch', $scope)
            && is_array($appBranch)
            && array_key_exists('pos_branch', $appBranch)
            && $appBranch['pos_branch'] !== null
            && $appBranch['pos_branch'] !== ''
            && (int) $appBranch['pos_branch'] >= 0
        ) {
            $scope['pos_branch'] = (int) $appBranch['pos_branch'];
        }

        return $scope;
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

    private function nullableScopeInt($value): int
    {
        if ($value === null || $value === '') {
            return -1;
        }

        return max(-1, (int) $value);
    }

    private function limit($value): int
    {
        return max(1, min(500, (int) ($value ?: 100)));
    }

    private function intInRange($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
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
                $ints[$int] = $int;
            }
        }

        return array_values($ints);
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
