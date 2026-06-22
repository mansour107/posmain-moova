<?php
/**
 * Runtime: Moova delivery partial edit must not wipe fulfillment/fee.
 */

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "delivery-moova-partial-edit-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function moovaPartialAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "delivery-moova-partial-edit-FAIL: {$msg}\n");
        exit(1);
    }
}

MoovaPosIntegration::ensureSchema($conn);

$link = $conn->query("SELECT * FROM moova_pos_shop_links WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$items = $conn->query("SELECT id FROM myitems WHERE isdeleted=0 ORDER BY id ASC LIMIT 2")->fetch_all(MYSQLI_ASSOC);
moovaPartialAssert(is_array($link) && count($items) >= 2, 'kody2 moova link and item fixtures required');

$itemA = (int) $items[0]['id'];
$itemB = (int) $items[1]['id'];
$prefix = 'partial-edit-' . bin2hex(random_bytes(3));
$moovaOrderId = $prefix . '-order';
$scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
$posOrders = new PosOrderService();
$ingest = new MoovaLocalIngestService();
$fulfillment = new OrderFulfillmentService();

$createPayload = $ingest->normalizeNewOrderForPos([
    'cofeOrderId' => $moovaOrderId,
    'branchId' => (string) $link['moova_branch_id'],
    'fulfillmentType' => 'delivery',
    'orderChannel' => 'moova_delivery',
    'customerName' => 'Partial Edit Customer',
    'customerPhone' => '0100' . random_int(1000000, 9999999),
    'customerAddress' => 'Partial Edit Street',
    'deliveryFee' => '18.5',
    'deliveryZone' => 'Maadi',
    'items' => [
        ['itemId' => 'pos-item-' . $itemA, 'qty' => 1],
    ],
]);

$conn->begin_transaction();
try {
    $created = $posOrders->createOrMergeMoovaTableOrder($conn, $scope, $createPayload);
    $orderId = (int) ($created['order_id'] ?? 0);
    moovaPartialAssert($orderId > 0, 'delivery order should be created');
    $fulfillment->upsertMoovaFulfillment($conn, $orderId, $createPayload, ['require_table' => false]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'delivery-moova-partial-edit-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$beforeHead = $conn->query("SELECT fat_plus, fat_net FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
$beforeFulfillment = $fulfillment->fulfillmentForOrder($conn, $orderId);
moovaPartialAssert(abs((float) $beforeHead['fat_plus'] - 18.5) < 0.01, 'initial fat_plus should include delivery fee');
moovaPartialAssert($beforeFulfillment['fulfillment_type'] === 'delivery', 'initial fulfillment should be delivery');
moovaPartialAssert($beforeFulfillment['customer_name'] === 'Partial Edit Customer', 'initial customer should be stored');

$partialEditPayload = [
    'cofeOrderId' => $moovaOrderId,
    'branchId' => (string) $link['moova_branch_id'],
    'expectedStateHash' => (string) ($created['state_hash'] ?? ''),
    'items' => [
        ['itemId' => 'pos-item-' . $itemA, 'qty' => 1],
        ['itemId' => 'pos-item-' . $itemB, 'qty' => 1],
    ],
];

$conn->begin_transaction();
try {
    $edited = $posOrders->replaceMoovaDeliveryOrder($conn, $scope, $orderId, $partialEditPayload);
    moovaPartialAssert((int) ($edited['order_id'] ?? 0) === $orderId, 'partial edit should update same order');
    $merged = $fulfillment->upsertMoovaFulfillment($conn, $orderId, $partialEditPayload, [
        'require_table' => false,
        'merge_existing' => true,
    ]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'delivery-moova-partial-edit-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$afterHead = $conn->query("SELECT fat_plus, fat_net FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
moovaPartialAssert(abs((float) $afterHead['fat_plus'] - 18.5) < 0.01, 'partial edit must preserve fat_plus delivery fee');
moovaPartialAssert($merged['fulfillment_type'] === 'delivery', 'partial edit must keep fulfillment_type delivery');
moovaPartialAssert($merged['order_channel'] === 'moova_delivery', 'partial edit must keep moova_delivery channel');
moovaPartialAssert($merged['customer_name'] === 'Partial Edit Customer', 'partial edit must keep customer name');
moovaPartialAssert(abs((float) $merged['delivery_fee'] - 18.5) < 0.01, 'partial edit must keep delivery fee');
moovaPartialAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'] === 2, 'partial edit should add second line');

$conn->query("UPDATE ot_head SET isdeleted = 1 WHERE id = {$orderId}");

echo "delivery-moova-partial-edit-runtime-ok order_id={$orderId}\n";
