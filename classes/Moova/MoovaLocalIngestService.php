<?php

if (!class_exists('MoovaInboundQueueService')) {
    require_once __DIR__ . '/../Sync/MoovaInboundQueueService.php';
}

class MoovaLocalIngestService
{
    private $queue;

    public function __construct(?MoovaInboundQueueService $queue = null)
    {
        $this->queue = $queue ?: new MoovaInboundQueueService();
    }

    public function ingestNewOrder(mysqli $conn, array $payload, array $ctx): array
    {
        return $this->ingest($conn, 'new_order', $payload, $ctx);
    }

    public function ingestChange(mysqli $conn, array $payload, array $ctx): array
    {
        $eventType = $this->eventTypeFromChangePayload($payload);

        return $this->ingest($conn, $eventType, $payload, $ctx);
    }

    public function normalizeIdempotencyKey(array $payload, string $eventType): string
    {
        $explicitKey = $this->firstNonEmpty($payload, ['idempotency_key', 'idempotencyKey']);
        if ($explicitKey !== null) {
            return $this->cleanExplicitIdempotencyKey($explicitKey);
        }

        $eventType = $this->normalizeEventType($eventType);
        $branchId = $this->requiredPayloadString($payload, [
            'moova_branch_id',
            'moovaBranchId',
            'branch_id',
            'branchId',
        ], 'moova branch id');
        $orderId = $this->requiredPayloadString($payload, [
            'moova_order_id',
            'moovaOrderId',
            'order_id',
            'orderId',
            'cofeOrderId',
            'cofe_order_id',
        ], 'moova order id');
        $revision = $this->firstNonEmpty($payload, [
            'provider_event_id',
            'providerEventId',
            'request_event_id',
            'requestEventId',
            'change_id',
            'changeId',
            'revision',
            'idempotency_key',
            'idempotencyKey',
        ]);

        if ($revision === null) {
            $revision = substr($this->normalizePayloadHash($payload), 0, 16);
        }

        return implode(':', [
            'moova',
            $this->cleanKeyPart($branchId, 50),
            $this->cleanKeyPart($orderId, 70),
            $eventType,
            $this->cleanKeyPart($revision, 50),
        ]);
    }

    public function normalizePayloadHash(array $payload): string
    {
        return hash('sha256', $this->encodeJson($this->normalizePayloadForHash($payload)));
    }

    public function normalizeNewOrderForPos(array $payload): array
    {
        $payload = $this->payloadWithContextFallbacks($payload, []);
        $posPayload = [
            'cofeOrderId' => $this->requiredPayloadString($payload, [
                'cofeOrderId',
                'cofe_order_id',
                'moovaOrderId',
                'moova_order_id',
                'orderId',
                'order_id',
            ], 'moova order id'),
            'branchId' => $this->requiredPayloadString($payload, [
                'branchId',
                'branch_id',
                'moovaBranchId',
                'moova_branch_id',
            ], 'moova branch id'),
            'items' => $this->normalizeItemsForPos($payload['items'] ?? []),
        ];

        $tableId = $this->firstNonEmpty($payload, ['tableId', 'table_id']);
        $tableNumber = $this->firstNonEmpty($payload, ['tableNumber', 'table_number', 'tableName', 'table_name']);
        if ($tableId !== null) {
            $posPayload['tableId'] = (string) $tableId;
        } elseif ($tableNumber !== null) {
            $posPayload['tableNumber'] = (string) $tableNumber;
        } else {
            throw new InvalidArgumentException('TABLE_REQUIRED');
        }

        $notes = $this->firstNonEmpty($payload, ['notes', 'note']);
        if ($notes !== null) {
            $posPayload['notes'] = (string) $notes;
        }
        $this->copyFulfillmentFieldsForPos($payload, $posPayload);

        return $posPayload;
    }

