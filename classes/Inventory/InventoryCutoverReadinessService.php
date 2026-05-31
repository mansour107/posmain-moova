<?php

require_once __DIR__ . '/../Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/InventoryAccountingReconciliationAcceptanceService.php';
require_once __DIR__ . '/InventoryAccountingReconciliationService.php';
require_once __DIR__ . '/InventoryBalanceRebuildAcceptanceService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryHistoricalMigrationService.php';
require_once __DIR__ . '/InventoryLegacyRetirementReadinessService.php';
require_once __DIR__ . '/InventoryOperationalHardeningService.php';
require_once __DIR__ . '/InventoryReconciliationAcceptanceService.php';

class InventoryCutoverReadinessService
{
    public function review(mysqli $conn, array $filters = [], array $options = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $flags = $options['flags'] ?? new InventoryFeatureFlags();
        if (!$flags instanceof InventoryFeatureFlags) {
            $flags = new InventoryFeatureFlags();
        }

        $migration = $this->migrationReadiness($conn, $filters, $options);
        $reconciliation = $this->inventoryReconciliationReadiness($conn, $filters, $options);
        $accounting = $this->accountingReadiness($conn, $filters, $options);
        $hardening = $this->hardeningReadiness($conn);
        $legacy = $this->legacyRetirementReadiness($conn);

        $cutoverBlockers = array_values(array_unique(array_merge(
            $migration['blockers'],
            $reconciliation['blockers'],
            $accounting['blockers'],
            $hardening['blockers']
        )));
        $legacyBlockers = array_values(array_unique(array_merge($cutoverBlockers, $legacy['blockers'])));

        return [
            'ready_for_cutover' => empty($cutoverBlockers),
            'ready_for_legacy_retirement' => empty($legacyBlockers),
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'filters' => $filters,
            'mode' => $flags->mode(),
            'warnings' => $flags->mode() === 'live' ? [] : ['inventory_ledger_mode_not_live_yet'],
            'migration' => $migration,
            'reconciliation' => $reconciliation,
            'accounting_reconciliation' => $accounting,
            'hardening' => $hardening,
            'legacy_retirement' => $legacy,
            'required_before_cutover' => [
                'clean_or_accepted_reconciliation',
                'reviewed_historical_migration_plan',
                'clean_or_accepted_inventory_accounting_reconciliation',
                'inventory_operational_health_green',
                'browser_operator_qa',
                'live_inventory_cutover_signoff',
            ],
            'required_before_legacy_retirement' => [
                'ready_for_cutover',
                'fat_details_stock_triggers_removed',
                'no_direct_legacy_stock_truth_paths',
            ],
            'blockers' => $cutoverBlockers,
            'legacy_retirement_blockers' => $legacyBlockers,
        ];
    }

