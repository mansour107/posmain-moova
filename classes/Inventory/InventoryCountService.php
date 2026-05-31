<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryAccountingService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryCountService
{
    private InventoryFeatureFlags $flags;
    private InventoryLedgerService $ledger;
    private InventoryAccountingService $accounting;
    private InventoryScopeResolver $scopeResolver;
    private InventoryItemPolicyService $itemPolicy;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryLedgerService $ledger = null,
        ?InventoryAccountingService $accounting = null,
        ?InventoryScopeResolver $scopeResolver = null,
        ?InventoryItemPolicyService $itemPolicy = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->ledger = $ledger ?: new InventoryLedgerService($this->flags);
        $this->accounting = $accounting ?: new InventoryAccountingService($this->flags);
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
    }

    public function createDraft(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $countUuid = $this->uuidFromRequest($request, 'count_uuid');
            $scope = $this->resolveScope($request, $context);
            $requestLines = $this->normalizeLines($request['lines'] ?? []);
            $existing = $this->findCountByUuid($conn, $countUuid);
            if ($existing) {
                $this->assertExistingCountReplay($conn, $existing, $request, $scope, $requestLines);
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'count_id' => (int) $existing['id'],
                    'count_uuid' => $countUuid,
                    'status' => (string) $existing['status'],
                ];
            }

            $countId = $this->insertCount($conn, $countUuid, $request, $scope, $context);
            $lines = $this->countLinesForDraft($conn, $scope, $request);
            foreach ($lines as $line) {
                $this->insertSnapshotLine($conn, $countId, $scope, $line, $context);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'count_id' => $countId,
                'count_uuid' => $countUuid,
                'status' => 'draft',
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function saveLines(mysqli $conn, int $countId, array $lines, array $context = []): array
    {
        $this->assertTables($conn);
        if ($countId < 1) {
            throw new InvalidArgumentException('COUNT_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $count = $this->lockCount($conn, $countId);
            if (!$count) {
                throw new InvalidArgumentException('COUNT_NOT_FOUND');
            }
            if ((string) $count['status'] !== 'draft') {
                throw new RuntimeException('COUNT_NOT_EDITABLE');
            }

            $scope = [
                'pos_tenant' => (int) $count['pos_tenant'],
                'pos_branch' => (int) $count['pos_branch'],
                'branch_uuid' => $count['branch_uuid'],
                'store_id' => (int) $count['store_id'],
            ];
            $saved = 0;
            foreach ($this->normalizeLines($lines) as $line) {
                $lineId = $this->ensureCountLine($conn, $countId, $scope, $line, $context);
                $this->updateCountedLine($conn, $lineId, $line, $context);
                $saved++;
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'count_id' => $countId,
                'saved_lines' => $saved,
                'status' => 'draft',
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function submit(mysqli $conn, int $countId, array $context = []): array
    {
        return $this->transition($conn, $countId, ['draft'], 'submitted', 'submitted_by', 'submitted_at', $context);
    }

    public function approve(mysqli $conn, int $countId, array $context = []): array
    {
        return $this->transition($conn, $countId, ['submitted'], 'approved', 'approved_by', 'approved_at', $context);
    }

    public function close(mysqli $conn, int $countId, array $context = []): array
    {
        $this->assertCanClose();
        $this->assertTables($conn);
        if ($countId < 1) {
            throw new InvalidArgumentException('COUNT_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $count = $this->lockCount($conn, $countId);
            if (!$count) {
                throw new InvalidArgumentException('COUNT_NOT_FOUND');
            }
            if ((string) $count['status'] === 'closed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'count_id' => $countId,
                    'status' => 'closed',
                    'movement_ids' => [],
                ];
            }
            if ((string) $count['status'] !== 'approved') {
                throw new RuntimeException('COUNT_NOT_APPROVED');
            }

            $lines = $this->lockCountLines($conn, $countId);
            if (!$lines) {
                throw new RuntimeException('COUNT_LINES_REQUIRED');
            }

            $scope = [
                'pos_tenant' => (int) $count['pos_tenant'],
                'pos_branch' => (int) $count['pos_branch'],
                'branch_uuid' => $count['branch_uuid'],
                'store_id' => (int) $count['store_id'],
            ];
            $allowStaleClose = !empty($context['allow_stale_close']);
            $staleLines = [];
            $movementIds = [];
            foreach ($lines as $line) {
                if ($line['counted_qty'] === null) {
                    throw new RuntimeException('COUNT_LINE_MISSING_COUNTED_QTY');
                }
                $currentBalance = $this->loadBalance($conn, $scope, (int) $line['item_id']);
                $unitConversion = $this->lineUnitConversion($line);
                $varianceBaselineQty = $line['snapshot_qty'];
                if ($this->lineIsStale($line, $currentBalance)) {
                    $staleLines[] = (int) $line['id'];
                    $this->markStale($conn, (int) $line['id']);
                    if (!$allowStaleClose) {
                        continue;
                    }
                    $varianceBaselineQty = InventoryDecimal::divide(InventoryDecimal::normalize($currentBalance['qty_on_hand'] ?? '0'), $unitConversion);
                }
                $variance = InventoryDecimal::subtract($line['counted_qty'], $varianceBaselineQty);
                if (InventoryDecimal::compare($variance, '0') === 0) {
                    $this->refreshVariance($conn, (int) $line['id'], $variance, InventoryDecimal::zero());
                    continue;
                }

                $movement = $this->recordCloseMovement($conn, $count, $line, $scope, $variance, $currentBalance, $context);
                if (!empty($movement['movement_id'])) {
                    $movementIds[] = (int) $movement['movement_id'];
                }
            }
            if ($staleLines && !$allowStaleClose) {
                throw new RuntimeException('COUNT_STALE_SNAPSHOT');
            }

            $userId = $this->userId($context);
            $stmt = $conn->prepare("UPDATE inventory_counts SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $userId, $countId);
            $stmt->execute();
            $stmt->close();
            $accounting = $this->accounting->postAdjustment($conn, [
                'pos_tenant' => (int) $count['pos_tenant'],
                'pos_branch' => (int) $count['pos_branch'],
                'store_id' => (int) $count['store_id'],
                'count_id' => $countId,
                'user_id' => $userId,
                'details' => 'Inventory count variance #' . $countId,
            ], $movementIds);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'count_id' => $countId,
                'status' => 'closed',
                'movement_ids' => $movementIds,
                'stale_lines' => $staleLines,
                'accounting' => $accounting,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function reverseClosed(mysqli $conn, int $countId, array $context = []): array
    {
        $this->assertCanClose();
        $this->assertTables($conn);
        if ($countId < 1) {
            throw new InvalidArgumentException('COUNT_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $count = $this->lockCount($conn, $countId);
            if (!$count) {
                throw new InvalidArgumentException('COUNT_NOT_FOUND');
            }
            if ((string) $count['status'] === 'cancelled') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'count_id' => $countId,
                    'status' => 'cancelled',
                    'movement_ids' => [],
                ];
            }
            if ((string) $count['status'] !== 'closed') {
                throw new RuntimeException('COUNT_NOT_CLOSED');
            }

            $scope = [
                'pos_tenant' => (int) $count['pos_tenant'],
                'pos_branch' => (int) $count['pos_branch'],
                'branch_uuid' => $count['branch_uuid'],
                'store_id' => (int) $count['store_id'],
            ];
            $movementIds = [];
            foreach ($this->lockCountLines($conn, $countId) as $line) {
                foreach ($this->loadCloseMovementsForLine($conn, (int) $line['id']) as $movement) {
                    $reversal = $this->recordReversalMovement($conn, $count, $line, $movement, $scope, $context);
                    if (!empty($reversal['movement_id'])) {
                        $movementIds[] = (int) $reversal['movement_id'];
                    }
                }
            }

            $userId = $this->userId($context);
            $reason = trim((string) ($context['reason'] ?? ''));
            $noteSuffix = 'تصحيح بعد الإغلاق بواسطة مستخدم #' . (string) ($userId ?? 0);
            if ($reason !== '') {
                $noteSuffix .= ': ' . $reason;
            }
            $stmt = $conn->prepare("
UPDATE inventory_counts
SET status = 'cancelled',
    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))
WHERE id = ?");
            $stmt->bind_param('si', $noteSuffix, $countId);
            $stmt->execute();
            $stmt->close();

            $accounting = $this->accounting->postAdjustment($conn, [
                'pos_tenant' => (int) $count['pos_tenant'],
                'pos_branch' => (int) $count['pos_branch'],
                'store_id' => (int) $count['store_id'],
                'count_id' => $countId,
                'user_id' => $userId,
                'details' => 'Inventory count reversal #' . $countId,
            ], $movementIds);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'count_id' => $countId,
                'status' => 'cancelled',
                'movement_ids' => $movementIds,
                'accounting' => $accounting,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function transition(
        mysqli $conn,
        int $countId,
        array $allowedStatuses,
        string $nextStatus,
        string $userColumn,
        string $dateColumn,
        array $context
    ): array {
        $this->assertTables($conn);
        if ($countId < 1) {
            throw new InvalidArgumentException('COUNT_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $count = $this->lockCount($conn, $countId);
            if (!$count) {
                throw new InvalidArgumentException('COUNT_NOT_FOUND');
            }
            $status = (string) $count['status'];
            if ($status === $nextStatus) {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return ['success' => true, 'idempotent_replay' => true, 'count_id' => $countId, 'status' => $nextStatus];
            }
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('COUNT_INVALID_TRANSITION');
            }
            if ($nextStatus === 'submitted' && !$this->countHasCountedLine($conn, $countId)) {
                throw new RuntimeException('COUNT_LINES_REQUIRED');
            }

            $userId = $this->userId($context);
            $sql = "UPDATE inventory_counts SET status = ?, {$userColumn} = ?, {$dateColumn} = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $nextStatus, $userId, $countId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return ['success' => true, 'idempotent_replay' => false, 'count_id' => $countId, 'status' => $nextStatus];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function insertCount(mysqli $conn, string $countUuid, array $request, array $scope, array $context): int
    {
        $stmt = $conn->prepare("
INSERT INTO inventory_counts
  (count_uuid, pos_tenant, pos_branch, branch_uuid, store_id, count_type, hide_expected_qty, include_zero_stock, assigned_user_id, created_by, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $posTenant = (int) $scope['pos_tenant'];
        $posBranch = (int) $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];
        $storeId = (int) $scope['store_id'];
        $countType = $this->countType($request['count_type'] ?? 'selected');
        $hideExpected = !empty($request['hide_expected_qty']) ? 1 : 0;
        $includeZero = !empty($request['include_zero_stock']) ? 1 : 0;
        $assignedUserId = $this->nullablePositiveInt($request['assigned_user_id'] ?? null);
        $userId = $this->userId($context);
        $notes = trim((string) ($request['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param('siisisiiiis', $countUuid, $posTenant, $posBranch, $branchUuid, $storeId, $countType, $hideExpected, $includeZero, $assignedUserId, $userId, $notes);
        $stmt->execute();
        $countId = (int) $conn->insert_id;
        $stmt->close();

        return $countId;
    }

    private function insertSnapshotLine(mysqli $conn, int $countId, array $scope, array $line, array $context): int
    {
        $item = $this->loadItem($conn, (int) $line['item_id']);
        $policy = $this->itemPolicy->policyForItem($item, $scope);
        if (empty($policy['track_stock'])) {
            throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_BE_COUNTED');
        }

        $balance = $this->loadBalance($conn, $scope, (int) $line['item_id']);
        $unitId = $this->nullablePositiveInt($line['unit_id'] ?? $policy['base_unit_id'] ?? null);
        $unitConversion = $this->unitConversionForItem($conn, (int) $line['item_id'], $unitId);
        $snapshotBaseQty = InventoryDecimal::normalize($balance['qty_on_hand'] ?? '0');
        $snapshotQty = InventoryDecimal::divide($snapshotBaseQty, $unitConversion);
        $lastMovementId = $this->nullablePositiveInt($balance['last_movement_id'] ?? null);
        $countedQty = array_key_exists('counted_qty', $line) ? $line['counted_qty'] : null;
        $varianceQty = $countedQty !== null ? InventoryDecimal::subtract($countedQty, $snapshotQty) : InventoryDecimal::zero();
        $varianceCost = $this->varianceCost(InventoryDecimal::multiply($varianceQty, $unitConversion), $balance['moving_average_cost'] ?? '0');
        $notes = trim((string) ($line['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $userId = $countedQty !== null ? $this->userId($context) : null;

        $stmt = $conn->prepare("
INSERT INTO inventory_count_lines
  (count_id, item_id, unit_id, unit_conversion_to_base, snapshot_qty, counted_qty, variance_qty, variance_percent, variance_cost, snapshot_last_movement_id, counted_by, counted_at, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($countedQty !== null ? 'NOW()' : 'NULL') . ", ?)
ON DUPLICATE KEY UPDATE
  counted_qty = VALUES(counted_qty),
  variance_qty = VALUES(variance_qty),
  variance_percent = VALUES(variance_percent),
  variance_cost = VALUES(variance_cost),
  counted_by = VALUES(counted_by),
  counted_at = VALUES(counted_at),
  notes = VALUES(notes)");
        $variancePercent = $this->variancePercent($varianceQty, $snapshotQty);
        $stmt->bind_param('iiissssssiis', $countId, $line['item_id'], $unitId, $unitConversion, $snapshotQty, $countedQty, $varianceQty, $variancePercent, $varianceCost, $lastMovementId, $userId, $notes);
        $stmt->execute();
        $lineId = (int) ($conn->insert_id ?: $this->findCountLineId($conn, $countId, (int) $line['item_id']));
        $stmt->close();

        return $lineId;
    }

    private function ensureCountLine(mysqli $conn, int $countId, array $scope, array $line, array $context): int
    {
        $lineId = $this->findCountLineId($conn, $countId, (int) $line['item_id']);
        if ($lineId > 0) {
            return $lineId;
        }

        return $this->insertSnapshotLine($conn, $countId, $scope, $line, $context);
    }

    private function updateCountedLine(mysqli $conn, int $lineId, array $line, array $context): void
    {
        if (!array_key_exists('counted_qty', $line)) {
            return;
        }

        $existing = $this->loadCountLine($conn, $lineId);
        $unitConversion = $this->lineUnitConversion($existing);
        $varianceQty = InventoryDecimal::subtract($line['counted_qty'], $existing['snapshot_qty']);
        $balance = $this->loadBalance($conn, [
            'pos_tenant' => (int) ($existing['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($existing['pos_branch'] ?? 0),
            'branch_uuid' => $existing['branch_uuid'] ?? null,
            'store_id' => (int) ($existing['store_id'] ?? 0),
        ], (int) $existing['item_id']);
        $varianceCost = $this->varianceCost(InventoryDecimal::multiply($varianceQty, $unitConversion), $balance['moving_average_cost'] ?? '0');
        $variancePercent = $this->variancePercent($varianceQty, $existing['snapshot_qty']);
        $userId = $this->userId($context);
        $notes = trim((string) ($line['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : ($existing['notes'] ?? null);

        $stmt = $conn->prepare("
UPDATE inventory_count_lines
SET counted_qty = ?, variance_qty = ?, variance_percent = ?, variance_cost = ?, counted_by = ?, counted_at = NOW(), notes = ?
WHERE id = ?");
        $stmt->bind_param('ssssisi', $line['counted_qty'], $varianceQty, $variancePercent, $varianceCost, $userId, $notes, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function recordCloseMovement(mysqli $conn, array $count, array $line, array $scope, string $variance, array $balance, array $context): array
    {
        $unitConversion = $this->lineUnitConversion($line);
        $baseVariance = InventoryDecimal::multiply($variance, $unitConversion);
        $qtyIn = InventoryDecimal::compare($baseVariance, '0') > 0 ? $baseVariance : InventoryDecimal::zero();
        $qtyOut = InventoryDecimal::compare($baseVariance, '0') < 0 ? ltrim($baseVariance, '-') : InventoryDecimal::zero();
        $unitCost = InventoryDecimal::normalize($balance['moving_average_cost'] ?? '0');
        $qtyAbs = InventoryDecimal::isPositive($qtyIn) ? $qtyIn : $qtyOut;
        $totalCost = InventoryDecimal::multiply($qtyAbs, $unitCost);
        $lineId = (int) $line['id'];
        $countUuid = (string) $count['count_uuid'];

        $this->refreshVariance($conn, $lineId, $variance, $totalCost);

        return $this->ledger->recordMovement($conn, [
            'scope' => $scope,
            'item_id' => (int) $line['item_id'],
            'movement_type' => 'adjustment',
            'source_type' => 'inventory_count',
            'source_id' => $lineId,
            'source_uuid' => 'inventory-count:' . $countUuid . ':line:' . $lineId,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'unit_id' => $line['unit_id'],
            'unit_conversion_to_base' => $unitConversion,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $this->movementKey($scope, $countUuid, $lineId),
            'metadata' => [
                'source' => 'inventory_count_close',
                'count_id' => (int) $count['id'],
                'snapshot_qty' => InventoryDecimal::normalize($line['snapshot_qty']),
                'counted_qty' => InventoryDecimal::normalize($line['counted_qty']),
                'variance_qty' => $variance,
                'base_variance_qty' => $baseVariance,
                'unit_conversion_to_base' => $unitConversion,
            ],
            'created_by' => $this->userId($context),
        ], $this->loadItem($conn, (int) $line['item_id']), ['manage_transaction' => false]);
    }

    private function loadCloseMovementsForLine(mysqli $conn, int $lineId): array
    {
        $stmt = $conn->prepare("
SELECT *
FROM inventory_movements
WHERE source_type = 'inventory_count'
  AND source_id = ?
  AND movement_type = 'adjustment'
  AND idempotency_key LIKE 'inventory-count-close:%'
ORDER BY id ASC
FOR UPDATE");
        $stmt->bind_param('i', $lineId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function recordReversalMovement(mysqli $conn, array $count, array $line, array $movement, array $scope, array $context): array
    {
        $originalQtyIn = InventoryDecimal::normalize($movement['qty_in'] ?? '0');
        $originalQtyOut = InventoryDecimal::normalize($movement['qty_out'] ?? '0');
        if (InventoryDecimal::compare($originalQtyIn, '0') === 0 && InventoryDecimal::compare($originalQtyOut, '0') === 0) {
            return ['success' => true, 'noop' => true];
        }

        $qtyIn = InventoryDecimal::isPositive($originalQtyOut) ? $originalQtyOut : InventoryDecimal::zero();
        $qtyOut = InventoryDecimal::isPositive($originalQtyIn) ? $originalQtyIn : InventoryDecimal::zero();
        $countUuid = (string) $count['count_uuid'];
        $lineId = (int) $line['id'];
        $unitConversion = InventoryDecimal::normalize($movement['unit_conversion_to_base'] ?? $this->lineUnitConversion($line), 8);
        $unitCost = InventoryDecimal::normalize($movement['unit_cost'] ?? '0');
        $qtyAbs = InventoryDecimal::isPositive($qtyIn) ? $qtyIn : $qtyOut;
        $totalCost = InventoryDecimal::normalize($movement['total_cost'] ?? InventoryDecimal::multiply($qtyAbs, $unitCost));
        $reason = trim((string) ($context['reason'] ?? ''));

        return $this->ledger->recordMovement($conn, [
            'scope' => $scope,
            'item_id' => (int) $line['item_id'],
            'movement_type' => 'adjustment',
            'source_type' => 'inventory_count',
            'source_id' => $lineId,
            'source_uuid' => 'inventory-count-reversal:' . $countUuid . ':line:' . $lineId,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'unit_id' => $movement['unit_id'] ?? $line['unit_id'],
            'unit_conversion_to_base' => $unitConversion,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $this->reversalKey($scope, $countUuid, $lineId, (int) $movement['id']),
            'metadata' => [
                'source' => 'inventory_count_reversal',
                'count_id' => (int) $count['id'],
                'count_line_id' => $lineId,
                'reverses_movement_id' => (int) $movement['id'],
                'original_qty_in' => $originalQtyIn,
                'original_qty_out' => $originalQtyOut,
                'reason' => $reason,
            ],
            'created_by' => $this->userId($context),
        ], $this->loadItem($conn, (int) $line['item_id']), ['manage_transaction' => false]);
    }

    private function resolveScope(array $request, array $context): array
    {
        $scope = $this->scopeResolver->resolve([
            'store_id' => $request['store_id'] ?? $request['destination_store_id'] ?? 0,
            'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
            'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
            'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
            'source' => 'inventory_count',
        ]);
        if ((int) $scope['store_id'] < 1) {
            throw new InvalidArgumentException('STORE_REQUIRED');
        }

        return $scope;
    }

    private function normalizeLines($lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemId = (int) ($line['item_id'] ?? $line['id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $normalized[] = [
                'item_id' => $itemId,
                'unit_id' => $this->nullablePositiveInt($line['unit_id'] ?? null),
                'counted_qty' => array_key_exists('counted_qty', $line) && $line['counted_qty'] !== '' ? InventoryDecimal::normalize($line['counted_qty']) : null,
                'notes' => trim((string) ($line['notes'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function countLinesForDraft(mysqli $conn, array $scope, array $request): array
    {
        $manualLines = $this->normalizeLines($request['lines'] ?? []);
        $countType = $this->countType($request['count_type'] ?? 'selected');
        $lowStockOnly = !empty($request['low_stock_only']);
        if (!in_array($countType, ['full', 'category'], true) && !$lowStockOnly) {
            return $manualLines;
        }

        $generated = $this->autoFillLines($conn, $scope, $request, $countType, $lowStockOnly);
        $merged = [];
        foreach (array_merge($generated, $manualLines) as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $merged[$itemId] = $line;
        }

        if (!$merged) {
            throw new RuntimeException('COUNT_AUTOFILL_EMPTY');
        }

        return array_values($merged);
    }

    private function autoFillLines(mysqli $conn, array $scope, array $request, string $countType, bool $lowStockOnly): array
    {
        if (!$this->tableExists($conn, 'myitems')) {
            return [];
        }

        $conditions = ['1 = 1'];
        if ($this->columnExists($conn, 'myitems', 'isdeleted')) {
            $conditions[] = 'COALESCE(i.isdeleted, 0) = 0';
        }
        if ($this->columnExists($conn, 'myitems', 'item_type')) {
            $conditions[] = "COALESCE(i.item_type, 'sellable') <> 'service'";
        }
        if ($this->columnExists($conn, 'myitems', 'track_stock')) {
            $conditions[] = 'COALESCE(i.track_stock, 1) = 1';
        }
        $params = [];
        $types = '';

        if ($countType === 'category') {
            if (!$this->columnExists($conn, 'myitems', 'group1')) {
                throw new RuntimeException('COUNT_CATEGORY_UNSUPPORTED');
            }
            $categoryId = $this->nullablePositiveInt($request['category_id'] ?? $request['group1'] ?? null);
            if (!$categoryId) {
                throw new InvalidArgumentException('CATEGORY_REQUIRED');
            }
            $conditions[] = 'COALESCE(i.group1, 0) = ?';
            $types .= 'i';
            $params[] = $categoryId;
        }

        $join = '';
        if ($lowStockOnly) {
            if (!$this->tableExists($conn, 'inventory_item_stock_levels')) {
                throw new RuntimeException('COUNT_LOW_STOCK_UNSUPPORTED');
            }
            $join = "
INNER JOIN inventory_item_stock_levels sl
        ON sl.item_id = i.id
       AND sl.pos_tenant = ?
       AND sl.pos_branch = ?
       AND sl.store_id = ?
       AND sl.is_active = 1
LEFT JOIN inventory_item_balances b
       ON b.item_id = i.id
      AND b.pos_tenant = sl.pos_tenant
      AND b.pos_branch = sl.pos_branch
      AND b.store_id = sl.store_id";
            $types = 'iii' . $types;
            $params = array_merge([
                (int) ($scope['pos_tenant'] ?? 0),
                (int) ($scope['pos_branch'] ?? 0),
                (int) ($scope['store_id'] ?? 0),
            ], $params);
            $conditions[] = "(
                (sl.reorder_level > 0 AND COALESCE(b.qty_available, b.qty_on_hand, 0) <= sl.reorder_level)
                OR (sl.reorder_level = 0 AND sl.minimum_level > 0 AND COALESCE(b.qty_available, b.qty_on_hand, 0) <= sl.minimum_level)
            )";
        }

        $limit = max(1, min(2000, (int) ($request['autofill_limit'] ?? 1000)));
        $sql = "
SELECT i.id AS item_id
FROM myitems i
{$join}
WHERE " . implode("\n  AND ", $conditions) . "
ORDER BY i.iname, i.id
LIMIT {$limit}";
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId > 0) {
                $lines[] = ['item_id' => $itemId];
            }
        }
        $stmt->close();

        return $lines;
    }

    private function assertCanClose(): void
    {
        if (!$this->flags->canWriteLedger()) {
            throw new RuntimeException('INVENTORY_LEDGER_NOT_READY');
        }
    }

    private function assertTables(mysqli $conn): void
    {
        foreach (['inventory_counts', 'inventory_count_lines', 'inventory_movements', 'inventory_item_balances'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_SCHEMA_MISSING_' . strtoupper($table));
            }
        }
    }

    private function lockCount(mysqli $conn, int $countId): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_counts WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function lockCountLines(mysqli $conn, int $countId): array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_count_lines WHERE count_id = ? ORDER BY id ASC FOR UPDATE');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function countHasCountedLine(mysqli $conn, int $countId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM inventory_count_lines WHERE count_id = ? AND counted_qty IS NOT NULL LIMIT 1');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function findCountByUuid(mysqli $conn, string $uuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_counts WHERE count_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function assertExistingCountReplay(mysqli $conn, array $existing, array $request, array $scope, array $requestLines): void
    {
        foreach ([
            'pos_tenant' => (int) ($scope['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($scope['pos_branch'] ?? 0),
            'store_id' => (int) ($scope['store_id'] ?? 0),
        ] as $column => $requestedValue) {
            if ((int) ($existing[$column] ?? 0) !== $requestedValue) {
                throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
            }
        }

        if ((string) ($existing['count_type'] ?? 'selected') !== $this->countType($request['count_type'] ?? 'selected')) {
            throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
        }

        if (array_key_exists('hide_expected_qty', $request) && (int) ($existing['hide_expected_qty'] ?? 0) !== (!empty($request['hide_expected_qty']) ? 1 : 0)) {
            throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
        }
        if (array_key_exists('include_zero_stock', $request) && (int) ($existing['include_zero_stock'] ?? 0) !== (!empty($request['include_zero_stock']) ? 1 : 0)) {
            throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
        }
        $assignedUserId = $this->nullablePositiveInt($request['assigned_user_id'] ?? null);
        if ($assignedUserId !== null && (int) ($existing['assigned_user_id'] ?? 0) !== $assignedUserId) {
            throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
        }

        if ($requestLines && $this->canonicalCountRequestLines($requestLines) !== $this->canonicalCountStoredLines($conn, (int) $existing['id'])) {
            throw new RuntimeException('COUNT_IDEMPOTENCY_CONFLICT');
        }
    }

    private function canonicalCountRequestLines(array $lines): array
    {
        $canonical = [];
        foreach ($lines as $line) {
            $canonical[] = [
                'item_id' => (int) $line['item_id'],
                'unit_id' => (int) ($line['unit_id'] ?? 0),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function canonicalCountStoredLines(mysqli $conn, int $countId): array
    {
        $stmt = $conn->prepare('SELECT item_id, unit_id FROM inventory_count_lines WHERE count_id = ?');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'unit_id' => (int) ($row['unit_id'] ?? 0),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function compareCanonicalLines(array $left, array $right): int
    {
        return strcmp(json_encode($left, JSON_UNESCAPED_SLASHES), json_encode($right, JSON_UNESCAPED_SLASHES));
    }

    private function findCountLineId(mysqli $conn, int $countId, int $itemId): int
    {
        $stmt = $conn->prepare('SELECT id FROM inventory_count_lines WHERE count_id = ? AND item_id = ? LIMIT 1');
        $stmt->bind_param('ii', $countId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['id'] ?? 0);
    }

    private function loadCountLine(mysqli $conn, int $lineId): array
    {
        $stmt = $conn->prepare("
SELECT l.*, c.pos_tenant, c.pos_branch, c.branch_uuid, c.store_id
FROM inventory_count_lines l
INNER JOIN inventory_counts c ON c.id = l.count_id
WHERE l.id = ?
LIMIT 1");
        $stmt->bind_param('i', $lineId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('COUNT_LINE_NOT_FOUND');
        }

        return $row;
    }

    private function loadBalance(mysqli $conn, array $scope, int $itemId): array
    {
        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1");
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $stmt->bind_param('iiii', $posTenant, $posBranch, $storeId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [
            'qty_on_hand' => InventoryDecimal::zero(),
            'qty_available' => InventoryDecimal::zero(),
            'moving_average_cost' => InventoryDecimal::zero(),
            'last_movement_id' => null,
        ];
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'myitems')) {
            return ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock', 'base_unit_id'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        $stmt = $conn->prepare('SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $item ?: ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
    }

    private function lineIsStale(array $line, array $balance): bool
    {
        $snapshotLast = $this->nullablePositiveInt($line['snapshot_last_movement_id'] ?? null);
        $currentLast = $this->nullablePositiveInt($balance['last_movement_id'] ?? null);

        return $snapshotLast !== $currentLast;
    }

    private function markStale(mysqli $conn, int $lineId): void
    {
        $stmt = $conn->prepare('UPDATE inventory_count_lines SET stale_count_conflict = 1 WHERE id = ?');
        $stmt->bind_param('i', $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function refreshVariance(mysqli $conn, int $lineId, string $varianceQty, string $varianceCost): void
    {
        $line = $this->loadCountLine($conn, $lineId);
        $variancePercent = $this->variancePercent($varianceQty, $line['snapshot_qty']);
        $stmt = $conn->prepare('UPDATE inventory_count_lines SET variance_qty = ?, variance_percent = ?, variance_cost = ? WHERE id = ?');
        $stmt->bind_param('sssi', $varianceQty, $variancePercent, $varianceCost, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function variancePercent(string $varianceQty, string $snapshotQty): string
    {
        if (InventoryDecimal::compare($snapshotQty, '0') === 0) {
            return InventoryDecimal::compare($varianceQty, '0') === 0 ? InventoryDecimal::zero() : '100.000000';
        }

        return InventoryDecimal::multiply(InventoryDecimal::divide($varianceQty, $snapshotQty), '100');
    }

    private function varianceCost(string $varianceQty, string $unitCost): string
    {
        $abs = InventoryDecimal::compare($varianceQty, '0') < 0 ? ltrim($varianceQty, '-') : $varianceQty;

        return InventoryDecimal::multiply($abs, InventoryDecimal::normalize($unitCost));
    }

    private function movementKey(array $scope, string $countUuid, int $lineId): string
    {
        return implode(':', [
            'inventory-count-close',
            'v1',
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'count',
            $countUuid,
            'line',
            $lineId,
        ]);
    }

    private function reversalKey(array $scope, string $countUuid, int $lineId, int $movementId): string
    {
        return implode(':', [
            'inventory-count-reversal',
            'v1',
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'count',
            $countUuid,
            'line',
            $lineId,
            'movement',
            $movementId,
        ]);
    }

    private function countType($value): string
    {
        $type = strtolower(trim((string) $value));
        return in_array($type, ['full', 'category', 'selected', 'spot'], true) ? $type : 'selected';
    }

    private function userId(array $context): ?int
    {
        $userId = (int) ($context['user_id'] ?? ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0));

        return $userId > 0 ? $userId : null;
    }

    private function uuidFromRequest(array $request, string $key): string
    {
        $uuid = trim((string) ($request[$key] ?? ''));
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) === 1) {
            return strtolower($uuid);
        }

        return $this->uuid();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function unitConversionForItem(mysqli $conn, int $itemId, ?int $unitId): string
    {
        if (!$unitId) {
            return '1.00000000';
        }
        if ($itemId < 1 || !$this->tableExists($conn, 'item_units')) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conditions = ['item_id = ?', 'unit_id = ?'];
        if ($this->columnExists($conn, 'item_units', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        $stmt = $conn->prepare('SELECT u_val FROM item_units WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conversion = InventoryDecimal::normalize($row['u_val'] ?? '0', 8);
        if (InventoryDecimal::compare($conversion, '0', 8) <= 0) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }

        return $conversion;
    }

    private function lineUnitConversion(array $line): string
    {
        $conversion = InventoryDecimal::normalize($line['unit_conversion_to_base'] ?? '1', 8);
        if (InventoryDecimal::compare($conversion, '0', 8) <= 0) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }

        return $conversion;
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

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }
}
