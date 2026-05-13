<?php

if (!class_exists('SyncBranchIdentity')) {
    require_once __DIR__ . '/BranchIdentity.php';
}
if (!class_exists('MoovaInboundQueueService')) {
    require_once __DIR__ . '/MoovaInboundQueueService.php';
}
if (!class_exists('MoovaLocalIngestService')) {
    require_once __DIR__ . '/../Moova/MoovaLocalIngestService.php';
}
if (!class_exists('MoovaNewOrderApplyService')) {
    require_once __DIR__ . '/../Moova/MoovaNewOrderApplyService.php';
}
if (!class_exists('MoovaChangeOrderApplyService')) {
    require_once __DIR__ . '/../Moova/MoovaChangeOrderApplyService.php';
}
if (!class_exists('MoovaApplyResponse')) {
    require_once __DIR__ . '/../Moova/MoovaApplyResponse.php';
}
if (!class_exists('MoovaPosIntegration')) {
    require_once __DIR__ . '/../MoovaPosIntegration.php';
}
if (!class_exists('PosOrderService')) {
    require_once __DIR__ . '/../PosOrderService.php';
}

class BranchMoovaApplyWorker
{
    private MoovaInboundQueueService $inboundQueue;
    private MoovaLocalIngestService $localIngest;
    private PosOrderService $posOrders;
    private MoovaNewOrderApplyService $newOrderApply;
    private MoovaChangeOrderApplyService $changeOrderApply;

    public function __construct(
        ?MoovaInboundQueueService $inboundQueue = null,
        ?MoovaLocalIngestService $localIngest = null,
        ?PosOrderService $posOrders = null,
        ?MoovaNewOrderApplyService $newOrderApply = null,
        ?MoovaChangeOrderApplyService $changeOrderApply = null
    ) {
        $this->inboundQueue = $inboundQueue ?: new MoovaInboundQueueService();
        $this->localIngest = $localIngest ?: new MoovaLocalIngestService($this->inboundQueue);
        $this->posOrders = $posOrders ?: new PosOrderService();
        $this->newOrderApply = $newOrderApply ?: new MoovaNewOrderApplyService($this->posOrders);
        $this->changeOrderApply = $changeOrderApply ?: new MoovaChangeOrderApplyService($this->posOrders);
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $runUuid = $this->uuid();
        $metrics = [
            'worker' => 'moova_apply',
            'run_uuid' => $runUuid,
            'claimed' => 0,
            'applied' => 0,
            'declined' => 0,
            'failed' => 0,
            'skipped' => null,
        ];

        $this->logWorker($conn, $runUuid, 'started', 'Moova apply worker started', $metrics);

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $metrics['skipped'] = 'not_branch_role';
            $this->logWorker($conn, $runUuid, 'success', 'Moova apply worker skipped outside branch role', $metrics);
            return $metrics;
        }

        if (empty($config['sync']['moova_apply_enabled'])) {
            $metrics['skipped'] = 'moova_apply_disabled';
            $this->logWorker($conn, $runUuid, 'success', 'Moova apply worker disabled by config', $metrics);
            return $metrics;
        }

        $identity = (new SyncBranchIdentity())->ensure($conn, $config);
        $branchUuid = (string) $identity['branch_uuid'];
        $limit = max(1, min(100, (int) ($options['batch_size'] ?? 25)));
        $rows = $this->inboundQueue->claimPending($conn, [
            'branch_uuid' => $branchUuid,
            'pos_tenant' => $identity['pos_tenant'] ?? 0,
            'pos_branch' => $identity['pos_branch'] ?? 0,
        ], [
            'worker_name' => 'moova-apply',
            'limit' => $limit,
            'lock_ttl_seconds' => (int) ($options['lock_ttl_seconds'] ?? 60),
            'event_types' => ['new_order', 'edit_order', 'cancel_order'],
        ]);

