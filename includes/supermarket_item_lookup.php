<?php

require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemAvailabilityService.php';
require_once __DIR__ . '/../classes/Items/ItemUnitColumnSupport.php';
require_once __DIR__ . '/../classes/Items/ItemUnitResolver.php';

function posmain_supermarket_normalize_term(string $term): string
{
    return trim(substr($term, 0, 120));
}

function posmain_supermarket_variant_child_sql(mysqli $conn, string $alias = 'myitems'): string
{
    $variantTable = $conn->query("SHOW TABLES LIKE 'item_variants'");
    if (!$variantTable || $variantTable->num_rows === 0) {
        return '';
    }

    $prefix = trim($alias) !== '' ? rtrim($alias, '.') . '.' : '';

    return ' AND NOT EXISTS (
        SELECT 1
        FROM item_variants ivc
        WHERE ivc.variant_item_id = ' . $prefix . 'id
          AND ivc.is_active = 1
    )';
}

function posmain_supermarket_catalog_sql(mysqli $conn, string $alias = 'myitems'): string
{
    return ItemCatalogStatus::activeOnlySql($conn, $alias)
        . ItemCatalogStatus::posSellableOnlySql($conn, $alias)
        . posmain_supermarket_variant_child_sql($conn, $alias);
}

function posmain_supermarket_availability_scope(?mysqli $conn = null): array
{
    if ($conn instanceof mysqli && function_exists('posmain_pos_availability_scope')) {
        return posmain_pos_availability_scope($conn);
    }

    $branchConfig = function_exists('posmain_app_config')
        ? (posmain_app_config()['branch'] ?? [])
        : [];

    return [
        'tenant' => (int) ($branchConfig['pos_tenant'] ?? 0),
        'branch' => (int) ($branchConfig['pos_branch'] ?? 0),
        'channel' => 'pos',
        'order_type' => 'takeaway',
    ];
}

function posmain_supermarket_finalize_item(mysqli $conn, ?array $row): ?array
{
    if (!$row) {
        return null;
    }

    $item = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'barcode' => (string) ($row['barcode'] ?? ''),
        'price' => (float) ($row['price'] ?? 0),
        'u_val' => ItemUnitResolver::sellToStockFactor($conn, (int) ($row['id'] ?? 0)),
        'unit_name' => (string) ($row['unit_name'] ?? ''),
    ];

    if (!empty($row['_unit_row'])) {
        $item['u_val'] = ItemUnitResolver::inventoryFactorForUnitRow($conn, $row['_unit_row']);
    }

    if ($item['id'] <= 0 || $item['name'] === '') {
        return null;
    }

    $decorated = (new ItemAvailabilityService())->decorateItems($conn, [$item], posmain_supermarket_availability_scope($conn));
    $final = $decorated[0] ?? $item;

    $isAvailable = !in_array($final['is_available'] ?? 1, [0, '0', false], true);
    $canAdd = array_key_exists('availability_can_add', $final)
        ? (bool) $final['availability_can_add']
        : $isAvailable;
    if (!$canAdd) {
        return null;
    }

    return [
        'id' => (int) $final['id'],
        'name' => (string) $final['name'],
        'barcode' => (string) ($final['barcode'] ?? ''),
        'price' => (float) ($final['price'] ?? 0),
        'u_val' => $final['u_val'] ?? 1,
        'unit_name' => (string) ($final['unit_name'] ?? ''),
        'is_available' => (int) ($final['is_available'] ?? 1),
        'availability_can_add' => (int) ($final['availability_can_add'] ?? ($final['is_available'] ?? 1)),
        'unavailable_reason' => (string) ($final['unavailable_reason'] ?? ''),
    ];
}

