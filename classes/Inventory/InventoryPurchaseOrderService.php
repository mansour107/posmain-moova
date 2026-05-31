<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryPurchaseOrderService
{
    private InventoryFeatureFlags $flags;
    private InventoryScopeResolver $scopeResolver;

    public function __construct(?InventoryFeatureFlags $flags = null, ?InventoryScopeResolver $scopeResolver = null)
    {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
    }

    public function createDraft(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $uuid = $this->uuidFromRequest($request, 'purchase_order_uuid');
            $lines = $this->normalizeLines($request['lines'] ?? []);
            if (!$lines) {
                throw new InvalidArgumentException('PURCHASE_ORDER_LINES_REQUIRED');
            }
            $existing = $this->findOrderByUuid($conn, $uuid);
            if ($existing) {
                $this->assertExistingOrderReplay($conn, $existing, $request, $lines);
                if ($ownsTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'purchase_order_id' => (int) $existing['id'],
                    'purchase_order_uuid' => $uuid,
                    'status' => (string) $existing['status'],
                ];
            }

            $scope = $this->scopeResolver->resolve([
                'store_id' => $request['destination_store_id'] ?? $request['store_id'] ?? 0,
                'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
                'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
                'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
                'source' => 'inventory_purchase_order',
            ]);
            if ((int) $scope['store_id'] < 1) {
                throw new InvalidArgumentException('DESTINATION_STORE_REQUIRED');
            }

            $orderId = $this->insertOrder($conn, $uuid, $request, $scope, $context);
            foreach ($lines as $line) {
                $this->insertOrderLine($conn, $orderId, $line);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'purchase_order_id' => $orderId,
                'purchase_order_uuid' => $uuid,
                'status' => 'draft',
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function submit(mysqli $conn, int $purchaseOrderId, array $context = []): array
    {
        return $this->transition($conn, $purchaseOrderId, ['draft'], 'submitted', 'submitted_by', 'submitted_at', $context);
    }

    public function approve(mysqli $conn, int $purchaseOrderId, array $context = []): array
    {
        return $this->transition($conn, $purchaseOrderId, ['submitted'], 'approved', 'approved_by', 'approved_at', $context);
    }

    public function createAndSubmit(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $context['in_transaction'] = true;
            $created = $this->createDraft($conn, $request, $context);
            if (empty($created['idempotent_replay']) && (string) ($created['status'] ?? '') === 'draft') {
                $created = $this->submit($conn, (int) $created['purchase_order_id'], $context);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return $created;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function transition(
        mysqli $conn,
        int $purchaseOrderId,
        array $allowedStatuses,
        string $nextStatus,
        string $userColumn,
        string $dateColumn,
        array $context
    ): array {
        $this->assertTables($conn);
        if ($purchaseOrderId < 1) {
            throw new InvalidArgumentException('PURCHASE_ORDER_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $order = $this->lockOrder($conn, $purchaseOrderId);
            if (!$order) {
                throw new InvalidArgumentException('PURCHASE_ORDER_NOT_FOUND');
            }
            $status = (string) ($order['status'] ?? '');
            if ($status === $nextStatus) {
                if ($ownsTransaction) {
                    $conn->commit();
                }
                return [
                    'success' => true,
                    'idempotent_replay' => true,
                    'purchase_order_id' => $purchaseOrderId,
                    'status' => $nextStatus,
                ];
            }
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('PURCHASE_ORDER_INVALID_TRANSITION');
            }
            if ($nextStatus === 'submitted' && !$this->orderHasLines($conn, $purchaseOrderId)) {
                throw new RuntimeException('PURCHASE_ORDER_LINES_REQUIRED');
            }

            $userId = $this->userId($context);
            $sql = "UPDATE inventory_purchase_orders SET status = ?, {$userColumn} = ?, {$dateColumn} = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $nextStatus, $userId, $purchaseOrderId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'idempotent_replay' => false,
                'purchase_order_id' => $purchaseOrderId,
                'status' => $nextStatus,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function insertOrder(mysqli $conn, string $uuid, array $request, array $scope, array $context): int
    {
        $stmt = $conn->prepare("
INSERT INTO inventory_purchase_orders
  (purchase_order_uuid, pos_tenant, pos_branch, branch_uuid, supplier_account_id, destination_store_id, expected_at, created_by, notes)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $posTenant = (int) $scope['pos_tenant'];
        $posBranch = (int) $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];
        $supplierId = $this->nullablePositiveInt($request['supplier_account_id'] ?? null);
        $storeId = (int) $scope['store_id'];
        $expectedAt = $this->dateOrNull($request['expected_at'] ?? null);
        $userId = $this->userId($context);
        $notes = trim((string) ($request['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param('siisiisis', $uuid, $posTenant, $posBranch, $branchUuid, $supplierId, $storeId, $expectedAt, $userId, $notes);
        $stmt->execute();
        $orderId = (int) $conn->insert_id;
        $stmt->close();

        return $orderId;
    }

    private function insertOrderLine(mysqli $conn, int $orderId, array $line): int
    {
        $stmt = $conn->prepare("
INSERT INTO inventory_purchase_order_lines
  (purchase_order_id, item_id, unit_id, ordered_qty, unit_cost, total_cost, notes)
VALUES (?, ?, ?, ?, ?, ?, ?)");
        $unitId = $this->nullablePositiveInt($line['unit_id'] ?? null);
        $notes = trim((string) ($line['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;
        $stmt->bind_param('iiissss', $orderId, $line['item_id'], $unitId, $line['qty'], $line['unit_cost'], $line['total_cost'], $notes);
        $stmt->execute();
        $lineId = (int) $conn->insert_id;
        $stmt->close();

        return $lineId;
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
            $qty = InventoryDecimal::normalize($line['qty'] ?? $line['ordered_qty'] ?? '0');
            $unitCost = InventoryDecimal::normalize($line['unit_cost'] ?? $line['cost_price'] ?? '0');
            if ($itemId < 1 || !InventoryDecimal::isPositive($qty)) {
                continue;
            }

            $normalized[] = [
                'item_id' => $itemId,
                'unit_id' => $this->nullablePositiveInt($line['unit_id'] ?? null),
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
                'notes' => trim((string) ($line['notes'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function lockOrder(mysqli $conn, int $purchaseOrderId): ?array
    {
        $stmt = $conn->prepare("
SELECT *
FROM inventory_purchase_orders
WHERE id = ?
LIMIT 1
FOR UPDATE");
        $stmt->bind_param('i', $purchaseOrderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function orderHasLines(mysqli $conn, int $purchaseOrderId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM inventory_purchase_order_lines WHERE purchase_order_id = ? LIMIT 1');
        $stmt->bind_param('i', $purchaseOrderId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function findOrderByUuid(mysqli $conn, string $uuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_orders WHERE purchase_order_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function assertExistingOrderReplay(mysqli $conn, array $existing, array $request, array $lines): void
    {
        foreach ([
            'supplier_account_id' => $this->nullablePositiveInt($request['supplier_account_id'] ?? null),
            'destination_store_id' => $this->nullablePositiveInt($request['destination_store_id'] ?? $request['store_id'] ?? null),
        ] as $column => $requestedValue) {
            if ($requestedValue !== null && (int) ($existing[$column] ?? 0) !== $requestedValue) {
                throw new RuntimeException('PURCHASE_ORDER_IDEMPOTENCY_CONFLICT');
            }
        }

        if ($this->canonicalOrderRequestLines($lines) !== $this->canonicalOrderStoredLines($conn, (int) $existing['id'])) {
            throw new RuntimeException('PURCHASE_ORDER_IDEMPOTENCY_CONFLICT');
        }
    }

    private function canonicalOrderRequestLines(array $lines): array
    {
        $canonical = [];
        foreach ($lines as $line) {
            $canonical[] = [
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

    private function canonicalOrderStoredLines(mysqli $conn, int $purchaseOrderId): array
    {
        $stmt = $conn->prepare("
SELECT item_id, unit_id, ordered_qty AS qty, unit_cost, total_cost
FROM inventory_purchase_order_lines
WHERE purchase_order_id = ?");
        $stmt->bind_param('i', $purchaseOrderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
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

    private function assertTables(mysqli $conn): void
    {
        foreach (['inventory_purchase_orders', 'inventory_purchase_order_lines'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_SCHEMA_MISSING_' . strtoupper($table));
            }
        }
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

    private function userId(array $context): ?int
    {
        $userId = (int) ($context['user_id'] ?? ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0));

        return $userId > 0 ? $userId : null;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function dateOrNull($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) === 1) {
            return $text;
        }

        return null;
    }
}
