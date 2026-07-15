#!/usr/bin/env php
<?php
/**
 * Seeds one open drawer session for dashboard attention e2e (optional helper).
 * Usage: php tests/e2e/helpers/seed_dashboard_attention_cli.php
 */
require_once dirname(__DIR__, 3) . '/includes/connect.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "no-db\n");
    exit(1);
}

$check = $conn->query("SHOW TABLES LIKE 'drawer_sessions'");
if (!$check || $check->num_rows < 1) {
    echo "drawer_sessions-missing\n";
    exit(0);
}

$open = $conn->query("SELECT COUNT(*) AS c FROM drawer_sessions WHERE status = 'open'");
$row = $open ? $open->fetch_assoc() : null;
if ((int) ($row['c'] ?? 0) > 0) {
    echo "open-drawer-already-present\n";
    exit(0);
}

$userId = 1;
$userRes = $conn->query("SELECT id FROM users WHERE uname = 'p6_admin' LIMIT 1");
if ($userRes && ($u = $userRes->fetch_assoc())) {
    $userId = (int) $u['id'];
}

$cols = [];
$colRes = $conn->query('SHOW COLUMNS FROM drawer_sessions');
while ($colRes && ($c = $colRes->fetch_assoc())) {
    $cols[$c['Field']] = true;
}

$fields = ['user_id', 'status', 'opened_at'];
$values = [$userId, "'open'", 'NOW()'];
if (isset($cols['tenant'])) {
    $fields[] = 'tenant';
    $values[] = '0';
}
if (isset($cols['branch'])) {
    $fields[] = 'branch';
    $values[] = '0';
}
if (isset($cols['opening_cash'])) {
    $fields[] = 'opening_cash';
    $values[] = '0';
}

$sql = 'INSERT INTO drawer_sessions (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')';
if (!$conn->query($sql)) {
    fwrite(STDERR, 'seed-failed: ' . $conn->error . "\n");
    exit(1);
}

echo "open-drawer-seeded\n";