    public function normalizeChangeForPos(array $payload): array
    {
        $eventType = $this->eventTypeFromChangePayload($payload);
        $action = $eventType === 'cancel_order' ? 'cancel' : 'edit';
        $posPayload = [
            'action' => $action,
            'moovaOrderId' => $this->requiredPayloadString($payload, [
                'moovaOrderId',
                'moova_order_id',
                'orderId',
                'order_id',
                'cofeOrderId',
                'cofe_order_id',
            ], 'moova order id'),
        ];

        $branchId = $this->firstNonEmpty($payload, [
            'branchId',
            'branch_id',
            'moovaBranchId',
            'moova_branch_id',
        ]);
        if ($branchId !== null) {
            $posPayload['branchId'] = (string) $branchId;
        }

        foreach ([
            'requestEventId' => ['requestEventId', 'request_event_id', 'provider_event_id', 'providerEventId', 'change_id', 'changeId'],
            'providerOrderId' => ['providerOrderId', 'provider_order_id'],
            'providerReferenceId' => ['providerReferenceId', 'provider_reference_id', 'idempotency_key', 'idempotencyKey'],
            'expectedStateHash' => ['expectedStateHash', 'expected_state_hash', 'state_hash', 'stateHash'],
            'reason' => ['reason', 'cancel_reason', 'cancelReason'],
        ] as $canonical => $aliases) {
            $value = $this->firstNonEmpty($payload, $aliases);
            if ($value !== null) {
                $posPayload[$canonical] = (string) $value;
            }
        }

        if ($action === 'edit') {
            $posPayload['items'] = $this->normalizeItemsForPos($payload['items'] ?? []);
        }

        return $posPayload;
    }

    private function ingest(mysqli $conn, string $eventType, array $payload, array $ctx): array
    {
        $eventType = $this->normalizeEventType($eventType);
        $effectivePayload = $this->payloadWithContextFallbacks($payload, $ctx);
        $idempotencyKey = $this->normalizeIdempotencyKey($effectivePayload, $eventType);
        $payloadHash = $this->normalizePayloadHash($effectivePayload);
        $moovaOrderId = $this->requiredPayloadString($effectivePayload, [
            'moova_order_id',
            'moovaOrderId',
            'order_id',
            'orderId',
            'cofeOrderId',
            'cofe_order_id',
        ], 'moova order id');
        $moovaBranchId = $this->firstNonEmpty($effectivePayload, [
            'moova_branch_id',
            'moovaBranchId',
            'branch_id',
            'branchId',
        ]);

        $event = [
            'event_uuid' => $this->eventUuid($effectivePayload, $idempotencyKey),
            'moova_order_id' => $moovaOrderId,
            'moova_branch_id' => $moovaBranchId,
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'payload' => $effectivePayload,
        ];

        $result = $this->queue->record($conn, $event, $ctx);
        $result['event_type'] = $eventType;
        $result['idempotency_key'] = $idempotencyKey;
        $result['payload_hash'] = $payloadHash;

        return $result;
    }

    private function payloadWithContextFallbacks(array $payload, array $ctx): array
    {
        if (!isset($payload['moova_branch_id']) && !isset($payload['moovaBranchId'])) {
            $branchId = $this->firstNonEmpty($ctx, ['moova_branch_id', 'moovaBranchId']);
            if ($branchId !== null) {
                $payload['moova_branch_id'] = $branchId;
            }
        }

        return $payload;
    }

    private function eventTypeFromChangePayload(array $payload): string
    {
        $explicitEventType = $this->firstNonEmpty($payload, ['event_type', 'eventType']);
        if ($explicitEventType !== null) {
            $explicitEventType = $this->normalizeEventType($explicitEventType);
            if ($explicitEventType === 'edit_order' || $explicitEventType === 'cancel_order') {
                return $explicitEventType;
            }
        }

        $action = strtolower((string) ($this->firstNonEmpty($payload, ['action', 'change_type', 'changeType']) ?: 'edit'));
        if (in_array($action, ['cancel', 'cancel_order', 'cancelled', 'canceled'], true)) {
            return 'cancel_order';
        }

        return 'edit_order';
    }

