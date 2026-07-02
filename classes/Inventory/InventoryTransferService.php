<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryReasonCodeService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';
require_once __DIR__ . '/../Items/ItemUnitResolver.php';

class InventoryTransferService
{
    private InventoryFeatureFlags $flags;
    private InventoryLedgerService $ledger;
    private InventoryScopeResolver $scopeResolver;
    private InventoryItemPolicyService $itemPolicy;
    private InventoryReasonCodeService $reasonCodes;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryLedgerService $ledger = null,
        ?InventoryScopeResolver $scopeResolver = null,
        ?InventoryItemPolicyService $itemPolicy = null,
        ?InventoryReasonCodeService $reasonCodes = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->ledger = $ledger ?: new InventoryLedgerService($this->flags);
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
        $this->reasonCodes = $reasonCodes ?: new InventoryReasonCodeService();
    }

    public function createDraft(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transferUuid = $this->uuidFromRequest($request, 'transfer_uuid');
            $scope = $this->resolveScope($conn, $request, $context);
            $lines = $this->normalizeLines($request['lines'] ?? []);
            if (!$lines) {
                throw new InvalidArgumentException('TRANSFER_LINES_REQUIRED');
            }
            $existing = $this->findTransferByUuid($conn, $transferUuid);
            if ($existing) {
                $this->assertExistingTransferReplay($conn, $existing, $scope, $lines);
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'transfer_id' => (int) $existing['id'],
                    'transfer_uuid' => $transferUuid,
                    'status' => (string) $existing['status'],
                ];
            }

            $transferId = $this->insertTransfer($conn, $transferUuid, $request, $scope, $context);
            foreach ($lines as $line) {
                $line = $this->withResolvedUnit($conn, $line, 'requested_qty');
                $this->insertTransferLine($conn, $transferId, $line);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'transfer_id' => $transferId,
                'transfer_uuid' => $transferUuid,
                'status' => 'draft',
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function submit(mysqli $conn, int $transferId, array $context = []): array
    {
        return $this->transition($conn, $transferId, ['draft'], 'submitted', 'submitted_by', 'submitted_at', $context);
    }

    public function send(mysqli $conn, int $transferId, array $context = []): array
    {
        $this->assertCanPost();
        $this->assertTables($conn);
        if ($transferId < 1) {
            throw new InvalidArgumentException('TRANSFER_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transfer = $this->lockTransfer($conn, $transferId);
            if (!$transfer) {
                throw new InvalidArgumentException('TRANSFER_NOT_FOUND');
            }
            if ((string) $transfer['status'] === 'sent') {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId, 'status' => 'sent', 'movement_ids' => []];
            }
            if (!in_array((string) $transfer['status'], ['draft', 'submitted'], true)) {
                throw new RuntimeException('TRANSFER_NOT_SENDABLE');
            }

            $lines = $this->lockTransferLines($conn, $transferId);
            if (!$lines) {
                throw new RuntimeException('TRANSFER_LINES_REQUIRED');
            }

            $sourceScope = $this->transferScope($transfer, (int) $transfer['source_store_id'], 'source');
            $movementIds = [];
            foreach ($lines as $line) {
                if ($this->nullablePositiveInt($line['transfer_out_movement_id'] ?? null)) {
                    continue;
                }
                $item = $this->loadItem($conn, (int) $line['item_id']);
                $policy = $this->itemPolicy->policyForItem($item, $sourceScope);
                if (empty($policy['track_stock'])) {
                    throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_BE_TRANSFERRED');
                }
                $balance = $this->loadBalance($conn, $sourceScope, (int) $line['item_id']);
                $unitCost = InventoryDecimal::normalize($balance['moving_average_cost'] ?? $line['unit_cost'] ?? '0');
                $line = $this->withResolvedUnit($conn, $line, 'requested_qty');
                $sentQty = InventoryDecimal::normalize($line['requested_qty']);
                $baseSentQty = $line['base_qty'];
                $movement = $this->ledger->recordMovement($conn, [
                    'scope' => $sourceScope,
                    'item_id' => (int) $line['item_id'],
                    'movement_type' => 'transfer_out',
                    'source_type' => 'inventory_transfer',
                    'source_id' => (int) $line['id'],
                    'source_uuid' => 'inventory-transfer-out:' . $transfer['transfer_uuid'] . ':line:' . (int) $line['id'],
                    'qty_out' => $baseSentQty,
                    'unit_id' => $line['unit_id'],
                    'unit_conversion_to_base' => $line['unit_conversion_to_base'],
                    'unit_cost' => $unitCost,
                    'total_cost' => InventoryDecimal::multiply($baseSentQty, $unitCost),
                    'idempotency_key' => $this->movementKey('send', $sourceScope, (string) $transfer['transfer_uuid'], (int) $line['id']),
                    'metadata' => [
                        'source' => 'inventory_transfer_send',
                        'transfer_id' => $transferId,
                        'source_store_id' => (int) $transfer['source_store_id'],
                        'destination_store_id' => (int) $transfer['destination_store_id'],
                        'source_pos_branch' => (int) $sourceScope['pos_branch'],
                        'destination_pos_branch' => (int) ($transfer['destination_pos_branch'] ?? $transfer['pos_branch']),
                        'entered_qty' => $sentQty,
                    ],
                    'created_by' => $this->userId($context),
                ], $item, ['manage_transaction' => false]);
                $movementId = (int) ($movement['movement_id'] ?? 0);
                if ($movementId > 0) {
                    $movementIds[] = $movementId;
                    $this->markLineSent($conn, (int) $line['id'], $sentQty, $unitCost, $movementId);
                }
            }

            $userId = $this->userId($context);
            $stmt = $conn->prepare("UPDATE inventory_transfers SET status = 'sent', sent_by = ?, sent_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $userId, $transferId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return ['success' => true, 'idempotent_replay' => false, 'transfer_id' => $transferId, 'status' => 'sent', 'movement_ids' => $movementIds];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function receive(mysqli $conn, int $transferId, array $request = [], array $context = []): array
    {
        $this->assertCanPost();
        $this->assertTables($conn);
        if ($transferId < 1) {
            throw new InvalidArgumentException('TRANSFER_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transfer = $this->lockTransfer($conn, $transferId);
            if (!$transfer) {
                throw new InvalidArgumentException('TRANSFER_NOT_FOUND');
            }
            if ((string) $transfer['status'] === 'received') {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId, 'status' => 'received', 'movement_ids' => []];
            }
            if (!in_array((string) $transfer['status'], ['sent', 'partially_received'], true)) {
                throw new RuntimeException('TRANSFER_NOT_RECEIVABLE');
            }

            $lines = $this->lockTransferLines($conn, $transferId);
            $receiveTargets = $this->normalizeReceiveTargets($request['lines'] ?? [], $lines);
            if (!$receiveTargets) {
                throw new RuntimeException('TRANSFER_RECEIVE_LINES_REQUIRED');
            }

            $destinationScope = $this->transferScope($transfer, (int) $transfer['destination_store_id'], 'destination');
            $movementIds = [];
            foreach ($receiveTargets as $lineId => $targetReceivedQty) {
                $line = $this->lineById($lines, $lineId);
                if (!$line) {
                    throw new InvalidArgumentException('TRANSFER_LINE_NOT_FOUND');
                }
                if (!$this->nullablePositiveInt($line['transfer_out_movement_id'] ?? null)) {
                    throw new RuntimeException('TRANSFER_LINE_NOT_SENT');
                }
                if (InventoryDecimal::compare($targetReceivedQty, $line['sent_qty']) > 0) {
                    throw new RuntimeException('TRANSFER_OVER_RECEIVE');
                }
                if (InventoryDecimal::compare($targetReceivedQty, $line['received_qty']) < 0) {
                    throw new RuntimeException('TRANSFER_RECEIVE_REVERSAL_REQUIRED');
                }
                $receiveQty = InventoryDecimal::subtract($targetReceivedQty, $line['received_qty']);
                if (!InventoryDecimal::isPositive($receiveQty)) {
                    continue;
                }

                $item = $this->loadItem($conn, (int) $line['item_id']);
                $resolvedLine = $this->withResolvedUnit($conn, $line, 'received_delta_qty', $receiveQty);
                $baseReceiveQty = $resolvedLine['base_qty'];
                $movement = $this->ledger->recordMovement($conn, [
                    'scope' => $destinationScope,
                    'item_id' => (int) $line['item_id'],
                    'movement_type' => 'transfer_in',
                    'source_type' => 'inventory_transfer',
                    'source_id' => (int) $line['id'],
                    'source_uuid' => 'inventory-transfer-in:' . $transfer['transfer_uuid'] . ':line:' . (int) $line['id'] . ':received:' . $targetReceivedQty,
                    'qty_in' => $baseReceiveQty,
                    'unit_id' => $line['unit_id'],
                    'unit_conversion_to_base' => $resolvedLine['unit_conversion_to_base'],
                    'unit_cost' => $line['unit_cost'],
                    'total_cost' => InventoryDecimal::multiply($baseReceiveQty, $line['unit_cost']),
                    'idempotency_key' => $this->movementKey('receive', $destinationScope, (string) $transfer['transfer_uuid'], (int) $line['id']) . ':received:' . $targetReceivedQty,
                    'metadata' => [
                        'source' => 'inventory_transfer_receive',
                        'transfer_id' => $transferId,
                        'source_store_id' => (int) $transfer['source_store_id'],
                        'destination_store_id' => (int) $transfer['destination_store_id'],
                        'source_pos_branch' => (int) $transfer['pos_branch'],
                        'destination_pos_branch' => (int) $destinationScope['pos_branch'],
                        'entered_qty' => $receiveQty,
                        'target_received_qty' => $targetReceivedQty,
                    ],
                    'created_by' => $this->userId($context),
                ], $item, ['manage_transaction' => false]);
                $movementId = (int) ($movement['movement_id'] ?? 0);
                if ($movementId > 0) {
                    $movementIds[] = $movementId;
                    $this->markLineReceived($conn, (int) $line['id'], $receiveQty, $movementId);
                }
            }

            $status = $this->refreshTransferReceiveStatus($conn, $transferId, $context);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return ['success' => true, 'idempotent_replay' => false, 'transfer_id' => $transferId, 'status' => $status, 'movement_ids' => $movementIds];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function closeVariance(mysqli $conn, int $transferId, array $request = [], array $context = []): array
    {
        $this->assertCanPost();
        $this->assertTables($conn);
        if ($transferId < 1) {
            throw new InvalidArgumentException('TRANSFER_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transfer = $this->lockTransfer($conn, $transferId);
            if (!$transfer) {
                throw new InvalidArgumentException('TRANSFER_NOT_FOUND');
            }
            if ((string) $transfer['status'] === 'variance_closed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'transfer_id' => $transferId,
                    'status' => 'variance_closed',
                    'variance_lines' => 0,
                ];
            }
            if (!in_array((string) $transfer['status'], ['sent', 'partially_received'], true)) {
                throw new RuntimeException('TRANSFER_VARIANCE_NOT_CLOSABLE');
            }

            $scope = $this->transferScope($transfer, (int) $transfer['destination_store_id'], 'destination');
            $reasonText = trim((string) ($request['reason'] ?? $request['notes'] ?? ''));
            $reasonCode = $this->reasonCodes->validateForOperation(
                $conn,
                $request['reason_code_id'] ?? null,
                $scope,
                'transfer_variance',
                'out',
                !empty($context['allow_reason_code_approval'])
            );
            if (!$reasonCode && $reasonText === '') {
                throw new InvalidArgumentException('TRANSFER_VARIANCE_REASON_REQUIRED');
            }

            $varianceLines = 0;
            foreach ($this->lockTransferLines($conn, $transferId) as $line) {
                if (!$this->nullablePositiveInt($line['transfer_out_movement_id'] ?? null)) {
                    throw new RuntimeException('TRANSFER_LINE_NOT_SENT');
                }
                $varianceQty = InventoryDecimal::subtract($line['sent_qty'], $line['received_qty']);
                if (InventoryDecimal::compare($varianceQty, '0') < 0) {
                    throw new RuntimeException('TRANSFER_OVER_RECEIVE');
                }
                if (!InventoryDecimal::isPositive($varianceQty)) {
                    continue;
                }
                $this->markLineVarianceClosed($conn, (int) $line['id'], $varianceQty, $reasonCode, $reasonText);
                $varianceLines++;
            }

            if ($varianceLines < 1) {
                throw new RuntimeException('TRANSFER_VARIANCE_NOT_FOUND');
            }

            $noteSuffix = 'إغلاق فرق تحويل';
            if ($reasonCode) {
                $noteSuffix .= ': ' . (string) ($reasonCode['reason_name'] ?? $reasonCode['reason_code'] ?? '');
            }
            if ($reasonText !== '') {
                $noteSuffix .= ' - ' . $reasonText;
            }
            $stmt = $conn->prepare("
UPDATE inventory_transfers
SET status = 'variance_closed',
    closed_at = NOW(),
    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))
WHERE id = ?");
            $stmt->bind_param('si', $noteSuffix, $transferId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'transfer_id' => $transferId,
                'status' => 'variance_closed',
                'variance_lines' => $varianceLines,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function cancel(mysqli $conn, int $transferId, array $request = [], array $context = []): array
    {
        $this->assertTables($conn);
        if ($transferId < 1) {
            throw new InvalidArgumentException('TRANSFER_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transfer = $this->lockTransfer($conn, $transferId);
            if (!$transfer) {
                throw new InvalidArgumentException('TRANSFER_NOT_FOUND');
            }
            if ((string) $transfer['status'] === 'cancelled') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'transfer_id' => $transferId,
                    'status' => 'cancelled',
                    'movement_ids' => [],
                ];
            }
            if (!in_array((string) $transfer['status'], ['draft', 'submitted', 'sent'], true)) {
                throw new RuntimeException('TRANSFER_NOT_CANCELLABLE');
            }

            $reason = trim((string) ($request['reason'] ?? $request['notes'] ?? ''));
            $context['reason'] = $reason;
            $movementIds = [];
            if ((string) $transfer['status'] === 'sent') {
                $this->assertCanPost();
                $sourceScope = $this->transferScope($transfer, (int) $transfer['source_store_id'], 'source');
                foreach ($this->lockTransferLines($conn, $transferId) as $line) {
                    if (InventoryDecimal::isPositive($line['received_qty'] ?? '0')) {
                        throw new RuntimeException('TRANSFER_CANCEL_RECEIVED_LINES_NOT_ALLOWED');
                    }
                    if (!$this->nullablePositiveInt($line['transfer_out_movement_id'] ?? null)) {
                        throw new RuntimeException('TRANSFER_LINE_NOT_SENT');
                    }

                    $originalMovement = $this->loadMovement($conn, (int) $line['transfer_out_movement_id']);
                    $reversal = $this->recordCancelMovement($conn, $transfer, $line, $originalMovement, $sourceScope, $context);
                    if (!empty($reversal['movement_id'])) {
                        $movementIds[] = (int) $reversal['movement_id'];
                    }
                }
            }

            $userId = $this->userId($context);
            $noteSuffix = 'إلغاء تحويل';
            if ($reason !== '') {
                $noteSuffix .= ': ' . $reason;
            }
            $stmt = $conn->prepare("
UPDATE inventory_transfers
SET status = 'cancelled',
    cancelled_by = ?,
    cancelled_at = NOW(),
    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))
WHERE id = ?");
            $stmt->bind_param('isi', $userId, $noteSuffix, $transferId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'transfer_id' => $transferId,
                'status' => 'cancelled',
                'movement_ids' => $movementIds,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function transition(mysqli $conn, int $transferId, array $allowedStatuses, string $nextStatus, string $userColumn, string $dateColumn, array $context): array
    {
        $this->assertTables($conn);
        if ($transferId < 1) {
            throw new InvalidArgumentException('TRANSFER_REQUIRED');
        }
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $transfer = $this->lockTransfer($conn, $transferId);
            if (!$transfer) {
                throw new InvalidArgumentException('TRANSFER_NOT_FOUND');
            }
            $status = (string) $transfer['status'];
            if ($status === $nextStatus) {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId, 'status' => $nextStatus];
            }
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('TRANSFER_INVALID_TRANSITION');
            }
            if ($nextStatus === 'submitted' && !$this->transferHasLines($conn, $transferId)) {
                throw new RuntimeException('TRANSFER_LINES_REQUIRED');
            }

            $userId = $this->userId($context);
            $sql = "UPDATE inventory_transfers SET status = ?, {$userColumn} = ?, {$dateColumn} = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $nextStatus, $userId, $transferId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return ['success' => true, 'idempotent_replay' => false, 'transfer_id' => $transferId, 'status' => $nextStatus];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function resolveScope(mysqli $conn, array $request, array $context): array
    {
        if (function_exists('posmain_inventory_transfers_allowed') && !posmain_inventory_transfers_allowed()) {
            throw new InvalidArgumentException('INVENTORY_TRANSFERS_DISABLED');
        }

        $sourceStoreId = $this->positiveInt($request['source_store_id'] ?? 0);
        $destinationStoreId = $this->positiveInt($request['destination_store_id'] ?? 0);
        if ($sourceStoreId < 1 || $destinationStoreId < 1) {
            throw new InvalidArgumentException('TRANSFER_STORES_REQUIRED');
        }

        $scope = $this->scopeResolver->resolveForConn($conn, [
            'store_id' => $sourceStoreId,
            'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
            'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
            'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
            'source' => 'inventory_transfer',
        ]);
        $destinationScope = $this->scopeResolver->resolveForConn($conn, [
            'store_id' => $destinationStoreId,
            'pos_tenant' => $scope['pos_tenant'],
            'pos_branch' => $context['destination_pos_branch'] ?? $request['destination_pos_branch'] ?? $scope['pos_branch'],
            'branch_uuid' => $context['destination_branch_uuid'] ?? $request['destination_branch_uuid'] ?? $scope['branch_uuid'],
            'source' => 'inventory_transfer',
        ]);
        if ($sourceStoreId === $destinationStoreId && (int) $scope['pos_branch'] === (int) $destinationScope['pos_branch']) {
            throw new InvalidArgumentException('TRANSFER_STORES_MUST_DIFFER');
        }

        $scope['source_store_id'] = $sourceStoreId;
        $scope['destination_store_id'] = $destinationStoreId;
        $scope['destination_pos_branch'] = (int) $destinationScope['pos_branch'];
        $scope['destination_branch_uuid'] = $destinationScope['branch_uuid'];

        return $scope;
    }

    private function transferScope(array $transfer, int $storeId, string $side = 'source'): array
    {
        $isDestination = $side === 'destination';
        $destinationBranch = $transfer['destination_pos_branch'] ?? null;
        $destinationBranchUuid = $transfer['destination_branch_uuid'] ?? null;

        return [
            'pos_tenant' => (int) $transfer['pos_tenant'],
            'pos_branch' => $isDestination && $destinationBranch !== null ? (int) $destinationBranch : (int) $transfer['pos_branch'],
            'branch_uuid' => $isDestination && $destinationBranchUuid !== null && trim((string) $destinationBranchUuid) !== '' ? $destinationBranchUuid : $transfer['branch_uuid'],
            'store_id' => $storeId,
        ];
    }

    private function insertTransfer(mysqli $conn, string $uuid, array $request, array $scope, array $context): int
    {
        $hasDestinationBranchColumns = $this->columnExists($conn, 'inventory_transfers', 'destination_pos_branch')
            && $this->columnExists($conn, 'inventory_transfers', 'destination_branch_uuid');
        if (!$hasDestinationBranchColumns && (int) $scope['destination_pos_branch'] !== (int) $scope['pos_branch']) {
            throw new RuntimeException('TRANSFER_DESTINATION_BRANCH_SCHEMA_MISSING');
        }

        if ($hasDestinationBranchColumns) {
            $stmt = $conn->prepare("
INSERT INTO inventory_transfers
  (transfer_uuid, pos_tenant, pos_branch, branch_uuid, source_store_id, destination_store_id, destination_pos_branch, destination_branch_uuid, created_by, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        } else {
            $stmt = $conn->prepare("
INSERT INTO inventory_transfers
  (transfer_uuid, pos_tenant, pos_branch, branch_uuid, source_store_id, destination_store_id, created_by, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        }
        $posTenant = (int) $scope['pos_tenant'];
        $posBranch = (int) $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];
        $sourceStoreId = (int) $scope['source_store_id'];
        $destinationStoreId = (int) $scope['destination_store_id'];
        $destinationPosBranch = (int) $scope['destination_pos_branch'];
        $destinationBranchUuid = $scope['destination_branch_uuid'];
        $userId = $this->userId($context);
        $notes = trim((string) ($request['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        if ($hasDestinationBranchColumns) {
            $stmt->bind_param('siisiiisis', $uuid, $posTenant, $posBranch, $branchUuid, $sourceStoreId, $destinationStoreId, $destinationPosBranch, $destinationBranchUuid, $userId, $notes);
        } else {
            $stmt->bind_param('siisiiis', $uuid, $posTenant, $posBranch, $branchUuid, $sourceStoreId, $destinationStoreId, $userId, $notes);
        }
        $stmt->execute();
        $transferId = (int) $conn->insert_id;
        $stmt->close();

        return $transferId;
    }

    private function insertTransferLine(mysqli $conn, int $transferId, array $line): int
    {
        $stmt = $conn->prepare("
INSERT INTO inventory_transfer_lines
  (transfer_id, item_id, unit_id, requested_qty, notes)
VALUES (?, ?, ?, ?, ?)");
        $notes = trim((string) ($line['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param('iiiss', $transferId, $line['item_id'], $line['unit_id'], $line['requested_qty'], $notes);
        $stmt->execute();
        $lineId = (int) $conn->insert_id;
        $stmt->close();

        return $lineId;
    }

    private function markLineSent(mysqli $conn, int $lineId, string $sentQty, string $unitCost, int $movementId): void
    {
        $stmt = $conn->prepare('UPDATE inventory_transfer_lines SET sent_qty = ?, unit_cost = ?, transfer_out_movement_id = ? WHERE id = ?');
        $stmt->bind_param('ssii', $sentQty, $unitCost, $movementId, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function markLineReceived(mysqli $conn, int $lineId, string $receiveQty, int $movementId): void
    {
        $stmt = $conn->prepare('UPDATE inventory_transfer_lines SET variance_qty = sent_qty - (received_qty + ?), received_qty = received_qty + ?, transfer_in_movement_id = ? WHERE id = ?');
        $stmt->bind_param('ssii', $receiveQty, $receiveQty, $movementId, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function markLineVarianceClosed(mysqli $conn, int $lineId, string $varianceQty, ?array $reasonCode, string $reasonText): void
    {
        $reasonCodeId = $reasonCode ? (int) ($reasonCode['id'] ?? 0) : null;
        $reasonLabel = $reasonCode ? trim((string) ($reasonCode['reason_name'] ?? $reasonCode['reason_code'] ?? '')) : '';
        $note = trim(implode(' - ', array_filter(['فرق تحويل مغلق', $reasonLabel, $reasonText], static fn($value) => trim((string) $value) !== '')));
        $stmt = $conn->prepare("
UPDATE inventory_transfer_lines
SET variance_qty = ?,
    reason_code_id = ?,
    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))
WHERE id = ?");
        $stmt->bind_param('sisi', $varianceQty, $reasonCodeId, $note, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function loadMovement(mysqli $conn, int $movementId): array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_movements WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $movementId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('TRANSFER_ORIGINAL_MOVEMENT_NOT_FOUND');
        }

        return $row;
    }

    private function recordCancelMovement(mysqli $conn, array $transfer, array $line, array $originalMovement, array $sourceScope, array $context): array
    {
        $qtyIn = InventoryDecimal::normalize($originalMovement['qty_out'] ?? '0');
        if (!InventoryDecimal::isPositive($qtyIn)) {
            throw new RuntimeException('TRANSFER_ORIGINAL_MOVEMENT_INVALID');
        }

        $unitCost = InventoryDecimal::normalize($originalMovement['unit_cost'] ?? '0');
        $totalCost = InventoryDecimal::normalize($originalMovement['total_cost'] ?? InventoryDecimal::multiply($qtyIn, $unitCost));
        $lineId = (int) $line['id'];
        $transferUuid = (string) $transfer['transfer_uuid'];
        $reason = trim((string) ($context['reason'] ?? ''));

        return $this->ledger->recordMovement($conn, [
            'scope' => $sourceScope,
            'item_id' => (int) $line['item_id'],
            'movement_type' => 'transfer_in',
            'source_type' => 'inventory_transfer',
            'source_id' => $lineId,
            'source_uuid' => 'inventory-transfer-cancel:' . $transferUuid . ':line:' . $lineId,
            'qty_in' => $qtyIn,
            'unit_id' => $originalMovement['unit_id'] ?? $line['unit_id'],
            'unit_conversion_to_base' => InventoryDecimal::normalize($originalMovement['unit_conversion_to_base'] ?? '1', 8),
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $this->movementKey('cancel', $sourceScope, $transferUuid, $lineId),
            'metadata' => [
                'source' => 'inventory_transfer_cancel',
                'transfer_id' => (int) $transfer['id'],
                'source_store_id' => (int) $transfer['source_store_id'],
                'destination_store_id' => (int) $transfer['destination_store_id'],
                'source_pos_branch' => (int) $sourceScope['pos_branch'],
                'destination_pos_branch' => (int) ($transfer['destination_pos_branch'] ?? $transfer['pos_branch']),
                'reverses_movement_id' => (int) $originalMovement['id'],
                'reason' => $reason,
            ],
            'created_by' => $this->userId($context),
        ], $this->loadItem($conn, (int) $line['item_id']), ['manage_transaction' => false]);
    }

    private function refreshTransferReceiveStatus(mysqli $conn, int $transferId, array $context): string
    {
        $stmt = $conn->prepare("
SELECT
  COALESCE(SUM(sent_qty), 0) AS sent_qty,
  COALESCE(SUM(received_qty), 0) AS received_qty
FROM inventory_transfer_lines
WHERE transfer_id = ?");
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $sent = InventoryDecimal::normalize($row['sent_qty'] ?? '0');
        $received = InventoryDecimal::normalize($row['received_qty'] ?? '0');
        $status = InventoryDecimal::compare($received, $sent) >= 0 ? 'received' : 'partially_received';
        if (!InventoryDecimal::isPositive($received)) {
            return 'sent';
        }

        $userId = $this->userId($context);
        $stmt = $conn->prepare('UPDATE inventory_transfers SET status = ?, received_by = ?, received_at = NOW() WHERE id = ?');
        $stmt->bind_param('sii', $status, $userId, $transferId);
        $stmt->execute();
        $stmt->close();

        return $status;
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
            $qty = InventoryDecimal::normalize($line['requested_qty'] ?? $line['qty'] ?? '0');
            if ($itemId < 1 || !InventoryDecimal::isPositive($qty)) {
                continue;
            }
            $normalized[] = [
                'item_id' => $itemId,
                'unit_id' => $this->nullablePositiveInt($line['unit_id'] ?? null),
                'requested_qty' => $qty,
                'notes' => trim((string) ($line['notes'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function normalizeReceiveTargets($lines, array $transferLines): array
    {
        if (!is_array($lines) || !$lines) {
            $all = [];
            foreach ($transferLines as $line) {
                if (InventoryDecimal::compare($line['received_qty'], $line['sent_qty']) < 0) {
                    $all[(int) $line['id']] = InventoryDecimal::normalize($line['sent_qty']);
                }
            }
            return $all;
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineId = (int) ($line['transfer_line_id'] ?? $line['id'] ?? 0);
            $qty = InventoryDecimal::normalize($line['received_qty'] ?? $line['qty'] ?? '0');
            if ($lineId > 0 && InventoryDecimal::isPositive($qty)) {
                $normalized[$lineId] = $qty;
            }
        }

        return $normalized;
    }

    private function withResolvedUnit(mysqli $conn, array $line, string $qtyKey, ?string $qtyOverride = null): array
    {
        $unitConversion = '1.00000000';
        if (!empty($line['unit_id'])) {
            $unitConversion = $this->unitConversionForItem($conn, (int) $line['item_id'], (int) $line['unit_id']);
        }

        $enteredQty = InventoryDecimal::normalize($qtyOverride ?? ($line[$qtyKey] ?? '0'));
        $baseQty = InventoryDecimal::multiply($enteredQty, $unitConversion);
        if (!InventoryDecimal::isPositive($baseQty)) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }

        $line['unit_conversion_to_base'] = $unitConversion;
        $line['base_qty'] = $baseQty;

        return $line;
    }

    private function unitConversionForItem(mysqli $conn, int $itemId, int $unitId): string
    {
        if ($itemId < 1 || $unitId < 1 || !$this->tableExists($conn, 'item_units')) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conditions = ['item_id = ?', 'unit_id = ?'];
        if ($this->columnExists($conn, 'item_units', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        $stmt = $conn->prepare('SELECT * FROM item_units WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conversion = InventoryDecimal::normalize(
            (string) ItemUnitResolver::inventoryFactorForUnitRow($conn, $row),
            8
        );
        if (InventoryDecimal::compare($conversion, '0', 8) <= 0) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }

        return $conversion;
    }

    private function lockTransfer(mysqli $conn, int $transferId): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_transfers WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function lockTransferLines(mysqli $conn, int $transferId): array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_transfer_lines WHERE transfer_id = ? ORDER BY id ASC FOR UPDATE');
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function lineById(array $lines, int $lineId): ?array
    {
        foreach ($lines as $line) {
            if ((int) $line['id'] === $lineId) {
                return $line;
            }
        }

        return null;
    }

    private function transferHasLines(mysqli $conn, int $transferId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM inventory_transfer_lines WHERE transfer_id = ? LIMIT 1');
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function findTransferByUuid(mysqli $conn, string $uuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_transfers WHERE transfer_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function assertExistingTransferReplay(mysqli $conn, array $existing, array $scope, array $lines): void
    {
        foreach ([
            'pos_tenant' => (int) ($scope['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($scope['pos_branch'] ?? 0),
            'source_store_id' => (int) ($scope['source_store_id'] ?? 0),
            'destination_store_id' => (int) ($scope['destination_store_id'] ?? 0),
        ] as $column => $requestedValue) {
            if ((int) ($existing[$column] ?? 0) !== $requestedValue) {
                throw new RuntimeException('TRANSFER_IDEMPOTENCY_CONFLICT');
            }
        }

        if ($this->columnExists($conn, 'inventory_transfers', 'destination_pos_branch')) {
            $requestedDestinationBranch = (int) ($scope['destination_pos_branch'] ?? $scope['pos_branch'] ?? 0);
            $existingDestinationBranch = isset($existing['destination_pos_branch']) && $existing['destination_pos_branch'] !== null
                ? (int) $existing['destination_pos_branch']
                : (int) ($existing['pos_branch'] ?? 0);
            if ($existingDestinationBranch !== $requestedDestinationBranch) {
                throw new RuntimeException('TRANSFER_IDEMPOTENCY_CONFLICT');
            }
        }

        if ($this->canonicalTransferRequestLines($lines) !== $this->canonicalTransferStoredLines($conn, (int) $existing['id'])) {
            throw new RuntimeException('TRANSFER_IDEMPOTENCY_CONFLICT');
        }
    }

    private function canonicalTransferRequestLines(array $lines): array
    {
        $canonical = [];
        foreach ($lines as $line) {
            $canonical[] = [
                'item_id' => (int) $line['item_id'],
                'unit_id' => (int) ($line['unit_id'] ?? 0),
                'requested_qty' => InventoryDecimal::normalize($line['requested_qty'] ?? '0'),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function canonicalTransferStoredLines(mysqli $conn, int $transferId): array
    {
        $stmt = $conn->prepare('SELECT item_id, unit_id, requested_qty FROM inventory_transfer_lines WHERE transfer_id = ?');
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'requested_qty' => InventoryDecimal::normalize($row['requested_qty'] ?? '0'),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function compareCanonicalLines(array $left, array $right): int
    {
        return strcmp(json_encode($left, JSON_UNESCAPED_SLASHES), json_encode($right, JSON_UNESCAPED_SLASHES));
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

    private function assertCanPost(): void
    {
        if (!$this->flags->canWriteLedger()) {
            throw new RuntimeException('INVENTORY_LEDGER_NOT_READY');
        }
    }

    private function assertTables(mysqli $conn): void
    {
        foreach (['inventory_transfers', 'inventory_transfer_lines', 'inventory_movements', 'inventory_item_balances'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_SCHEMA_MISSING_' . strtoupper($table));
            }
        }
    }

    private function movementKey(string $action, array $scope, string $transferUuid, int $lineId): string
    {
        return implode(':', [
            'inventory-transfer',
            'v1',
            $action,
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'transfer',
            $transferUuid,
            'line',
            $lineId,
        ]);
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

    private function positiveInt($value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
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
