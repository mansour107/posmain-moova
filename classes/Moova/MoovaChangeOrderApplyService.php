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
if (!class_exists('SyncOutboxEventService')) {
    require_once __DIR__ . '/../Sync/SyncOutboxEventService.php';
}
if (!class_exists('OrderFulfillmentService')) {
    require_once __DIR__ . '/../Pos/Service/OrderFulfillmentService.php';
}

class MoovaChangeOrderApplyService
{
    private PosOrderService $posOrders;
    private SyncOutboxEventService $syncOutbox;
    private OrderFulfillmentService $fulfillment;

    public function __construct(?PosOrderService $posOrders = null, ?SyncOutboxEventService $syncOutbox = null, ?OrderFulfillmentService $fulfillment = null)
    {
        $this->posOrders = $posOrders ?: new PosOrderService();
        $this->syncOutbox = $syncOutbox ?: new SyncOutboxEventService();
        $this->fulfillment = $fulfillment ?: new OrderFulfillmentService();
    }

    public function applyInTransaction(mysqli $conn, array $link, array $payload, array $options = []): array
    {
        $action = strtolower(trim((string) ($options['action'] ?? ($payload['action'] ?? ''))));
        if ($action !== 'edit' && $action !== 'cancel') {
            throw new RuntimeException('INVALID_ACTION');
        }

        $tenant = (int) ($link['pos_tenant'] ?? $options['tenant'] ?? 0);
        $branch = (int) ($link['pos_branch'] ?? $options['branch'] ?? 0);
        $userId = max(1, (int) ($options['user_id'] ?? 1));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ($payload['idempotencyKey'] ?? '')));
        if ($idempotencyKey === '') {
            throw new RuntimeException('IDEMPOTENCY_REQUIRED');
        }

        $moovaOrderId = trim((string) ($options['moova_order_id'] ?? ($payload['moovaOrderId'] ?? $payload['orderId'] ?? '')));
        if ($moovaOrderId === '') {
            throw new RuntimeException('MOOVA_ORDER_REQUIRED');
        }

        $requestHash = (string) ($options['request_hash'] ?? MoovaPosIntegration::changePayloadHash($payload));
        $requestJson = (string) ($options['request_json'] ?? $this->encodeJson(MoovaPosIntegration::normalizeChangePayloadForHash($payload)));
        $requestEventId = trim((string) ($options['request_event_id'] ?? ($payload['requestEventId'] ?? $payload['request_event_id'] ?? '')));
        $moovaBranchId = trim((string) ($options['moova_branch_id'] ?? ($payload['branchId'] ?? ($link['moova_branch_id'] ?? ''))));
        $responseMode = (string) ($options['response_mode'] ?? 'direct');
        $syncConfig = is_array($options['config'] ?? null) ? $options['config'] : [];
        $branchUuid = trim((string) ($link['branch_uuid'] ?? $options['branch_uuid'] ?? ($syncConfig['sync']['branch_uuid'] ?? '')));

        $changeLink = $this->fetchChangeLinkForUpdate($conn, $tenant, $branch, $idempotencyKey);
        if ($changeLink && !hash_equals((string) $changeLink['request_hash'], $requestHash)) {
            throw new RuntimeException('IDEMPOTENCY_PAYLOAD_CONFLICT');
        }

        if ($changeLink && (string) ($changeLink['provider_status'] ?? '') !== 'processing') {
            $status = (string) ($changeLink['provider_status'] ?? '') === 'declined' ? 'declined' : 'applied';
            $response = json_decode((string) ($changeLink['response_payload'] ?? ''), true);
            if (!is_array($response)) {
                $response = $this->declineResponse($action, $moovaOrderId, $idempotencyKey, 'MOOVA_APPLY_FAILED', null, $responseMode);
            }
            $responseAction = (string) ($response['action'] ?? $action);
            $response = $this->stampChangeResponse($response, $responseAction, $responseMode, $status);

            return [
                'existing' => true,
                'status' => $status,
                'provider_status' => (string) ($changeLink['provider_status'] ?? $status),
                'pos_order_id' => (int) ($changeLink['pos_order_id'] ?? 0),
                'response' => $response,
            ];
        }

        $orderLink = $this->fetchOrderLinkByMoovaOrderForUpdate($conn, $tenant, $branch, $moovaOrderId);
        $changeLinkId = $changeLink
            ? (int) $changeLink['id']
            : $this->createChangeLink(
                $conn,
                $tenant,
                $branch,
                $idempotencyKey,
                $requestHash,
                $requestJson,
                $moovaOrderId,
                $requestEventId,
                $action,
                $moovaBranchId ?: (string) ($orderLink['moova_branch_id'] ?? ''),
                $orderLink ? (int) $orderLink['pos_order_id'] : null
            );

        $declineCode = $this->preApplyDeclineCode($conn, $tenant, $branch, $orderLink, $payload, $moovaOrderId);
        if ($declineCode !== null) {
            $response = $this->declineResponse($action, $moovaOrderId, $idempotencyKey, $declineCode, null, $responseMode);
            $this->updateChangeAction($conn, $changeLinkId, 'declined', $response);

            return [
                'existing' => false,
                'status' => 'declined',
                'provider_status' => 'declined',
                'pos_order_id' => $orderLink ? (int) $orderLink['pos_order_id'] : 0,
                'error_message' => $declineCode,
                'response' => $response,
            ];
        }

        try {
            $posOrderId = (int) ($orderLink['pos_order_id'] ?? 0);
            $isDelivery = $posOrderId > 0
                && $this->posOrders->isDeliveryMoovaOrder($conn, $tenant, $branch, $posOrderId);
            $scope = [
                'tenant' => $tenant,
                'branch' => $branch,
                'user_id' => $userId,
                'branch_uuid' => $branchUuid,
            ];

            if ($action === 'edit') {
                $payload['expectedStateHash'] = (string) $orderLink['last_pos_state_hash'];
                if ($isDelivery) {
                    $result = $this->posOrders->replaceMoovaDeliveryOrder($conn, $scope, $posOrderId, $payload);
                } else {
                    $result = $this->posOrders->replaceMoovaTableOrder($conn, $scope, $posOrderId, $payload);
                }
                $this->fulfillment->upsertMoovaFulfillment($conn, $posOrderId, $payload, [
                    'require_table' => !$isDelivery,
                ]);
                $providerStatus = 'edited';
                $response = $this->editResponse($result, $moovaOrderId, $idempotencyKey, $providerStatus);
                $this->updateOrderLinkState($conn, (int) $orderLink['id'], $providerStatus, $result['state_hash'] ?? null, $result['state_payload'] ?? null);
            } else {
                if ($isDelivery) {
                    $result = $this->posOrders->cancelMoovaDeliveryOrder($conn, $scope, $posOrderId, $moovaOrderId, (string) $orderLink['last_pos_state_hash']);
                } else {
                    $result = $this->posOrders->cancelMoovaTableOrder($conn, $scope, $posOrderId, $moovaOrderId, (string) $orderLink['last_pos_state_hash']);
                }
                $providerStatus = 'cancelled';
                $response = $this->cancelResponse($result, $moovaOrderId, $idempotencyKey, $providerStatus);
                $this->updateOrderLinkState($conn, (int) $orderLink['id'], $providerStatus, null, null);
            }
        } catch (Throwable $serviceError) {
            $code = $this->errorCode($serviceError->getMessage());
            if (!$this->isChangeDeclineCode($code)) {
                throw $serviceError;
            }

            $response = $this->declineResponse($action, $moovaOrderId, $idempotencyKey, $code, null, $responseMode);
            $this->updateChangeAction($conn, $changeLinkId, 'declined', $response);

            return [
                'existing' => false,
                'status' => 'declined',
                'provider_status' => 'declined',
                'pos_order_id' => (int) $orderLink['pos_order_id'],
                'error_message' => $code,
                'response' => $response,
            ];
        }

        $response = $this->stampChangeResponse($response, $action, $responseMode, 'applied');
        $this->updateChangeAction($conn, $changeLinkId, $providerStatus, $response);

        $orderId = (int) $result['order_id'];
        $this->syncOutbox->recordOrderSnapshot($conn, $orderId, [
            'event_type' => $action === 'cancel' ? 'order.cancelled' : 'order.updated',
            'source_system' => 'moova_pos',
            'config' => $syncConfig,
        ]);
        if ((int) ($result['table_id'] ?? 0) > 0) {
            $this->syncOutbox->recordTableSnapshot($conn, (int) $result['table_id'], [
                'event_type' => 'table.updated',
                'source_system' => 'moova_pos',
                'active_order_id' => $action === 'cancel' ? null : $orderId,
                'config' => $syncConfig,
            ]);
        }

        return [
            'existing' => false,
            'status' => 'applied',
            'provider_status' => $providerStatus,
            'pos_order_id' => $orderId,
            'response' => $response,
        ];
    }

    private function fetchOrderLinkByMoovaOrderForUpdate(mysqli $conn, int $tenant, int $branch, string $moovaOrderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_order_links
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND moova_order_id = ?
              AND pos_order_id IS NOT NULL
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('iis', $tenant, $branch, $moovaOrderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchChangeLinkForUpdate(mysqli $conn, int $tenant, int $branch, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_order_change_links
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

    private function createChangeLink(
        mysqli $conn,
        int $tenant,
        int $branch,
        string $idempotencyKey,
        string $requestHash,
        string $requestJson,
        string $moovaOrderId,
        string $requestEventId,
        string $changeType,
        string $moovaBranchId,
        ?int $orderId
    ): int {
        $status = 'processing';
        $stmt = $conn->prepare("
            INSERT INTO moova_pos_order_change_links (
                idempotency_key, request_hash, moova_order_id, moova_request_event_id,
                change_type, moova_branch_id, pos_tenant, pos_branch, pos_order_id,
                provider_status, request_payload
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssssssiiiss',
            $idempotencyKey,
            $requestHash,
            $moovaOrderId,
            $requestEventId,
            $changeType,
            $moovaBranchId,
            $tenant,
            $branch,
            $orderId,
            $status,
            $requestJson
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function updateOrderLinkState(mysqli $conn, int $linkId, string $providerStatus, ?string $stateHash, $statePayload): void
    {
        $statePayloadJson = $statePayload === null ? null : $this->encodeJson($statePayload);
        $stmt = $conn->prepare("
            UPDATE moova_pos_order_links
            SET provider_status = ?,
                last_pos_state_hash = ?,
                last_pos_state_payload = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $providerStatus, $stateHash, $statePayloadJson, $linkId);
        $stmt->execute();
        $stmt->close();
    }

    private function updateChangeAction(mysqli $conn, int $changeLinkId, string $providerStatus, array $response): void
    {
        $responseJson = $this->encodeJson($response);
        $stmt = $conn->prepare("
            UPDATE moova_pos_order_change_links
            SET provider_status = ?,
                response_payload = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $providerStatus, $responseJson, $changeLinkId);
        $stmt->execute();
        $stmt->close();
    }

    private function preApplyDeclineCode(mysqli $conn, int $tenant, int $branch, ?array $orderLink, array $payload, string $moovaOrderId): ?string
    {
        if (!$orderLink) {
            return 'POS_ORDER_LINK_NOT_FOUND';
        }

        $requestedProviderOrderId = trim((string) ($payload['providerOrderId'] ?? $payload['provider_order_id'] ?? ''));
        $linkedProviderOrderId = trim((string) ($orderLink['pos_order_id'] ?? ''));
        if ($requestedProviderOrderId !== '' && $linkedProviderOrderId !== '' && $requestedProviderOrderId !== $linkedProviderOrderId) {
            return 'POS_PROVIDER_ORDER_MISMATCH';
        }

        $currentSnapshot = $this->posOrders->getMoovaOrderStateSnapshot($conn, $tenant, $branch, (int) $orderLink['pos_order_id']);
        if (!$currentSnapshot) {
            return 'POS_ORDER_NOT_FOUND';
        }

        $headDeclineCode = $this->headDeclineCode($currentSnapshot);
        if ($headDeclineCode !== null) {
            return $headDeclineCode;
        }

        $lineSnapshot = $this->posOrders->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, (int) $orderLink['pos_order_id'], $moovaOrderId);
        if (!$lineSnapshot || empty($lineSnapshot['lines'])) {
            return 'POS_ORDER_LINES_UNMAPPED';
        }

        if (empty($orderLink['last_pos_state_hash'])) {
            return 'POS_STATE_UNKNOWN';
        }

        if (!hash_equals((string) $orderLink['last_pos_state_hash'], (string) $lineSnapshot['hash'])) {
            return 'POS_ORDER_LINES_CHANGED';
        }

        return null;
    }

    private function headDeclineCode(?array $snapshot): ?string
    {
        $head = $snapshot['payload']['head'] ?? [];
        if ((int) ($head['isdeleted'] ?? 0) === 1) {
            return 'POS_ORDER_DELETED';
        }
        if ((int) ($head['pro_tybe'] ?? 0) !== PosOrderService::TYPE_POS) {
            return 'POS_ORDER_NOT_TABLE';
        }
        $orderType = strtolower(trim((string) ($head['order_type'] ?? '')));
        if ($orderType !== 'delivery' && (int) ($head['table_id'] ?? 0) < 1) {
            return 'POS_ORDER_NOT_TABLE';
        }
        $paymentStatus = strtolower(trim((string) ($head['payment_status'] ?? '')));
        $paidAmount = (float) ($head['paid_amount'] ?? 0);
        if (($paymentStatus !== '' && $paymentStatus !== 'unpaid') || $paidAmount > 0) {
            return 'POS_ORDER_PAID';
        }
        if ((float) ($head['fat_net'] ?? 0) <= 0) {
            return 'POS_ORDER_NOT_ACTIVE';
        }

        return null;
    }

    private function editResponse(array $result, string $moovaOrderId, string $idempotencyKey, string $providerStatus): array
    {
        return [
            'success' => true,
            'applied' => true,
            'action' => 'edit',
            'moovaOrderId' => $moovaOrderId,
            'orderId' => (int) $result['order_id'],
            'providerOrderId' => (string) $result['order_id'],
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => $providerStatus,
            'stateHash' => $result['state_hash'] ?? null,
            'tableId' => (int) $result['table_id'],
            'tableName' => $result['table_name'],
            'totals' => [
                'total' => (float) $result['total'],
                'discount' => (float) $result['discount'],
                'net' => (float) $result['net'],
            ],
        ];
    }

    private function cancelResponse(array $result, string $moovaOrderId, string $idempotencyKey, string $providerStatus): array
    {
        return [
            'success' => true,
            'applied' => true,
            'action' => 'cancel',
            'moovaOrderId' => $moovaOrderId,
            'orderId' => (int) $result['order_id'],
            'providerOrderId' => (string) $result['order_id'],
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => $providerStatus,
            'tableId' => (int) $result['table_id'],
        ];
    }

    private function declineResponse(string $action, string $moovaOrderId, string $idempotencyKey, string $code, ?string $message, string $responseMode): array
    {
        return $this->stampChangeResponse([
            'success' => true,
            'applied' => false,
            'retryable' => false,
            'action' => $action,
            'moovaOrderId' => $moovaOrderId,
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => 'declined',
            'code' => $code,
            'message' => $message ?: MoovaApplyResponse::declineMessage($code),
        ], $action, $responseMode, 'declined');
    }

    private function stampChangeResponse(array $response, string $action, string $responseMode, string $syncStatus): array
    {
        if ($responseMode === 'queued') {
            return MoovaApplyResponse::queuedWorkerChange($response, $action, $syncStatus);
        }

        return MoovaApplyResponse::directWidgetChange($response, $action, $syncStatus);
    }

    private function errorCode(string $message): string
    {
        if (strpos($message, ':') !== false) {
            $message = substr($message, 0, strpos($message, ':'));
        }

        return preg_replace('/[^A-Z0-9_]/', '_', strtoupper($message ?: 'MOOVA_APPLY_FAILED'));
    }

    private function isChangeDeclineCode(string $code): bool
    {
        return in_array($code, [
            'POS_ORDER_LINK_NOT_FOUND',
            'POS_PROVIDER_ORDER_MISMATCH',
            'POS_STATE_UNKNOWN',
            'POS_ORDER_NOT_FOUND',
            'POS_ORDER_DELETED',
            'POS_ORDER_NOT_TABLE',
            'POS_ORDER_NOT_DELIVERY',
            'POS_ORDER_PAID',
            'POS_ORDER_NOT_ACTIVE',
            'POS_ORDER_CHANGED',
            'POS_ORDER_LINES_CHANGED',
            'POS_ORDER_LINES_UNMAPPED',
            'ITEM_NOT_FOUND',
            'NO_VALID_ITEMS',
            'TABLE_NOT_FOUND',
            'IDEMPOTENCY_PAYLOAD_CONFLICT',
        ], true);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
