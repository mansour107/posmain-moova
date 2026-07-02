<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitColumnSupport.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitProfileBuilder.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitPersistence.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitResolver.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "item-unit-def-flags-runtime-skipped-no-db\n";
    exit(0);
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function itemUnitDefFlagsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERT FAILED: ' . $message);
    }
}

ItemUnitColumnSupport::ensureDefFlags($conn);

$conn->query("INSERT INTO myitems (iname, price1, price3, item_type, isdeleted) VALUES ('TEST-UNIT-PROFILE', 0, 0, 'sellable', 0)");
$itemId = (int) $conn->insert_id;

try {
    $profile = ItemUnitProfileBuilder::buildFromPost([
        'item_unit_profile_present' => '1',
        'item_type' => 'sellable',
        'sell_active' => '1',
        'purchase_active' => '1',
        'sell_unit_id' => '1',
        'storage_unit_id' => '1',
        'purchase_unit_id' => '2',
        'purchase_storage_factor' => '6',
        'purchase_cost' => '18',
        'sell_price1' => '4.5',
        'barcode' => 'UNIT-TEST-' . $itemId,
    ], 1);

    ItemUnitPersistence::saveForItem($conn, $itemId, $profile['units'], (int) $profile['purchase_unit_id']);

    $conn->query("UPDATE myitems SET price1 = {$profile['price1']} WHERE id = {$itemId}");

    itemUnitDefFlagsAssert(abs(ItemUnitResolver::sellPriceForItem($conn, $itemId) - 4.5) < 0.0001, 'resolver should read def_sale price');
    itemUnitDefFlagsAssert(ItemUnitResolver::stockUnitIdForItem($conn, $itemId) === 1, 'resolver should read def_stock unit');
    itemUnitDefFlagsAssert(ItemUnitResolver::purchaseUnitIdForItem($conn, $itemId) === 2, 'resolver should read def_buy unit');

    $sell = ItemUnitResolver::sellRowForItem($conn, $itemId);
    itemUnitDefFlagsAssert($sell !== null && (int) ($sell['def_sale'] ?? 0) === 1, 'sell row should be flagged');

    echo "item-unit-def-flags-runtime-ok\n";
} finally {
    $conn->query('DELETE FROM item_units WHERE item_id = ' . $itemId);
    $conn->query('DELETE FROM myitems WHERE id = ' . $itemId);
}
