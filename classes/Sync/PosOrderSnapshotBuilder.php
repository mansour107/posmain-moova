<?php

require_once __DIR__ . '/../Financial/Money.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

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
            'schema_version' => 4,
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
            'financial_bundle' => $this->financialBundle($conn, $orderId),
            'fulfillment' => $this->fulfillmentPayload($conn, $orderId),
            'kitchen_events' => $this->kitchenEventPayloads($conn, $orderId),
        ];

        $snapshot['payload_hash'] = hash('sha256', $this->encodeJson($snapshot));

        return $snapshot;
    }

    public static function assertFinancialBundle(array $bundle, int $orderId): array
    {
        if (($bundle['complete'] ?? null) !== true || (int) ($bundle['local_order_id'] ?? 0) !== $orderId) {
            throw new RuntimeException('ORDER_FINANCIAL_BUNDLE_SCOPE_INVALID');
        }

        foreach (['accounts', 'journal_heads', 'journal_entries', 'totals'] as $key) {
            if (!isset($bundle[$key]) || !is_array($bundle[$key])) {
                throw new RuntimeException('ORDER_FINANCIAL_BUNDLE_SHAPE_INVALID');
            }
        }

        $accounts = [];
        foreach ($bundle['accounts'] as $account) {
            $accountId = is_array($account) ? (int) ($account['id'] ?? 0) : 0;
            if ($accountId <= 0 || isset($accounts[$accountId])) {
                throw new RuntimeException('ORDER_FINANCIAL_ACCOUNT_INVALID');
            }
            foreach (['balance', 'debit', 'credit', 'phone', 'address', 'e_mail', 'info'] as $excluded) {
                if (array_key_exists($excluded, $account)) {
                    throw new RuntimeException('ORDER_FINANCIAL_ACCOUNT_SENSITIVE_FIELD');
                }
            }
            $accounts[$accountId] = true;
        }

        $heads = [];
        foreach ($bundle['journal_heads'] as $head) {
            $headId = is_array($head) ? (int) ($head['id'] ?? 0) : 0;
            if ($headId <= 0 || isset($heads[$headId])) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_HEAD_INVALID');
            }
            $heads[$headId] = $head;
        }

        $entriesByHead = [];
        foreach ($bundle['journal_entries'] as $entry) {
            $entryId = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
            $headId = is_array($entry) ? (int) ($entry['journal_id'] ?? 0) : 0;
            $accountId = is_array($entry) ? (int) ($entry['account_id'] ?? 0) : 0;
            if ($entryId <= 0 || $headId <= 0 || !isset($heads[$headId]) || !isset($accounts[$accountId])) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_ENTRY_INVALID');
            }
            if (isset($entriesByHead[$headId][$entryId])) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_ENTRY_DUPLICATE');
            }
            $entriesByHead[$headId][$entryId] = $entry;
        }

        $bundleDebit = Money::zero();
        $bundleCredit = Money::zero();
        foreach ($heads as $headId => $head) {
            $entries = $entriesByHead[$headId] ?? [];
            if (count($entries) < 2) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_ENTRIES_REQUIRED');
            }
            $sourceType = strtolower(trim((string) ($head['source_type'] ?? '')));
            if (!in_array($sourceType, ['', 'invoice', 'payment'], true)) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_SCOPE_INVALID');
            }
            $headLinked = (int) ($head['op2'] ?? 0) === $orderId;
            if (!$headLinked && (int) ($head['op_id'] ?? 0) === $orderId) {
                foreach ($entries as $entry) {
                    if ((int) ($entry['op2'] ?? 0) === $orderId || (int) ($entry['op_id'] ?? 0) === $orderId) {
                        $headLinked = true;
                        break;
                    }
                }
            }
            if (!$headLinked) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_SCOPE_INVALID');
            }
            $headDebit = Money::zero();
            $headCredit = Money::zero();
            foreach ($entries as $entry) {
                $headDebit = $headDebit->add(Money::from((string) ($entry['debit'] ?? '0')));
                $headCredit = $headCredit->add(Money::from((string) ($entry['credit'] ?? '0')));
            }
            if (!$headDebit->isPositive() || $headDebit->compare($headCredit) !== 0) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_UNBALANCED');
            }
            if ($headDebit->compare(Money::from((string) ($head['total'] ?? '0'))) !== 0) {
                throw new RuntimeException('ORDER_FINANCIAL_JOURNAL_TOTAL_MISMATCH');
            }
            $bundleDebit = $bundleDebit->add($headDebit);
            $bundleCredit = $bundleCredit->add($headCredit);
        }

        $declared = $bundle['totals'];
        if (
            (int) ($declared['journal_count'] ?? -1) !== count($heads)
            || (int) ($declared['entry_count'] ?? -1) !== count($bundle['journal_entries'])
            || $bundleDebit->compare(Money::from((string) ($declared['debit'] ?? '0'))) !== 0
            || $bundleCredit->compare(Money::from((string) ($declared['credit'] ?? '0'))) !== 0
        ) {
            throw new RuntimeException('ORDER_FINANCIAL_TOTALS_INVALID');
        }

        $expectedHash = trim((string) ($bundle['bundle_hash'] ?? ''));
        $hashPayload = $bundle;
        unset($hashPayload['bundle_hash']);
        $actualHash = hash('sha256', self::encodeStaticJson($hashPayload));
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('ORDER_FINANCIAL_BUNDLE_HASH_INVALID');
        }

        return [
            'journal_count' => count($heads),
            'entry_count' => count($bundle['journal_entries']),
            'account_count' => count($accounts),
            'debit' => $bundleDebit->toString(),
            'credit' => $bundleCredit->toString(),
        ];
    }

    public static function assertFinancialSnapshotScope(array $payload, int $orderId, string $orderUuid): void
    {
        $normalizedOrderUuid = strtolower(trim($orderUuid));
        if ($orderId <= 0 || $normalizedOrderUuid === '') {
            throw new RuntimeException('ORDER_FINANCIAL_SNAPSHOT_SCOPE_INVALID');
        }
        foreach (['payments', 'receipts'] as $key) {
            if (array_key_exists($key, $payload) && !is_array($payload[$key])) {
                throw new RuntimeException('ORDER_FINANCIAL_SNAPSHOT_SHAPE_INVALID');
            }
        }

        $receiptAmounts = [];
        $receiptUuids = [];
        foreach ($payload['receipts'] ?? [] as $receipt) {
            if (!is_array($receipt)) {
                throw new RuntimeException('ORDER_FINANCIAL_RECEIPT_INVALID');
            }
            $receiptId = (int) ($receipt['local_receipt_id'] ?? 0);
            $receiptUuid = strtolower(trim((string) ($receipt['receipt_uuid'] ?? '')));
            $receiptOrderUuid = strtolower(trim((string) ($receipt['order_uuid'] ?? '')));
            if (
                $receiptId <= 0
                || isset($receiptAmounts[$receiptId])
                || $receiptUuid === ''
                || isset($receiptUuids[$receiptUuid])
                || (int) ($receipt['local_order_id'] ?? 0) !== $orderId
                || $receiptOrderUuid !== $normalizedOrderUuid
            ) {
                throw new RuntimeException('ORDER_FINANCIAL_RECEIPT_SCOPE_INVALID');
            }
            $receiptUuids[$receiptUuid] = true;
            $receiptAmounts[$receiptId] = Money::from((string) ($receipt['amount'] ?? '0'))->toString();
        }

        $receiptPaymentAmounts = [];
        $paymentKeys = [];
        $paymentUuids = [];
        foreach ($payload['payments'] ?? [] as $payment) {
            if (!is_array($payment)) {
                throw new RuntimeException('ORDER_FINANCIAL_PAYMENT_INVALID');
            }
            $source = strtolower(trim((string) ($payment['source'] ?? '')));
            $paymentId = (int) ($payment['local_payment_id'] ?? 0);
            $paymentUuid = strtolower(trim((string) ($payment['payment_uuid'] ?? '')));
            $paymentOrderUuid = strtolower(trim((string) ($payment['order_uuid'] ?? '')));
            $key = $source . ':' . $paymentId;
            if (
                !in_array($source, ['ot_head', 'order_payments'], true)
                || $paymentId <= 0
                || isset($paymentKeys[$key])
                || $paymentUuid === ''
                || isset($paymentUuids[$paymentUuid])
                || $paymentOrderUuid !== $normalizedOrderUuid
            ) {
                throw new RuntimeException('ORDER_FINANCIAL_PAYMENT_SCOPE_INVALID');
            }
            $paymentKeys[$key] = true;
            $paymentUuids[$paymentUuid] = true;
            if ($source === 'ot_head') {
                $receiptPaymentAmounts[$paymentId] = Money::from((string) ($payment['amount'] ?? '0'))->toString();
            }
        }

        ksort($receiptAmounts, SORT_NUMERIC);
        ksort($receiptPaymentAmounts, SORT_NUMERIC);
        if ($receiptAmounts !== $receiptPaymentAmounts) {
            throw new RuntimeException('ORDER_FINANCIAL_RECEIPT_PAYMENT_MISMATCH');
        }
    }

    public static function assertFulfillmentSnapshotScope(array $payload, int $orderId): ?array
    {
        $schemaVersion = (int) ($payload['schema_version'] ?? 1);
        if ($schemaVersion < 3 && !array_key_exists('fulfillment', $payload)) {
            return null;
        }
        if (!array_key_exists('fulfillment', $payload)) {
            throw new RuntimeException('ORDER_FULFILLMENT_SNAPSHOT_REQUIRED');
        }

        $fulfillment = $payload['fulfillment'];
        if ($fulfillment === null) {
            return null;
        }
        if (!is_array($fulfillment)) {
            throw new RuntimeException('ORDER_FULFILLMENT_SNAPSHOT_INVALID');
        }

        $allowed = [
            'id', 'order_id', 'order_channel', 'fulfillment_type', 'external_provider',
            'external_order_id', 'customer_name', 'customer_phone', 'customer_address',
            'delivery_client_id', 'pos_customer_id', 'delivery_zone', 'delivery_fee',
            'delivery_status', 'promised_at', 'crm_rollup_paid_amount',
            'crm_rollup_counted', 'metadata', 'created_at', 'updated_at',
        ];
        if (array_diff(array_keys($fulfillment), $allowed) !== []
            || (int) ($fulfillment['id'] ?? 0) < 1
            || (int) ($fulfillment['order_id'] ?? 0) !== $orderId
        ) {
            throw new RuntimeException('ORDER_FULFILLMENT_SCOPE_INVALID');
        }

        $channels = ['cashier', 'waiter', 'moova_qr', 'moova_delivery', 'call_center', 'online', 'kiosk', 'import'];
        $types = ['takeaway', 'table', 'delivery', 'pickup', 'staff_meal', 'waste'];
        $statuses = ['none', 'pending', 'accepted', 'preparing', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed'];
        if (!in_array((string) ($fulfillment['order_channel'] ?? ''), $channels, true)
            || !in_array((string) ($fulfillment['fulfillment_type'] ?? ''), $types, true)
            || !in_array((string) ($fulfillment['delivery_status'] ?? ''), $statuses, true)
        ) {
            throw new RuntimeException('ORDER_FULFILLMENT_STATE_INVALID');
        }

        foreach (['delivery_client_id', 'pos_customer_id'] as $identityField) {
            if (($fulfillment[$identityField] ?? null) !== null && (int) $fulfillment[$identityField] < 1) {
                throw new RuntimeException('ORDER_FULFILLMENT_CUSTOMER_ID_INVALID');
            }
        }
        if ((int) ($fulfillment['crm_rollup_counted'] ?? 0) < 0
            || (int) ($fulfillment['crm_rollup_counted'] ?? 0) > 1
        ) {
            throw new RuntimeException('ORDER_FULFILLMENT_ROLLUP_INVALID');
        }

        $metadata = $fulfillment['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw new RuntimeException('ORDER_FULFILLMENT_METADATA_INVALID');
        }
        if ($metadata !== null) {
            self::assertSafeFulfillmentMetadata($metadata);
            $encoded = self::encodeStaticJson($metadata);
            if (strlen($encoded) > 16384) {
                throw new RuntimeException('ORDER_FULFILLMENT_METADATA_TOO_LARGE');
            }
        }

        return $fulfillment;
    }

    private static function assertSafeFulfillmentMetadata(array $metadata, int $depth = 0): void
    {
        if ($depth > 6) {
            throw new RuntimeException('ORDER_FULFILLMENT_METADATA_TOO_DEEP');
        }
        $forbidden = [
            'authorization', 'cookie', 'password', 'pin', 'secret', 'token',
            'request_payload', 'response_payload', 'raw_payload', 'last_pos_state_payload',
        ];
        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if (in_array($normalizedKey, $forbidden, true)) {
                throw new RuntimeException('ORDER_FULFILLMENT_METADATA_SENSITIVE');
            }
            if (is_array($value)) {
                self::assertSafeFulfillmentMetadata($value, $depth + 1);
            } elseif (!is_null($value) && !is_scalar($value)) {
                throw new RuntimeException('ORDER_FULFILLMENT_METADATA_INVALID');
            }
        }
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
            'pro_value' => $this->decimalString($order['pro_value'] ?? null, 4),
            'fat_total' => $this->decimalString($order['fat_total'] ?? null, 4),
            'fat_net' => $this->decimalString($order['fat_net'] ?? null, 4),
            'fat_disc' => $this->decimalString($order['fat_disc'] ?? null, 4),
            'fat_tax' => $this->decimalString($order['fat_tax'] ?? null, 4),
            'profit' => $this->decimalString($order['profit'] ?? null, 6),
            'paid_amount' => $this->decimalString($order['paid_amount'] ?? null, 4),
            'remaining_amount' => $this->decimalString($order['remaining_amount'] ?? null, 4),
            'payment_status' => $this->nullableString($order['payment_status'] ?? null),
            'invoice_status' => $this->nullableString($order['invoice_status'] ?? null),
            'order_status' => $this->nullableString($order['order_status'] ?? null),
            'mutation_version' => max(1, (int) ($order['mutation_version'] ?? 1)),
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
        $hasItemsTable = $this->tableExists($conn, 'myitems');
        $itemNameSelect = $hasItemsTable && $this->columnExists($conn, 'myitems', 'iname')
            ? 'mi.iname AS item_name'
            : 'NULL AS item_name';
        $itemBarcodeSelect = $hasItemsTable && $this->columnExists($conn, 'myitems', 'barcode')
            ? 'mi.barcode AS item_barcode'
            : 'NULL AS item_barcode';
        $itemJoin = $hasItemsTable ? 'LEFT JOIN myitems mi ON mi.id = fd.item_id' : '';
        $stmt = $conn->prepare("
            SELECT fd.*,
                   {$itemNameSelect},
                   {$itemBarcodeSelect}
            FROM fat_details fd
            {$itemJoin}
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
            $kitchenSnapshot = $this->lineKitchenSnapshot($conn, $orderId, $localLineId);
            if ($kitchenSnapshot !== null) {
                $modifiers = is_array($kitchenSnapshot['payload']['modifiers'] ?? null)
                    ? $kitchenSnapshot['payload']['modifiers']
                    : [];
                $notes = is_array($kitchenSnapshot['payload']['notes'] ?? null)
                    ? $kitchenSnapshot['payload']['notes']
                    : [];
            }
            $lines[] = [
                'line_uuid' => self::deterministicUuid($branchUuid, 'fat_details:' . $localLineId),
                'order_uuid' => $orderUuid,
                'local_line_id' => $localLineId,
                'item_id' => $itemId,
                'item_uuid' => $itemId ? self::deterministicUuid($branchUuid, 'myitems:' . $itemId) : null,
                'item_name' => $this->nullableString($kitchenSnapshot['payload']['name'] ?? ($row['item_name'] ?? null)),
                'barcode' => $this->nullableString($row['item_barcode'] ?? null),
                'qty_in' => $this->decimalString($row['qty_in'] ?? null, 6),
                'qty_out' => $this->decimalString($row['qty_out'] ?? null, 6),
                'price' => $this->decimalString($row['price'] ?? null, 6),
                'cost_price' => $this->decimalString($row['cost_price'] ?? null, 6),
                'discount' => $this->decimalString($row['discount'] ?? null, 4),
                'det_value' => $this->decimalString($row['det_value'] ?? null, 4),
                'profit' => $this->decimalString($row['profit'] ?? null, 6),
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
                'modifiers' => $modifiers,
                'notes' => $notes,
                'preparation_values' => is_array($kitchenSnapshot['payload']['preparation_values'] ?? null)
                    ? $kitchenSnapshot['payload']['preparation_values']
                    : [],
                'kitchen_snapshot' => $kitchenSnapshot,
            ];
        }
        $stmt->close();

        return $lines;
    }

    private function lineKitchenSnapshot(mysqli $conn, int $orderId, int $detailId): ?array
    {
        if (!$this->tableExists($conn, 'order_line_kitchen_snapshots')) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT display_order, snapshot_version, snapshot_hash, payload_json, created_at
            FROM order_line_kitchen_snapshots
            WHERE order_id = ? AND detail_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('ORDER_KITCHEN_SNAPSHOT_INVALID');
        }
        $actualHash = hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '');
        if (!hash_equals((string) $row['snapshot_hash'], $actualHash)) {
            throw new RuntimeException('ORDER_KITCHEN_SNAPSHOT_HASH_INVALID');
        }

        return [
            'display_order' => (int) $row['display_order'],
            'snapshot_version' => (int) $row['snapshot_version'],
            'snapshot_hash' => (string) $row['snapshot_hash'],
            'created_at' => (string) $row['created_at'],
            'payload' => $payload,
        ];
    }

    private function fulfillmentPayload(mysqli $conn, int $orderId): ?array
    {
        if (!$this->tableExists($conn, 'order_fulfillment')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT * FROM order_fulfillment WHERE order_id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $metadata = null;
        if (($row['metadata_json'] ?? null) !== null && trim((string) $row['metadata_json']) !== '') {
            $metadata = json_decode((string) $row['metadata_json'], true);
            if (!is_array($metadata)) {
                throw new RuntimeException('ORDER_FULFILLMENT_METADATA_INVALID');
            }
        }

        $payload = $this->selectFields($row, [
            'id', 'order_id', 'order_channel', 'fulfillment_type', 'external_provider',
            'external_order_id', 'customer_name', 'customer_phone', 'customer_address',
            'delivery_client_id', 'pos_customer_id', 'delivery_zone', 'delivery_fee',
            'delivery_status', 'promised_at', 'crm_rollup_paid_amount',
            'crm_rollup_counted', 'created_at', 'updated_at',
        ]);
        $payload['metadata'] = $metadata;
        self::assertFulfillmentSnapshotScope([
            'schema_version' => 3,
            'fulfillment' => $payload,
        ], $orderId);

        return $payload;
    }

    private function kitchenEventPayloads(mysqli $conn, int $orderId): array
    {
        if (!$this->tableExists($conn, 'kds_order_events')) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT uuid, idempotency_key, station_id, ticket_id, kitchen_revision, event_type,
                   status, before_snapshot_json, after_snapshot_json, reason, actor_user_id,
                   approval_id, version, delivered_at, acknowledged_at, acknowledged_by, created_at
            FROM kds_order_events
            WHERE order_id = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        while ($row = $result->fetch_assoc()) {
            $before = json_decode((string) $row['before_snapshot_json'], true);
            $after = json_decode((string) $row['after_snapshot_json'], true);
            if (!is_array($before) || !is_array($after)) {
                $stmt->close();
                throw new RuntimeException('ORDER_KITCHEN_EVENT_SNAPSHOT_INVALID');
            }
            $events[] = [
                'uuid' => (string) $row['uuid'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'order_id' => $orderId,
                'station_id' => (int) $row['station_id'],
                'ticket_id' => isset($row['ticket_id']) ? (int) $row['ticket_id'] : null,
                'kitchen_revision' => (int) $row['kitchen_revision'],
                'event_type' => (string) $row['event_type'],
                'status' => (string) $row['status'],
                'before' => array_values($before),
                'after' => array_values($after),
                'reason' => (string) ($row['reason'] ?? ''),
                'actor_user_id' => (int) $row['actor_user_id'],
                'approval_id' => isset($row['approval_id']) ? (int) $row['approval_id'] : null,
                'version' => (int) $row['version'],
                'delivered_at' => (string) ($row['delivered_at'] ?? ''),
                'acknowledged_at' => (string) ($row['acknowledged_at'] ?? ''),
                'acknowledged_by' => isset($row['acknowledged_by']) ? (int) $row['acknowledged_by'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        $stmt->close();

        return $events;
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
            $qty = RecipeDecimal::normalize($row['qty'] ?? '0', 6);
            $priceDelta = RecipeDecimal::normalize($row['price_delta'] ?? '0', 6);
            $modifiers[] = [
                'modifier_group_id' => $this->nullableInt($row['modifier_group_id'] ?? null),
                'modifier_option_id' => $this->nullableInt($row['modifier_option_id'] ?? null),
                'option_id' => $this->nullableInt($row['modifier_option_id'] ?? null),
                'qty' => $this->decimalString($qty, 6),
                'price_delta' => $this->decimalString($priceDelta, 6),
                'line_delta' => $this->decimalString(RecipeDecimal::multiply($qty, $priceDelta, 4), 4),
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
                'source' => $source,
                'local_payment_id' => $localPaymentId,
                'amount' => $this->decimalString($row['pro_value'] ?? ($row['amount'] ?? null), 4),
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
            $paymentDate = $this->datetimeOrNull($row['payment_date'] ?? null);
            if ($paymentDate === null) {
                $proDate = $this->dateOrNull($row['pro_date'] ?? null);
                $paymentDate = $proDate !== null ? $proDate . ' 00:00:00' : null;
            }
            $receipts[] = [
                'receipt_uuid' => self::deterministicUuid($branchUuid, 'payment_receipt:' . $localReceiptId),
                'order_uuid' => $orderUuid,
                'local_receipt_id' => $localReceiptId,
                'local_order_id' => $orderId,
                'pro_id' => $this->nullableString($row['pro_id'] ?? null),
                'pro_tybe' => $this->nullableInt($row['pro_tybe'] ?? null),
                'amount' => $this->decimalString($row['pro_value'] ?? null, 4),
                'acc_fund' => $this->nullableInt($row['acc1'] ?? null),
                'payment_method' => $this->paymentMethod($row),
                'payment_date' => $paymentDate,
                'acc_customer' => $this->nullableInt($row['acc2'] ?? null),
                'employee_id' => $this->nullableInt($row['emp_id'] ?? null),
                'created_by' => $this->nullableInt($row['user'] ?? null),
                'info' => $this->nullableString($row['info'] ?? null),
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
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
        // Legacy receipt rows share ot_head and are linked through op2. Newer
        // minimal/cutover schemas may have only order_payments; absence of the
        // legacy column means there are no legacy receipt rows to collect.
        if (!$this->columnExists($conn, 'ot_head', 'op2')) {
            return [];
        }

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

    private function financialBundle(mysqli $conn, int $orderId): array
    {
        $heads = $this->orderJournalHeads($conn, $orderId);
        $headIds = array_values(array_map('intval', array_column($heads, 'id')));
        $entries = $this->journalEntries($conn, $headIds);
        $accountIds = [];
        foreach ($entries as $entry) {
            $accountId = (int) ($entry['account_id'] ?? 0);
            if ($accountId > 0) {
                $accountIds[$accountId] = $accountId;
            }
        }
        $accounts = $this->accountIdentitiesWithAncestors($conn, array_values($accountIds));

        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($entries as $entry) {
            $debit = $debit->add(Money::from((string) ($entry['debit'] ?? '0')));
            $credit = $credit->add(Money::from((string) ($entry['credit'] ?? '0')));
        }

        $bundle = [
            'schema_version' => 1,
            'scope' => 'pos_order',
            'complete' => true,
            'local_order_id' => $orderId,
            'accounts' => $accounts,
            'journal_heads' => $heads,
            'journal_entries' => $entries,
            'totals' => [
                'journal_count' => count($heads),
                'entry_count' => count($entries),
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
            ],
        ];
        $bundle['bundle_hash'] = hash('sha256', self::encodeStaticJson($bundle));
        self::assertFinancialBundle($bundle, $orderId);

        return $bundle;
    }

    private function orderJournalHeads(mysqli $conn, int $orderId): array
    {
        if (!$this->tableExists($conn, 'journal_heads') || !$this->tableExists($conn, 'journal_entries')) {
            return [];
        }

        $headHasOp2 = $this->columnExists($conn, 'journal_heads', 'op2');
        $headHasOpId = $this->columnExists($conn, 'journal_heads', 'op_id');
        $entryLinks = [];
        if ($this->columnExists($conn, 'journal_entries', 'op2')) {
            $entryLinks[] = 'COALESCE(je.op2, 0) = ' . $orderId;
        }
        if ($this->columnExists($conn, 'journal_entries', 'op_id')) {
            $entryLinks[] = 'COALESCE(je.op_id, 0) = ' . $orderId;
        }

        $scope = [];
        if ($headHasOp2) {
            $scope[] = 'COALESCE(h.op2, 0) = ' . $orderId;
        }
        if ($headHasOpId && $entryLinks !== []) {
            $scope[] = '(COALESCE(h.op_id, 0) = ' . $orderId
                . ' AND EXISTS (SELECT 1 FROM journal_entries je WHERE je.journal_id = h.id AND ('
                . implode(' OR ', $entryLinks) . ')))';
        }
        if ($scope === []) {
            return [];
        }

        $sourceFilter = '';
        if ($this->columnExists($conn, 'journal_heads', 'source_type')) {
            $sourceFilter = " AND COALESCE(h.source_type, '') IN ('', 'invoice', 'payment')";
        }
        $result = $conn->query(
            'SELECT h.* FROM journal_heads h WHERE (' . implode(' OR ', $scope) . ')' . $sourceFilter . ' ORDER BY h.id ASC'
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->selectFields($row, [
                'id', 'journal_id', 'total', 'jdate', 'op_id', 'pro_tybe', 'details', 'op2',
                'isdeleted', 'user', 'tenant', 'branch', 'source_type', 'source_id',
                'posting_kind', 'idempotency_key', 'reversal_of_journal_id',
            ]);
        }

        return $rows;
    }

    private function journalEntries(mysqli $conn, array $headIds): array
    {
        if ($headIds === []) {
            return [];
        }
        $ids = implode(', ', array_map('intval', $headIds));
        $result = $conn->query("SELECT * FROM journal_entries WHERE journal_id IN ({$ids}) ORDER BY id ASC");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->selectFields($row, [
                'id', 'journal_id', 'account_id', 'debit', 'credit', 'tybe', 'op2', 'op_id',
                'isdeleted', 'tenant', 'branch',
            ]);
        }

        return $rows;
    }

    private function accountIdentitiesWithAncestors(mysqli $conn, array $accountIds): array
    {
        if ($accountIds === [] || !$this->tableExists($conn, 'acc_head')) {
            return [];
        }

        $accounts = [];
        $pending = array_fill_keys(array_map('intval', $accountIds), true);
        while ($pending !== []) {
            $ids = implode(', ', array_map('intval', array_keys($pending)));
            $pending = [];
            $result = $conn->query("SELECT * FROM acc_head WHERE id IN ({$ids}) ORDER BY id ASC");
            while ($row = $result->fetch_assoc()) {
                $accountId = (int) ($row['id'] ?? 0);
                if ($accountId <= 0 || isset($accounts[$accountId])) {
                    continue;
                }
                $accounts[$accountId] = $this->selectFields($row, [
                    'id', 'code', 'deletable', 'editable', 'aname', 'constant', 'is_stock',
                    'is_fund', 'rentable', 'parent_id', 'nature', 'kind', 'is_basic', 'secret',
                    'isdeleted', 'tenant', 'branch',
                ]);
                $parentId = (int) ($row['parent_id'] ?? 0);
                if ($parentId > 0 && !isset($accounts[$parentId])) {
                    $pending[$parentId] = true;
                }
            }
        }
        ksort($accounts, SORT_NUMERIC);

        return array_values($accounts);
    }

    private function selectFields(array $row, array $allowed): array
    {
        $selected = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $row)) {
                $selected[$field] = $row[$field];
            }
        }

        return $selected;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        if (!$this->tableExists($conn, $table)) {
            return false;
        }

        $escapedTable = $conn->real_escape_string($table);
        $escapedColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");

        return $result && $result->num_rows > 0;
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
        if (isset($order['mutation_version']) && (int) $order['mutation_version'] > 0) {
            return (int) $order['mutation_version'];
        }
        if (isset($order['kitchen_revision']) && (int) $order['kitchen_revision'] > 0) {
            return (int) $order['kitchen_revision'];
        }

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

    private static function encodeStaticJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode POS order financial bundle.');
        }

        return $json;
    }

    private function decimalString($value, int $scale = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $text)) {
            return null;
        }

        return RecipeDecimal::normalize($text, $scale);
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
