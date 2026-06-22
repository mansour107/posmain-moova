<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

$manager = new SyncSchemaManager();
$planned = $manager->plannedStatements();

deliveryZonesAssert(isset($planned['delivery_zones']), 'delivery_zones planned statement missing');
deliveryZonesAssert(strpos($planned['delivery_zones'], 'CREATE TABLE IF NOT EXISTS delivery_zones') !== false, 'delivery_zones create missing');
deliveryZonesAssert(strpos($planned['order_fulfillment'], 'delivery_client_id') !== false, 'order_fulfillment should include delivery_client_id');

$root = dirname(__DIR__, 2);
deliveryZonesAssert(is_file($root . '/delivery_zones.php'), 'delivery_zones admin page missing');
deliveryZonesAssert(is_file($root . '/ajax/delivery_zones_list.php'), 'delivery zones list endpoint missing');
deliveryZonesAssert(is_file($root . '/do/doedit_delivery_zone.php'), 'delivery zone editor missing');
deliveryZonesAssert(strpos(file_get_contents($root . '/js/pos_delivery.js'), 'delivery_zones_list.php') !== false, 'POS should load zones list');
deliveryZonesAssert(strpos(file_get_contents($root . '/delivery_zones.php'), "require_permission('delivery.zones.manage'") !== false, 'delivery zones page should require manage permission');
deliveryZonesAssert(strpos(file_get_contents($root . '/delivery_board.php'), "require_permission('delivery.dispatch'") !== false, 'delivery board should require dispatch permission');

echo "delivery_zones_contract_test: OK\n";

function deliveryZonesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_zones_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
