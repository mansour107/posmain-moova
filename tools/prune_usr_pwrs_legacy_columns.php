#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';

$conn = posmain_db_connect();
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

$keep = [
    'id' => true,
    'rollname' => true,
    'info' => true,
    'role_key' => true,
    'is_system' => true,
    'isdeleted' => true,
    'is_active' => true,
];

$columnsResult = $conn->query('SHOW COLUMNS FROM usr_pwrs');
if (!$columnsResult) {
    fwrite(STDERR, "unable to inspect usr_pwrs\n");
    exit(1);
}

$toDrop = [];
while ($column = $columnsResult->fetch_assoc()) {
    $name = (string) ($column['Field'] ?? '');
    if ($name === '' || isset($keep[$name])) {
        continue;
    }
    $toDrop[] = $name;
}

if ($toDrop === []) {
    echo "usr-pwrs-prune-ok nothing_to_drop\n";
    exit(0);
}

RolePermissionSyncService::backfillRoleCapabilitiesFromLegacyFlags($conn);

foreach ($toDrop as $column) {
    $sql = 'ALTER TABLE usr_pwrs DROP COLUMN `' . str_replace('`', '', $column) . '`';
    echo ($dryRun ? '[dry-run] ' : '') . $sql . "\n";
    if (!$dryRun) {
        $conn->query($sql);
    }
}

echo 'usr-pwrs-prune-ok dropped=' . count($toDrop) . ($dryRun ? ' dry_run=1' : '') . "\n";
