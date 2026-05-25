<?php

if (!class_exists('MoovaApplyResponse')) {
    require_once __DIR__ . '/MoovaApplyResponse.php';
}
if (!class_exists('MoovaPosIntegration')) {
    require_once __DIR__ . '/../MoovaPosIntegration.php';
}
if (!class_exists('PosOrderService')) {
    require_once __DIR__ . '/../PosOrderService.php';
}
if (!class_exists('OrderFulfillmentService')) {
    require_once __DIR__ . '/../Pos/Service/OrderFulfillmentService.php';
}
if (!class_exists('SyncOutboxEventService')) {
    require_once __DIR__ . '/../Sync/SyncOutboxEventService.php';
}

class MoovaNewOrderApplyService
{
    private PosOrderService $posOrders;
    private SyncOutboxEventService $syncOutbox;
    private OrderFulfillmentService $fulfillment;

    public function __construct(
        ?PosOrderService $posOrders = null,
        ?SyncOutboxEventService $syncOutbox = null,
        ?OrderFulfillmentService $fulfillment = null
    )
    {
        $this->posOrders = $posOrders ?: new PosOrderService();
        $this->syncOutbox = $syncOutbox ?: new SyncOutboxEventService();
        $this->fulfillment = $fulfillment ?: new OrderFulfillmentService();
    }

