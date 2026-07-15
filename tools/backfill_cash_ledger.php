<?php

require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$synthesize = in_array('--synthesize-from-payments', $argv, true);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'posmain';

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');
(new SyncSchemaReadinessGuard())->assertReady($conn);

if ($synthesize) {
    fwrite(STDERR, "SYNTHETIC_HISTORICAL_DRAWER_MOVEMENTS_FORBIDDEN: reconstruct payments through the reviewed financial baseline workflow; do not invent drawer custody.\n");
    exit(2);
}

$drawer = new DrawerSessionService();
$actions = 0;

if ($conn->query("SHOW TABLES LIKE 'drawer_movements'")->num_rows < 1) {
    echo "backfill-cash-ledger-skip no drawer tables\n";
    exit(0);
}

$backfillScope = "
    UPDATE drawer_movements dm
    INNER JOIN drawer_sessions ds ON ds.id = dm.drawer_session_id
    SET dm.tenant = ds.tenant,
        dm.branch = ds.branch
    WHERE dm.drawer_session_id IS NOT NULL
      AND dm.tenant = 0
      AND dm.branch = 0
";
if ($dryRun) {
    echo "[dry-run] {$backfillScope}\n";
} else {
    $conn->query($backfillScope);
    $actions++;
}

$sessionRows = $conn->query("SELECT id, opening_cash, opened_by FROM drawer_sessions");
while ($row = $sessionRows->fetch_assoc()) {
    $sessionId = (int) $row['id'];
    $check = $conn->prepare("SELECT 1 FROM drawer_movements WHERE drawer_session_id = ? AND movement_type = 'opening' LIMIT 1");
    $check->bind_param('i', $sessionId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        continue;
    }
    if ($dryRun) {
        echo "[dry-run] opening movement for session {$sessionId}\n";
        continue;
    }
    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'opening',
        'amount' => (string) ($row['opening_cash'] ?? '0'),
        'created_by' => (int) ($row['opened_by'] ?? 1),
        'allow_zero_amount' => true,
        'reason' => 'backfill_opening',
    ]);
    $actions++;
}

echo "backfill-cash-ledger-ok actions={$actions}" . ($dryRun ? ' dry-run' : '') . "\n";
