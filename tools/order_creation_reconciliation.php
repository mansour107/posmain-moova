#!/usr/bin/env php
<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/SideEffectPolicy.php';

$conn = posmain_db_connect();
$since = trim((string) ($argv[1] ?? date('Y-m-d', strtotime('-1 day'))));

$orders = (int) ($conn->query("SELECT COUNT(*) AS c FROM ot_head WHERE pro_tybe = 9 AND pro_date >= '{$conn->real_escape_string($since)}'")->fetch_assoc()['c'] ?? 0);
$outbox = 0;
$outboxResult = $conn->query("SHOW TABLES LIKE 'sync_outbox'");
if ($outboxResult && $outboxResult->num_rows > 0) {
    $outbox = (int) ($conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE created_at >= '{$conn->real_escape_string($since)} 00:00:00'")->fetch_assoc()['c'] ?? 0);
}

echo json_encode([
    'since' => $since,
    'orders_created' => $orders,
    'sync_outbox_events' => $outbox,
    'order_side_effect_mode' => class_exists('SideEffectPolicy') ? SideEffectPolicy::mode() : 'shadow',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