        $metrics['claimed'] = count($rows);
        foreach ($rows as $row) {
            if ((string) ($row['event_type'] ?? '') === 'cancel_order') {
                $status = $this->applyChangeOrderRow($conn, $row, $identity, $config, 'cancel');
            } elseif ((string) ($row['event_type'] ?? '') === 'edit_order') {
                $status = $this->applyChangeOrderRow($conn, $row, $identity, $config, 'edit');
            } else {
                $status = $this->applyNewOrderRow($conn, $row, $identity, $config);
            }
            if ($status === 'applied') {
                $metrics['applied']++;
            } elseif ($status === 'declined') {
                $metrics['declined']++;
            } else {
                $metrics['failed']++;
            }
        }

        $status = $metrics['failed'] > 0 ? 'failed' : 'success';
        $this->logWorker($conn, $runUuid, $status, 'Moova apply batch finished', $metrics);

        return $metrics;
    }

    private function applyNewOrderRow(mysqli $conn, array $row, array $identity, array $config): string
    {
        try {
            $posPayload = $this->localIngest->normalizeNewOrderForPos($row['payload'] ?? []);
            MoovaPosIntegration::ensureSchema($conn);

            $tenant = (int) ($identity['pos_tenant'] ?? $row['pos_tenant'] ?? 0);
            $branch = (int) ($identity['pos_branch'] ?? $row['pos_branch'] ?? 0);
            $userId = max(1, (int) ($config['sync']['moova_apply_user_id'] ?? 1));
            $idempotencyKey = (string) $row['idempotency_key'];
            $requestHash = (string) $row['request_hash'];
            $requestJson = (string) $row['payload_json'];
            $moovaOrderId = (string) $row['moova_order_id'];
            $moovaBranchId = (string) ($row['moova_branch_id'] ?: ($posPayload['branchId'] ?? ''));

            $conn->begin_transaction();
            try {
                $result = $this->newOrderApply->applyInTransaction($conn, [
                    'tenant' => $tenant,
                    'branch' => $branch,
                    'pos_tenant' => $tenant,
                    'pos_branch' => $branch,
                    'moova_branch_id' => $moovaBranchId,
                ], $posPayload, [
                    'user_id' => $userId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'request_json' => $requestJson,
                    'moova_order_id' => $moovaOrderId,
                    'moova_branch_id' => $moovaBranchId,
                    'response_mode' => 'queued',
                ]);
                $response = $result['response'];
                $this->inboundQueue->markProcessingResultInTransaction($conn, (int) $row['id'], 'applied', $response, [
                    'pos_order_id' => (int) $result['order_id'],
                ]);
                $conn->commit();

                return 'applied';
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            $code = $this->errorCode($e->getMessage());
            $status = $this->isDeclineCode($code) ? 'declined' : 'failed';
            $result = [
                'success' => true,
                'applied' => false,
                'retryable' => $status === 'failed',
                'providerStatus' => $status,
                'code' => $code,
                'message' => $e->getMessage(),
            ];
            $result = MoovaApplyResponse::queuedWorker($result, 'new_order', $status);
            $this->inboundQueue->markProcessingResult($conn, (int) $row['id'], $status, $result, [
                'error_message' => $e->getMessage(),
            ]);

            return $status;
        }
    }

    private function applyChangeOrderRow(mysqli $conn, array $row, array $identity, array $config, string $expectedAction): string
    {
        try {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if (!isset($payload['event_type']) && !isset($payload['eventType'])) {
                $payload['event_type'] = $expectedAction === 'cancel' ? 'cancel_order' : 'edit_order';
            }
            $posPayload = $this->localIngest->normalizeChangeForPos($payload);
            if (($posPayload['action'] ?? '') !== $expectedAction) {
                throw new RuntimeException('INVALID_ACTION');
            }
            MoovaPosIntegration::ensureSchema($conn);

            $tenant = (int) ($identity['pos_tenant'] ?? $row['pos_tenant'] ?? 0);
            $branch = (int) ($identity['pos_branch'] ?? $row['pos_branch'] ?? 0);
            $userId = max(1, (int) ($config['sync']['moova_apply_user_id'] ?? 1));
            $idempotencyKey = (string) $row['idempotency_key'];
            $requestHash = (string) $row['request_hash'];
            $requestJson = (string) $row['payload_json'];
            $moovaOrderId = (string) ($row['moova_order_id'] ?: ($posPayload['moovaOrderId'] ?? ''));
            $moovaBranchId = (string) ($row['moova_branch_id'] ?: ($posPayload['branchId'] ?? ''));

            $conn->begin_transaction();
            try {
                $result = $this->changeOrderApply->applyInTransaction($conn, [
                    'tenant' => $tenant,
                    'branch' => $branch,
                    'pos_tenant' => $tenant,
                    'pos_branch' => $branch,
                    'moova_branch_id' => $moovaBranchId,
                ], $posPayload, [
                    'user_id' => $userId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'request_json' => $requestJson,
                    'moova_order_id' => $moovaOrderId,
                    'moova_branch_id' => $moovaBranchId,
                    'request_event_id' => (string) (($posPayload['requestEventId'] ?? '') ?: ($payload['request_event_id'] ?? '')),
                    'action' => $expectedAction,
                    'response_mode' => 'queued',
                ]);
                $resultOptions = [];
                if ((int) ($result['pos_order_id'] ?? 0) > 0) {
                    $resultOptions['pos_order_id'] = (int) $result['pos_order_id'];
                }
                if (!empty($result['error_message'])) {
                    $resultOptions['error_message'] = (string) $result['error_message'];
                }
                $status = (string) $result['status'];
                $this->inboundQueue->markProcessingResultInTransaction($conn, (int) $row['id'], $status, $result['response'], $resultOptions);
                $conn->commit();

                return $status;
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            $code = $this->errorCode($e->getMessage());
            $status = $this->isChangeDeclineCode($code) ? 'declined' : 'failed';
            $result = $this->changeDeclineResponse($expectedAction, (string) ($row['moova_order_id'] ?? ''), (string) ($row['idempotency_key'] ?? ''), $code, $e->getMessage());
            $result['retryable'] = $status === 'failed';
            $result['providerStatus'] = $status;
            $result = MoovaApplyResponse::queuedWorkerChange($result, $expectedAction, $status);
            $this->inboundQueue->markProcessingResult($conn, (int) $row['id'], $status, $result, [
                'error_message' => $e->getMessage(),
            ]);

            return $status;
        }
    }

    private function changeDeclineResponse(string $action, string $moovaOrderId, string $idempotencyKey, string $code, ?string $message = null): array
    {
        return MoovaApplyResponse::queuedWorkerChange([
            'success' => true,
            'applied' => false,
            'retryable' => false,
            'action' => $action,
            'moovaOrderId' => $moovaOrderId,
            'providerReferenceId' => $idempotencyKey,
            'providerStatus' => 'declined',
            'code' => $code,
            'message' => $message ?: $this->changeDeclineMessage($code),
        ], $action, 'declined');
    }

    private function changeDeclineMessage(string $code): string
    {
        return MoovaApplyResponse::declineMessage($code);
    }

    private function errorCode(string $message): string
    {
        if (strpos($message, ':') !== false) {
            $message = substr($message, 0, strpos($message, ':'));
        }

        return preg_replace('/[^A-Z0-9_]/', '_', strtoupper($message ?: 'MOOVA_APPLY_FAILED'));
    }

    private function isDeclineCode(string $code): bool
    {
        return in_array($code, [
            'TABLE_REQUIRED',
            'TABLE_NOT_FOUND',
            'TABLE_MAPPING_AMBIGUOUS',
            'ITEM_NOT_FOUND',
            'NO_VALID_ITEMS',
            'IDEMPOTENCY_PAYLOAD_CONFLICT',
        ], true);
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

    private function logWorker(mysqli $conn, string $runUuid, string $status, string $message, array $metrics): void
    {
        try {
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $workerName = 'moova_apply';
            $stmt = $conn->prepare("
                INSERT INTO sync_worker_logs (worker_name, run_uuid, status, message, metrics_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssss', $workerName, $runUuid, $status, $message, $metricsJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignored) {
        }
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
