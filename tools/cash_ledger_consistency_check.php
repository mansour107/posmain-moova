<?php

require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerLedgerPostingService.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$dateFrom = $argv[1] ?? date('Y-m-d');
$dateTo = $argv[2] ?? $dateFrom;
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'posmain';

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');
$violations = [];

if ($conn->query("SHOW TABLES LIKE 'drawer_sessions'")->num_rows < 1) {
    echo "cash-ledger-consistency-ok skipped=no-drawer-tables\n";
    exit(0);
}

$drawer = new DrawerSessionService();
$ledger = new DrawerLedgerPostingService();
$canPost = $ledger->canPost($conn);

$sessionSql = "
    SELECT id
    FROM drawer_sessions
    WHERE opened_at >= ?
      AND opened_at <= ?
";
$params = [$dateFrom, $dateTo . ' 23:59:59'];
$stmt = $conn->prepare($sessionSql);
$stmt->bind_param('ss', ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $sessionId = (int) $row['id'];
    $expected = (float) $drawer->expectedCash($conn, $sessionId);
    $session = $drawer->sessionById($conn, $sessionId);
    if ($session['status'] !== 'closed' || $session['counted_cash'] === null) {
        continue;
    }
    if (abs($expected - (float) $session['counted_cash']) > 0.01) {
        $violations[] = "session {$sessionId}: expected {$expected} != counted {$session['counted_cash']}";
    }
}
$stmt->close();

if ($canPost) {
    $missingVoucher = $conn->query("
        SELECT id, movement_type
        FROM drawer_movements
        WHERE movement_type IN ('paid_in', 'paid_out', 'safe_drop')
          AND created_at >= '{$conn->real_escape_string($dateFrom)}'
          AND created_at <= '{$conn->real_escape_string($dateTo)} 23:59:59'
          AND (ref_ot_head_id IS NULL OR ref_ot_head_id = 0)
        LIMIT 20
    ");
    while ($row = $missingVoucher->fetch_assoc()) {
        $violations[] = "movement {$row['id']} ({$row['movement_type']}) missing ref_ot_head_id";
    }
}

$period = new CashFlowPeriodService();
$payments = $period->paymentBreakdown($conn, ['date_from' => $dateFrom, 'date_to' => $dateTo]);
if (abs((float) ($payments['cash_reconciliation_diff'] ?? 0)) > 0.05) {
    $violations[] = 'cash payments != drawer sale_cash - refund_cash diff=' . ($payments['cash_reconciliation_diff'] ?? '0');
}

$unassigned = $period->movements($conn, [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'only_unassigned' => true,
    'limit' => 1,
    'offset' => 0,
]);
if ((int) ($unassigned['total'] ?? 0) > 0) {
    echo "cash-ledger-consistency-warning unassigned_count=" . (int) $unassigned['total'] . "\n";
}

if ($violations) {
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

echo "cash-ledger-consistency-ok date_from={$dateFrom} date_to={$dateTo}\n";
