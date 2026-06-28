<?php

error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_permission('pos.open', $conn);

$staleSeconds = 300;
$staleCutoff = date('Y-m-d H:i:s', time() - $staleSeconds);

$stuckIdempotency = 0;
$failedOutbox = 0;

$tableCheck = $conn->query("SHOW TABLES LIKE 'pos_request_keys'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM pos_request_keys WHERE status = 'processing' AND updated_at < ?");
    if ($stmt) {
        $stmt->bind_param('s', $staleCutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stuckIdempotency = (int) ($row['c'] ?? 0);
        $stmt->close();
    }
}

$outboxCheck = $conn->query("SHOW TABLES LIKE 'sync_outbox'");
if ($outboxCheck && $outboxCheck->num_rows > 0) {
    $result = $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE status IN ('failed', 'dead')");
    if ($result) {
        $row = $result->fetch_assoc();
        $failedOutbox = (int) ($row['c'] ?? 0);
    }
}

echo json_encode([
    'success' => true,
    'code' => 'OK',
    'recovery' => [
        'stuck_idempotency' => $stuckIdempotency,
        'failed_outbox_events' => $failedOutbox,
        'stale_after_seconds' => $staleSeconds,
        'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ],
], JSON_UNESCAPED_UNICODE);
