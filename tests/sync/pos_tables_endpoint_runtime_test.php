<?php

$root = dirname(__DIR__, 2);

function posTablesEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connect = file_get_contents($root . '/includes/connect.php');
posTablesEndpointAssert(
    strpos($connect, 'global $conn;') !== false && strpos($connect, '$conn = posmain_db_connect();') !== false,
    'connect.php must publish $conn to the global scope for RBAC-guarded AJAX handlers'
);

$getTables = file_get_contents($root . '/ajax/get_tables.php');
posTablesEndpointAssert(strpos($getTables, 'rbac_guard_route') !== false, 'get_tables.php must stay RBAC guarded');
posTablesEndpointAssert(strpos($getTables, '$conn->query($query)') !== false, 'get_tables.php must query through $conn');

echo "pos-tables-endpoint-runtime-ok\n";
