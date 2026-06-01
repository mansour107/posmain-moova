<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $hasVariantTable = false;
    $variantTable = $conn->query("SHOW TABLES LIKE 'item_variants'");
    $hasVariantTable = $variantTable && $variantTable->num_rows > 0;
    $variantSelect = $hasVariantTable
        ? "(EXISTS (SELECT 1 FROM item_variants iv WHERE iv.parent_item_id = myitems.id AND iv.is_active = 1)) AS has_variants"
        : "0 AS has_variants";
    $variantChildFilter = $hasVariantTable
        ? "AND NOT EXISTS (SELECT 1 FROM item_variants ivc WHERE ivc.variant_item_id = myitems.id AND ivc.is_active = 1)"
        : "";
    $activeFilter = ItemCatalogStatus::activeOnlySql($conn, 'myitems');
    $query = "SELECT myitems.*, {$variantSelect}
              FROM myitems
              WHERE isdeleted = 0
              {$activeFilter}
              {$variantChildFilter}
              ORDER BY iname ASC";
    $result = $conn->query($query);
    
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
