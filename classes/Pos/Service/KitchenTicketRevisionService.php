<?php

require_once __DIR__ . '/OrderPrintPayloadService.php';

class KitchenTicketRevisionService
{
    private OrderPrintPayloadService $printPayloadService;

    public function __construct(?OrderPrintPayloadService $printPayloadService = null)
    {
        $this->printPayloadService = $printPayloadService ?: new OrderPrintPayloadService();
    }

    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'kitchen_order_revisions'");

        return $result && $result->num_rows > 0;
    }

    /**
     * @return array{revision:int,supersedes_revision:?int,is_current:bool,kot_payload:?array}
     */
    public function recordRevision(mysqli $conn, int $orderId, int $revision): array
    {
        if ($orderId < 1 || $revision < 1) {
            return [
                'revision' => max(0, $revision),
                'supersedes_revision' => null,
                'is_current' => false,
                'kot_payload' => null,
            ];
        }

        if (!$this->tableExists($conn)) {
            $kotPayload = $this->safeBuildKotPayload($conn, $orderId);

            return [
                'revision' => $revision,
                'supersedes_revision' => $revision > 1 ? $revision - 1 : null,
                'is_current' => true,
                'kot_payload' => $kotPayload,
            ];
        }

        $kotPayload = $this->safeBuildKotPayload($conn, $orderId);
        $payloadJson = json_encode($kotPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }
        $payloadHash = hash('sha256', $payloadJson);

        $supersedesRevision = null;
        $currentRow = $this->fetchCurrentRevision($conn, $orderId);
        if ($currentRow) {
            $supersedesRevision = (int) ($currentRow['revision'] ?? 0);
            $stmt = $conn->prepare("
                UPDATE kitchen_order_revisions
                   SET status = 'superseded', superseded_at = NOW()
                 WHERE order_id = ? AND status = 'current'
            ");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("
            INSERT INTO kitchen_order_revisions (
                order_id, revision, status, kot_payload_json, payload_hash, created_at
            ) VALUES (?, ?, 'current', ?, ?, NOW())
        ");
        $stmt->bind_param('iiss', $orderId, $revision, $payloadJson, $payloadHash);
        $stmt->execute();
        $stmt->close();

        return [
            'revision' => $revision,
            'supersedes_revision' => $supersedesRevision > 0 ? $supersedesRevision : ($revision > 1 ? $revision - 1 : null),
            'is_current' => true,
            'kot_payload' => $kotPayload,
        ];
    }

    private function fetchCurrentRevision(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, revision
              FROM kitchen_order_revisions
             WHERE order_id = ? AND status = 'current'
             ORDER BY revision DESC
             LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function safeBuildKotPayload(mysqli $conn, int $orderId): ?array
    {
        try {
            return $this->printPayloadService->buildKotPayloadByOrderId($conn, $orderId);
        } catch (Throwable $exception) {
            error_log('KitchenTicketRevisionService payload build skipped: ' . $exception->getMessage());

            return null;
        }
    }
}
