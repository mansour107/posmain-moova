<?php

require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/DeliveryCompensationService.php';
require_once __DIR__ . '/DeliveryAccountingService.php';

class OrderFulfillmentService
{
    private const CHANNELS = ['cashier', 'waiter', 'moova_qr', 'moova_delivery', 'call_center', 'online', 'kiosk', 'import'];
    private const FULFILLMENT_TYPES = ['takeaway', 'table', 'delivery', 'pickup', 'staff_meal', 'waste'];
    private const DELIVERY_STATUSES = ['none', 'pending', 'accepted', 'preparing', 'ready', 'picked_up', 'delivered', 'cancelled', 'failed'];
    private SyncOutboxEventService $syncOutbox;

    public function __construct(?SyncOutboxEventService $syncOutbox = null)
    {
        $this->syncOutbox = $syncOutbox ?: new SyncOutboxEventService();
    }

    public function upsertMoovaFulfillment(mysqli $conn, int $orderId, array $payload, array $options = []): array
    {
        $data = $this->extractFromMoovaPayload($payload, $options + ['external_provider' => 'moova']);
        if (!empty($options['merge_existing'])) {
            $existing = $this->fulfillmentForOrder($conn, $orderId);
            if (is_array($existing)) {
                $data = $this->mergeMoovaFulfillmentData($existing, $data, $payload);
            }
        }

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
        $hasClientColumn = $this->columnExists($conn, 'delivery_client_id');
        $hasPosCustomerColumn = $this->columnExists($conn, 'pos_customer_id');
        if ($hasClientColumn && $hasPosCustomerColumn) {
            $stmt = $conn->prepare("
                INSERT INTO order_fulfillment (
                    order_id, order_channel, fulfillment_type, external_provider, external_order_id,
                    customer_name, customer_phone, customer_address, delivery_client_id, pos_customer_id,
                    delivery_zone, delivery_fee, delivery_status, promised_at, metadata_json
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    order_channel = VALUES(order_channel),
                    fulfillment_type = VALUES(fulfillment_type),
                    external_provider = VALUES(external_provider),
                    external_order_id = VALUES(external_order_id),
                    customer_name = VALUES(customer_name),
                    customer_phone = VALUES(customer_phone),
                    customer_address = VALUES(customer_address),
                    delivery_client_id = VALUES(delivery_client_id),
                    pos_customer_id = VALUES(pos_customer_id),
                    delivery_zone = VALUES(delivery_zone),
                    delivery_fee = VALUES(delivery_fee),
                    delivery_status = VALUES(delivery_status),
                    promised_at = VALUES(promised_at),
                    metadata_json = VALUES(metadata_json),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $deliveryClientId = $data['delivery_client_id'];
            $posCustomerId = $data['pos_customer_id'];
            $stmt->bind_param(
                'isssssssiisdsss',
                $orderId,
                $data['order_channel'],
                $data['fulfillment_type'],
                $data['external_provider'],
                $data['external_order_id'],
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_address'],
                $deliveryClientId,
                $posCustomerId,
                $data['delivery_zone'],
                $data['delivery_fee'],
                $data['delivery_status'],
                $data['promised_at'],
                $data['metadata_json']
            );
        } elseif ($hasClientColumn) {
            $stmt = $conn->prepare("
                INSERT INTO order_fulfillment (
                    order_id, order_channel, fulfillment_type, external_provider, external_order_id,
                    customer_name, customer_phone, customer_address, delivery_client_id, delivery_zone, delivery_fee,
                    delivery_status, promised_at, metadata_json
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    order_channel = VALUES(order_channel),
                    fulfillment_type = VALUES(fulfillment_type),
                    external_provider = VALUES(external_provider),
                    external_order_id = VALUES(external_order_id),
                    customer_name = VALUES(customer_name),
                    customer_phone = VALUES(customer_phone),
                    customer_address = VALUES(customer_address),
                    delivery_client_id = VALUES(delivery_client_id),
                    delivery_zone = VALUES(delivery_zone),
                    delivery_fee = VALUES(delivery_fee),
                    delivery_status = VALUES(delivery_status),
                    promised_at = VALUES(promised_at),
                    metadata_json = VALUES(metadata_json),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $deliveryClientId = $data['delivery_client_id'];
            $stmt->bind_param(
                'isssssssisdsss',
                $orderId,
                $data['order_channel'],
                $data['fulfillment_type'],
                $data['external_provider'],
                $data['external_order_id'],
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_address'],
                $deliveryClientId,
                $data['delivery_zone'],
                $data['delivery_fee'],
                $data['delivery_status'],
                $data['promised_at'],
                $data['metadata_json']
            );
        } else {
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
        }
        $stmt->execute();
        $stmt->close();

        return ['persisted' => true] + ($this->fulfillmentForOrder($conn, $orderId) ?: []);
    }

    public function transitionDeliveryStatus(mysqli $conn, int $orderId, string $newStatus, array $options = []): array
    {
        $ownsTransaction = empty($options['in_transaction']) && empty($options['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $result = $this->transitionDeliveryStatusInsideTransaction($conn, $orderId, $newStatus, $options);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function transitionDeliveryStatusInsideTransaction(
        mysqli $conn,
        int $orderId,
        string $newStatus,
        array $options
    ): array
    {
        $newStatus = $this->deliveryStatus($newStatus, 'delivery');
        if ($newStatus === 'none') {
            throw new InvalidArgumentException('INVALID_DELIVERY_STATUS');
        }

        $current = $this->fulfillmentForOrder($conn, $orderId, true);
        if (!$current) {
            throw new RuntimeException('FULFILLMENT_NOT_FOUND');
        }

        $currentStatus = (string) ($current['delivery_status'] ?? 'pending');
        $allowed = $this->allowedDeliveryTransitions();
        if ($currentStatus !== $newStatus) {
            $nextAllowed = $allowed[$currentStatus] ?? [];
            if (!in_array($newStatus, $nextAllowed, true) && !(!empty($options['force']) && $newStatus === 'cancelled')) {
                throw new InvalidArgumentException('DELIVERY_STATUS_TRANSITION_NOT_ALLOWED');
            }
        }

        $courierSource = (string) ($current['courier_source'] ?? 'in_house');
        if ($newStatus === 'picked_up' && $courierSource === 'in_house' && (int) ($current['delivery_worker_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP');
        }

        $metadata = is_array($current['metadata'] ?? null) ? $current['metadata'] : [];
        if (!empty($options['driver_name'])) {
            $metadata['driver_name'] = (string) $options['driver_name'];
        }
        if (!empty($options['driver_phone'])) {
            $metadata['driver_phone'] = (string) $options['driver_phone'];
        }
        if (!empty($options['actor_user_id'])) {
            $metadata['last_status_actor_user_id'] = (int) $options['actor_user_id'];
        }

        $updated = $this->upsertForOrder($conn, $orderId, [
            'order_channel' => $current['order_channel'],
            'fulfillment_type' => $current['fulfillment_type'],
            'external_provider' => $current['external_provider'],
            'external_order_id' => $current['external_order_id'],
            'customer_name' => $current['customer_name'],
            'customer_phone' => $current['customer_phone'],
            'customer_address' => $current['customer_address'],
            'delivery_client_id' => $current['delivery_client_id'] ?? null,
            'pos_customer_id' => $current['pos_customer_id'] ?? null,
            'delivery_zone' => $current['delivery_zone'],
            'delivery_fee' => $current['delivery_fee'],
            'delivery_status' => $newStatus,
            'promised_at' => $current['promised_at'],
            'metadata_json' => $metadata,
        ], ['require_table' => true]);

        $tip = max(0, (float) ($options['driver_tip'] ?? $current['driver_tip'] ?? 0));
        $postedCod = max(0, (float) ($options['cod_amount'] ?? 0));
        $codAmount = $postedCod > 0 ? $postedCod : max(0, (float) ($current['cod_amount'] ?? 0));
        $resolvedCollectionMode = (string) ($current['collection_mode'] ?? 'prepaid');
        if ($newStatus === 'delivered') {
            $orderStmt = $conn->prepare('SELECT remaining_amount FROM ot_head WHERE id = ? LIMIT 1 FOR UPDATE');
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $remaining = (float) ($orderStmt->get_result()->fetch_assoc()['remaining_amount'] ?? 0);
            $orderStmt->close();
            if ($remaining > 0.01) {
                $resolvedCollectionMode = 'cod';
                if ($codAmount <= 0) {
                    $codAmount = $remaining;
                }
                if (abs($codAmount - $remaining) > 0.01) {
                    throw new InvalidArgumentException('DELIVERY_COD_AMOUNT_MUST_MATCH_REMAINING');
                }
            } else {
                $resolvedCollectionMode = 'prepaid';
                $codAmount = 0.0;
            }
        }
        $stampSql = '';
        if ($newStatus === 'picked_up') {
            $stampSql = ', picked_up_at = COALESCE(picked_up_at, NOW())';
        } elseif ($newStatus === 'delivered') {
            $stampSql = ', delivered_at = COALESCE(delivered_at, NOW())';
        }
        $financeUpdate = $conn->prepare("UPDATE order_fulfillment SET cod_amount = ?, driver_tip = ?, collection_mode = ? {$stampSql} WHERE order_id = ?");
        $financeUpdate->bind_param('ddsi', $codAmount, $tip, $resolvedCollectionMode, $orderId);
        $financeUpdate->execute();
        $financeUpdate->close();

        if ($newStatus === 'delivered' && $currentStatus !== 'delivered') {
            $accounting = new DeliveryAccountingService();
            if ($resolvedCollectionMode === 'cod') {
                $accounting->finalizeCodOrder(
                    $conn,
                    $orderId,
                    number_format($codAmount, 3, '.', ''),
                    (int) ($options['actor_user_id'] ?? 0),
                    $options
                );
            }
            $accounting->postDeliveryFeeReclassification(
                $conn,
                $orderId,
                number_format((float) ($current['delivery_fee'] ?? 0), 3, '.', ''),
                (int) ($options['actor_user_id'] ?? 0),
                $options
            );
            $financial = (new DeliveryCompensationService())->accrueDeliveredOrder($conn, $orderId, [
                'tenant' => (int) ($options['tenant'] ?? 0),
                'branch' => (int) ($options['branch'] ?? 0),
                'config' => $options['config'] ?? null,
            ]);
            if ($financial) {
                $accounting->postDeliveredAccrual(
                    $conn,
                    $financial,
                    (int) ($options['actor_user_id'] ?? 0),
                    $options
                );
            }
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ((string) ($config['role'] ?? 'branch') === 'branch') {
            $this->syncOutbox->recordOrderSnapshot($conn, $orderId, [
                'event_type' => (string) ($options['event_type'] ?? 'order.fulfillment_updated'),
                'source_system' => (string) ($options['source_system'] ?? 'pos_delivery_dispatch'),
                'config' => $config,
            ]);
        }

        return $this->fulfillmentForOrder($conn, $orderId) ?: $updated;
    }

    private function allowedDeliveryTransitions(): array
    {
        return [
            'pending' => ['accepted', 'cancelled'],
            'accepted' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['picked_up', 'cancelled'],
            'picked_up' => ['delivered', 'failed'],
            'delivered' => [],
            'cancelled' => [],
            'failed' => [],
            'none' => [],
        ];
    }

    public function listActiveDeliveryOrders(mysqli $conn, array $options = []): array
    {
        if (!$this->tableExists($conn)) {
            return [];
        }

        $limit = max(1, min(200, (int) ($options['limit'] ?? 100)));
        $includeTerminal = !empty($options['include_terminal']);
        $statusFilter = $includeTerminal
            ? ''
            : " AND f.delivery_status NOT IN ('delivered', 'cancelled', 'failed', 'none')";
        $scopeFilter = '';
        if ($this->tableColumnExists($conn, 'ot_head', 'tenant') && $this->tableColumnExists($conn, 'ot_head', 'branch')) {
            $tenant = max(0, (int) ($options['tenant'] ?? 0));
            $branch = max(0, (int) ($options['branch'] ?? 0));
            $scopeFilter = " AND o.tenant = {$tenant} AND o.branch = {$branch}";
        }

        $workerColumns = $this->columnExists($conn, 'delivery_worker_id')
            ? "f.delivery_worker_id, f.delivery_zone_id, f.courier_source, f.collection_mode, f.cod_amount, f.driver_tip, f.assigned_at, f.picked_up_at, f.delivered_at, w.name AS delivery_worker_name, w.phone AS delivery_worker_phone,"
            : "NULL AS delivery_worker_id, NULL AS delivery_zone_id, 'in_house' AS courier_source, 'prepaid' AS collection_mode, 0 AS cod_amount, 0 AS driver_tip, NULL AS assigned_at, NULL AS picked_up_at, NULL AS delivered_at, NULL AS delivery_worker_name, NULL AS delivery_worker_phone,";
        $workerJoin = $this->columnExists($conn, 'delivery_worker_id') ? 'LEFT JOIN delivery_workers w ON w.id = f.delivery_worker_id' : '';
        $sql = "
            SELECT
                o.id AS order_id,
                o.pro_id,
                o.fat_net,
                o.order_type,
                o.payment_status,
                o.order_status,
                COALESCE(o.mdtime, o.crtime, o.pro_date) AS order_time,
                f.order_channel,
                f.fulfillment_type,
                f.customer_name,
                f.customer_phone,
                f.customer_address,
                f.delivery_zone,
                f.delivery_fee,
                f.delivery_status,
                {$workerColumns}
                f.delivery_client_id,
                f.metadata_json,
                (SELECT COUNT(*) FROM fat_details fd WHERE fd.fatid = o.id AND fd.isdeleted = 0) AS line_count
            FROM order_fulfillment f
            INNER JOIN ot_head o ON o.id = f.order_id
            {$workerJoin}
            WHERE f.fulfillment_type = 'delivery'
              AND o.isdeleted = 0
              {$statusFilter}
              {$scopeFilter}
            ORDER BY f.delivery_status ASC, order_time DESC
            LIMIT {$limit}
        ";
        $result = $conn->query($sql);
        $orders = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $metadata = null;
                if (!empty($row['metadata_json'])) {
                    $decoded = json_decode((string) $row['metadata_json'], true);
                    $metadata = is_array($decoded) ? $decoded : null;
                }
                $orders[] = [
                    'order_id' => (int) $row['order_id'],
                    'pro_id' => (int) $row['pro_id'],
                    'fat_net' => (float) $row['fat_net'],
                    'order_type' => (string) $row['order_type'],
                    'payment_status' => (string) $row['payment_status'],
                    'order_status' => (string) $row['order_status'],
                    'order_time' => (string) $row['order_time'],
                    'order_channel' => (string) $row['order_channel'],
                    'customer_name' => (string) ($row['customer_name'] ?? ''),
                    'customer_phone' => (string) ($row['customer_phone'] ?? ''),
                    'customer_address' => (string) ($row['customer_address'] ?? ''),
                    'delivery_zone' => (string) ($row['delivery_zone'] ?? ''),
                    'delivery_fee' => (float) ($row['delivery_fee'] ?? 0),
                    'delivery_status' => (string) $row['delivery_status'],
                    'delivery_worker_id' => isset($row['delivery_worker_id']) ? (int) $row['delivery_worker_id'] : null,
                    'delivery_worker_name' => (string) ($row['delivery_worker_name'] ?? ''),
                    'delivery_worker_phone' => (string) ($row['delivery_worker_phone'] ?? ''),
                    'delivery_zone_id' => isset($row['delivery_zone_id']) ? (int) $row['delivery_zone_id'] : null,
                    'courier_source' => (string) ($row['courier_source'] ?? 'in_house'),
                    'collection_mode' => (string) ($row['collection_mode'] ?? 'prepaid'),
                    'cod_amount' => (float) ($row['cod_amount'] ?? 0),
                    'driver_tip' => (float) ($row['driver_tip'] ?? 0),
                    'assigned_at' => $row['assigned_at'] ?? null,
                    'picked_up_at' => $row['picked_up_at'] ?? null,
                    'delivered_at' => $row['delivered_at'] ?? null,
                    'delivery_client_id' => isset($row['delivery_client_id']) ? (int) $row['delivery_client_id'] : null,
                    'line_count' => (int) ($row['line_count'] ?? 0),
                    'metadata' => $metadata,
                ];
            }
        }

        return $orders;
    }

    public function countPendingDeliveryOrders(mysqli $conn, array $options = []): int
    {
        if (!$this->tableExists($conn)) {
            return 0;
        }
        $scopeFilter = '';
        if ($this->tableColumnExists($conn, 'ot_head', 'tenant') && $this->tableColumnExists($conn, 'ot_head', 'branch')) {
            $tenant = max(0, (int) ($options['tenant'] ?? 0));
            $branch = max(0, (int) ($options['branch'] ?? 0));
            $scopeFilter = " AND o.tenant = {$tenant} AND o.branch = {$branch}";
        }
        $result = $conn->query("
            SELECT COUNT(*) AS pending_count
            FROM order_fulfillment f
            INNER JOIN ot_head o ON o.id = f.order_id
            WHERE f.fulfillment_type = 'delivery'
              AND f.delivery_status = 'pending'
              AND o.isdeleted = 0
              {$scopeFilter}
        ");
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();

        return (int) ($row['pending_count'] ?? 0);
    }

    private function columnExists(mysqli $conn, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_fulfillment'
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['column_count'] > 0;
    }

    private function tableColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS column_count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && (int) $row['column_count'] > 0;
    }

    public function fulfillmentForOrder(mysqli $conn, int $orderId, bool $forUpdate = false): ?array
    {
        $lockSql = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare("
            SELECT *
            FROM order_fulfillment
            WHERE order_id = ?
            LIMIT 1
            {$lockSql}
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
            'delivery_client_id' => null,
            'pos_customer_id' => isset($data['pos_customer_id']) && (int) $data['pos_customer_id'] > 0
                ? (int) $data['pos_customer_id']
                : null,
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

    private function mergeMoovaFulfillmentData(array $existing, array $incoming, array $payload): array
    {
        $delivery = isset($payload['delivery']) && is_array($payload['delivery']) ? $payload['delivery'] : [];
        $merged = $incoming;

        if (($existing['fulfillment_type'] ?? '') === 'delivery'
            && !$this->hasDeliverySignal($payload, $delivery)
            && !$this->payloadHasExplicitFulfillmentType($payload)) {
            $merged['fulfillment_type'] = 'delivery';
            if (!empty($existing['order_channel'])) {
                $merged['order_channel'] = (string) $existing['order_channel'];
            }
        }

        if (!$this->payloadHasDeliveryFee($payload, $delivery)) {
            $merged['delivery_fee'] = $existing['delivery_fee'] ?? 0;
        }

        if (!$this->payloadHasDeliveryStatus($payload, $delivery)) {
            $merged['delivery_status'] = $existing['delivery_status'] ?? $incoming['delivery_status'];
        }

        foreach (['customer_name', 'customer_phone', 'customer_address', 'delivery_zone', 'external_order_id', 'external_provider'] as $field) {
            if ($this->isBlank($merged[$field] ?? null) && !$this->isBlank($existing[$field] ?? null)) {
                $merged[$field] = $existing[$field];
            }
        }

        if (!empty($existing['delivery_client_id']) && empty($merged['delivery_client_id'])) {
            $merged['delivery_client_id'] = $existing['delivery_client_id'];
        }

        if ($this->isBlank($merged['promised_at'] ?? null) && !$this->isBlank($existing['promised_at'] ?? null)) {
            $merged['promised_at'] = $existing['promised_at'];
        }

        return $merged;
    }

    private function payloadHasExplicitFulfillmentType(array $payload): bool
    {
        foreach (['fulfillmentType', 'fulfillment_type', 'orderType', 'order_type', 'type'] as $key) {
            if (array_key_exists($key, $payload) && trim((string) $payload[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function payloadHasDeliveryFee(array $payload, array $delivery = []): bool
    {
        foreach (['deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee', 'deliveryCharge', 'delivery_charge'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                return true;
            }
        }

        foreach (['fee', 'deliveryFee', 'delivery_fee', 'shippingFee', 'shipping_fee'] as $key) {
            if (array_key_exists($key, $delivery) && $delivery[$key] !== null && $delivery[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function payloadHasDeliveryStatus(array $payload, array $delivery = []): bool
    {
        foreach (['deliveryStatus', 'delivery_status'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                return true;
            }
        }

        foreach (['status', 'deliveryStatus', 'delivery_status'] as $key) {
            if (array_key_exists($key, $delivery) && $delivery[$key] !== null && $delivery[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
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
            'delivery_client_id' => isset($row['delivery_client_id']) ? (int) $row['delivery_client_id'] : null,
            'pos_customer_id' => isset($row['pos_customer_id']) ? (int) $row['pos_customer_id'] : null,
            'delivery_zone' => $row['delivery_zone'] !== null ? (string) $row['delivery_zone'] : null,
            'delivery_zone_id' => isset($row['delivery_zone_id']) && $row['delivery_zone_id'] !== null ? (int) $row['delivery_zone_id'] : null,
            'delivery_worker_id' => isset($row['delivery_worker_id']) && $row['delivery_worker_id'] !== null ? (int) $row['delivery_worker_id'] : null,
            'courier_source' => (string) ($row['courier_source'] ?? 'in_house'),
            'collection_mode' => (string) ($row['collection_mode'] ?? 'prepaid'),
            'cod_amount' => (float) ($row['cod_amount'] ?? 0),
            'driver_tip' => (float) ($row['driver_tip'] ?? 0),
            'delivery_fee' => (float) $row['delivery_fee'],
            'delivery_status' => (string) $row['delivery_status'],
            'assigned_at' => $row['assigned_at'] ?? null,
            'picked_up_at' => $row['picked_up_at'] ?? null,
            'delivered_at' => $row['delivered_at'] ?? null,
            'crm_rollup_paid_amount' => isset($row['crm_rollup_paid_amount']) ? (float) $row['crm_rollup_paid_amount'] : 0.0,
            'crm_rollup_counted' => isset($row['crm_rollup_counted']) ? (int) $row['crm_rollup_counted'] : 0,
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
