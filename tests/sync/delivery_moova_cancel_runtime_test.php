<?php
/**
 * Runtime: Moova delivery cancel must void order lines and fulfillment.
 */

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "delivery-moova-cancel-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function moovaCancelAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "delivery-moova-cancel-FAIL: {$msg}\n");
        exit(1);
    }
}

MoovaPosIntegration::ensureSchema($conn);

$link = $conn->query("SELECT * FROM moova_pos_shop_links WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$item = $conn->query("SELECT id FROM myitems WHERE isdeleted=0 ORDER BY id ASC LIMIT 1")->fetch_assoc();
moovaCancelAssert(is_array($link) && is_array($item), 'kody2 moova link and item fixtures required');

$itemId = (int) $item['id'];
$prefix = 'moova-cancel-' . bin2hex(random_bytes(3));
$moovaOrderId = $prefix . '-order';
$scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
$posOrders = new PosOrderService();
$ingest = new MoovaLocalIngestService();
$fulfillment = new OrderFulfillmentService();
$mutation = new PosOrderMutationService();

$createPayload = $ingest->normalizeNewOrderForPos([
    'cofeOrderId' => $moovaOrderId,
    'branchId' => (string) $link['moova_branch_id'],
    'fulfillmentType' => 'delivery',
    'orderChannel' => 'moova_delivery',
    'customerName' => 'Moova Cancel Customer',
    'customerPhone' => '0100' . random_int(1000000, 9999999),
    'customerAddress' => 'Moova Cancel Street',
    'deliveryFee' => '14',
    'deliveryZone' => 'Downtown',
    'items' => [
        ['itemId' => 'pos-item-' . $itemId, 'qty' => 1],
    ],
]);

$conn->begin_transaction();
try {
    $created = $posOrders->createOrMergeMoovaTableOrder($conn, $scope, $createPayload);
    $orderId = (int) ($created['order_id'] ?? 0);
    moovaCancelAssert($orderId > 0, 'moova delivery order should be created');
    $fulfillment->upsertMoovaFulfillment($conn, $orderId, $createPayload, ['require_table' => false]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'delivery-moova-cancel-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$beforeLines = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'];
moovaCancelAssert($beforeLines > 0, 'moova delivery should have active lines before cancel');

$conn->begin_transaction();
try {
    $cancelled = $mutation->cancelDeliveryOrder($conn, [
        'order_id' => $orderId,
        'user_id' => 1,
        'reason' => 'moova cancel runtime test',
        'force' => true,
    ], ['in_transaction' => true]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'delivery-moova-cancel-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

moovaCancelAssert(($cancelled['data']['payment_status'] ?? '') === 'voided', 'cancel should void payment status');
$head = $conn->query("SELECT isdeleted, payment_status, order_status FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
moovaCancelAssert((int) ($head['isdeleted'] ?? 0) === 1, 'moova cancel should void ot_head');
moovaCancelAssert($head['payment_status'] === 'voided', 'moova cancel should set payment_status voided');
$afterLines = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'];
moovaCancelAssert($afterLines === 0, 'moova cancel should remove active lines');
$row = $fulfillment->fulfillmentForOrder($conn, $orderId);
moovaCancelAssert(is_array($row), 'fulfillment row required after cancel');
moovaCancelAssert($row['delivery_status'] === 'cancelled', 'moova cancel should set fulfillment cancelled');

echo "delivery-moova-cancel-runtime-ok order_id={$orderId}\n";
