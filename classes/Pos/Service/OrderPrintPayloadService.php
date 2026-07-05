<?php

require_once __DIR__ . '/ModifierLineNoteService.php';
require_once __DIR__ . '/PosOrderMutationService.php';

class OrderPrintPayloadService
{
    private ModifierLineNoteService $customizationService;
    private array $tableExistsCache = [];

    public function __construct(?ModifierLineNoteService $customizationService = null)
    {
        $this->customizationService = $customizationService ?: new ModifierLineNoteService();
    }

    public function buildReceiptPayload(mysqli $conn, int $orderId): array
    {
        return $this->buildForOrder($conn, $orderId, 'receipt');
    }

    public function buildKotPayloadByOrderId(mysqli $conn, int $orderId): array
    {
        return $this->buildForOrder($conn, $orderId, 'kot');
    }

    public function buildKotPayloadByTableId(mysqli $conn, int $tableId): array
    {
        $tableId = $this->positiveInt($tableId, 'TABLE_ID_REQUIRED');
        $stmt = $conn->prepare("
            SELECT id
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('ACTIVE_TABLE_ORDER_NOT_FOUND');
        }

        return $this->buildForOrder($conn, (int) $row['id'], 'kot');
    }

    private function buildForOrder(mysqli $conn, int $orderId, string $documentType): array
    {
        $orderId = $this->positiveInt($orderId, 'ORDER_ID_REQUIRED');
        $order = $this->fetchOrder($conn, $orderId);
        if (!$order) {
            throw new RuntimeException('ORDER_NOT_FOUND');
        }

        $lines = $this->fetchLines($conn, $orderId);
        $customizationsAvailable = $this->customizationsAvailable($conn);
        foreach ($lines as &$line) {
            if ($customizationsAvailable) {
                $customizations = $this->customizationService->fetchLineCustomizations(
                    $conn,
                    $orderId,
                    (int) $line['detail_id']
                );
                $line['modifiers'] = $customizations['modifiers'];
                $line['notes'] = $customizations['notes'];
            } else {
                $line['modifiers'] = [];
                $line['notes'] = [];
            }
        }
        unset($line);

        return [
            'document_type' => $documentType,
            'order' => [
                'id' => (int) $order['id'],
                'pro_id' => $this->nullableString($order['pro_id'] ?? null),
                'pro_date' => $this->nullableString($order['pro_date'] ?? null),
                'pro_tybe' => $this->nullableInt($order['pro_tybe'] ?? null),
                'order_type' => $this->nullableString($order['order_type'] ?? null),
                'order_status' => $this->nullableString($order['order_status'] ?? null),
                'payment_status' => $this->nullableString($order['payment_status'] ?? null),
                'created_at' => $this->nullableString($order['crtime'] ?? null),
            ],
            'table' => [
                'id' => $this->nullableInt($order['table_id'] ?? null),
                'name' => $this->nullableString($order['table_name'] ?? null),
            ],
            'customer' => [
                'id' => $this->nullableInt($order['acc1'] ?? null),
                'name' => $this->nullableString($order['customer_name'] ?? null),
                'info' => $this->nullableString($order['customer_info'] ?? null),
            ],
            'totals' => [
                'total' => $this->money($order['fat_total'] ?? 0),
                'discount' => $this->money($order['fat_disc'] ?? 0),
                'extra' => $this->money($order['fat_plus'] ?? 0),
                'net' => $this->money($order['fat_net'] ?? 0),
                'paid' => $this->money($order['paid_amount'] ?? 0),
                'remaining' => $this->money($order['remaining_amount'] ?? 0),
            ],
            'lines' => $lines,
            'customizations_available' => $customizationsAvailable,
            'escalation_attribution' => (new PosOrderMutationService())->escalationAttributionLineForOrder($conn, $orderId),
        ];
    }

    private function fetchOrder(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT h.*,
                   t.tname AS table_name,
                   a.aname AS customer_name,
                   a.info AS customer_info
            FROM ot_head h
            LEFT JOIN tables t ON t.id = h.table_id
            LEFT JOIN acc_head a ON a.id = h.acc1
            WHERE h.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchLines(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("
            SELECT fd.*,
                   i.iname AS item_name
            FROM fat_details fd
            LEFT JOIN myitems i ON i.id = fd.item_id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY fd.id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $qty = (float) ($row['qty_out'] ?? 0) - (float) ($row['qty_in'] ?? 0);
            $lines[] = [
                'detail_id' => (int) $row['id'],
                'item_id' => $this->nullableInt($row['item_id'] ?? null),
                'name' => $this->nullableString($row['item_name'] ?? null) ?: 'غير محدد',
                'qty' => $this->quantity($qty),
                'price' => $this->money($row['price'] ?? 0),
                'line_total' => $this->money($row['det_value'] ?? 0),
                'legacy_notes' => $this->nullableString($row['notes'] ?? null),
                'modifiers' => [],
                'notes' => [],
            ];
        }
        $stmt->close();

        return $lines;
    }

    private function customizationsAvailable(mysqli $conn): bool
    {
        foreach (['order_line_modifiers', 'order_line_notes', 'modifier_options'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                return false;
            }
        }

        return true;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->tableExistsCache[$table] = (int) ($row['c'] ?? 0) > 0;
        return $this->tableExistsCache[$table];
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function quantity($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
