<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include(__DIR__ . '/../includes/ajax_header.php');

header('Content-Type: application/json; charset=utf-8');

$zones = [];
$tenant = max(0, (int) ($_SESSION['pos_tenant'] ?? 0));
$branch = max(0, (int) ($_SESSION['pos_branch'] ?? 0));
$tableCheck = $conn->query("SHOW TABLES LIKE 'delivery_zones'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT id, name, fee
        FROM delivery_zones
        WHERE is_active = 1 AND tenant = ? AND branch = ?
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->bind_param('ii', $tenant, $branch);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $zones[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'fee' => (float) $row['fee'],
            ];
        }
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'zones' => $zones], JSON_UNESCAPED_UNICODE);
