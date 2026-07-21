<?php

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';

function moovaTableMappingAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$integrationSource = file_get_contents(__DIR__ . '/../../classes/MoovaPosIntegration.php');
$orderSource = file_get_contents(__DIR__ . '/../../classes/PosOrderService.php');

moovaTableMappingAssert(
    strpos($integrationSource, 'function upsertTableLink') !== false
    || strpos($integrationSource, 'public static function upsertTableLink') !== false,
    'MoovaPosIntegration should expose upsertTableLink'
);
moovaTableMappingAssert(
    strpos($orderSource, 'persistLearnedTableLink') === false,
    'PosOrderService must not learn table links while accepting an order'
);
moovaTableMappingAssert(
    strpos($orderSource, "AND tname LIKE ?") === false,
    'fuzzy table LIKE matching should be removed'
);
moovaTableMappingAssert(strpos($orderSource, 'TABLE_MAPPING_REQUIRED') !== false, 'missing explicit mapping should be rejected');

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "moova-table-mapping-contract-ok (source checks only; mysql unavailable)\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
MoovaPosIntegration::ensureSchema($conn);

$table = $conn->query("
    SELECT id
    FROM tables
    WHERE isdeleted = 0
    ORDER BY id ASC
    LIMIT 1
")->fetch_assoc();

if (!$table) {
    echo "moova-table-mapping-contract-ok (source checks only; no table fixture)\n";
    exit(0);
}

$items = $conn->query("
    SELECT id
    FROM myitems
    WHERE isdeleted = 0
      AND tenant = 0
      AND branch = 0
    ORDER BY id ASC
    LIMIT 1
")->fetch_assoc();

if (!$items) {
    echo "moova-table-mapping-contract-ok (source checks only; no item fixture)\n";
    exit(0);
}

$prefix = 'phpunit-table-map-' . bin2hex(random_bytes(4));
$moovaBranchId = $prefix . '-branch';
$moovaTableId = (string) $table['id'];
$scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];

$conn->query("
    DELETE FROM moova_pos_table_links
    WHERE moova_branch_id = '{$conn->real_escape_string($moovaBranchId)}'
      AND moova_table_id = '{$conn->real_escape_string($moovaTableId)}'
");

$service = new PosOrderService();
$conn->begin_transaction();
try {
    $service->createOrMergeMoovaTableOrder($conn, $scope, [
        'cofeOrderId' => $prefix . '-order',
        'branchId' => $moovaBranchId,
        'tableNumber' => $moovaTableId,
        'items' => [
            ['itemId' => (string) $items['id'], 'qty' => 1],
        ],
    ]);
    $conn->rollback();
    fwrite(STDERR, "unmapped table order should have been rejected\n");
    exit(1);
} catch (Throwable $e) {
    $conn->rollback();
    moovaTableMappingAssert($e->getMessage() === 'TABLE_MAPPING_REQUIRED', 'unmapped table should fail with TABLE_MAPPING_REQUIRED');
}

MoovaPosIntegration::upsertTableLink($conn, $scope, $moovaBranchId, $moovaTableId, (int) $table['id']);
$conn->begin_transaction();
try {
    $service->createOrMergeMoovaTableOrder($conn, $scope, [
        'cofeOrderId' => $prefix . '-mapped-order',
        'branchId' => $moovaBranchId,
        'tableNumber' => $moovaTableId,
        'items' => [['itemId' => (string) $items['id'], 'qty' => 1]],
    ]);
    $conn->rollback();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'explicitly mapped table order failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$conn->query("
    DELETE FROM moova_pos_table_links
    WHERE moova_branch_id = '{$conn->real_escape_string($moovaBranchId)}'
      AND moova_table_id = '{$conn->real_escape_string($moovaTableId)}'
");

echo "moova-table-mapping-contract-ok\n";
