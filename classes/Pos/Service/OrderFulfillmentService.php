<?php

class OrderFulfillmentService
{
    private const CHANNELS = ['cashier', 'waiter', 'moova_qr', 'moova_delivery', 'call_center', 'online', 'kiosk', 'import'];
    private const FULFILLMENT_TYPES = ['takeaway', 'table', 'delivery', 'pickup', 'staff_meal', 'waste'];
    private const DELIVERY_STATUSES = ['none', 'pending', 'accepted', 'preparing', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed'];

    public function upsertMoovaFulfillment(mysqli $conn, int $orderId, array $payload, array $options = []): array
    {
        $data = $this->extractFromMoovaPayload($payload, $options + ['external_provider' => 'moova']);

        return $this->upsertForOrder($conn, $orderId, $data, [
            'require_table' => !empty($options['require_table']),
        ]);
    }

    public function extractFromMoovaPayload(array $payload, array $options = []): array
    {
        $delivery = isset($payload['delivery']) && is_array($payload['delivery']) ? $payload['delivery'] : [];
        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];
        if (isset($delivery['customer']) && is_array($delivery['customer'])) {
            $customer = $delivery['customer'] + $customer;
        }

        $externalProvider = $this->nullableText($this->firstNonEmpty($options, ['external_provider'])
            ?? $this->firstNonEmpty($payload, ['externalProvider', 'external_provider', 'provider', 'sourceSystem', 'source_system'])
            ?? 'moova', 40);
        $externalOrderId = $this->nullableText($this->firstNonEmpty($options, ['external_order_id'])
            ?? $this->firstNonEmpty($payload, [
                'externalOrderId',
                'external_order_id',
                'cofeOrderId',
                'cofe_order_id',
                'moovaOrderId',
                'moova_order_id',
                'orderId',
                'order_id',
            ]), 120);
        $fulfillmentType = $this->fulfillmentType($this->firstNonEmpty($options, ['fulfillment_type'])
            ?? $this->firstNonEmpty($payload, ['fulfillmentType', 'fulfillment_type', 'orderType', 'order_type', 'type']), $payload, $delivery);
        $orderChannel = $this->orderChannel($this->firstNonEmpty($options, ['order_channel'])
            ?? $this->firstNonEmpty($payload, ['orderChannel', 'order_channel', 'channel']), $fulfillmentType, (string) $externalProvider);
        $deliveryStatus = $this->deliveryStatus($this->firstNonEmpty($payload, ['deliveryStatus', 'delivery_status'])
            ?? $this->firstNonEmpty($delivery, ['status', 'deliveryStatus', 'delivery_status']), $fulfillmentType);

        return [
            'order_channel' => $orderChannel,
            'fulfillment_type' => $fulfillmentType,
            'external_provider' => $externalProvider,
            'external_order_id' => $externalOrderId,
            'customer_name' => $this->nullableText($this->firstNonEmpty($payload, ['customerName', 'customer_name', 'deliveryCustomerName', 'delivery_customer_name'])
                ?? $this->firstNonEmpty($customer, ['name', 'fullName', 'full_name']), 160),
            'customer_phone' => $this->nullableText($this->firstNonEmpty($payload, ['customerPhone', 'customer_phone', 'deliveryCustomerPhone', 'delivery_customer_phone'])
                ?? $this->firstNonEmpty($customer, ['phone', 'mobile', 'mobileNumber', 'mobile_number']), 60),
            'customer_address' => $this->nullableText($this->addressValue($payload, $delivery, $customer), 500),
            'delivery_zone' => $this->nullableText($this->firstNonEmpty($payload, ['deliveryZone', 'delivery_zone', 'zone'])
                ?? $this->firstNonEmpty($delivery, ['zone', 'deliveryZone', 'delivery_zone']), 120),
            'delivery_fee' => $this->decimal($this->firstNonEmpty($payload, ['deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee', 'deliveryCharge', 'delivery_charge'])
                ?? $this->firstNonEmpty($delivery, ['fee', 'deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee'])),
            'delivery_status' => $deliveryStatus,
            'promised_at' => $this->datetimeOrNull($this->firstNonEmpty($payload, ['promisedAt', 'promised_at', 'eta', 'deliveryEta', 'delivery_eta'])
                ?? $this->firstNonEmpty($delivery, ['promisedAt', 'promised_at', 'eta', 'deliveryEta', 'delivery_eta'])),
            'metadata_json' => $this->metadataJson($payload, $delivery, $orderChannel, $fulfillmentType),
        ];
    }

