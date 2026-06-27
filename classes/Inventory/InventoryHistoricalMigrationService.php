<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/../../includes/pos_default_accounts.php';
require_once __DIR__ . '/../../includes/pos_operational_store.php';

class InventoryHistoricalMigrationService
{
    private const REVIEWED_MOVEMENT_TYPES = ['purchase', 'purchase_return', 'sale_direct', 'adjustment', 'refund_reversal', 'opening_balance'];

    public function migrationPlan(mysqli $conn, array $filters = []): array
    {
        $snapshot = $this->snapshot($conn, $filters);
        $backfill = $this->fatDetailsBackfillPlan($conn, $filters);
        $rebuild = $this->rebuildBalancesPlan($conn, $filters);

        return [
            'ok' => empty($snapshot['blockers']) && empty($backfill['blockers']) && empty($rebuild['blockers']),
            'mode' => 'dry_run',
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'filters' => $this->publicFilters($filters),
            'snapshot' => $snapshot,
            'backfill' => [
                'summary' => $backfill['summary'],
                'sample_planned_movements' => array_slice($backfill['planned_movements'], 0, $this->sampleLimit($filters)),
                'sample_ambiguous_rows' => array_slice($backfill['ambiguous_rows'], 0, $this->sampleLimit($filters)),
                'blockers' => $backfill['blockers'],
            ],
            'rebuild' => $rebuild,
            'required_before_apply' => [
                'database_backup',
                'clean_or_accepted_reconciliation',
                'review_ambiguous_fat_details_rows',
                'branch_store_item_category_signoff',
            ],
        ];
    }

