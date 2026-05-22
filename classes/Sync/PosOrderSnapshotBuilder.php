<?php

class PosOrderSnapshotBuilder
{
    public function build(mysqli $conn, string $branchUuid, int $orderId, array $options = []): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Order id must be positive.');
        }

        $order = $this->fetchOrder($conn, $orderId);
        if (!$order) {
            throw new RuntimeException('POS order was not found for sync snapshot: ' . $orderId);
        }

        $orderUuid = self::deterministicUuid($branchUuid, 'ot_head:' . $orderId);
        $sourceSystem = $this->stringOrDefault($options['source_system'] ?? null, 'pos');
        $timezone = $this->stringOrDefault($options['branch_timezone'] ?? null, date_default_timezone_get() ?: 'Africa/Cairo');

        $snapshot = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_order',
            'source_system' => $sourceSystem,
            'branch_uuid' => $branchUuid,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'order_uuid' => $orderUuid,
            'local_order_id' => (int) $order['id'],
            'order' => $this->orderPayload($order, $orderUuid, $sourceSystem, $timezone),
            'lines' => $this->linePayloads($conn, $branchUuid, $orderId, $orderUuid),
            'payments' => $this->paymentPayloads($conn, $branchUuid, $orderId, $orderUuid),
            'receipts' => $this->receiptPayloads($conn, $branchUuid, $orderId, $orderUuid),
        ];

        $snapshot['payload_hash'] = hash('sha256', $this->encodeJson($snapshot));

        return $snapshot;
    }

    public static function deterministicUuid(string $namespace, string $name): string
    {
        $seed = strtolower(trim($namespace)) . ':' . $name;
        $hex = substr(sha1($seed), 0, 32);
        $hex[12] = '5';
        $variant = hexdec($hex[16]);
        $hex[16] = dechex(($variant & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function fetchOrder(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT h.*,
                   t.tname AS sync_table_name
            FROM ot_head h
            LEFT JOIN tables t ON t.id = h.table_id
            WHERE h.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function orderPayload(array $order, string $orderUuid, string $sourceSystem, string $timezone): array
    {
        $localOrderId = (int) $order['id'];
        $proDate = $this->dateOrNull($order['pro_date'] ?? null);
        $completedAt = $this->datetimeOrNull($order['completed_at'] ?? null);
        if ($completedAt === null && strtolower((string) ($order['order_status'] ?? '')) === 'completed') {
            $completedAt = $this->datetimeOrNull($order['payment_date'] ?? null) ?: $this->datetimeOrNull($order['mdtime'] ?? null);
        }

        return [
            'order_uuid' => $orderUuid,
            'local_order_id' => $localOrderId,
            'pro_id' => $this->nullableString($order['pro_id'] ?? null),
            'pro_tybe' => $this->nullableInt($order['pro_tybe'] ?? null),
            'order_type' => $this->nullableString($order['order_type'] ?? null) ?: 'takeaway',
            'source_system' => $sourceSystem,
            'source_external_id' => 'ot_head:' . $localOrderId,
            'cashier_user_id' => $this->nullableInt($order['user'] ?? null),
            'waiter_id' => $this->nullableInt($order['waiter_id'] ?? ($order['emp2_id'] ?? null)),
            'table_uuid' => $this->tableUuid($order),
            'table_id' => $this->nullableInt($order['table_id'] ?? null),
            'table_name' => $this->nullableString($order['sync_table_name'] ?? null),
            'pro_date' => $proDate,
            'created_at' => $this->datetimeOrNull($order['crtime'] ?? null) ?: $proDate,
            'completed_at' => $completedAt,
            'payment_date' => $this->datetimeOrNull($order['payment_date'] ?? null),
            'branch_timezone' => $timezone,
            'pro_value' => $this->decimalString($order['pro_value'] ?? null),
            'fat_total' => $this->decimalString($order['fat_total'] ?? null),
            'fat_net' => $this->decimalString($order['fat_net'] ?? null),
            'fat_disc' => $this->decimalString($order['fat_disc'] ?? null),
            'paid_amount' => $this->decimalString($order['paid_amount'] ?? null),
            'remaining_amount' => $this->decimalString($order['remaining_amount'] ?? null),
            'payment_status' => $this->nullableString($order['payment_status'] ?? null),
            'invoice_status' => $this->nullableString($order['invoice_status'] ?? null),
            'order_status' => $this->nullableString($order['order_status'] ?? null),
            'isdeleted' => (int) ($order['isdeleted'] ?? 0),
            'closed' => (int) ($order['closed'] ?? 0),
            'sync_revision' => $this->revisionFromOrder($order),
            'legacy' => [
                'tenant' => $this->nullableInt($order['tenant'] ?? null),
                'branch' => $this->nullableInt($order['branch'] ?? null),
                'store_id' => $this->nullableInt($order['store_id'] ?? null),
                'acc1' => $this->nullableInt($order['acc1'] ?? null),
                'acc2' => $this->nullableInt($order['acc2'] ?? null),
                'info' => $this->nullableString($order['info'] ?? null),
            ],
        ];
    }

    private function linePayloads(mysqli $conn, string $branchUuid, int $orderId, string $orderUuid): array
    {
        $stmt = $conn->prepare("
            SELECT fd.*,
                   mi.iname AS item_name,
                   mi.barcode AS item_barcode
            FROM fat_details fd
            LEFT JOIN myitems mi ON mi.id = fd.item_id
            WHERE fd.fatid = ?
            ORDER BY fd.id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $localLineId = (int) $row['id'];
            $itemId = $this->nullableInt($row['item_id'] ?? null);
            $modifiers = $this->lineModifiers($conn, $orderId, $localLineId);
            $notes = $this->lineNotes($conn, $orderId, $localLineId);
            $lines[] = [
                'line_uuid' => self::deterministicUuid($branchUuid, 'fat_details:' . $localLineId),
                'order_uuid' => $orderUuid,
                'local_line_id' => $localLineId,
                'item_id' => $itemId,
                'item_uuid' => $itemId ? self::deterministicUuid($branchUuid, 'myitems:' . $itemId) : null,
                'item_name' => $this->nullableString($row['item_name'] ?? null),
                'barcode' => $this->nullableString($row['item_barcode'] ?? null),
                'qty_in' => $this->decimalString($row['qty_in'] ?? null),
                'qty_out' => $this->decimalString($row['qty_out'] ?? null),
                'price' => $this->decimalString($row['price'] ?? null),
                'cost_price' => $this->decimalString($row['cost_price'] ?? null),
                'discount' => $this->decimalString($row['discount'] ?? null),
                'det_value' => $this->decimalString($row['det_value'] ?? null),
                'profit' => $this->decimalString($row['profit'] ?? null),
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
                'modifiers' => $modifiers,
                'notes' => $notes,
            ];
        }
        $stmt->close();

        return $lines;
    }

    private function lineModifiers(mysqli $conn, int $orderId, int $detailId): array
    {
        if (!$this->tableExists($conn, 'order_line_modifiers')) {
            return [];
        }

        $optionNameSelect = $this->tableExists($conn, 'modifier_options')
            ? 'mo.name_ar, mo.name_en'
            : 'NULL AS name_ar, NULL AS name_en';
        $optionJoin = $this->tableExists($conn, 'modifier_options')
            ? 'LEFT JOIN modifier_options mo ON mo.id = olm.modifier_option_id'
            : '';
        $stmt = $conn->prepare("
            SELECT
                olm.modifier_group_id,
                olm.modifier_option_id,
                olm.qty,
                olm.price_delta,
                {$optionNameSelect}
            FROM order_line_modifiers olm
            {$optionJoin}
            WHERE olm.order_id = ?
              AND olm.detail_id = ?
            ORDER BY olm.id ASC
        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $result = $stmt->get_result();

        $modifiers = [];
        while ($row = $result->fetch_assoc()) {
            $qty = (float) ($row['qty'] ?? 0);
            $priceDelta = (float) ($row['price_delta'] ?? 0);
            $modifiers[] = [
                'modifier_group_id' => $this->nullableInt($row['modifier_group_id'] ?? null),
                'modifier_option_id' => $this->nullableInt($row['modifier_option_id'] ?? null),
                'option_id' => $this->nullableInt($row['modifier_option_id'] ?? null),
                'qty' => $this->decimalString($qty),
                'price_delta' => $this->decimalString($priceDelta),
                'line_delta' => $this->decimalString($qty * $priceDelta),
                'name_ar' => $this->nullableString($row['name_ar'] ?? null),
                'name_en' => $this->nullableString($row['name_en'] ?? null),
            ];
        }
        $stmt->close();

        return $modifiers;
    }

    private function lineNotes(mysqli $conn, int $orderId, int $detailId): array
    {
        if (!$this->tableExists($conn, 'order_line_notes')) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT note_type, note_text, created_by
            FROM order_line_notes
            WHERE order_id = ?
              AND detail_id = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $result = $stmt->get_result();

        $notes = [];
        while ($row = $result->fetch_assoc()) {
            $notes[] = [
                'note_type' => $this->nullableString($row['note_type'] ?? null) ?: 'kitchen',
                'note_text' => $this->nullableString($row['note_text'] ?? null) ?: '',
                'created_by' => $this->nullableInt($row['created_by'] ?? null),
            ];
        }
        $stmt->close();

        return $notes;
    }

    private function paymentPayloads(mysqli $conn, string $branchUuid, int $orderId, string $orderUuid): array
    {
        $payments = [];
        foreach ($this->paymentRows($conn, $orderId) as $row) {
            $localPaymentId = (int) $row['id'];
            $source = (string) ($row['_sync_source'] ?? 'ot_head');
            $uuidSeed = $source === 'order_payments'
                ? 'order_payments:' . $localPaymentId
                : 'order_payment:' . $localPaymentId;
            $payments[] = [
                'payment_uuid' => self::deterministicUuid($branchUuid, $uuidSeed),
                'order_uuid' => $orderUuid,
                'local_payment_id' => $localPaymentId,
                'amount' => $this->decimalString($row['pro_value'] ?? ($row['amount'] ?? null)),
                'payment_method' => $this->paymentMethod($row),
                'reference_no' => $this->nullableString($row['pro_id'] ?? ($row['reference_no'] ?? null)),
                'paid_by_customer_id' => $this->nullableInt($row['acc2'] ?? null),
                'created_by' => $this->nullableInt($row['user'] ?? ($row['created_by'] ?? null)),
                'voided' => (int) ($row['isdeleted'] ?? 0),
            ];
        }

        return $payments;
    }

    private function receiptPayloads(mysqli $conn, string $branchUuid, int $orderId, string $orderUuid): array
    {
        $receipts = [];
        foreach ($this->receiptRows($conn, $orderId) as $row) {
            $localReceiptId = (int) $row['id'];
            $receipts[] = [
                'receipt_uuid' => self::deterministicUuid($branchUuid, 'payment_receipt:' . $localReceiptId),
                'order_uuid' => $orderUuid,
                'local_receipt_id' => $localReceiptId,
                'local_order_id' => $orderId,
                'pro_id' => $this->nullableString($row['pro_id'] ?? null),
                'pro_tybe' => $this->nullableInt($row['pro_tybe'] ?? null),
                'amount' => $this->decimalString($row['pro_value'] ?? null),
                'acc_fund' => $this->nullableInt($row['acc1'] ?? null),
                'payment_method' => $this->paymentMethod($row),
                'payment_date' => $this->datetimeOrNull($row['payment_date'] ?? null) ?: $this->dateOrNull($row['pro_date'] ?? null),
            ];
        }

        return $receipts;
    }

    private function paymentRows(mysqli $conn, int $orderId): array
    {
        $rows = $this->receiptRows($conn, $orderId);
        if (!$this->tableExists($conn, 'order_payments')) {
            return $rows;
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM order_payments
            WHERE order_id = ?
              AND COALESCE(amount, 0) > 0
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['_sync_source'] = 'order_payments';
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function receiptRows(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM ot_head
            WHERE op2 = ?
              AND COALESCE(isdeleted, 0) = 0
              AND COALESCE(pro_value, 0) > 0
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }

    private function tableUuid(array $order): ?string
    {
        $tableId = $this->nullableInt($order['table_id'] ?? null);
        if (!$tableId) {
            return null;
        }

        return self::deterministicUuid('pos-table', (string) $tableId);
    }

    private function paymentMethod(array $row): string
    {
        $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
        if ($method !== '') {
            return substr($method, 0, 40);
        }

        $text = strtolower((string) ($row['info'] ?? ''));
        if (strpos($text, 'bank') !== false || strpos($text, 'card') !== false || strpos($text, 'صراف') !== false) {
            return 'bank';
        }

        return 'cash';
    }

    private function revisionFromOrder(array $order): int
    {
        $source = $order['mdtime'] ?? $order['crtime'] ?? null;
        $timestamp = $source ? strtotime((string) $source) : false;
        if ($timestamp !== false) {
            return max(1, (int) $timestamp);
        }

        return max(1, (int) ($order['id'] ?? 1));
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode POS order snapshot.');
        }

        return $json;
    }

    private function decimalString($value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || $value === false || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function stringOrDefault($value, string $default): string
    {
        $value = $this->nullableString($value);
        return $value === null ? $default : $value;
    }

    private function dateOrNull($value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d', $timestamp);
    }

    private function datetimeOrNull($value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
    }
}