    private function normalizeEventType(string $eventType): string
    {
        $eventType = strtolower(trim($eventType));
        if ($eventType === 'new' || $eventType === 'create' || $eventType === 'created') {
            $eventType = 'new_order';
        }
        if ($eventType === 'edit' || $eventType === 'change' || $eventType === 'update') {
            $eventType = 'edit_order';
        }
        if ($eventType === 'cancel' || $eventType === 'cancelled' || $eventType === 'canceled') {
            $eventType = 'cancel_order';
        }

        if (!in_array($eventType, ['new_order', 'edit_order', 'cancel_order'], true)) {
            throw new InvalidArgumentException('Invalid Moova ingest event type.');
        }

        return $eventType;
    }

    private function normalizePayloadForHash(array $payload): array
    {
        $normalized = [];

        foreach ([
            'moova_order_id' => ['moova_order_id', 'moovaOrderId', 'order_id', 'orderId', 'cofeOrderId', 'cofe_order_id'],
            'moova_branch_id' => ['moova_branch_id', 'moovaBranchId', 'branch_id', 'branchId'],
            'provider_order_id' => ['provider_order_id', 'providerOrderId'],
            'provider_reference_id' => ['provider_reference_id', 'providerReferenceId'],
            'request_event_id' => ['request_event_id', 'requestEventId', 'provider_event_id', 'providerEventId', 'change_id', 'changeId'],
            'revision' => ['revision'],
            'table_id' => ['table_id', 'tableId'],
            'table_number' => ['table_number', 'tableNumber'],
            'action' => ['action', 'change_type', 'changeType'],
            'notes' => ['notes', 'note'],
        ] as $canonical => $aliases) {
            $value = $this->firstNonEmpty($payload, $aliases);
            if ($value !== null) {
                $normalized[$canonical] = is_string($value) ? trim($value) : $value;
            }
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            $normalized['items'] = [];
            foreach ($payload['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalized['items'][] = $this->canonicalize([
                    'item_id' => $this->firstNonEmpty($item, ['item_id', 'itemId', 'id']),
                    'qty' => $this->normalizeNumericHashValue($this->firstNonEmpty($item, ['qty', 'quantity', 'count'])),
                    'price' => $this->normalizeNumericHashValue($this->firstNonEmpty($item, ['price', 'unit_price', 'unitPrice'])),
                    'name' => $this->firstNonEmpty($item, ['name', 'item_name', 'itemName']),
                    'modifiers' => isset($item['modifiers']) && is_array($item['modifiers']) ? $item['modifiers'] : null,
                ]);
            }
        }

        if (!isset($normalized['action'])) {
            $eventType = $this->firstNonEmpty($payload, ['event_type', 'eventType']);
            if ($eventType !== null) {
                $eventType = $this->normalizeEventType((string) $eventType);
                if ($eventType === 'edit_order') {
                    $normalized['action'] = 'edit';
                } elseif ($eventType === 'cancel_order') {
                    $normalized['action'] = 'cancel';
                }
            }
        }

        if (!$normalized) {
            return $this->canonicalize($payload);
        }