    public function snapshot(mysqli $conn, array $filters = []): array
    {
        $blockers = [];

        return [
            'myitems' => $this->snapshotMyitems($conn, $blockers),
            'fat_details' => $this->snapshotFatDetails($conn, $filters, $blockers),
            'inventory_movements' => $this->snapshotMovements($conn, $filters, $blockers),
            'inventory_item_balances' => $this->snapshotBalances($conn, $filters, $blockers),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    public function fatDetailsBackfillPlan(mysqli $conn, array $filters = []): array
    {
        $blockers = [];
        if (!$this->tableExists($conn, 'fat_details')) {
            return $this->emptyBackfill(['missing_table_fat_details']);
        }
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return $this->emptyBackfill(['missing_table_inventory_movements']);
        }

        foreach (['id', 'item_id', 'qty_in', 'qty_out'] as $column) {
            if (!$this->columnExists($conn, 'fat_details', $column)) {
                $blockers[] = 'missing_fat_details_' . $column;
            }
        }
        if ($blockers) {
            return $this->emptyBackfill($blockers);
        }

        $reviewedDecisions = $this->reviewedDecisionMap($filters);
        $rows = $this->legacyFatRows($conn, $filters);
        $planned = [];
        $ambiguous = [];
        $skipped = [];
        $reviewed = [];
        $reviewedSkipped = [];
        $safeCandidateCount = 0;
        $existing = 0;
        $usedReviewIds = [];
        foreach ($rows as $row) {
            $classified = $this->classifyFatDetailsRow($conn, $row);
            if (!empty($classified['already_migrated'])) {
                $existing++;
                continue;
            }
            if (($classified['status'] ?? '') === 'safe') {
                $planned[] = $classified['movement'];
                $safeCandidateCount++;
            } elseif (($classified['status'] ?? '') === 'skipped') {
                $skipped[] = $classified;
            } else {
                $review = $this->reviewAmbiguousFatDetailsRow($row, $classified, $reviewedDecisions);
                if (($review['status'] ?? '') === 'reviewed_movement') {
                    $planned[] = $review['movement'];
                    $reviewed[] = $review['movement'];
                    $usedReviewIds[] = (int) ($row['id'] ?? 0);
                    continue;
                }
                if (($review['status'] ?? '') === 'reviewed_skip') {
                    $reviewedSkipped[] = $review['row'];
                    $usedReviewIds[] = (int) ($row['id'] ?? 0);
                    continue;
                }
                if (($review['status'] ?? '') === 'review_invalid') {
                    $classified['review_errors'] = $review['errors'];
                    $usedReviewIds[] = (int) ($row['id'] ?? 0);
                }
                $ambiguous[] = $classified;
            }
        }
        $unusedReviewIds = array_values(array_diff(array_keys($reviewedDecisions), $usedReviewIds));

        return [
            'summary' => [
                'legacy_rows_scanned' => count($rows),
                'safe_candidate_count' => $safeCandidateCount,
                'planned_movement_count' => count($planned),
                'skipped_count' => count($skipped),
                'reviewed_candidate_count' => count($reviewed),
                'reviewed_skip_count' => count($reviewedSkipped),
                'ambiguous_count' => count($ambiguous),
                'unused_review_decision_count' => count($unusedReviewIds),
                'already_migrated_count' => $existing,
                'dry_run_only' => true,
            ],
            'planned_movements' => $planned,
            'skipped_rows' => $skipped,
            'reviewed_movements' => $reviewed,
            'reviewed_skipped_rows' => $reviewedSkipped,
            'unused_review_decision_ids' => $unusedReviewIds,
            'ambiguous_rows' => $ambiguous,
            'blockers' => [],
        ];
    }

    public function applyFatDetailsBackfill(mysqli $conn, array $filters = [], array $options = []): array
    {
        return $this->runFatDetailsBackfill($conn, $filters, $options, false);
    }

    public function rehearseFatDetailsBackfill(mysqli $conn, array $filters = [], array $options = []): array
    {
        $conn->begin_transaction();
        try {
            $result = $this->runFatDetailsBackfill($conn, $filters, $options + [
                'ledger_options' => ['manage_transaction' => false],
            ], true);
            $conn->rollback();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function runFatDetailsBackfill(mysqli $conn, array $filters, array $options, bool $rehearsal): array
    {
        $plan = $this->fatDetailsBackfillPlan($conn, $filters);
        $blockers = $plan['blockers'] ?? [];
        $ambiguousRows = $plan['ambiguous_rows'] ?? [];
        if ($ambiguousRows && empty($options['skip_ambiguous'])) {
            $blockers[] = 'ambiguous_legacy_rows_require_review';
        }
        if (!empty($plan['unused_review_decision_ids'])) {
            $blockers[] = 'unused_review_decisions_in_current_scope';
        }
        if ($blockers) {
            return [
                'ok' => false,
                'mode' => $rehearsal ? 'rehearse' : 'apply',
                'summary' => $plan['summary'] ?? $this->emptyBackfillSummary(false),
                'applied_movements' => [],
                'rehearsed_movements' => [],
                'idempotent_replays' => [],
                'skipped_rows' => $plan['skipped_rows'] ?? [],
                'reviewed_skipped_rows' => $plan['reviewed_skipped_rows'] ?? [],
                'unused_review_decision_ids' => $plan['unused_review_decision_ids'] ?? [],
                'ambiguous_rows' => $ambiguousRows,
                'blockers' => array_values(array_unique($blockers)),
            ];
        }

        $ledger = new InventoryLedgerService(new InventoryFeatureFlags([
            'inventory' => [
                'ledger_mode' => 'bridge',
                'legacy_mirror' => false,
            ],
        ]));
        $applied = [];
        $replayed = [];
        $ledgerOptions = is_array($options['ledger_options'] ?? null) ? $options['ledger_options'] : [];
        foreach (($plan['planned_movements'] ?? []) as $movement) {
            $request = $this->movementRequestFromPlannedMovement($movement);
            $result = $ledger->recordMovement(
                $conn,
                $request,
                $this->itemPolicyRow($conn, (int) ($movement['item_id'] ?? 0)),
                $ledgerOptions
            );
            if (!empty($result['idempotent_replay'])) {
                $replayed[] = [
                    'fat_detail_id' => (int) ($movement['fat_detail_id'] ?? 0),
                    'movement_id' => (int) ($result['movement_id'] ?? 0),
                    'idempotency_key' => (string) ($movement['idempotency_key'] ?? ''),
                ];
                continue;
            }

            $applied[] = [
                'fat_detail_id' => (int) ($movement['fat_detail_id'] ?? 0),
                'movement_id' => (int) ($result['movement_id'] ?? 0),
                'balance_id' => (int) ($result['balance_id'] ?? 0),
                'idempotency_key' => (string) ($movement['idempotency_key'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'summary' => [
                'legacy_rows_scanned' => (int) ($plan['summary']['legacy_rows_scanned'] ?? 0),
                'safe_candidate_count' => (int) ($plan['summary']['safe_candidate_count'] ?? 0),
                'planned_movement_count' => (int) ($plan['summary']['planned_movement_count'] ?? 0),
                'ambiguous_count' => (int) ($plan['summary']['ambiguous_count'] ?? 0),
                'already_migrated_count' => (int) ($plan['summary']['already_migrated_count'] ?? 0),
                'applied_count' => $rehearsal ? 0 : count($applied),
                'rehearsed_count' => $rehearsal ? count($applied) : 0,
                'idempotent_replay_count' => count($replayed),
                'reviewed_candidate_count' => (int) ($plan['summary']['reviewed_candidate_count'] ?? 0),
                'reviewed_skip_count' => (int) ($plan['summary']['reviewed_skip_count'] ?? 0),
                'skipped_count' => (int) ($plan['summary']['skipped_count'] ?? 0),
                'unused_review_decision_count' => (int) ($plan['summary']['unused_review_decision_count'] ?? 0),
                'dry_run_only' => $rehearsal,
            ],
            'applied_movements' => $rehearsal ? [] : $applied,
            'rehearsed_movements' => $rehearsal ? $applied : [],
            'idempotent_replays' => $replayed,
            'skipped_rows' => $plan['skipped_rows'] ?? [],
            'reviewed_skipped_rows' => $plan['reviewed_skipped_rows'] ?? [],
            'unused_review_decision_ids' => $plan['unused_review_decision_ids'] ?? [],
            'ambiguous_rows' => $ambiguousRows,
            'blockers' => [],
        ];
    }

    public function rebuildBalancesPlan(mysqli $conn, array $filters = []): array
    {
        $blockers = [];
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [
                'summary' => $this->emptyRebuildSummary(),
                'rows' => [],
                'blockers' => ['missing_table_inventory_movements'],
            ];
        }
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [
                'summary' => $this->emptyRebuildSummary(),
                'rows' => [],
                'blockers' => ['missing_table_inventory_item_balances'],
            ];
        }

        $derived = $this->derivedMovementBalances($conn, $filters);
        $rows = [];
        $differenceCount = 0;
        $costDifferenceCount = 0;
        $lastMovementDifferenceCount = 0;
        $missingBalanceCount = 0;
        $rebuildCandidateCount = 0;
        foreach ($derived as $row) {
            $current = $this->currentBalanceRow(
                $conn,
                (int) $row['pos_tenant'],
                (int) $row['pos_branch'],
                (int) $row['store_id'],
                (int) $row['item_id']
            );
            $derivedQty = InventoryDecimal::normalize($row['derived_qty_on_hand'] ?? '0');
            $currentQty = InventoryDecimal::normalize($current['qty_on_hand'] ?? '0');
            $difference = InventoryDecimal::subtract($derivedQty, $currentQty);
            $hasDifference = InventoryDecimal::compare($difference, '0') !== 0;
            $hasCurrentBalance = is_array($current) && $current !== [];
            $derivedStockValue = InventoryDecimal::normalize($row['derived_stock_value'] ?? '0');
            $derivedAverageCost = $this->movingAverageCostForDerivedBalance($derivedQty, $derivedStockValue);
            $currentAverageCost = InventoryDecimal::normalize($current['moving_average_cost'] ?? '0');
            $hasCostDifference = InventoryDecimal::compare($derivedAverageCost, $currentAverageCost) !== 0;
            $lastMovementId = (int) ($row['last_movement_id'] ?? 0);
            $hasLastMovementDifference = $hasCurrentBalance && (int) ($current['last_movement_id'] ?? 0) !== $lastMovementId;
            $needsRebuild = !$hasCurrentBalance || $hasDifference || $hasCostDifference || $hasLastMovementDifference;
            if ($hasDifference) {
                $differenceCount++;
            }
            if ($hasCostDifference) {
                $costDifferenceCount++;
            }
            if ($hasLastMovementDifference) {
                $lastMovementDifferenceCount++;
            }
            if (!$hasCurrentBalance) {
                $missingBalanceCount++;
            }
            if ($needsRebuild) {
                $rebuildCandidateCount++;
            }
            $rows[] = [
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'branch_uuid' => (string) ($row['branch_uuid'] ?? ''),
                'store_id' => (int) $row['store_id'],
                'item_id' => (int) $row['item_id'],
                'derived_qty_on_hand' => $derivedQty,
                'current_qty_on_hand' => $currentQty,
                'qty_difference' => $difference,
                'derived_stock_value' => $derivedStockValue,
                'derived_moving_average_cost' => $derivedAverageCost,
                'current_moving_average_cost' => $currentAverageCost,
                'movement_count' => (int) ($row['movement_count'] ?? 0),
                'last_movement_id' => $lastMovementId,
                'current_last_movement_id' => (int) ($current['last_movement_id'] ?? 0),
                'current_balance_exists' => $hasCurrentBalance,
                'has_difference' => $hasDifference,
                'has_cost_difference' => $hasCostDifference,
                'has_last_movement_difference' => $hasLastMovementDifference,
                'needs_rebuild' => $needsRebuild,
            ];
        }

        return [
            'summary' => [
                'derived_balance_rows' => count($rows),
                'difference_count' => $differenceCount,
                'cost_difference_count' => $costDifferenceCount,
                'last_movement_difference_count' => $lastMovementDifferenceCount,
                'missing_balance_count' => $missingBalanceCount,
                'rebuild_candidate_count' => $rebuildCandidateCount,
                'dry_run_only' => true,
            ],
            'rows' => array_slice($rows, 0, $this->limit($filters)),
            'blockers' => $blockers,
        ];
    }

    public function rehearseBalanceRebuild(mysqli $conn, array $filters = []): array
    {
        $conn->begin_transaction();
        try {
            $result = $this->runBalanceRebuild($conn, $filters, true);
            $conn->rollback();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function applyBalanceRebuild(mysqli $conn, array $filters = []): array
    {
        return $this->runBalanceRebuild($conn, $filters, false);
    }

    private function runBalanceRebuild(mysqli $conn, array $filters, bool $rehearsal): array
    {
        $plan = $this->rebuildBalancesPlan($conn, $filters);
        $blockers = $plan['blockers'] ?? [];
        if ($blockers) {
            return [
                'ok' => false,
                'mode' => $rehearsal ? 'rehearse' : 'apply',
                'summary' => $plan['summary'] ?? $this->emptyRebuildSummary(),
                'rebuilt_balances' => [],
                'rehearsed_balances' => [],
                'blockers' => array_values(array_unique($blockers)),
            ];
        }

        $balances = new InventoryBalanceRepository();
        $rebuilt = [];
        foreach (($plan['rows'] ?? []) as $row) {
            if (empty($row['needs_rebuild'])) {
                continue;
            }
            $balanceId = $balances->putBalance($conn, [
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'branch_uuid' => (string) ($row['branch_uuid'] ?? ''),
                'store_id' => (int) $row['store_id'],
                'item_id' => (int) $row['item_id'],
                'qty_on_hand' => (string) $row['derived_qty_on_hand'],
                'qty_reserved' => '0.000000',
                'qty_available' => (string) $row['derived_qty_on_hand'],
                'moving_average_cost' => (string) $row['derived_moving_average_cost'],
                'last_movement_id' => (int) $row['last_movement_id'],
            ]);
            $rebuilt[] = [
                'balance_id' => $balanceId,
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'store_id' => (int) $row['store_id'],
                'item_id' => (int) $row['item_id'],
                'qty_on_hand' => (string) $row['derived_qty_on_hand'],
                'moving_average_cost' => (string) $row['derived_moving_average_cost'],
                'last_movement_id' => (int) $row['last_movement_id'],
            ];
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'summary' => [
                'derived_balance_rows' => (int) ($plan['summary']['derived_balance_rows'] ?? 0),
                'difference_count' => (int) ($plan['summary']['difference_count'] ?? 0),
                'cost_difference_count' => (int) ($plan['summary']['cost_difference_count'] ?? 0),
                'last_movement_difference_count' => (int) ($plan['summary']['last_movement_difference_count'] ?? 0),
                'missing_balance_count' => (int) ($plan['summary']['missing_balance_count'] ?? 0),
                'rebuild_candidate_count' => (int) ($plan['summary']['rebuild_candidate_count'] ?? 0),
                'rebuilt_count' => $rehearsal ? 0 : count($rebuilt),
                'rehearsed_count' => $rehearsal ? count($rebuilt) : 0,
                'dry_run_only' => $rehearsal,
            ],
            'rebuilt_balances' => $rehearsal ? [] : $rebuilt,
            'rehearsed_balances' => $rehearsal ? $rebuilt : [],
            'blockers' => [],
        ];
    }

    private function snapshotMyitems(mysqli $conn, array &$blockers): array
    {
        if (!$this->tableExists($conn, 'myitems')) {
            $blockers[] = 'missing_table_myitems';
            return ['row_count' => 0, 'tracked_count' => 0, 'legacy_qty_total' => InventoryDecimal::zero()];
        }
        if (!$this->columnExists($conn, 'myitems', 'itmqty')) {
            $blockers[] = 'missing_myitems_itmqty';
            return ['row_count' => 0, 'tracked_count' => 0, 'legacy_qty_total' => InventoryDecimal::zero()];
        }

        $trackExpr = $this->columnExists($conn, 'myitems', 'track_stock') ? 'COALESCE(track_stock, 1) = 1' : '1 = 1';
        $deletedExpr = $this->columnExists($conn, 'myitems', 'isdeleted') ? 'COALESCE(isdeleted, 0) = 0' : '1 = 1';
        $row = $this->fetchOne($conn, "
SELECT
  COUNT(*) AS row_count,
  COALESCE(SUM(CASE WHEN {$trackExpr} THEN 1 ELSE 0 END), 0) AS tracked_count,
  COALESCE(SUM(CASE WHEN {$trackExpr} THEN itmqty ELSE 0 END), 0) AS legacy_qty_total
FROM myitems
WHERE {$deletedExpr}");

        return [
            'row_count' => (int) ($row['row_count'] ?? 0),
            'tracked_count' => (int) ($row['tracked_count'] ?? 0),
            'legacy_qty_total' => InventoryDecimal::normalize($row['legacy_qty_total'] ?? '0'),
        ];
    }

    private function snapshotFatDetails(mysqli $conn, array $filters, array &$blockers): array
    {
        if (!$this->tableExists($conn, 'fat_details')) {
            $blockers[] = 'missing_table_fat_details';
            return ['row_count' => 0, 'active_row_count' => 0, 'qty_balance' => InventoryDecimal::zero(), 'deleted_row_count' => 0];
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyFatScopeFilters($conn, $conditions, $params, $filters);
        $deletedCondition = $this->columnExists($conn, 'fat_details', 'isdeleted') ? 'COALESCE(isdeleted, 0) = 0' : '1 = 1';
        $row = $this->fetchOne($conn, "
SELECT
  COUNT(*) AS row_count,
  COALESCE(SUM(CASE WHEN {$deletedCondition} THEN 1 ELSE 0 END), 0) AS active_row_count,
  COALESCE(SUM(CASE WHEN {$deletedCondition} THEN qty_in - qty_out ELSE 0 END), 0) AS qty_balance,
  COALESCE(SUM(CASE WHEN NOT ({$deletedCondition}) THEN 1 ELSE 0 END), 0) AS deleted_row_count
FROM fat_details
WHERE " . implode(' AND ', $conditions), $params);

        return [
            'row_count' => (int) ($row['row_count'] ?? 0),
            'active_row_count' => (int) ($row['active_row_count'] ?? 0),
            'qty_balance' => InventoryDecimal::normalize($row['qty_balance'] ?? '0'),
            'deleted_row_count' => (int) ($row['deleted_row_count'] ?? 0),
        ];
    }

    private function snapshotMovements(mysqli $conn, array $filters, array &$blockers): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            $blockers[] = 'missing_table_inventory_movements';
            return ['row_count' => 0, 'qty_balance' => InventoryDecimal::zero(), 'stock_value' => InventoryDecimal::zero()];
        }
        $conditions = ['1 = 1'];
        $params = [];
        $this->applyMovementScopeFilters($conditions, $params, $filters, 'im');
        $row = $this->fetchOne($conn, "
SELECT
  COUNT(*) AS row_count,
  COALESCE(SUM(qty_in - qty_out), 0) AS qty_balance,
  COALESCE(SUM(CASE WHEN qty_in > 0 THEN total_cost ELSE -total_cost END), 0) AS stock_value
FROM inventory_movements im
WHERE " . implode(' AND ', $conditions), $params);

        return [
            'row_count' => (int) ($row['row_count'] ?? 0),
            'qty_balance' => InventoryDecimal::normalize($row['qty_balance'] ?? '0'),
            'stock_value' => InventoryDecimal::normalize($row['stock_value'] ?? '0'),
        ];
    }

    private function snapshotBalances(mysqli $conn, array $filters, array &$blockers): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            $blockers[] = 'missing_table_inventory_item_balances';
            return ['row_count' => 0, 'qty_on_hand_total' => InventoryDecimal::zero(), 'stock_value' => InventoryDecimal::zero()];
        }
        $conditions = ['1 = 1'];
        $params = [];
        $this->applyMovementScopeFilters($conditions, $params, $filters, 'b');
        $row = $this->fetchOne($conn, "
SELECT
  COUNT(*) AS row_count,
  COALESCE(SUM(qty_on_hand), 0) AS qty_on_hand_total,
  COALESCE(SUM(qty_on_hand * moving_average_cost), 0) AS stock_value
FROM inventory_item_balances b
WHERE " . implode(' AND ', $conditions), $params);

        return [
            'row_count' => (int) ($row['row_count'] ?? 0),
            'qty_on_hand_total' => InventoryDecimal::normalize($row['qty_on_hand_total'] ?? '0'),
            'stock_value' => InventoryDecimal::normalize($row['stock_value'] ?? '0'),
        ];
    }

    private function legacyFatRows(mysqli $conn, array $filters): array
    {
        $columns = [];
        foreach (['id', 'fatid', 'pro_id', 'pro_tybe', 'item_id', 'u_val', 'qty_in', 'qty_out', 'det_store', 'cost_price', 'isdeleted', 'tenant', 'branch', 'crtime', 'mdtime'] as $column) {
            if ($this->columnExists($conn, 'fat_details', $column)) {
                $columns[] = $column;
            }
        }
        foreach (['id', 'item_id', 'qty_in', 'qty_out'] as $required) {
            if (!in_array($required, $columns, true)) {
                return [];
            }
        }

        $conditions = ['1 = 1'];
        $params = [];
        $this->applyFatScopeFilters($conn, $conditions, $params, $filters);
        if (empty($filters['include_deleted']) && $this->columnExists($conn, 'fat_details', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'item_id = ?';
            $params[] = $itemId;
        }
        if (($minFatDetailId = $this->positiveInt($filters['min_fat_detail_id'] ?? null)) > 0 && $this->columnExists($conn, 'fat_details', 'id')) {
            $conditions[] = 'id > ?';
            $params[] = $minFatDetailId;
        }

        return $this->fetchAll($conn, 'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ' FROM fat_details WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT ' . $this->limit($filters), $params);
    }

    private function classifyFatDetailsRow(mysqli $conn, array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $itemId = (int) ($row['item_id'] ?? 0);
        $qtyIn = InventoryDecimal::normalize($row['qty_in'] ?? '0');
        $qtyOut = InventoryDecimal::normalize($row['qty_out'] ?? '0');
        $storeId = (int) ($row['det_store'] ?? 0);
        $scopedStore = posmain_resolve_store_scope_for_read($conn, ['det_store' => $storeId]);
        $storeId = (int) ($scopedStore['store_id'] ?? $storeId);
        $posTenant = (int) ($row['tenant'] ?? 0);
        $posBranch = (int) ($row['branch'] ?? 0);
        $proType = (int) ($row['pro_tybe'] ?? 0);
        $reasons = [];

        if (!empty($row['isdeleted'])) {
            $reasons[] = 'deleted_legacy_row';
        }
        if ($id < 1) {
            $reasons[] = 'missing_legacy_detail_id';
        }
        if ($itemId < 1) {
            $reasons[] = 'missing_item_id';
        }
        if ($storeId < 1) {
            $reasons[] = 'missing_store_id';
        }
        $hasIn = InventoryDecimal::isPositive($qtyIn);
        $hasOut = InventoryDecimal::isPositive($qtyOut);
        if ($hasIn === $hasOut) {
            $reasons[] = 'ambiguous_quantity_direction';
        }
        if ($proType === 14) {
            $reasons[] = 'pro_tybe_14_opening_balance_offer_collision';
        }

        $movementType = $this->movementTypeForLegacyRow($proType, $hasIn, $hasOut);
        if ($movementType === '') {
            $reasons[] = 'unsupported_or_ambiguous_pro_tybe';
        }

        $idempotencyKey = 'migration:fat_details:' . $id . ':v1';
        if ($this->movementExists($conn, $posTenant, $posBranch, $storeId, $idempotencyKey)) {
            return [
                'status' => 'existing',
                'already_migrated' => true,
                'fat_detail_id' => $id,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        $itemPolicy = $this->itemPolicyRow($conn, $itemId);
        if ($itemId > 0 && empty($itemPolicy['track_stock'])) {
            return [
                'status' => 'skipped',
                'fat_detail_id' => $id,
                'item_id' => $itemId,
                'pro_tybe' => $proType,
                'store_id' => $storeId,
                'qty_in' => $qtyIn,
                'qty_out' => $qtyOut,
                'reasons' => ['non_stock_item_not_migrated_to_inventory_ledger'],
            ];
        }

        if ($reasons) {
            return [
                'status' => 'ambiguous',
                'fat_detail_id' => $id,
                'item_id' => $itemId,
                'pro_tybe' => $proType,
                'store_id' => $storeId,
                'qty_in' => $qtyIn,
                'qty_out' => $qtyOut,
                'reasons' => $reasons,
            ];
        }

        $qty = $hasIn ? $qtyIn : $qtyOut;
        $unitCost = InventoryDecimal::normalize($row['cost_price'] ?? '0');
        $totalCost = InventoryDecimal::multiply($qty, $unitCost);
        $unitConversion = $this->legacyUnitConversion($row);

        return [
            'status' => 'safe',
            'movement' => [
                'movement_uuid' => $this->deterministicUuid('fat_details:' . $id),
                'pos_tenant' => $posTenant,
                'pos_branch' => $posBranch,
                'store_id' => $storeId,
                'item_id' => $itemId,
                'movement_type' => $movementType,
                'source_type' => 'fat_details',
                'source_id' => $id,
                'source_uuid' => 'legacy-fat-details:' . $id,
                'order_id' => (int) ($row['fatid'] ?? $row['pro_id'] ?? 0),
                'fat_detail_id' => $id,
                'qty_in' => $hasIn ? $qtyIn : InventoryDecimal::zero(),
                'qty_out' => $hasOut ? $qtyOut : InventoryDecimal::zero(),
                'unit_conversion_to_base' => $unitConversion,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'source' => 'phase14_fat_details_backfill',
                    'legacy_pro_tybe' => $proType,
                    'legacy_u_val' => InventoryDecimal::normalize($row['u_val'] ?? '1'),
                    'legacy_created_at' => (string) ($row['crtime'] ?? ''),
                ],
            ],
        ];
    }

    private function reviewedDecisionMap(array $filters): array
    {
        $decisions = $filters['reviewed_decisions'] ?? [];
        if (!is_array($decisions)) {
            return [];
        }
        if ($this->isListArray($decisions)) {
            $map = [];
            foreach ($decisions as $decision) {
                if (!is_array($decision)) {
                    continue;
                }
                $id = (int) ($decision['fat_detail_id'] ?? 0);
                if ($id > 0) {
                    $map[$id] = $decision;
                }
            }

            return $map;
        }

        $map = [];
        foreach ($decisions as $id => $decision) {
            if (!is_array($decision)) {
                continue;
            }
            $fatDetailId = (int) ($decision['fat_detail_id'] ?? $id);
            if ($fatDetailId > 0) {
                $decision['fat_detail_id'] = $fatDetailId;
                $map[$fatDetailId] = $decision;
            }
        }

        return $map;
    }

    private function reviewAmbiguousFatDetailsRow(array $row, array $classified, array $decisions): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || !isset($decisions[$id])) {
            return ['status' => 'unreviewed'];
        }

        $decision = $decisions[$id];
        $action = trim((string) ($decision['action'] ?? ''));
        if ($action === 'skip') {
            return [
                'status' => 'reviewed_skip',
                'row' => [
                    'fat_detail_id' => $id,
                    'item_id' => (int) ($row['item_id'] ?? 0),
                    'store_id' => (int) ($row['det_store'] ?? 0),
                    'pro_tybe' => (int) ($row['pro_tybe'] ?? 0),
                    'reason' => trim((string) ($decision['reason'] ?? 'reviewed_not_inventory_stock')),
                    'original_reasons' => $classified['reasons'] ?? [],
                ],
            ];
        }
        if ($action !== 'movement') {
            return [
                'status' => 'review_invalid',
                'errors' => ['review_decision_action_must_be_movement_or_skip'],
            ];
        }

        $errors = [];
        $movementType = trim((string) ($decision['movement_type'] ?? ''));
        if (!in_array($movementType, self::REVIEWED_MOVEMENT_TYPES, true)) {
            $errors[] = 'reviewed_movement_type_not_allowed';
        }

        $itemId = $this->positiveInt($decision['item_id'] ?? null) ?: (int) ($row['item_id'] ?? 0);
        $storeId = $this->positiveInt($decision['store_id'] ?? null) ?: (int) ($row['det_store'] ?? 0);
        if ($itemId < 1) {
            $errors[] = 'reviewed_movement_requires_item_id';
        }
        if ($storeId < 1) {
            $errors[] = 'reviewed_movement_requires_store_id';
        }

        $qtyIn = array_key_exists('qty_in', $decision)
            ? InventoryDecimal::normalize($decision['qty_in'])
            : InventoryDecimal::normalize($row['qty_in'] ?? '0');
        $qtyOut = array_key_exists('qty_out', $decision)
            ? InventoryDecimal::normalize($decision['qty_out'])
            : InventoryDecimal::normalize($row['qty_out'] ?? '0');
        $hasIn = InventoryDecimal::isPositive($qtyIn);
        $hasOut = InventoryDecimal::isPositive($qtyOut);
        if ($hasIn === $hasOut) {
            $errors[] = 'reviewed_movement_requires_exactly_one_quantity_direction';
        }
        if (in_array($movementType, ['purchase', 'refund_reversal', 'opening_balance'], true) && !$hasIn) {
            $errors[] = 'reviewed_movement_type_requires_qty_in';
        }
        if (in_array($movementType, ['purchase_return', 'sale_direct'], true) && !$hasOut) {
            $errors[] = 'reviewed_movement_type_requires_qty_out';
        }

        if ($errors) {
            return [
                'status' => 'review_invalid',
                'errors' => array_values(array_unique($errors)),
            ];
        }

        $qty = $hasIn ? $qtyIn : $qtyOut;
        $unitCost = array_key_exists('unit_cost', $decision)
            ? InventoryDecimal::normalize($decision['unit_cost'])
            : InventoryDecimal::normalize($row['cost_price'] ?? '0');
        $unitConversion = array_key_exists('unit_conversion_to_base', $decision)
            ? $this->positiveUnitConversion($decision['unit_conversion_to_base'])
            : $this->legacyUnitConversion($row);

        return [
            'status' => 'reviewed_movement',
            'movement' => [
                'movement_uuid' => $this->deterministicUuid('fat_details_reviewed:' . $id),
                'pos_tenant' => (int) ($decision['pos_tenant'] ?? $row['tenant'] ?? 0),
                'pos_branch' => (int) ($decision['pos_branch'] ?? $row['branch'] ?? 0),
                'store_id' => $storeId,
                'item_id' => $itemId,
                'movement_type' => $movementType,
                'source_type' => 'fat_details',
                'source_id' => $id,
                'source_uuid' => 'legacy-fat-details-reviewed:' . $id,
                'order_id' => (int) ($row['fatid'] ?? $row['pro_id'] ?? 0),
                'fat_detail_id' => $id,
                'qty_in' => $hasIn ? $qtyIn : InventoryDecimal::zero(),
                'qty_out' => $hasOut ? $qtyOut : InventoryDecimal::zero(),
                'unit_conversion_to_base' => $unitConversion,
                'unit_cost' => $unitCost,
                'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
                'idempotency_key' => 'migration:fat_details:' . $id . ':reviewed:v1',
                'metadata' => [
                    'source' => 'phase14_reviewed_fat_details_backfill',
                    'review_reason' => trim((string) ($decision['reason'] ?? 'reviewed_ambiguous_legacy_row')),
                    'original_reasons' => $classified['reasons'] ?? [],
                    'legacy_pro_tybe' => (int) ($row['pro_tybe'] ?? 0),
                    'legacy_u_val' => InventoryDecimal::normalize($row['u_val'] ?? '1'),
                    'legacy_created_at' => (string) ($row['crtime'] ?? ''),
                ],
            ],
        ];
    }

    private function legacyUnitConversion(array $row): string
    {
        return $this->positiveUnitConversion($row['u_val'] ?? '1');
    }

    private function positiveUnitConversion($value): string
    {
        $conversion = InventoryDecimal::normalize($value ?? '1', 8);
        if (InventoryDecimal::compare($conversion, '0', 8) <= 0) {
            return '1.00000000';
        }

        return $conversion;
    }

    private function movementTypeForLegacyRow(int $proType, bool $hasIn, bool $hasOut): string
    {
        if ($hasIn && $proType === 4) {
            return 'purchase';
        }
        if ($hasOut && in_array($proType, [3, 9], true)) {
            return 'sale_direct';
        }
        if ($hasOut && $proType === 10) {
            return 'purchase_return';
        }
        if ($hasIn && $proType === 11) {
            return 'refund_reversal';
        }

        return '';
    }

    private function derivedMovementBalances(mysqli $conn, array $filters): array
    {
        $conditions = ['1 = 1'];
        $params = [];
        $this->applyMovementScopeFilters($conditions, $params, $filters, 'im');
        if (($itemId = $this->positiveInt($filters['item_id'] ?? null)) > 0) {
            $conditions[] = 'im.item_id = ?';
            $params[] = $itemId;
        }

        return $this->fetchAll($conn, "
SELECT
  im.pos_tenant,
  im.pos_branch,
  MAX(COALESCE(im.branch_uuid, '')) AS branch_uuid,
  im.store_id,
  im.item_id,
  COALESCE(SUM(im.qty_in - im.qty_out), 0) AS derived_qty_on_hand,
  COALESCE(SUM(CASE WHEN im.qty_in > 0 THEN im.total_cost ELSE -im.total_cost END), 0) AS derived_stock_value,
  COUNT(*) AS movement_count,
  MAX(im.id) AS last_movement_id
FROM inventory_movements im
WHERE " . implode(' AND ', $conditions) . "
GROUP BY im.pos_tenant, im.pos_branch, im.store_id, im.item_id
ORDER BY im.pos_tenant, im.pos_branch, im.store_id, im.item_id
LIMIT " . $this->limit($filters), $params);
    }

    private function currentBalanceRow(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [];
        }

        return $this->fetchOne($conn, "
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1", [$posTenant, $posBranch, $storeId, $itemId]) ?: [];
    }

    private function movingAverageCostForDerivedBalance(string $qtyOnHand, string $stockValue): string
    {
        if (InventoryDecimal::compare($qtyOnHand, '0') === 0) {
            return InventoryDecimal::zero();
        }

        $averageCost = InventoryDecimal::divide($stockValue, $qtyOnHand);

        return $this->absoluteDecimal($averageCost);
    }

    private function absoluteDecimal(string $value): string
    {
        $normalized = InventoryDecimal::normalize($value);
        if (strpos($normalized, '-') === 0) {
            return InventoryDecimal::normalize(substr($normalized, 1));
        }

        return $normalized;
    }

    private function movementExists(mysqli $conn, int $posTenant, int $posBranch, int $storeId, string $idempotencyKey): bool
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return false;
        }
        if (strpos($idempotencyKey, 'migration:fat_details:') === 0) {
            $row = $this->fetchOne($conn, '
SELECT id
FROM inventory_movements
WHERE idempotency_key = ?
LIMIT 1', [$idempotencyKey]);

            return is_array($row);
        }
        $row = $this->fetchOne($conn, "
SELECT id
FROM inventory_movements
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key = ?
LIMIT 1", [$posTenant, $posBranch, $storeId, $idempotencyKey]);

        return is_array($row);
    }

    private function applyFatScopeFilters(mysqli $conn, array &$conditions, array &$params, array $filters): void
    {
        if (isset($filters['pos_tenant']) && (int) $filters['pos_tenant'] >= 0 && $this->columnExists($conn, 'fat_details', 'tenant')) {
            $conditions[] = 'tenant = ?';
            $params[] = (int) $filters['pos_tenant'];
        }
        if (isset($filters['pos_branch']) && (int) $filters['pos_branch'] >= 0 && $this->columnExists($conn, 'fat_details', 'branch')) {
            $conditions[] = 'branch = ?';
            $params[] = (int) $filters['pos_branch'];
        }
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0 && $this->columnExists($conn, 'fat_details', 'det_store')) {
            $conditions[] = 'det_store = ?';
            $params[] = $storeId;
        }
    }

    private function applyMovementScopeFilters(array &$conditions, array &$params, array $filters, string $alias): void
    {
        foreach (['pos_tenant', 'pos_branch'] as $column) {
            if (isset($filters[$column]) && (int) $filters[$column] >= 0) {
                $conditions[] = $alias . '.' . $column . ' = ?';
                $params[] = (int) $filters[$column];
            }
        }
        if (($storeId = $this->positiveInt($filters['store_id'] ?? null)) > 0) {
            $conditions[] = $alias . '.store_id = ?';
            $params[] = $storeId;
        }
    }

    private function emptyBackfill(array $blockers): array
    {
        return [
            'summary' => $this->emptyBackfillSummary(true),
            'planned_movements' => [],
            'skipped_rows' => [],
            'reviewed_movements' => [],
            'reviewed_skipped_rows' => [],
            'unused_review_decision_ids' => [],
            'ambiguous_rows' => [],
            'blockers' => $blockers,
        ];
    }

    private function emptyBackfillSummary(bool $dryRunOnly): array
    {
        return [
            'legacy_rows_scanned' => 0,
            'safe_candidate_count' => 0,
            'planned_movement_count' => 0,
            'skipped_count' => 0,
            'reviewed_candidate_count' => 0,
            'reviewed_skip_count' => 0,
            'ambiguous_count' => 0,
            'unused_review_decision_count' => 0,
            'already_migrated_count' => 0,
            'dry_run_only' => $dryRunOnly,
        ];
    }

    private function movementRequestFromPlannedMovement(array $movement): array
    {
        return [
            'movement_uuid' => (string) ($movement['movement_uuid'] ?? ''),
            'scope' => [
                'pos_tenant' => (int) ($movement['pos_tenant'] ?? 0),
                'pos_branch' => (int) ($movement['pos_branch'] ?? 0),
                'store_id' => (int) ($movement['store_id'] ?? 0),
            ],
            'item_id' => (int) ($movement['item_id'] ?? 0),
            'movement_type' => (string) ($movement['movement_type'] ?? ''),
            'source_type' => (string) ($movement['source_type'] ?? 'fat_details'),
            'source_id' => (int) ($movement['source_id'] ?? 0),
            'source_uuid' => (string) ($movement['source_uuid'] ?? ''),
            'order_id' => (int) ($movement['order_id'] ?? 0),
            'fat_detail_id' => (int) ($movement['fat_detail_id'] ?? 0),
            'qty_in' => (string) ($movement['qty_in'] ?? '0'),
            'qty_out' => (string) ($movement['qty_out'] ?? '0'),
            'unit_conversion_to_base' => (string) ($movement['unit_conversion_to_base'] ?? '1'),
            'unit_cost' => (string) ($movement['unit_cost'] ?? '0'),
            'total_cost' => (string) ($movement['total_cost'] ?? '0'),
            'idempotency_key' => (string) ($movement['idempotency_key'] ?? ''),
            'metadata' => is_array($movement['metadata'] ?? null) ? $movement['metadata'] : [],
        ];
    }

    private function itemPolicyRow(mysqli $conn, int $itemId): array
    {
        $row = ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 1];
        if ($itemId < 1 || !$this->tableExists($conn, 'myitems')) {
            return $row;
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }
        $item = $this->fetchOne(
            $conn,
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ' FROM myitems WHERE id = ? LIMIT 1',
            [$itemId]
        );
        if (!$item) {
            return $row;
        }

        return [
            'item_id' => $itemId,
            'item_type' => (string) ($item['item_type'] ?? 'sellable'),
            'track_stock' => array_key_exists('track_stock', $item) ? (int) $item['track_stock'] : 1,
        ];
    }

    private function emptyRebuildSummary(): array
    {
        return [
            'derived_balance_rows' => 0,
            'difference_count' => 0,
            'dry_run_only' => true,
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'pos_tenant' => isset($filters['pos_tenant']) ? (int) $filters['pos_tenant'] : 0,
            'pos_branch' => isset($filters['pos_branch']) ? (int) $filters['pos_branch'] : 0,
            'store_id' => isset($filters['store_id']) ? (int) $filters['store_id'] : 0,
            'item_id' => isset($filters['item_id']) ? (int) $filters['item_id'] : 0,
            'limit' => $this->limit($filters),
        ];
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = hash('sha256', 'posmain-inventory-migration:' . $seed);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne($conn, "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?", [$table]);

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $row = $this->fetchOne($conn, "
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?", [$table, $column]);

        return (int) ($row['column_count'] ?? 0) > 0;
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        if (!$params) {
            return;
        }

        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }
        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    private function limit(array $filters): int
    {
        return max(1, min(5000, (int) ($filters['limit'] ?? 1000)));
    }

    private function sampleLimit(array $filters): int
    {
        return max(1, min(100, (int) ($filters['sample_limit'] ?? 25)));
    }

    private function positiveInt($value): int
    {
        return (int) $value > 0 ? (int) $value : 0;
    }

    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
