<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include(__DIR__ . '/../includes/ajax_header.php');

header('Content-Type: application/json; charset=utf-8');

$zones = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'delivery_zones'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $result = $conn->query("
        SELECT id, name, fee
        FROM delivery_zones
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $zones[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'fee' => (float) $row['fee'],
            ];
        }
    }
}

echo json_encode(['success' => true, 'zones' => $zones], JSON_UNESCAPED_UNICODE);