        return $this->canonicalize($normalized);
    }

    private function normalizeItemsForPos($items): array
    {
        if (!is_array($items)) {
            throw new InvalidArgumentException('Moova order items are required.');
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = $this->firstNonEmpty($item, ['itemId', 'item_id', 'id', 'barcode']);
            $qty = $this->firstNonEmpty($item, ['qty', 'quantity', 'count']);
            if ($itemId === null || $qty === null || (float) $qty <= 0) {
                continue;
            }

            $normalized[] = [
                'itemId' => (string) $itemId,
                'qty' => (float) $qty,
            ];
        }

        if (!$normalized) {
            throw new InvalidArgumentException('NO_VALID_ITEMS');
        }

        return $normalized;
    }

    private function copyFulfillmentFieldsForPos(array $payload, array &$posPayload): void
    {
        foreach ([
            'orderChannel' => ['orderChannel', 'order_channel', 'channel'],
            'fulfillmentType' => ['fulfillmentType', 'fulfillment_type', 'orderType', 'order_type', 'type'],
            'externalProvider' => ['externalProvider', 'external_provider', 'provider', 'sourceSystem', 'source_system'],
            'externalOrderId' => ['externalOrderId', 'external_order_id', 'providerOrderId', 'provider_order_id'],
            'customerName' => ['customerName', 'customer_name', 'deliveryCustomerName', 'delivery_customer_name'],
            'customerPhone' => ['customerPhone', 'customer_phone', 'deliveryCustomerPhone', 'delivery_customer_phone'],
            'customerAddress' => ['customerAddress', 'customer_address', 'deliveryCustomerAddress', 'delivery_customer_address', 'deliveryAddress', 'delivery_address', 'address'],
            'deliveryZone' => ['deliveryZone', 'delivery_zone', 'zone'],
            'deliveryFee' => ['deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee', 'deliveryCharge', 'delivery_charge'],
            'deliveryStatus' => ['deliveryStatus', 'delivery_status'],
            'promisedAt' => ['promisedAt', 'promised_at', 'eta', 'deliveryEta', 'delivery_eta'],
        ] as $target => $aliases) {
            $value = $this->firstNonEmpty($payload, $aliases);
            if ($value !== null) {
                $posPayload[$target] = is_array($value) ? $value : (string) $value;
            }
        }

        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $posPayload['customer'] = $payload['customer'];
            foreach ([
                'customerName' => ['name', 'fullName', 'full_name'],
                'customerPhone' => ['phone', 'mobile', 'mobileNumber', 'mobile_number'],
                'customerAddress' => ['address', 'customerAddress', 'customer_address'],
            ] as $target => $aliases) {
                if (!isset($posPayload[$target])) {
                    $value = $this->firstNonEmpty($payload['customer'], $aliases);
                    if ($value !== null) {
                        $posPayload[$target] = is_array($value) ? $value : (string) $value;
                    }
                }
            }
        }

        if (isset($payload['delivery']) && is_array($payload['delivery'])) {
            $posPayload['delivery'] = $payload['delivery'];
            foreach ([
                'customerAddress' => ['address', 'customerAddress', 'customer_address', 'deliveryAddress', 'delivery_address'],
                'deliveryZone' => ['zone', 'deliveryZone', 'delivery_zone'],
                'deliveryFee' => ['fee', 'deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee'],
                'deliveryStatus' => ['status', 'deliveryStatus', 'delivery_status'],
                'promisedAt' => ['promisedAt', 'promised_at', 'eta', 'deliveryEta', 'delivery_eta'],
            ] as $target => $aliases) {
                if (!isset($posPayload[$target])) {
                    $value = $this->firstNonEmpty($payload['delivery'], $aliases);
                    if ($value !== null) {
                        $posPayload[$target] = is_array($value) ? $value : (string) $value;
                    }
                }
            }
        }
    }

    private function eventUuid(array $payload, string $idempotencyKey): string
    {
        $explicit = $this->firstNonEmpty($payload, ['event_uuid', 'eventUuid']);
        if (is_string($explicit) && preg_match('/^[0-9a-fA-F-]{36}$/', $explicit)) {
            return strtolower($explicit);
        }

        return $this->uuidFromSeed($idempotencyKey);
    }

    private function uuidFromSeed(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function requiredPayloadString(array $payload, array $keys, string $label): string
    {
        $value = $this->firstNonEmpty($payload, $keys);
        if ($value === null) {
            throw new InvalidArgumentException($label . ' is required.');
        }

        return trim((string) $value);
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

    private function cleanKeyPart($value, int $maxLength): string
    {
        $value = trim((string) $value);
        $value = str_replace([':', "\r", "\n", "\t"], '_', $value);
        $value = preg_replace('/\s+/', '-', $value);
        if (!is_string($value) || $value === '') {
            $value = 'unknown';
        }

        return substr($value, 0, $maxLength);
    }

    private function cleanExplicitIdempotencyKey($value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Moova idempotency key is required.');
        }

        return substr(trim($value), 0, 191);
    }

    private function normalizeNumericHashValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return $value;
        }

        $normalized = rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }

        ksort($value);
        foreach ($value as $key => $child) {
            if ($child === null) {
                unset($value[$key]);
                continue;
            }

            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode Moova ingest JSON.');
        }

        return $json;
    }
}