    private function migrationReadiness(mysqli $conn, array $filters, array $options): array
    {
        if (isset($options['reviewed_decisions']) && is_array($options['reviewed_decisions'])) {
            $filters['reviewed_decisions'] = $options['reviewed_decisions'];
        }
        $plan = (new InventoryHistoricalMigrationService())->migrationPlan($conn, $filters);
        $backfillSummary = $plan['backfill']['summary'] ?? [];
        $rebuildSummary = $plan['rebuild']['summary'] ?? [];
        $blockers = $this->sectionBlockers($plan, ['snapshot', 'backfill', 'rebuild']);
        $rebuildRows = $plan['rebuild']['rows'] ?? [];
        $rebuildCandidateRows = array_values(array_filter($rebuildRows, static fn(array $row): bool => !empty($row['needs_rebuild'])));

        if ((int) ($backfillSummary['ambiguous_count'] ?? 0) > 0) {
            $blockers[] = 'ambiguous_legacy_rows_require_review';
        }
        if ((int) ($backfillSummary['unused_review_decision_count'] ?? 0) > 0) {
            $blockers[] = 'unused_review_decisions_in_current_scope';
        }
        if ((int) ($backfillSummary['safe_candidate_count'] ?? 0) > 0) {
            $blockers[] = 'safe_legacy_backfill_candidates_not_applied';
        }

        $acceptedRebuildRows = [];
        $unacceptedRebuildRows = $rebuildCandidateRows;
        $acceptanceBlockers = [];
        if (!empty($options['rebuild_acceptance_file'])) {
            $acceptance = (new InventoryBalanceRebuildAcceptanceService())->loadFile((string) $options['rebuild_acceptance_file']);
            foreach (($acceptance['blockers'] ?? []) as $blocker) {
                $acceptanceBlockers[] = (string) $blocker;
                $blockers[] = (string) $blocker;
            }
            if (empty($acceptance['blockers'])) {
                $evaluation = (new InventoryBalanceRebuildAcceptanceService())->evaluate($rebuildRows, $acceptance['entries'] ?? []);
                $rebuildRows = $evaluation['rows'] ?? $rebuildRows;
                foreach (($evaluation['blockers'] ?? []) as $blocker) {
                    $acceptanceBlockers[] = (string) $blocker;
                    $blockers[] = (string) $blocker;
                }
                $acceptedRebuildRows = array_values(array_filter($rebuildRows, static fn(array $row): bool => !empty($row['accepted_balance_rebuild_difference'])));
                $unacceptedRebuildRows = array_values(array_filter($rebuildRows, static fn(array $row): bool => !empty($row['needs_rebuild']) && empty($row['accepted_balance_rebuild_difference'])));
            }
        }
        if ((int) ($rebuildSummary['rebuild_candidate_count'] ?? 0) > count($rebuildCandidateRows)) {
            $blockers[] = 'inventory_rebuild_candidates_exceed_review_limit';
        }
        if ($this->anyRebuildRow($unacceptedRebuildRows, 'has_difference')) {
            $blockers[] = 'inventory_rebuild_has_quantity_differences';
        }
        if ($this->anyRebuildRow($unacceptedRebuildRows, 'has_cost_difference')) {
            $blockers[] = 'inventory_rebuild_has_cost_differences';
        }
        if ($this->anyRebuildRow($unacceptedRebuildRows, 'has_last_movement_difference')) {
            $blockers[] = 'inventory_rebuild_has_last_movement_differences';
        }
        if ($this->anyMissingCurrentBalance($unacceptedRebuildRows)) {
            $blockers[] = 'inventory_rebuild_has_missing_balance_rows';
        }
        if ($acceptedRebuildRows && empty($options['allow_accepted_rebuild_differences'])) {
            $blockers[] = 'accepted_balance_rebuild_differences_require_explicit_allow_flag';
        }

        return [
            'ok' => empty($blockers) && empty($acceptanceBlockers),
            'summary' => [
                'safe_candidate_count' => (int) ($backfillSummary['safe_candidate_count'] ?? 0),
                'already_migrated_count' => (int) ($backfillSummary['already_migrated_count'] ?? 0),
                'ambiguous_count' => (int) ($backfillSummary['ambiguous_count'] ?? 0),
                'unused_review_decision_count' => (int) ($backfillSummary['unused_review_decision_count'] ?? 0),
                'difference_count' => (int) ($rebuildSummary['difference_count'] ?? 0),
                'cost_difference_count' => (int) ($rebuildSummary['cost_difference_count'] ?? 0),
                'rebuild_candidate_count' => (int) ($rebuildSummary['rebuild_candidate_count'] ?? 0),
                'accepted_rebuild_candidate_count' => count($acceptedRebuildRows),
                'unaccepted_rebuild_candidate_count' => count($unacceptedRebuildRows),
            ],
            'sample_ambiguous_rows' => array_slice($plan['backfill']['sample_ambiguous_rows'] ?? [], 0, 5),
            'sample_rebuild_rows' => array_slice(array_values(array_filter($rebuildRows, static fn(array $row): bool => !empty($row['needs_rebuild']))), 0, 5),
            'sample_unaccepted_rebuild_rows' => array_slice($unacceptedRebuildRows, 0, 5),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function inventoryReconciliationReadiness(mysqli $conn, array $filters, array $options): array
    {
        $rows = (new RecipeReconciliationService())->report($conn, $filters + ['differences_only' => true]);
        $differenceRows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['has_difference'])));
        $blockers = [];