    public function upsertForOrder(mysqli $conn, int $orderId, array $data, array $options = []): array
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        if (!$this->tableExists($conn)) {
            if (!empty($options['require_table'])) {
                throw new RuntimeException('ORDER_FULFILLMENT_TABLE_MISSING');
            }

            return [
                'persisted' => false,
                'skipped' => true,
                'reason' => 'ORDER_FULFILLMENT_TABLE_MISSING',
                'order_id' => $orderId,
            ] + $this->normalizeData($data);
        }

        $data = $this->normalizeData($data);
        $stmt = $conn->prepare("
            INSERT INTO order_fulfillment (
                order_id, order_channel, fulfillment_type, external_provider, external_order_id,
                customer_name, customer_phone, customer_address, delivery_zone, delivery_fee,
                delivery_status, promised_at, metadata_json
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                order_channel = VALUES(order_channel),
                fulfillment_type = VALUES(fulfillment_type),
                external_provider = VALUES(external_provider),
                external_order_id = VALUES(external_order_id),
                customer_name = VALUES(customer_name),
                customer_phone = VALUES(customer_phone),
                customer_address = VALUES(customer_address),
                delivery_zone = VALUES(delivery_zone),
                delivery_fee = VALUES(delivery_fee),
                delivery_status = VALUES(delivery_status),
                promised_at = VALUES(promised_at),
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param(
            'issssssssdsss',
            $orderId,
            $data['order_channel'],
            $data['fulfillment_type'],
            $data['external_provider'],
            $data['external_order_id'],
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_address'],
            $data['delivery_zone'],
            $data['delivery_fee'],
            $data['delivery_status'],
            $data['promised_at'],
            $data['metadata_json']
        );
        $stmt->execute();
        $stmt->close();

        return ['persisted' => true] + ($this->fulfillmentForOrder($conn, $orderId) ?: []);
    }

    public function fulfillmentForOrder(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM order_fulfillment
            WHERE order_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatRow($row) : null;
    }

    private function normalizeData(array $data): array
    {
        $fulfillmentType = $this->cleanEnum($data['fulfillment_type'] ?? 'takeaway', self::FULFILLMENT_TYPES, 'takeaway');
        $externalProvider = $this->nullableText($data['external_provider'] ?? null, 40);

        return [
            'order_channel' => $this->cleanEnum($data['order_channel'] ?? null, self::CHANNELS, $externalProvider === 'moova' && $fulfillmentType === 'delivery' ? 'moova_delivery' : 'cashier'),
            'fulfillment_type' => $fulfillmentType,
            'external_provider' => $externalProvider,
            'external_order_id' => $this->nullableText($data['external_order_id'] ?? null, 120),
            'customer_name' => $this->nullableText($data['customer_name'] ?? null, 160),
            'customer_phone' => $this->nullableText($data['customer_phone'] ?? null, 60),
            'customer_address' => $this->nullableText($data['customer_address'] ?? null, 500),
            'delivery_zone' => $this->nullableText($data['delivery_zone'] ?? null, 120),
            'delivery_fee' => $this->decimal($data['delivery_fee'] ?? 0),
            'delivery_status' => $this->deliveryStatus($data['delivery_status'] ?? null, $fulfillmentType),
            'promised_at' => $this->datetimeOrNull($data['promised_at'] ?? null),
            'metadata_json' => $this->jsonOrNull($data['metadata_json'] ?? null),
        ];
    }

    private function fulfillmentType($value, array $payload, array $delivery): string
    {
        $normalized = $this->slug($value);
        if (in_array($normalized, self::FULFILLMENT_TYPES, true)) {
            return $normalized;
        }

        if ($normalized === 'moova_delivery') {
            return 'delivery';
        }

        if ($this->hasDeliverySignal($payload, $delivery)) {
            return 'delivery';
        }

        if ($this->firstNonEmpty($payload, ['tableId', 'table_id', 'tableNumber', 'table_number', 'tableName', 'table_name']) !== null) {
            return 'table';
        }

        return 'takeaway';
    }

    private function orderChannel($value, string $fulfillmentType, string $externalProvider): string
    {
        $normalized = $this->slug($value);
        if ($normalized === 'moovadelivery') {
            $normalized = 'moova_delivery';
        } elseif ($normalized === 'moovaqr') {
            $normalized = 'moova_qr';
        }
        if (in_array($normalized, self::CHANNELS, true)) {
            return $normalized;
        }

        if ($externalProvider === 'moova') {
            return $fulfillmentType === 'delivery' ? 'moova_delivery' : 'moova_qr';
        }

        return 'cashier';
    }

    private function deliveryStatus($value, string $fulfillmentType): string
    {
        $normalized = $this->slug($value);
        if ($normalized === 'canceled') {
            $normalized = 'cancelled';
        } elseif ($normalized === 'pickedup') {
            $normalized = 'picked_up';
        }
        if (in_array($normalized, self::DELIVERY_STATUSES, true)) {
            return $normalized;
        }

        return $fulfillmentType === 'delivery' ? 'pending' : 'none';
    }

    private function metadataJson(array $payload, array $delivery, string $orderChannel, string $fulfillmentType): ?string
    {
        $metadata = [
            'source' => 'moova',
            'order_channel' => $orderChannel,
            'fulfillment_type' => $fulfillmentType,
        ];

        foreach ([
            'branch_id' => ['branchId', 'branch_id', 'moovaBranchId', 'moova_branch_id'],
            'table_id' => ['tableId', 'table_id'],
            'table_number' => ['tableNumber', 'table_number', 'tableName', 'table_name'],
            'idempotency_key' => ['idempotencyKey', 'idempotency_key', 'providerReferenceId', 'provider_reference_id'],
            'notes' => ['notes', 'note'],
        ] as $key => $aliases) {
            $value = $this->firstNonEmpty($payload, $aliases);
            if ($value !== null) {
                $metadata[$key] = is_scalar($value) ? (string) $value : $value;
            }
        }

        if ($delivery) {
            $metadata['delivery_keys'] = array_values(array_slice(array_keys($delivery), 0, 30));
        }

        return $this->jsonOrNull($metadata);
    }

    private function addressValue(array $payload, array $delivery, array $customer)
    {
        $value = $this->firstNonEmpty($payload, [
            'customerAddress',
            'customer_address',
            'deliveryCustomerAddress',
            'delivery_customer_address',
            'deliveryAddress',
            'delivery_address',
            'address',
        ]) ?? $this->firstNonEmpty($delivery, ['address', 'customerAddress', 'customer_address', 'deliveryAddress', 'delivery_address'])
            ?? $this->firstNonEmpty($customer, ['address', 'customerAddress', 'customer_address']);

        if (is_array($value)) {
            return $this->addressArrayToString($value);
        }

        return $value;
    }

    private function addressArrayToString(array $address): string
    {
        $parts = [];
        foreach (['line1', 'line2', 'street', 'building', 'floor', 'apartment', 'city', 'area', 'zone'] as $key) {
            $value = $this->firstNonEmpty($address, [$key]);
            if ($value !== null) {
                $parts[] = (string) $value;
            }
        }

        return implode(', ', array_values(array_unique($parts)));
    }

    private function hasDeliverySignal(array $payload, array $delivery): bool
    {
        if ($delivery) {
            return true;
        }

        foreach ([
            'deliveryFee',
            'delivery_fee',
            'deliveryStatus',
            'delivery_status',
            'deliveryZone',
            'delivery_zone',
            'deliveryAddress',
            'delivery_address',
            'deliveryCustomerName',
            'delivery_customer_name',
            'deliveryCustomerPhone',
            'delivery_customer_phone',
            'deliveryCustomerAddress',
            'delivery_customer_address',
        ] as $key) {
            if ($this->firstNonEmpty($payload, [$key]) !== null) {
                return true;
            }
        }

        return false;
    }

    private function tableExists(mysqli $conn): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_fulfillment'
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['table_count'] > 0;
    }

