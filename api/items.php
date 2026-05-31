<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/moova_menu_api_auth.php';
require_once __DIR__ . '/../classes/Inventory/InventoryStockReadService.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../classes/Recipe/RecipeCostLeakAuditService.php';

function posmain_items_api_sanitize_public_payload(array $payload): array
{
    $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
    $flags = new RecipeFeatureFlags($config);

    return (new RecipeCostLeakAuditService())->sanitizePayload($payload, 'moova-facing api', $flags);
}

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection not established',
        'debug' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit;
}

posmain_menu_api_require_access($conn);

try {
    // Get optional category filter
    $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    
    // Build query - all item fields with images
    $sql = "SELECT 
                i.id,
                i.iname as name,
                i.name2 as name2,
                i.code,
                i.barcode,
                i.itmqty as quantity,
                i.salesqty,
                i.info as description,
                i.market_price,
                i.cost_price,
                i.last_price as purchase_price,
                i.price1 as sale_price,
                i.price2,
                i.price3,
                i.group1,
                i.group2,
                i.group3,
                g1.gname as group1_name,
                g2.gname as group2_name,
                g3.gname as group3_name,
                i.crtime as created_at,
                i.mdtime as updated_at,
                img.iname as image_name
            FROM myitems i
            LEFT JOIN item_group g1 ON i.group1 = g1.id
            LEFT JOIN item_group g2 ON i.group2 = g2.id
            LEFT JOIN item_group g3 ON i.group3 = g3.id
            LEFT JOIN imgs img ON i.id = img.itemid
            WHERE i.isdeleted = 0";
    
    // Add category filter if provided (searches in all 3 groups)
    if ($categoryId) {
        $sql .= " AND (i.group1 = ? OR i.group2 = ? OR i.group3 = ?)";
    }
    
    $sql .= " ORDER BY i.id, img.id";
    
    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    if ($categoryId) {
        $stmt->bind_param("iii", $categoryId, $categoryId, $categoryId);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    // Fetch all items and group images
    $items = [];
    $currentItemId = null;
    $currentItem = null;
    
    while ($row = $result->fetch_assoc()) {
        // If we encounter a new item, save the previous one and start a new one
        if ($currentItemId !== $row['id']) {
            if ($currentItem !== null) {
                $items[] = $currentItem;
            }
            
            $currentItemId = $row['id'];
            $currentItem = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'name2' => $row['name2'],
                'code' => (int)$row['code'],
                'barcode' => $row['barcode'],
                'quantity' => (float)$row['quantity'],
                'sales_quantity' => (float)$row['salesqty'],
                'description' => $row['description'],
                'prices' => [
                    'sale_price' => (float)$row['sale_price'],
                    'price2' => (float)$row['price2'],
                    'price3' => (float)$row['price3'],
                    'purchase_price' => (float)$row['purchase_price'],
                    'cost_price' => (float)$row['cost_price'],
                    'market_price' => (float)$row['market_price']
                ],
                'categories' => [
                    'group1' => [
                        'id' => (int)$row['group1'],
                        'name' => $row['group1_name']
                    ],
                    'group2' => [
                        'id' => (int)$row['group2'],
                        'name' => $row['group2_name']
                    ],
                    'group3' => [
                        'id' => (int)$row['group3'],
                        'name' => $row['group3_name']
                    ]
                ],
                'images' => [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        // Add image URL if it exists
        if ($row['image_name']) {
            $currentItem['images'][] = 'uploads/' . $row['image_name'];
        }
    }
    
    // Don't forget to add the last item
    if ($currentItem !== null) {
        $items[] = $currentItem;
    }
    
    $stmt->close();

    $items = (new InventoryStockReadService())->decoratePublicItemPayload($conn, $items);

    $variantService = new ItemVariantService();
    $itemIds = array_map(static function (array $item): int {
        return (int) ($item['id'] ?? 0);
    }, $items);
    $variantsByParent = $variantService->activeVariantsForParents($conn, $itemIds);
    foreach ($items as &$item) {
        $itemId = (int) $item['id'];
        $variants = $variantsByParent[$itemId] ?? [];
        $variantParent = $variantService->variantParentForChild($conn, $itemId);
        $item['has_variants'] = count($variants) > 0;
        $item['is_orderable'] = !$item['has_variants'];
        $item['is_variant_child'] = $variantParent !== null;
        $item['parent_item_id'] = $variantParent ? (int) ($variantParent['parent_item_id'] ?? 0) : null;
        $item['variant_label'] = $variantParent ? (string) ($variantParent['variant_label'] ?? '') : null;
        $item['variants'] = array_map(static function (array $variant): array {
            return [
                'relation_id' => (int) ($variant['relation_id'] ?? 0),
                'item_id' => (int) ($variant['variant_item_id'] ?? $variant['item_id'] ?? 0),
                'variant_item_id' => (int) ($variant['variant_item_id'] ?? $variant['item_id'] ?? 0),
                'label' => (string) ($variant['variant_label'] ?? $variant['label'] ?? ''),
                'name' => (string) ($variant['iname'] ?? $variant['name'] ?? ''),
                'barcode' => (string) ($variant['barcode'] ?? ''),
                'price' => (float) ($variant['price1'] ?? $variant['price'] ?? 0),
                'price1' => (float) ($variant['price1'] ?? $variant['price'] ?? 0),
                'price2' => (float) ($variant['price2'] ?? 0),
                'price3' => (float) ($variant['price3'] ?? 0),
                'cost_price' => (float) ($variant['cost_price'] ?? 0),
                'sort_order' => (int) ($variant['sort_order'] ?? 0),
                'is_default' => (bool) ($variant['is_default'] ?? false),
                'is_active' => (bool) ($variant['is_active'] ?? true),
                'is_orderable' => true,
            ];
        }, $variants);
    }
    unset($item);
    $conn->close();
    
    // Return success response
    echo json_encode(posmain_items_api_sanitize_public_payload([
        'status' => 'success',
        'data' => $items
    ]), JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Something went wrong',
        'debug' => $e->getMessage() // Remove this line in production
    ], JSON_PRETTY_PRINT);
}