        if (!empty($options['acceptance_file'])) {
            $acceptance = (new InventoryReconciliationAcceptanceService())->loadFile((string) $options['acceptance_file']);
            foreach (($acceptance['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
            if (empty($acceptance['blockers'])) {
                $evaluation = (new InventoryReconciliationAcceptanceService())->evaluate($differenceRows, $acceptance['entries'] ?? []);
                $differenceRows = $evaluation['rows'] ?? $differenceRows;
                foreach (($evaluation['blockers'] ?? []) as $blocker) {
                    $blockers[] = (string) $blocker;
                }
            }
        }

        $acceptedRows = array_values(array_filter($differenceRows, static fn(array $row): bool => !empty($row['accepted_reconciliation'])));
        $unacceptedRows = array_values(array_filter($differenceRows, static fn(array $row): bool => empty($row['accepted_reconciliation'])));
        if ($unacceptedRows) {
            $blockers[] = 'inventory_reconciliation_has_differences';
        }
        if ($acceptedRows && empty($options['allow_accepted_reconciliation'])) {
            $blockers[] = 'accepted_reconciliation_requires_explicit_allow_flag';
        }

        return [
            'ok' => empty($blockers),
            'difference_count' => count($differenceRows),
            'accepted_difference_count' => count($acceptedRows),
            'unaccepted_difference_count' => count($unacceptedRows),
            'sample_unaccepted_differences' => array_slice($unacceptedRows, 0, 5),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function accountingReadiness(mysqli $conn, array $filters, array $options): array
    {
        $required = array_key_exists('require_accounting', $options) ? (bool) $options['require_accounting'] : true;
        $review = (new InventoryAccountingReconciliationService())->review($conn, $this->accountingFilters($filters));
        $rows = $review['rows'] ?? [];
        $blockers = [];

        if (!empty($options['accounting_acceptance_file'])) {
            $acceptance = (new InventoryAccountingReconciliationAcceptanceService())->loadFile((string) $options['accounting_acceptance_file']);
            foreach (($acceptance['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
            if (empty($acceptance['blockers'])) {
                $evaluation = (new InventoryAccountingReconciliationAcceptanceService())->evaluate($rows, $acceptance['entries'] ?? []);
                $rows = $evaluation['rows'] ?? $rows;
                foreach (($evaluation['blockers'] ?? []) as $blocker) {
                    $blockers[] = (string) $blocker;
                }
            }
        }

        $problemRows = array_values(array_filter($rows, static function (array $row): bool {
            return (string) ($row['reconciliation_status'] ?? '') !== 'balanced';
        }));
        $acceptedRows = array_values(array_filter($problemRows, static fn(array $row): bool => !empty($row['accepted_accounting_reconciliation'])));
        $unacceptedRows = array_values(array_filter($problemRows, static fn(array $row): bool => empty($row['accepted_accounting_reconciliation'])));
        if ($required && empty($review['ok']) && !$problemRows) {
            $blockers[] = 'inventory_accounting_reconciliation_unavailable';
        }
        if ($required && $unacceptedRows) {
            $blockers[] = 'inventory_accounting_reconciliation_not_ready';
        }
        if ($required && $acceptedRows && empty($options['allow_accepted_accounting_reconciliation'])) {
            $blockers[] = 'accepted_inventory_accounting_reconciliation_requires_explicit_allow_flag';
        }

        return [
            'ok' => empty($blockers),
            'required' => $required,
            'status' => (string) ($review['status'] ?? ''),
            'problem_count' => count($problemRows),
            'accepted_problem_count' => count($acceptedRows),
            'unaccepted_problem_count' => count($unacceptedRows),
            'sample_unaccepted_problems' => array_slice($unacceptedRows, 0, 5),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function hardeningReadiness(mysqli $conn): array
    {
        $service = new InventoryOperationalHardeningService();
        $missing = [];
        foreach ($service->requiredIndexes() as $table => $indexes) {
            foreach ($indexes as $index) {
                if (!$this->indexExists($conn, $table, $index)) {
                    $missing[] = $table . '.' . $index;
                }
            }
        }

        return [
            'ok' => empty($missing),
            'missing_indexes' => $missing,
            'blockers' => $missing ? ['missing_required_inventory_indexes'] : [],
        ];
    }

    private function legacyRetirementReadiness(mysqli $conn): array
    {
        $review = (new InventoryLegacyRetirementReadinessService())->review($conn);

        return [
            'ok' => !empty($review['ok']),
            'trigger_names' => $review['trigger_names'] ?? [],
            'proven_controls' => $review['proven_controls'] ?? [],
            'pending_retirement_items' => $review['pending_retirement_items'] ?? [],
            'blockers' => $review['blockers'] ?? [],
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $normalized = [
            'pos_tenant' => max(0, (int) ($filters['pos_tenant'] ?? 0)),
            'pos_branch' => max(0, (int) ($filters['pos_branch'] ?? 0)),
            'store_id' => max(0, (int) ($filters['store_id'] ?? 0)),
            'item_id' => max(0, (int) ($filters['item_id'] ?? 0)),
            'limit' => max(1, min(5000, (int) ($filters['limit'] ?? 1000))),
            'sample_limit' => max(1, min(100, (int) ($filters['sample_limit'] ?? 25))),
        ];
        if ($normalized['item_id'] > 0) {
            $normalized['item_ids'] = [$normalized['item_id']];
        }

        return $normalized;
    }

    private function accountingFilters(array $filters): array
    {
        $storeId = (int) ($filters['store_id'] ?? 0);

        return [
            'pos_tenant' => (int) ($filters['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($filters['pos_branch'] ?? 0),
            'store_id' => $storeId > 0 ? $storeId : -1,
            'limit' => (int) ($filters['limit'] ?? 1000),
        ];
    }

    private function sectionBlockers(array $payload, array $sections): array
    {
        $blockers = [];
        foreach ($sections as $section) {
            foreach (($payload[$section]['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
        }
        foreach (($payload['blockers'] ?? []) as $blocker) {
            $blockers[] = (string) $blocker;
        }

        return array_values(array_unique($blockers));
    }

    private function anyRebuildRow(array $rows, string $flag): bool
    {
        foreach ($rows as $row) {
            if (!empty($row[$flag])) {
                return true;
            }
        }

        return false;
    }

    private function anyMissingCurrentBalance(array $rows): bool
    {
        foreach ($rows as $row) {
            if (empty($row['current_balance_exists'])) {
                return true;
            }
        }

        return false;
    }

    private function indexExists(mysqli $conn, string $table, string $index): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS index_count
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND INDEX_NAME = ?");
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['index_count'] ?? 0) > 0;
    }

}
