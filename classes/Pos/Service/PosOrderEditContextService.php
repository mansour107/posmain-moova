<?php

declare(strict_types=1);

/**
 * Loads the server-authoritative state required to reopen an unpaid POS order.
 *
 * The edit page must never infer delivery money from the current zone catalog:
 * the fulfillment snapshot attached to the order is the source of truth.
 */
final class PosOrderEditContextService
{
    /**
     * @return array{order: array<string, mixed>, delivery: ?array<string, mixed>}
     */
    public function load(mysqli $conn, int $orderId, int $tenant, int $branch): array
    {
        if ($orderId < 1) {
            throw new RuntimeException('POS_ORDER_NOT_EDITABLE');
        }

        $stmt = $conn->prepare(
            "SELECT *
             FROM ot_head
             WHERE id = ?
               AND tenant = ?
               AND branch = ?
               AND pro_tybe = 9
               AND COALESCE(isdeleted, 0) = 0
               AND COALESCE(order_status, 'active') = 'active'
               AND COALESCE(payment_status, 'unpaid') = 'unpaid'
               AND COALESCE(paid_amount, 0) = 0
             LIMIT 1"
        );
        $stmt->bind_param('iii', $orderId, $tenant, $branch);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($order)) {
            throw new RuntimeException('POS_ORDER_NOT_EDITABLE');
        }

        $order['mutation_version'] = max(1, (int) ($order['mutation_version'] ?? 1));
        $delivery = null;
        if (strtolower(trim((string) ($order['order_type'] ?? ''))) === 'delivery') {
            if (!$this->tableExists($conn, 'order_fulfillment')) {
                throw new RuntimeException('POS_DELIVERY_FULFILLMENT_REQUIRED');
            }

            $fulfillmentStmt = $conn->prepare(
                "SELECT f.*
                 FROM order_fulfillment f
                 INNER JOIN ot_head o ON o.id = f.order_id
                 WHERE f.order_id = ?
                   AND f.fulfillment_type = 'delivery'
                   AND o.tenant = ?
                   AND o.branch = ?
                 LIMIT 1"
            );
            $fulfillmentStmt->bind_param('iii', $orderId, $tenant, $branch);
            $fulfillmentStmt->execute();
            $fulfillment = $fulfillmentStmt->get_result()->fetch_assoc();
            $fulfillmentStmt->close();

            if (!is_array($fulfillment)) {
                throw new RuntimeException('POS_DELIVERY_FULFILLMENT_REQUIRED');
            }

            $delivery = $this->deliveryBootstrap($fulfillment);
        }

        return [
            'order' => $order,
            'delivery' => $delivery,
        ];
    }

    /**
     * @param array<string, mixed> $fulfillment
     * @return array<string, mixed>
     */
    private function deliveryBootstrap(array $fulfillment): array
    {
        $name = trim((string) ($fulfillment['customer_name'] ?? ''));
        $phone = trim((string) ($fulfillment['customer_phone'] ?? ''));
        $address = trim((string) ($fulfillment['customer_address'] ?? ''));
        $zoneId = max(0, (int) ($fulfillment['delivery_zone_id'] ?? 0));
        $zoneName = trim((string) ($fulfillment['delivery_zone'] ?? ''));

        if ($name === '' || $phone === '' || $address === '' || $zoneId < 1) {
            throw new RuntimeException('POS_DELIVERY_FULFILLMENT_INCOMPLETE');
        }

        $collectionMode = strtolower(trim((string) ($fulfillment['collection_mode'] ?? 'prepaid')));
        if (!in_array($collectionMode, ['prepaid', 'cod'], true)) {
            $collectionMode = 'prepaid';
        }

        return [
            'confirmed' => true,
            'client_id' => max(0, (int) ($fulfillment['pos_customer_id'] ?? 0)),
            'phone' => $phone,
            'name' => $name,
            'address' => $address,
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'fee' => number_format(max(0.0, (float) ($fulfillment['delivery_fee'] ?? 0)), 3, '.', ''),
            'worker_id' => max(0, (int) ($fulfillment['delivery_worker_id'] ?? 0)),
            'collection_mode' => $collectionMode,
            'courier_source' => strtolower(trim((string) ($fulfillment['courier_source'] ?? 'in_house'))),
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $exists;
    }
}