    public function applyInTransaction(mysqli $conn, array $link, array $payload, array $options = []): array
    {
        $tenant = (int) ($link['pos_tenant'] ?? $options['tenant'] ?? 0);
        $branch = (int) ($link['pos_branch'] ?? $options['branch'] ?? 0);
        $userId = max(1, (int) ($options['user_id'] ?? 1));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ($payload['idempotencyKey'] ?? '')));
        if ($idempotencyKey === '') {
            throw new RuntimeException('IDEMPOTENCY_REQUIRED');
        }

        $requestHash = (string) ($options['request_hash'] ?? MoovaPosIntegration::payloadHash($payload));
        $requestJson = (string) ($options['request_json'] ?? $this->encodeJson(MoovaPosIntegration::normalizePayloadForHash($payload)));
        $moovaOrderId = trim((string) ($options['moova_order_id'] ?? ($payload['cofeOrderId'] ?? '')));
        $moovaBranchId = trim((string) ($options['moova_branch_id'] ?? ($payload['branchId'] ?? ($link['moova_branch_id'] ?? ''))));
        $responseMode = (string) ($options['response_mode'] ?? 'direct');
        $syncConfig = is_array($options['config'] ?? null) ? $options['config'] : [];
        $branchUuid = trim((string) ($link['branch_uuid'] ?? $options['branch_uuid'] ?? ($syncConfig['sync']['branch_uuid'] ?? '')));

        $orderLink = $this->fetchOrderLinkForUpdate($conn, $tenant, $branch, $idempotencyKey);
        if ($orderLink && !hash_equals((string) $orderLink['request_hash'], $requestHash)) {
            throw new RuntimeException('IDEMPOTENCY_PAYLOAD_CONFLICT');
        }

        if ($orderLink && (int) ($orderLink['pos_order_id'] ?? 0) > 0) {
            $this->fulfillment->upsertMoovaFulfillment($conn, (int) $orderLink['pos_order_id'], $payload);
            $response = json_decode((string) ($orderLink['response_payload'] ?? ''), true);
            if (!is_array($response)) {
                $response = $this->responseFromExistingLink($orderLink, $idempotencyKey);
            }
            $response = $this->stampResponse($response, $responseMode, 'applied');

            return [
                'existing' => true,
                'order_id' => (int) $orderLink['pos_order_id'],
                'order_link_id' => (int) $orderLink['id'],
                'provider_status' => (string) ($orderLink['provider_status'] ?: 'created'),
                'response' => $response,
            ];
        }

        $orderLinkId = $orderLink
            ? (int) $orderLink['id']
            : $this->createOrderLink($conn, $tenant, $branch, $idempotencyKey, $requestHash, $requestJson, $moovaOrderId, $moovaBranchId);

        $order = $this->posOrders->createOrMergeMoovaTableOrder($conn, [
            'tenant' => $tenant,
            'branch' => $branch,
            'user_id' => $userId,
            'branch_uuid' => $branchUuid,
        ], $payload);
        $this->fulfillment->upsertMoovaFulfillment($conn, (int) $order['order_id'], $payload);

        $providerStatus = !empty($order['merged']) ? 'updated' : 'created';
        $response = $this->responseFromOrder($order, $idempotencyKey, $providerStatus);
        $response = $this->stampResponse($response, $responseMode, 'applied');

        $this->updateOrderLinkSuccess(
            $conn,
            $orderLinkId,
            (int) $order['order_id'],
            $providerStatus,
            $response,
            $order['state_hash'] ?? null,
            $order['state_payload'] ?? null
        );

        $orderId = (int) $order['order_id'];
        $this->syncOutbox->recordOrderSnapshot($conn, $orderId, [
            'event_type' => !empty($order['merged']) ? 'order.updated' : 'order.saved',
            'source_system' => 'moova_pos',
            'config' => $syncConfig,
        ]);
        if ((int) ($order['table_id'] ?? 0) > 0) {
            $this->syncOutbox->recordTableSnapshot($conn, (int) $order['table_id'], [
                'event_type' => 'table.updated',
                'source_system' => 'moova_pos',
                'active_order_id' => $orderId,
                'config' => $syncConfig,
            ]);
        }

        return [
            'existing' => false,
            'order_id' => $orderId,
            'order_link_id' => $orderLinkId,
            'provider_status' => $providerStatus,
            'state_hash' => $order['state_hash'] ?? null,
            'state_payload' => $order['state_payload'] ?? null,
            'response' => $response,
        ];
    }

    private function fetchOrderLinkForUpdate(mysqli $conn, int $tenant, int $branch, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_order_links
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND idempotency_key = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('iis', $tenant, $branch, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function createOrderLink(
        mysqli $conn,
        int $tenant,
        int $branch,
        string $idempotencyKey,
        string $requestHash,
        string $requestJson,
        string $moovaOrderId,
        string $moovaBranchId
    ): int {
        $status = 'processing';
        $stmt = $conn->prepare("
            INSERT INTO moova_pos_order_links (
                idempotency_key, request_hash, moova_order_id, moova_branch_id,
                pos_tenant, pos_branch, provider_status, request_payload
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssiiss', $idempotencyKey, $requestHash, $moovaOrderId, $moovaBranchId, $tenant, $branch, $status, $requestJson);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function updateOrderLinkSuccess(
        mysqli $conn,
        int $linkId,
        int $orderId,
        string $providerStatus,
        array $response,
        ?string $stateHash,
        $statePayload
    ): void {
        $responseJson = $this->encodeJson($response);
        $statePayloadJson = $statePayload === null ? null : $this->encodeJson($statePayload);
        $stmt = $conn->prepare("
            UPDATE moova_pos_order_links
            SET pos_order_id = ?,
                provider_status = ?,
                last_pos_state_hash = ?,
                last_pos_state_payload = ?,
                response_payload = ?
            WHERE id = ?
        ");
        $stmt->bind_param('issssi', $orderId, $providerStatus, $stateHash, $statePayloadJson, $responseJson, $linkId);
        $stmt->execute();
        $stmt->close();
    }

    private function responseFromOrder(array $order, string $idempotencyKey, string $providerStatus): array
    {
        $response = [
            'success' => true,
            'applied' => true,
            'orderId' => (int) $order['order_id'],
            'providerOrderId' => (string) $order['order_id'],
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => $providerStatus,
            'merged' => !empty($order['merged']),
            'receiptUrl' => 'print/receipt.php?id=' . (int) $order['order_id'],
            'tableId' => (int) $order['table_id'],
            'tableName' => $order['table_name'],
            'totals' => [
                'total' => (float) $order['total'],
                'discount' => (float) $order['discount'],
                'net' => (float) $order['net'],
            ],
        ];

        if (!empty($order['state_hash'])) {
            $response['stateHash'] = $order['state_hash'];
        }

        return $response;
    }

    private function responseFromExistingLink(array $orderLink, string $idempotencyKey): array
    {
        return [
            'success' => true,
            'applied' => true,
            'orderId' => (int) $orderLink['pos_order_id'],
            'providerOrderId' => (string) $orderLink['pos_order_id'],
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => (string) ($orderLink['provider_status'] ?: 'created'),
        ];
    }

    private function stampResponse(array $response, string $responseMode, string $syncStatus): array
    {
        if ($responseMode === 'queued') {
            return MoovaApplyResponse::queuedWorker($response, 'new_order', $syncStatus);
        }

        return MoovaApplyResponse::directWidget($response, 'new_order', $syncStatus);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