function posmain_supermarket_lookup_item(mysqli $conn, string $term): ?array
{
    $term = posmain_supermarket_normalize_term($term);
    if ($term === '') {
        return null;
    }

    $catalogFilter = posmain_supermarket_catalog_sql($conn, 'myitems');
    $stmt = $conn->prepare(
        "SELECT id, iname AS name, barcode, price1 AS price, 1 AS u_val, '' AS unit_name
         FROM myitems
         WHERE barcode = ? AND isdeleted = 0{$catalogFilter}
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $item = posmain_supermarket_finalize_item($conn, $row);
    if ($item) {
        return $item;
    }

    $catalogFilterM = posmain_supermarket_catalog_sql($conn, 'm');
    $swapSelect = ItemUnitColumnSupport::hasConversionSwapped($conn)
        ? 'iu.conversion_swapped'
        : '0 AS conversion_swapped';
    $unitStmt = $conn->prepare(
        "SELECT iu.item_id AS id, m.iname AS name, iu.unit_barcode AS barcode,
                iu.price1 AS price, iu.u_val, {$swapSelect}, iu.def_sale, iu.def_buy
         FROM item_units iu
         INNER JOIN myitems m ON m.id = iu.item_id
         WHERE iu.unit_barcode = ? AND m.isdeleted = 0{$catalogFilterM}
         LIMIT 1"
    );
    if (!$unitStmt) {
        return null;
    }

    $unitStmt->bind_param('s', $term);
    $unitStmt->execute();
    $unitResult = $unitStmt->get_result();
    $unitRow = $unitResult ? $unitResult->fetch_assoc() : null;
    $unitStmt->close();

    if (!$unitRow) {
        return null;
    }

    $unitRow['unit_name'] = 'وحدة فرعية';
    $unitRow['_unit_row'] = [
        'u_val' => $unitRow['u_val'] ?? 1,
        'conversion_swapped' => $unitRow['conversion_swapped'] ?? 0,
        'def_sale' => $unitRow['def_sale'] ?? 1,
        'def_buy' => $unitRow['def_buy'] ?? 0,
    ];
    return posmain_supermarket_finalize_item($conn, $unitRow);
}

function posmain_supermarket_autocomplete_items(mysqli $conn, string $term, int $nameLimit = 15): array
{
    $term = posmain_supermarket_normalize_term($term);
    if ($term === '') {
        return [];
    }

    $items = [];
    $seenIds = [];

    $exact = posmain_supermarket_lookup_item($conn, $term);
    if ($exact) {
        $items[] = posmain_supermarket_autocomplete_row($exact);
        $seenIds[(int) $exact['id']] = true;
    }

    $like = '%' . $term . '%';
    $nameLimit = max(1, min(50, $nameLimit));
    $catalogFilter = posmain_supermarket_catalog_sql($conn, 'myitems');
    $nameStmt = $conn->prepare(
        "SELECT id, iname AS name, barcode, price1 AS price, 1 AS u_val, '' AS unit_name
         FROM myitems
         WHERE iname LIKE ? AND isdeleted = 0{$catalogFilter}
         ORDER BY iname ASC
         LIMIT ?"
    );
    if (!$nameStmt) {
        return $items;
    }

    $nameStmt->bind_param('si', $like, $nameLimit);
    $nameStmt->execute();
    $nameResult = $nameStmt->get_result();
    while ($row = $nameResult->fetch_assoc()) {
        $finalRow = posmain_supermarket_finalize_item($conn, $row);
        if (!$finalRow) {
            continue;
        }

        $itemId = (int) ($finalRow['id'] ?? 0);
        if ($itemId <= 0 || isset($seenIds[$itemId])) {
            continue;
        }

        $seenIds[$itemId] = true;
        $items[] = posmain_supermarket_autocomplete_row($finalRow);
    }
    $nameStmt->close();

    return $items;
}

function posmain_supermarket_autocomplete_row(array $row): array
{
    $labelBarcode = $row['barcode'] ?: ($row['id'] ?? '');
    return [
        'id' => $row['id'],
        'label' => $row['name'] . ' (' . $labelBarcode . ') - ' . $row['price'] . ' ج.م',
        'value' => $row['name'],
        'item' => $row,
    ];
}

function posmain_supermarket_require_pos_session(): void
{
    require_once __DIR__ . '/auth_guard.php';

    if (!auth_guard_is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = current_user_id();
    if (
        empty($_SESSION['pos_authenticated'])
        || $_SESSION['pos_authenticated'] !== true
        || (int) ($_SESSION['pos_user_id'] ?? 0) !== $userId
    ) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'code' => 'POS_AUTH_REQUIRED', 'message' => 'POS authentication required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function posmain_supermarket_require_ajax_csrf(): void
{
    require_once __DIR__ . '/csrf.php';
    require_csrf('pos_browser');
}