    private function formatRow(array $row): array
    {
        $metadata = null;
        if ($row['metadata_json'] !== null && $row['metadata_json'] !== '') {
            $decoded = json_decode((string) $row['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'order_channel' => (string) $row['order_channel'],
            'fulfillment_type' => (string) $row['fulfillment_type'],
            'external_provider' => $row['external_provider'] !== null ? (string) $row['external_provider'] : null,
            'external_order_id' => $row['external_order_id'] !== null ? (string) $row['external_order_id'] : null,
            'customer_name' => $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            'customer_phone' => $row['customer_phone'] !== null ? (string) $row['customer_phone'] : null,
            'customer_address' => $row['customer_address'] !== null ? (string) $row['customer_address'] : null,
            'delivery_zone' => $row['delivery_zone'] !== null ? (string) $row['delivery_zone'] : null,
            'delivery_fee' => (float) $row['delivery_fee'],
            'delivery_status' => (string) $row['delivery_status'],
            'promised_at' => $row['promised_at'] !== null ? (string) $row['promised_at'] : null,
            'metadata' => $metadata,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function firstNonEmpty(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === false) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            return $value;
        }

        return null;
    }

    private function nullableText($value, int $maxLength): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        if (is_array($value)) {
            $value = $this->addressArrayToString($value);
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function cleanEnum($value, array $allowed, string $default): string
    {
        $value = $this->slug($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function slug($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim((string) $value, '_');

        return $value;
    }

    private function decimal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return max(0.0, round((float) $value, 3));
    }

    private function datetimeOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
        } else {
            $timestamp = strtotime((string) $value);
        }

        if (!$timestamp) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function jsonOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $value : null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }
}
