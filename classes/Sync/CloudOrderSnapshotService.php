<?php

require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class CloudOrderSnapshotService
{
    public function upsertFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        if (!$this->isOrderEvent($event)) {
            return null;
        }

        $payload = $this->payload($event);
        $order = $this->orderPayload($payload);
        $orderUuid = $this->orderUuid($event, $payload, $order);
        if ($orderUuid === null) {
            throw new InvalidArgumentException('Order sync event is missing order_uuid or aggregate_uuid.');
        }
        $this->assertFinancialPayload($payload, $order, $orderUuid);
        $fulfillment = $this->assertFulfillmentPayload($payload, $order);

        $payloadJson = $this->encodeJson($event);
        $payloadHash = $this->payloadHash($event, $payload);
        $eventUuid = $this->nullableString($event['event_uuid'] ?? null, 36);
        $sourceSystem = $this->nullableString($this->firstValue([$order, $payload, $event], 'source_system'), 40);
        if ($sourceSystem === null) {
            $sourceSystem = 'pos';
        }

        $params = [
            $branchUuid,
            $orderUuid,
            $this->intOrNull($this->firstExistingValue([$order, $payload, $event], ['local_order_id', 'order_id', 'id', 'aggregate_local_id', 'entity_local_id'])),
            $this->nullableString($this->firstValue([$order, $payload], 'pro_id'), 100),
            $this->intOrNull($this->firstValue([$order, $payload], 'pro_tybe')),
            $this->nullableString($this->firstValue([$order, $payload], 'order_type'), 50),
            $sourceSystem,
            $this->nullableString($this->firstValue([$order, $payload], 'source_external_id'), 191),
            $this->intOrNull($this->firstExistingValue([$order, $payload], ['cashier_user_id', 'user', 'user_id'])),
            $this->intOrNull($this->firstExistingValue([$order, $payload], ['waiter_id', 'emp2_id'])),
            $this->nullableUuid($this->firstValue([$order, $payload], 'table_uuid')),
            $this->intOrNull($this->firstValue([$order, $payload], 'table_id')),
            $this->nullableString($this->firstValue([$order, $payload], 'table_name'), 255),
            $this->datetimeOrNull($this->firstExistingValue([$order, $payload], ['pro_date', 'created_at'])),
            $this->datetimeOrNull($this->firstExistingValue([$order, $payload], ['completed_at', 'closed_at'])),
            $this->datetimeOrNull($this->firstValue([$order, $payload], 'payment_date')),
            $this->branchTimezone($order, $payload, $event),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'pro_value'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'fat_total'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'fat_net'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'fat_disc'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'fat_tax'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'profit'), 6),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'paid_amount'), 4),
            $this->nullableDecimal($this->firstValue([$order, $payload], 'remaining_amount'), 4),
            $this->nullableString($this->firstValue([$order, $payload], 'payment_status'), 50),
            $this->nullableString($this->firstValue([$order, $payload], 'invoice_status'), 50),
            $this->nullableString($this->firstValue([$order, $payload], 'order_status'), 50),
            $this->boolInt($this->firstValue([$order, $payload], 'isdeleted')),
            $this->boolInt($this->firstValue([$order, $payload], 'closed')),
            $this->intOrZero($this->firstExistingValue([$order, $payload, $event], ['sync_revision', 'revision', 'event_version'])),
            $payloadHash,
            $payloadJson,
            $eventUuid,
        ];

        $stmt = $conn->prepare("
            INSERT INTO cloud_orders (
                branch_uuid,
                order_uuid,
                local_order_id,
                pro_id,
                pro_tybe,
                order_type,
                source_system,
                source_external_id,
                cashier_user_id,
                waiter_id,
                table_uuid,
                table_id,
                table_name,
                pro_date,
                completed_at,
                payment_date,
                branch_timezone,
                pro_value,
                fat_total,
                fat_net,
                fat_disc,
                fat_tax,
                profit,
                paid_amount,
                remaining_amount,
                payment_status,
                invoice_status,
                order_status,
                isdeleted,
                closed,
                sync_revision,
                payload_hash,
                payload_json,
                last_event_uuid
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                local_order_id = VALUES(local_order_id),
                pro_id = VALUES(pro_id),
                pro_tybe = VALUES(pro_tybe),
                order_type = VALUES(order_type),
                source_system = VALUES(source_system),
                source_external_id = VALUES(source_external_id),
                cashier_user_id = VALUES(cashier_user_id),
                waiter_id = VALUES(waiter_id),
                table_uuid = VALUES(table_uuid),
                table_id = VALUES(table_id),
                table_name = VALUES(table_name),
                pro_date = VALUES(pro_date),
                completed_at = VALUES(completed_at),
                payment_date = VALUES(payment_date),
                branch_timezone = VALUES(branch_timezone),
                pro_value = VALUES(pro_value),
                fat_total = VALUES(fat_total),
                fat_net = VALUES(fat_net),
                fat_disc = VALUES(fat_disc),
                fat_tax = VALUES(fat_tax),
                profit = VALUES(profit),
                paid_amount = VALUES(paid_amount),
                remaining_amount = VALUES(remaining_amount),
                payment_status = VALUES(payment_status),
                invoice_status = VALUES(invoice_status),
                order_status = VALUES(order_status),
                isdeleted = VALUES(isdeleted),
                closed = VALUES(closed),
                sync_revision = VALUES(sync_revision),
                payload_hash = VALUES(payload_hash),
                payload_json = VALUES(payload_json),
                last_event_uuid = VALUES(last_event_uuid),
                last_received_at = NOW(6)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $cloudOrderId = (int) $conn->insert_id;
        $stmt->close();

        $lineCount = $this->upsertLines($conn, $branchUuid, $orderUuid, $payload, $order);
        $paymentCount = $this->upsertPayments($conn, $branchUuid, $orderUuid, $payload, $order);
        $receiptCount = $this->upsertReceipts($conn, $branchUuid, $orderUuid, $payload, $order);

        return [
            'cloud_order_id' => $cloudOrderId,
            'order_uuid' => $orderUuid,
            'line_count' => $lineCount,
            'payment_count' => $paymentCount,
            'receipt_count' => $receiptCount,
            'fulfillment_count' => $fulfillment === null ? 0 : 1,
        ];
    }

    private function assertFulfillmentPayload(array $payload, array $order): ?array
    {
        if ((int) ($payload['schema_version'] ?? 1) < 3 && !array_key_exists('fulfillment', $payload)) {
            return null;
        }
        $orderId = (int) ($order['local_order_id'] ?? $payload['local_order_id'] ?? 0);
        if ($orderId < 1) {
            throw new RuntimeException('ORDER_FULFILLMENT_SCOPE_INVALID');
        }

        return PosOrderSnapshotBuilder::assertFulfillmentSnapshotScope($payload, $orderId);
    }

    private function assertFinancialPayload(array $payload, array $order, string $orderUuid): void
    {
        $schemaVersion = (int) ($payload['schema_version'] ?? 1);
        $hasBundle = array_key_exists('financial_bundle', $payload);
        if ($schemaVersion < 2 && !$hasBundle) {
            return;
        }

        $bundle = $payload['financial_bundle'] ?? null;
        $orderId = (int) ($order['local_order_id'] ?? $payload['local_order_id'] ?? 0);
        if (!is_array($bundle) || $orderId <= 0) {
            throw new RuntimeException('ORDER_FINANCIAL_BUNDLE_REQUIRED');
        }
        PosOrderSnapshotBuilder::assertFinancialSnapshotScope($payload, $orderId, $orderUuid);
        PosOrderSnapshotBuilder::assertFinancialBundle($bundle, $orderId);
    }

    private function upsertLines(mysqli $conn, string $branchUuid, string $orderUuid, array $payload, array $order): int
    {
        $count = 0;
        foreach ($this->listFromPayload($payload, $order, ['lines', 'order_lines', 'items']) as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineUuid = $this->nullableUuid($this->firstExistingValue([$line], ['line_uuid', 'local_uuid', 'uuid']));
            if ($lineUuid === null) {
                continue;
            }

            $payloadJson = $this->encodeJson($line);
            $params = [
                $branchUuid,
                $orderUuid,
                $lineUuid,
                $this->intOrNull($this->firstExistingValue([$line], ['local_line_id', 'line_id', 'det_id', 'id'])),
                $this->intOrNull($this->firstValue([$line], 'item_id')),
                $this->nullableUuid($this->firstValue([$line], 'item_uuid')),
                $this->nullableString($this->firstExistingValue([$line], ['item_name', 'name']), 255),
                $this->nullableString($this->firstValue([$line], 'barcode'), 191),
                $this->nullableDecimal($this->firstValue([$line], 'qty_in'), 6),
                $this->nullableDecimal($this->firstValue([$line], 'qty_out'), 6),
                $this->nullableDecimal($this->firstValue([$line], 'price'), 6),
                $this->nullableDecimal($this->firstValue([$line], 'cost_price'), 6),
                $this->nullableDecimal($this->firstExistingValue([$line], ['discount', 'disc']), 4),
                $this->nullableDecimal($this->firstExistingValue([$line], ['det_value', 'line_total']), 4),
                $this->nullableDecimal($this->firstValue([$line], 'profit'), 6),
                $this->boolInt($this->firstValue([$line], 'isdeleted')),
                $this->rowPayloadHash($line, $payloadJson),
                $payloadJson,
            ];

            $stmt = $conn->prepare("
                INSERT INTO cloud_order_lines (
                    branch_uuid,
                    order_uuid,
                    line_uuid,
                    local_line_id,
                    item_id,
                    item_uuid,
                    item_name,
                    barcode,
                    qty_in,
                    qty_out,
                    price,
                    cost_price,
                    discount,
                    det_value,
                    profit,
                    isdeleted,
                    payload_hash,
                    payload_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    order_uuid = VALUES(order_uuid),
                    local_line_id = VALUES(local_line_id),
                    item_id = VALUES(item_id),
                    item_uuid = VALUES(item_uuid),
                    item_name = VALUES(item_name),
                    barcode = VALUES(barcode),
                    qty_in = VALUES(qty_in),
                    qty_out = VALUES(qty_out),
                    price = VALUES(price),
                    cost_price = VALUES(cost_price),
                    discount = VALUES(discount),
                    det_value = VALUES(det_value),
                    profit = VALUES(profit),
                    isdeleted = VALUES(isdeleted),
                    payload_hash = VALUES(payload_hash),
                    payload_json = VALUES(payload_json)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
            $count++;
        }

        return $count;
    }

    private function upsertPayments(mysqli $conn, string $branchUuid, string $orderUuid, array $payload, array $order): int
    {
        $count = 0;
        foreach ($this->listFromPayload($payload, $order, ['payments', 'order_payments']) as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $paymentUuid = $this->nullableUuid($this->firstExistingValue([$payment], ['payment_uuid', 'local_uuid', 'uuid']));
            if ($paymentUuid === null) {
                continue;
            }

            $payloadJson = $this->encodeJson($payment);
            $params = [
                $branchUuid,
                $orderUuid,
                $paymentUuid,
                $this->intOrNull($this->firstExistingValue([$payment], ['local_payment_id', 'payment_id', 'id'])),
                $this->decimal($this->firstExistingValue([$payment], ['amount', 'pro_value', 'paid_amount'])),
                $this->nullableString($this->firstExistingValue([$payment], ['payment_method', 'method']), 50),
                $this->nullableString($this->firstExistingValue([$payment], ['reference_no', 'reference', 'ref_no']), 191),
                $this->intOrNull($this->firstValue([$payment], 'paid_by_customer_id')),
                $this->intOrNull($this->firstExistingValue([$payment], ['created_by', 'user_id', 'user'])),
                $this->boolInt($this->firstValue([$payment], 'voided')),
                $this->rowPayloadHash($payment, $payloadJson),
                $payloadJson,
            ];

            $stmt = $conn->prepare("
                INSERT INTO cloud_order_payments (
                    branch_uuid,
                    order_uuid,
                    payment_uuid,
                    local_payment_id,
                    amount,
                    payment_method,
                    reference_no,
                    paid_by_customer_id,
                    created_by,
                    voided,
                    payload_hash,
                    payload_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    order_uuid = VALUES(order_uuid),
                    local_payment_id = VALUES(local_payment_id),
                    amount = VALUES(amount),
                    payment_method = VALUES(payment_method),
                    reference_no = VALUES(reference_no),
                    paid_by_customer_id = VALUES(paid_by_customer_id),
                    created_by = VALUES(created_by),
                    voided = VALUES(voided),
                    payload_hash = VALUES(payload_hash),
                    payload_json = VALUES(payload_json)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
            $count++;
        }

        return $count;
    }

    private function upsertReceipts(mysqli $conn, string $branchUuid, string $orderUuid, array $payload, array $order): int
    {
        $count = 0;
        foreach ($this->listFromPayload($payload, $order, ['receipts', 'payment_receipts']) as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }

            $receiptUuid = $this->nullableUuid($this->firstExistingValue([$receipt], ['receipt_uuid', 'local_uuid', 'uuid']));
            if ($receiptUuid === null) {
                continue;
            }

            $payloadJson = $this->encodeJson($receipt);
            $receiptOrderUuid = $this->nullableUuid($this->firstValue([$receipt], 'order_uuid')) ?: $orderUuid;
            $params = [
                $branchUuid,
                $receiptUuid,
                $receiptOrderUuid,
                $this->intOrNull($this->firstExistingValue([$receipt], ['local_receipt_id', 'receipt_id', 'id'])),
                $this->intOrNull($this->firstExistingValue([$receipt, $order], ['local_order_id', 'order_id'])),
                $this->nullableString($this->firstValue([$receipt], 'pro_id'), 100),
                $this->intOrNull($this->firstValue([$receipt], 'pro_tybe')),
                $this->decimal($this->firstExistingValue([$receipt], ['amount', 'pro_value'])),
                $this->intOrNull($this->firstValue([$receipt], 'acc_fund')),
                $this->nullableString($this->firstExistingValue([$receipt], ['payment_method', 'method']), 50),
                $this->datetimeOrNull($this->firstValue([$receipt], 'payment_date')),
                $this->rowPayloadHash($receipt, $payloadJson),
                $payloadJson,
            ];

            $stmt = $conn->prepare("
                INSERT INTO cloud_payment_receipts (
                    branch_uuid,
                    receipt_uuid,
                    order_uuid,
                    local_receipt_id,
                    local_order_id,
                    pro_id,
                    pro_tybe,
                    amount,
                    acc_fund,
                    payment_method,
                    payment_date,
                    payload_hash,
                    payload_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    order_uuid = VALUES(order_uuid),
                    local_receipt_id = VALUES(local_receipt_id),
                    local_order_id = VALUES(local_order_id),
                    pro_id = VALUES(pro_id),
                    pro_tybe = VALUES(pro_tybe),
                    amount = VALUES(amount),
                    acc_fund = VALUES(acc_fund),
                    payment_method = VALUES(payment_method),
                    payment_date = VALUES(payment_date),
                    payload_hash = VALUES(payload_hash),
                    payload_json = VALUES(payload_json)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
            $count++;
        }

        return $count;
    }

    private function isOrderEvent(array $event): bool
    {
        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        $entityType = strtolower(trim((string) ($event['entity_type'] ?? '')));
        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if ($aggregateType === 'order' || $entityType === 'order' || strpos($eventType, 'order.') === 0) {
            return true;
        }

        $payload = $this->payload($event);
        $order = $this->orderPayload($payload);

        return $this->firstExistingValue([$order, $payload], ['order_uuid', 'pos_order_uuid']) !== null;
    }

    private function payload(array $event): array
    {
        return isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    }

    private function orderPayload(array $payload): array
    {
        if (isset($payload['order']) && is_array($payload['order'])) {
            return $payload['order'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function orderUuid(array $event, array $payload, array $order): ?string
    {
        return $this->nullableUuid(
            $this->firstExistingValue([$order, $payload, $event], [
                'order_uuid',
                'pos_order_uuid',
                'entity_uuid',
                'aggregate_uuid',
            ])
        );
    }

    private function payloadHash(array $event, array $payload): string
    {
        $hash = $this->nullableString($event['payload_hash'] ?? null, 64);
        if ($hash !== null) {
            return $hash;
        }

        return hash('sha256', $this->encodeJson($payload ?: $event));
    }

    private function rowPayloadHash(array $row, string $payloadJson): string
    {
        $hash = $this->nullableString($row['payload_hash'] ?? null, 64);
        return $hash ?: hash('sha256', $payloadJson);
    }

    private function branchTimezone(array $order, array $payload, array $event): string
    {
        $timezone = $this->nullableString($this->firstValue([$order, $payload, $event], 'branch_timezone'), 100);
        return $timezone ?: 'Africa/Cairo';
    }

    private function firstExistingValue(array $sources, array $keys)
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return null;
    }

    private function firstValue(array $sources, string $key)
    {
        return $this->firstExistingValue($sources, [$key]);
    }

    private function listFromPayload(array $payload, array $order, array $keys): array
    {
        $list = $this->firstExistingValue([$order, $payload], $keys);
        return is_array($list) ? $list : [];
    }

    private function nullableUuid($value): ?string
    {
        $value = $this->nullableString($value, 36);
        if ($value === null) {
            return null;
        }

        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)
            ? strtolower($value)
            : null;
    }

    private function nullableString($value, int $maxLength): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '' || $value === false || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function intOrZero($value): int
    {
        $value = $this->intOrNull($value);
        return $value === null ? 0 : max(0, $value);
    }

    private function boolInt($value): int
    {
        if ($value === true) {
            return 1;
        }

        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return 0;
            }
        }

        return (int) ((bool) $value);
    }

    private function decimal($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function nullableDecimal($value, int $scale): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function datetimeOrNull($value): ?string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud order payload JSON.');
        }

        return $json;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }
}
