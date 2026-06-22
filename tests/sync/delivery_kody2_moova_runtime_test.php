<?php
/**
 * Phase 4 runtime: Moova delivery on real kody2 fixtures.
 */

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaNewOrderApplyService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "delivery-kody2-moova-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function deliveryKody2Assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "delivery-kody2-moova-FAIL: {$msg}\n");
        exit(1);
    }
}

MoovaPosIntegration::ensureSchema($conn);

$link = $conn->query("SELECT * FROM moova_pos_shop_links WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$item = $conn->query("SELECT id FROM myitems WHERE isdeleted=0 ORDER BY id ASC LIMIT 1")->fetch_assoc();
deliveryKody2Assert(is_array($link) && is_array($item), 'kody2 moova link and item fixtures required');

$prefix = 'delivery-kody2-' . bin2hex(random_bytes(3));
$scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
$ingest = new MoovaLocalIngestService();
$posOrders = new PosOrderService();
$fulfillment = new OrderFulfillmentService();

// 4.1 ingest delivery without table
$payload = [
    'cofeOrderId' => $prefix . '-del',
    'branchId' => (string) $link['moova_branch_id'],
    'fulfillmentType' => 'delivery',
    'orderChannel' => 'moova_delivery',
    'customerName' => 'Kody2 Delivery Customer',
    'customerPhone' => '0100' . random_int(1000000, 9999999),
    'customerAddress' => 'Test Address 42',
    'deliveryFee' => '12.5',
    'deliveryZone' => 'Downtown',
    'items' => [
        ['itemId' => 'pos-item-' . (int) $item['id'], 'qty' => 1],
    ],
];
$posPayload = $ingest->normalizeNewOrderForPos($payload);
deliveryKody2Assert(($posPayload['fulfillmentType'] ?? '') === 'delivery', 'ingest should preserve delivery fulfillment');
deliveryKody2Assert(!isset($posPayload['tableId']) && !isset($posPayload['tableNumber']), 'delivery ingest should not require table');

$conn->begin_transaction();
try {
    $result = $posOrders->createOrMergeMoovaTableOrder($conn, $scope, $posPayload);
    $orderId = (int) ($result['order_id'] ?? 0);
    deliveryKody2Assert($orderId > 0, 'moova delivery order should be created');
    $fulfillment->upsertMoovaFulfillment($conn, $orderId, $posPayload, ['require_table' => false]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'moova delivery create failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$head = $conn->query("SELECT order_type, table_id FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
deliveryKody2Assert($head['order_type'] === 'delivery', 'moova delivery must set ot_head.order_type=delivery');
deliveryKody2Assert($head['table_id'] === null || (int) $head['table_id'] === 0, 'moova delivery must not bind table');

$row = $fulfillment->fulfillmentForOrder($conn, $orderId);
deliveryKody2Assert(is_array($row), 'fulfillment row required');
deliveryKody2Assert($row['order_channel'] === 'moova_delivery', 'moova_delivery channel');
deliveryKody2Assert($row['customer_name'] === 'Kody2 Delivery Customer', 'customer name on fulfillment');
deliveryKody2Assert(abs((float) $row['delivery_fee'] - 12.5) < 0.01, 'delivery fee on fulfillment');

// cleanup
$conn->query("UPDATE ot_head SET isdeleted = 1 WHERE id = {$orderId}");

echo "delivery-kody2-moova-runtime-ok order_id={$orderId}\n";
