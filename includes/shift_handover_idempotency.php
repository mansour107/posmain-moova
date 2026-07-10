<?php

require_once __DIR__ . '/../classes/Pos/Service/IdempotencyService.php';
require_once __DIR__ . '/db_transaction.php';

/**
 * @param callable(array $txContext): array $handler Must return response array with success key.
 *        Receives ['in_transaction' => true] so nested services join this TX.
 */
function pos_shift_handover_idempotent(
    mysqli $conn,
    string $scope,
    array $post,
    array $server,
    int $userId,
    callable $handler
): array {
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($post, $server);
    $idempotencyHash = $idempotencyService->requestHashForPayload($post);
    $tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
    $branch = (int) ($_SESSION['pos_branch'] ?? 0);

    $conn->begin_transaction();

    try {
        $idempotency = $idempotencyService->begin($conn, $scope, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => $tenant,
            'branch' => $branch,
            'stale_after_seconds' => 300,
        ]);

        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'success' => false,
                'code' => 'IDEMPOTENCY_CONFLICT',
                'error' => 'IDEMPOTENCY_CONFLICT',
                'request_id' => $idempotencyKey,
            ];
        }

        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();
            $response = $idempotency['response'];
            if (is_array($response)) {
                $response['idempotency_replayed'] = true;
            }

            return $response;
        }

        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            throw new RuntimeException('IDEMPOTENCY_IN_PROGRESS');
        }

        $txContext = ['in_transaction' => true];
        $response = $handler($txContext);
        if (!is_array($response)) {
            throw new RuntimeException('IDEMPOTENCY_HANDLER_INVALID_RESPONSE');
        }

        $idempotencyService->complete($conn, $scope, $idempotencyKey, $idempotencyHash, $response);
        $conn->commit();

        if (!empty($response['result']['clear_pos_shift_session']) || !empty($response['clear_pos_shift_session'])) {
            require_once __DIR__ . '/../classes/Pos/Service/ShiftCloseService.php';
            (new ShiftCloseService())->clearPosShiftSessionAfterClose();
            if (isset($response['result']) && is_array($response['result'])) {
                unset($response['result']['clear_pos_shift_session']);
            }
            unset($response['clear_pos_shift_session']);
        }

        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
