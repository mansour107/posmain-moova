<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryAccountingService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryPurchaseReceivingService
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

    public function receive(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertCanPost();
        $this->assertTables($conn);

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $receiptUuid = $this->uuidFromRequest($request, 'purchase_receipt_uuid');
            $lines = $this->normalizeLines($request['lines'] ?? []);
            if (!$lines) {
                throw new InvalidArgumentException('RECEIPT_LINES_REQUIRED');
            }
            $existing = $this->findReceiptByUuid($conn, $receiptUuid);
            if ($existing) {
                $this->assertExistingReceiptReplay($conn, $existing, $request, $lines, 'received_qty', ['received', 'posted']);
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'receipt_id' => (int) $existing['id'],
                    'purchase_receipt_uuid' => $receiptUuid,
                    'movements' => [],
                ];
            }

            $this->validatePurchaseOrderReceipt($conn, $request, $lines);

            $scope = $this->scopeResolver->resolveForConn($conn,[
                'store_id' => $request['destination_store_id'] ?? $request['store_id'] ?? 0,
                'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
                'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
                'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
                'source' => 'inventory_purchase_receiving',
            ]);
            if ((int) $scope['store_id'] < 1) {
                throw new InvalidArgumentException('DESTINATION_STORE_REQUIRED');
            }
            $this->assertSupplierInvoiceUnique($conn, $request, $scope);

            $receiptId = $this->insertReceipt($conn, $receiptUuid, $request, $scope, 'posted', $context);
            $movementIds = [];
            foreach ($lines as $index => $line) {
                $item = $this->loadItem($conn, (int) $line['item_id']);
                $policy = $this->itemPolicy->policyForItem($item, $scope);
                if (empty($policy['track_stock'])) {
                    throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_BE_RECEIVED');
                }
                $line = $this->withResolvedUnit($conn, $line);

                $lineId = $this->insertReceiptLine($conn, $receiptId, $line, 'received_qty');
                $movement = $this->ledger->recordMovement($conn, [
                    'scope' => $scope,
                    'item_id' => (int) $line['item_id'],
                    'movement_type' => 'purchase',
                    'source_type' => 'purchase_receipt',
                    'source_id' => $lineId,
                    'source_uuid' => 'purchase-receipt:' . $receiptUuid . ':line:' . $lineId,
                    'qty_in' => $line['base_qty'],
                    'unit_id' => $line['unit_id'],
                    'unit_conversion_to_base' => $line['unit_conversion_to_base'],
                    'unit_cost' => $line['base_unit_cost'],
                    'total_cost' => $line['total_cost'],
                    'idempotency_key' => $this->movementKey('receive', $scope, $receiptUuid, $lineId),
                    'metadata' => [
                        'source' => 'inventory_purchase_receiving',
                        'purchase_receipt_id' => $receiptId,
                        'supplier_account_id' => (int) ($request['supplier_account_id'] ?? 0),
                        'supplier_invoice_no' => trim((string) ($request['supplier_invoice_no'] ?? '')),
                        'entered_qty' => $line['qty'],
                        'entered_unit_cost' => $line['unit_cost'],
                    ],
                    'created_by' => $this->userId($context),
                ], $item, ['manage_transaction' => false]);
                if (!empty($movement['movement_id'])) {
                    $movementIds[] = (int) $movement['movement_id'];
                    $this->attachLineMovement($conn, $lineId, (int) $movement['movement_id']);
                }
                $this->applyPurchaseOrderReceipt($conn, $line, $line['qty']);
            }
            $this->refreshPurchaseOrderStatus($conn, (int) ($request['purchase_order_id'] ?? 0));
            $accounting = $this->accounting->postPurchaseReceipt($conn, [
                'pos_tenant' => (int) $scope['pos_tenant'],
                'pos_branch' => (int) $scope['pos_branch'],
                'store_id' => (int) $scope['store_id'],
                'supplier_account_id' => (int) ($request['supplier_account_id'] ?? 0),
                'purchase_receipt_id' => $receiptId,
                'receipt_id' => $receiptId,
                'user_id' => $this->userId($context),
                'details' => 'Inventory purchase receipt #' . $receiptId,
            ], $movementIds);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'receipt_id' => $receiptId,
                'purchase_receipt_uuid' => $receiptUuid,
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

    public function returnItems(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertCanPost();
        $this->assertTables($conn);

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $receiptUuid = $this->uuidFromRequest($request, 'purchase_receipt_uuid');
            $lines = $this->normalizeLines($request['lines'] ?? []);
            if (!$lines) {
                throw new InvalidArgumentException('RETURN_LINES_REQUIRED');
            }
            $existing = $this->findReceiptByUuid($conn, $receiptUuid);
            if ($existing) {
                $this->assertExistingReceiptReplay($conn, $existing, $request, $lines, 'returned_qty', ['returned']);
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'receipt_id' => (int) $existing['id'],
                    'purchase_receipt_uuid' => $receiptUuid,
                    'movements' => [],
                ];
            }

            $scope = $this->scopeResolver->resolveForConn($conn,[
                'store_id' => $request['destination_store_id'] ?? $request['store_id'] ?? 0,
                'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
                'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
                'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
                'source' => 'inventory_purchase_return',
            ]);
            if ((int) $scope['store_id'] < 1) {
                throw new InvalidArgumentException('DESTINATION_STORE_REQUIRED');
            }

            $receiptId = $this->insertReceipt($conn, $receiptUuid, $request, $scope, 'returned', $context);
            $movementIds = [];
            foreach ($lines as $line) {
                $item = $this->loadItem($conn, (int) $line['item_id']);
                $policy = $this->itemPolicy->policyForItem($item, $scope);
                if (empty($policy['track_stock'])) {
                    throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_BE_RETURNED');
                }
                $line = $this->withResolvedUnit($conn, $line);

                $lineId = $this->insertReceiptLine($conn, $receiptId, $line, 'returned_qty');
                $movement = $this->ledger->recordMovement($conn, [
                    'scope' => $scope,
                    'item_id' => (int) $line['item_id'],
                    'movement_type' => 'purchase_return',
                    'source_type' => 'purchase_receipt',
                    'source_id' => $lineId,
                    'source_uuid' => 'purchase-return:' . $receiptUuid . ':line:' . $lineId,
                    'qty_out' => $line['base_qty'],
                    'unit_id' => $line['unit_id'],
                    'unit_conversion_to_base' => $line['unit_conversion_to_base'],
                    'unit_cost' => $line['base_unit_cost'],
                    'total_cost' => $line['total_cost'],
                    'idempotency_key' => $this->movementKey('return', $scope, $receiptUuid, $lineId),
                    'metadata' => [
                        'source' => 'inventory_purchase_return',
                        'purchase_receipt_id' => $receiptId,
                        'supplier_account_id' => (int) ($request['supplier_account_id'] ?? 0),
                        'supplier_invoice_no' => trim((string) ($request['supplier_invoice_no'] ?? '')),
                        'entered_qty' => $line['qty'],
                        'entered_unit_cost' => $line['unit_cost'],
                    ],
                    'created_by' => $this->userId($context),
                ], $item, ['manage_transaction' => false]);
                if (!empty($movement['movement_id'])) {
                    $movementIds[] = (int) $movement['movement_id'];
                    $this->attachLineMovement($conn, $lineId, (int) $movement['movement_id']);
                }
            }
            $accounting = $this->accounting->postPurchaseReturn($conn, [
                'pos_tenant' => (int) $scope['pos_tenant'],
                'pos_branch' => (int) $scope['pos_branch'],
                'store_id' => (int) $scope['store_id'],
                'supplier_account_id' => (int) ($request['supplier_account_id'] ?? 0),
                'purchase_receipt_id' => $receiptId,
                'receipt_id' => $receiptId,
                'user_id' => $this->userId($context),
                'details' => 'Inventory purchase return #' . $receiptId,
            ], $movementIds);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'receipt_id' => $receiptId,
                'purchase_receipt_uuid' => $receiptUuid,
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

    private function assertCanPost(): void
    {
        if (!$this->flags->canWriteLedger()) {
            throw new RuntimeException('INVENTORY_LEDGER_NOT_READY');
        }
    }

    private function assertTables(mysqli $conn): void
    {
        foreach (['inventory_purchase_receipts', 'inventory_purchase_receipt_lines', 'inventory_movements', 'inventory_item_balances'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_SCHEMA_MISSING_' . strtoupper($table));
            }
        }
    }

    private function insertReceipt(mysqli $conn, string $uuid, array $request, array $scope, string $status, array $context): int
    {
        $stmt = $conn->prepare("
INSERT INTO inventory_purchase_receipts
  (purchase_receipt_uuid, purchase_order_id, pos_tenant, pos_branch, branch_uuid, supplier_account_id, destination_store_id, supplier_invoice_no, status, received_at, posted_at, created_by, posted_by, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)");
        $purchaseOrderId = $this->nullablePositiveInt($request['purchase_order_id'] ?? null);
        $supplierId = $this->nullablePositiveInt($request['supplier_account_id'] ?? null);
        $posTenant = (int) $scope['pos_tenant'];
        $posBranch = (int) $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];
        $storeId = (int) $scope['store_id'];
        $supplierInvoiceNo = trim((string) ($request['supplier_invoice_no'] ?? ''));
        $supplierInvoiceNo = $supplierInvoiceNo !== '' ? $supplierInvoiceNo : null;
        $userId = $this->userId($context);
        $notes = trim((string) ($request['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param(
            'siiisiissiis',
            $uuid,
            $purchaseOrderId,
            $posTenant,
            $posBranch,
            $branchUuid,
            $supplierId,
            $storeId,
            $supplierInvoiceNo,
            $status,
            $userId,
            $userId,
            $notes
        );
        $stmt->execute();
        $receiptId = (int) $conn->insert_id;
        $stmt->close();

        return $receiptId;
    }

    private function assertSupplierInvoiceUnique(mysqli $conn, array $request, array $scope): void
    {
        $supplierId = $this->nullablePositiveInt($request['supplier_account_id'] ?? null);
        $supplierInvoiceNo = trim((string) ($request['supplier_invoice_no'] ?? ''));
        if (!$supplierId || $supplierInvoiceNo === '') {
            return;
        }

        $stmt = $conn->prepare("
SELECT id
FROM inventory_purchase_receipts
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND supplier_account_id = ?
  AND supplier_invoice_no = ?
  AND status IN ('received','posted')
LIMIT 1
FOR UPDATE");
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $stmt->bind_param('iiis', $posTenant, $posBranch, $supplierId, $supplierInvoiceNo);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            throw new RuntimeException('SUPPLIER_INVOICE_DUPLICATE');
        }
    }

    private function assertExistingReceiptReplay(
        mysqli $conn,
        array $existing,
        array $request,
        array $lines,
        string $qtyColumn,
        array $expectedStatuses
    ): void {
        if (!in_array((string) ($existing['status'] ?? ''), $expectedStatuses, true)) {
            throw new RuntimeException('PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT');
        }

        foreach ([
            'purchase_order_id' => $this->nullablePositiveInt($request['purchase_order_id'] ?? null),
            'supplier_account_id' => $this->nullablePositiveInt($request['supplier_account_id'] ?? null),
            'destination_store_id' => $this->nullablePositiveInt($request['destination_store_id'] ?? $request['store_id'] ?? null),
        ] as $column => $requestedValue) {
            if ($requestedValue !== null && (int) ($existing[$column] ?? 0) !== $requestedValue) {
                throw new RuntimeException('PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT');
            }
        }

        $supplierInvoiceNo = trim((string) ($request['supplier_invoice_no'] ?? ''));
        if ($supplierInvoiceNo !== '' && trim((string) ($existing['supplier_invoice_no'] ?? '')) !== $supplierInvoiceNo) {
            throw new RuntimeException('PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT');
        }

        if ($this->canonicalReceiptRequestLines($lines) !== $this->canonicalReceiptStoredLines($conn, (int) $existing['id'], $qtyColumn)) {
            throw new RuntimeException('PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT');
        }
    }

    private function canonicalReceiptRequestLines(array $lines): array
    {
        $canonical = [];
        foreach ($lines as $line) {
            $canonical[] = [
                'purchase_order_line_id' => (int) ($line['purchase_order_line_id'] ?? 0),
                'item_id' => (int) $line['item_id'],
                'unit_id' => (int) ($line['unit_id'] ?? 0),
                'qty' => InventoryDecimal::normalize($line['qty'] ?? '0'),
                'unit_cost' => InventoryDecimal::normalize($line['unit_cost'] ?? '0'),
                'total_cost' => InventoryDecimal::normalize($line['total_cost'] ?? '0'),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function canonicalReceiptStoredLines(mysqli $conn, int $receiptId, string $qtyColumn): array
    {
        $stmt = $conn->prepare("
SELECT purchase_order_line_id, item_id, unit_id, {$qtyColumn} AS qty, unit_cost, total_cost
FROM inventory_purchase_receipt_lines
WHERE purchase_receipt_id = ?");
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'purchase_order_line_id' => (int) ($row['purchase_order_line_id'] ?? 0),
                'item_id' => (int) ($row['item_id'] ?? 0),
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'qty' => InventoryDecimal::normalize($row['qty'] ?? '0'),
                'unit_cost' => InventoryDecimal::normalize($row['unit_cost'] ?? '0'),
                'total_cost' => InventoryDecimal::normalize($row['total_cost'] ?? '0'),
            ];
        }

        usort($canonical, [$this, 'compareCanonicalLines']);
        return $canonical;
    }

    private function compareCanonicalLines(array $left, array $right): int
    {
        return strcmp(json_encode($left, JSON_UNESCAPED_SLASHES), json_encode($right, JSON_UNESCAPED_SLASHES));
    }

    private function insertReceiptLine(mysqli $conn, int $receiptId, array $line, string $qtyColumn): int
    {
        $receivedQty = $qtyColumn === 'received_qty' ? $line['qty'] : InventoryDecimal::zero();
        $returnedQty = $qtyColumn === 'returned_qty' ? $line['qty'] : InventoryDecimal::zero();
        $stmt = $conn->prepare("
INSERT INTO inventory_purchase_receipt_lines
  (purchase_receipt_id, purchase_order_line_id, item_id, unit_id, received_qty, returned_qty, unit_cost, total_cost, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $purchaseOrderLineId = $this->nullablePositiveInt($line['purchase_order_line_id'] ?? null);
        $unitId = $this->nullablePositiveInt($line['unit_id'] ?? null);
        $notes = trim((string) ($line['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param(
            'iiiisssss',
            $receiptId,
            $purchaseOrderLineId,
            $line['item_id'],
            $unitId,
            $receivedQty,
            $returnedQty,
            $line['unit_cost'],
            $line['total_cost'],
            $notes
        );
        $stmt->execute();
        $lineId = (int) $conn->insert_id;
        $stmt->close();

        return $lineId;
    }

    private function attachLineMovement(mysqli $conn, int $lineId, int $movementId): void
    {
        $stmt = $conn->prepare('UPDATE inventory_purchase_receipt_lines SET inventory_movement_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $movementId, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function applyPurchaseOrderReceipt(mysqli $conn, array $line, string $qty): void
    {
        $lineId = $this->nullablePositiveInt($line['purchase_order_line_id'] ?? null);
        if (!$lineId || !$this->tableExists($conn, 'inventory_purchase_order_lines')) {
            return;
        }

        $stmt = $conn->prepare('UPDATE inventory_purchase_order_lines SET received_qty = received_qty + ? WHERE id = ?');
        $stmt->bind_param('si', $qty, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function validatePurchaseOrderReceipt(mysqli $conn, array $request, array $lines): void
    {
        $purchaseOrderId = $this->nullablePositiveInt($request['purchase_order_id'] ?? null);
        if (!$purchaseOrderId) {
            foreach ($lines as $line) {
                if ($this->nullablePositiveInt($line['purchase_order_line_id'] ?? null)) {
                    throw new InvalidArgumentException('PURCHASE_ORDER_REQUIRED');
                }
            }
            return;
        }
        if (!$this->tableExists($conn, 'inventory_purchase_orders') || !$this->tableExists($conn, 'inventory_purchase_order_lines')) {
            throw new RuntimeException('PURCHASE_ORDER_SCHEMA_MISSING');
        }

        $stmt = $conn->prepare("
SELECT *
FROM inventory_purchase_orders
WHERE id = ?
LIMIT 1
FOR UPDATE");
        $stmt->bind_param('i', $purchaseOrderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) {
            throw new InvalidArgumentException('PURCHASE_ORDER_NOT_FOUND');
        }
        if (!in_array((string) ($order['status'] ?? ''), ['approved', 'partially_received'], true)) {
            throw new RuntimeException('PURCHASE_ORDER_NOT_APPROVED');
        }

        foreach ($lines as $line) {
            $lineId = $this->nullablePositiveInt($line['purchase_order_line_id'] ?? null);
            if (!$lineId) {
                throw new InvalidArgumentException('PURCHASE_ORDER_LINE_REQUIRED');
            }

            $stmt = $conn->prepare("
SELECT *
FROM inventory_purchase_order_lines
WHERE id = ?
  AND purchase_order_id = ?
LIMIT 1
FOR UPDATE");
            $stmt->bind_param('ii', $lineId, $purchaseOrderId);
            $stmt->execute();
            $orderLine = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$orderLine) {
                throw new InvalidArgumentException('PURCHASE_ORDER_LINE_NOT_FOUND');
            }
            if ((int) ($orderLine['item_id'] ?? 0) !== (int) $line['item_id']) {
                throw new InvalidArgumentException('PURCHASE_ORDER_LINE_ITEM_MISMATCH');
            }
            $orderUnitId = $this->nullablePositiveInt($orderLine['unit_id'] ?? null);
            $lineUnitId = $this->nullablePositiveInt($line['unit_id'] ?? null);
            if ($orderUnitId !== $lineUnitId) {
                throw new InvalidArgumentException('PURCHASE_ORDER_UNIT_MISMATCH');
            }

            $remaining = InventoryDecimal::subtract($orderLine['ordered_qty'] ?? '0', $orderLine['received_qty'] ?? '0');
            if (InventoryDecimal::compare($line['qty'], $remaining) > 0) {
                throw new RuntimeException('PURCHASE_ORDER_OVER_RECEIPT');
            }
        }
    }

    private function refreshPurchaseOrderStatus(mysqli $conn, int $purchaseOrderId): void
    {
        if ($purchaseOrderId < 1 || !$this->tableExists($conn, 'inventory_purchase_order_lines') || !$this->tableExists($conn, 'inventory_purchase_orders')) {
            return;
        }

        $stmt = $conn->prepare("
SELECT
  COALESCE(SUM(ordered_qty), 0) AS ordered_qty,
  COALESCE(SUM(received_qty), 0) AS received_qty
FROM inventory_purchase_order_lines
WHERE purchase_order_id = ?");
        $stmt->bind_param('i', $purchaseOrderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $ordered = InventoryDecimal::normalize($row['ordered_qty'] ?? '0');
        $received = InventoryDecimal::normalize($row['received_qty'] ?? '0');
        $status = InventoryDecimal::compare($received, $ordered) >= 0 ? 'received' : 'partially_received';
        if (!InventoryDecimal::isPositive($received)) {
            return;
        }

        $stmt = $conn->prepare("UPDATE inventory_purchase_orders SET status = ? WHERE id = ? AND status IN ('approved','partially_received')");
        $stmt->bind_param('si', $status, $purchaseOrderId);
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeLines($lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('INVALID_PURCHASE_LINE');
            }
            $itemId = (int) ($line['item_id'] ?? $line['id'] ?? 0);
            $qty = InventoryDecimal::normalize($line['qty'] ?? $line['received_qty'] ?? $line['returned_qty'] ?? '0');
            $unitCost = InventoryDecimal::normalize($line['unit_cost'] ?? $line['cost_price'] ?? '0');
            if ($itemId < 1) {
                throw new InvalidArgumentException('INVENTORY_ITEM_REQUIRED');
            }
            if (!InventoryDecimal::isPositive($qty)) {
                throw new InvalidArgumentException('INVENTORY_QTY_REQUIRED');
            }

            $normalized[] = [
                'item_id' => $itemId,
                'purchase_order_line_id' => $this->nullablePositiveInt($line['purchase_order_line_id'] ?? null),
                'unit_id' => $this->nullablePositiveInt($line['unit_id'] ?? null),
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
                'notes' => trim((string) ($line['notes'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function withResolvedUnit(mysqli $conn, array $line): array
    {
        $unitConversion = '1.00000000';
        if (!empty($line['unit_id'])) {
            $unitConversion = $this->unitConversionForItem($conn, (int) $line['item_id'], (int) $line['unit_id']);
        }

        $baseQty = InventoryDecimal::multiply($line['qty'], $unitConversion);
        if (!InventoryDecimal::isPositive($baseQty)) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }
        $totalCost = InventoryDecimal::multiply($line['qty'], $line['unit_cost']);
        $baseUnitCost = InventoryDecimal::divide($totalCost, $baseQty);

        $line['unit_conversion_to_base'] = $unitConversion;
        $line['base_qty'] = $baseQty;
        $line['base_unit_cost'] = $baseUnitCost;
        $line['total_cost'] = $totalCost;

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

    private function findReceiptByUuid(mysqli $conn, string $uuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_receipts WHERE purchase_receipt_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'myitems')) {
            throw new InvalidArgumentException('INVENTORY_ITEM_NOT_FOUND');
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock', 'base_unit_id'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        $conditions = ['id = ?'];
        if ($this->columnExists($conn, 'myitems', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        $stmt = $conn->prepare('SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new InvalidArgumentException('INVENTORY_ITEM_NOT_FOUND');
        }

        return $item;
    }

    private function movementKey(string $action, array $scope, string $receiptUuid, int $lineId): string
    {
        return implode(':', [
            'inventory-purchase-receiving',
            'v1',
            $action,
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'receipt',
            $receiptUuid,
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

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
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
