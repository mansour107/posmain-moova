<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ItemAvailabilityService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_item_availability_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new ItemAvailabilityService();
    $scope = ['tenant' => 7, 'branch' => 3, 'channel' => 'pos'];

    $default = $service->availabilityForItem($conn, 10, $scope);
    phase4AvailabilityAssert($default['is_available'] === true, 'items should default to available');
    phase4AvailabilityAssert($default['channel'] === 'pos', 'default channel should reflect requested channel');

    $disabled = $service->setAvailability($conn, 10, false, $scope, 'خلصت الكمية', 99);
    phase4AvailabilityAssert($disabled['is_available'] === false, 'specific channel should be unavailable after upsert');
    phase4AvailabilityAssert($disabled['unavailable_reason'] === 'خلصت الكمية', 'unavailable reason should persist');
    phase4AvailabilityAssert($disabled['updated_by'] === 99, 'updated_by should persist');

    $thrown = false;
    try {
        $service->assertSellable($conn, 10, $scope);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage() === 'ITEM_UNAVAILABLE';
    }
    phase4AvailabilityAssert($thrown, 'assertSellable should reject unavailable item');

    $service->setAvailability($conn, 11, false, ['tenant' => 7, 'branch' => 3, 'channel' => 'all'], 'غير متاح للفرع', 100);
    $fallback = $service->availabilityForItem($conn, 11, $scope);
    phase4AvailabilityAssert($fallback['is_available'] === false, 'specific channel should fall back to all-channel rule');
    phase4AvailabilityAssert($fallback['channel'] === 'all', 'fallback channel should expose source rule');

    $service->setAvailability($conn, 11, true, $scope, null, 101);
    $override = $service->availabilityForItem($conn, 11, $scope);
    phase4AvailabilityAssert($override['is_available'] === true, 'specific channel should override all-channel rule');
    phase4AvailabilityAssert($override['channel'] === 'pos', 'specific channel should win over all-channel rule');

    $decorated = $service->decorateItems($conn, [
        ['id' => 10, 'iname' => 'Espresso'],
        ['id' => 11, 'iname' => 'Latte'],
        ['id' => 12, 'iname' => 'Tea'],
    ], $scope);
    phase4AvailabilityAssert(count($decorated) === 3, 'decorated item count should be preserved');
    phase4AvailabilityAssert((int) $decorated[0]['is_available'] === 0, 'decorated unavailable item expected');
    phase4AvailabilityAssert($decorated[0]['unavailable_reason'] === 'خلصت الكمية', 'decorated reason expected');
    phase4AvailabilityAssert((int) $decorated[1]['is_available'] === 1, 'decorated override item expected');
    phase4AvailabilityAssert((int) $decorated[2]['is_available'] === 1, 'decorated default item expected');

    $same = $conn->query("SELECT COUNT(*) AS c FROM item_availability WHERE item_id = 10 AND tenant = 7 AND branch = 3 AND channel = 'pos'")->fetch_assoc();
    phase4AvailabilityAssert((int) $same['c'] === 1, 'upsert should keep one scoped availability row');

    echo "phase4-item-availability-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4AvailabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
