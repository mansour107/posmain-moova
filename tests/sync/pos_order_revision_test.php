<?php

require_once __DIR__ . '/../../classes/Pos/Service/OrderRevisionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/KitchenTicketRevisionService.php';
require_once __DIR__ . '/pos_table_save_service_test.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_order_revision_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-order-revision-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTableSaveCreateSchema($conn);

    $revisionService = new OrderRevisionService();
    $kitchenService = new KitchenTicketRevisionService();

    posOrderRevisionAssert($revisionService->columnExists($conn), 'kitchen_revision column should exist after schema apply');
    posOrderRevisionAssert($kitchenService->tableExists($conn), 'kitchen_order_revisions table should exist after schema apply');

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, pro_value, paid_amount,
            remaining_amount, acc2, kitchen_revision
        ) VALUES (
            900, 90, 1, 9, 0, 'active', 'unpaid',
            'draft', 10, 0, 10, 10, 0,
            10, 501, 0
        )
    ");

    $first = $revisionService->bumpAndGet($conn, 900);
    $second = $revisionService->bumpAndGet($conn, 900);
    posOrderRevisionAssert($first === 1, 'first bump should return revision 1');
    posOrderRevisionAssert($second === 2, 'second bump should return revision 2');
    posOrderRevisionAssert($revisionService->currentRevision($conn, 900) === 2, 'current revision should be 2');

    $initial = $kitchenService->recordRevision($conn, 900, 1);
    posOrderRevisionAssert($initial['revision'] === 1, 'first kitchen revision row should be revision 1');
    posOrderRevisionAssert($initial['is_current'] === true, 'first kitchen revision should be current');

    $revised = $kitchenService->recordRevision($conn, 900, 2);
    posOrderRevisionAssert($revised['revision'] === 2, 'second kitchen revision row should be revision 2');
    posOrderRevisionAssert($revised['supersedes_revision'] === 1, 'second kitchen revision should supersede revision 1');

    $currentCount = (int) $conn->query("SELECT COUNT(*) AS c FROM kitchen_order_revisions WHERE order_id = 900 AND status = 'current'")->fetch_assoc()['c'];
    $supersededCount = (int) $conn->query("SELECT COUNT(*) AS c FROM kitchen_order_revisions WHERE order_id = 900 AND status = 'superseded'")->fetch_assoc()['c'];
    posOrderRevisionAssert($currentCount === 1, 'exactly one current kitchen revision should remain');
    posOrderRevisionAssert($supersededCount === 1, 'prior kitchen revision should be superseded');

    echo "pos-order-revision-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posOrderRevisionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
