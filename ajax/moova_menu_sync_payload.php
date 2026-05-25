<?php
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../classes/Recipe/RecipeScopeResolver.php';
require_once __DIR__ . '/../classes/Recipe/RecipeCostLeakAuditService.php';
require_once __DIR__ . '/../classes/Recipe/RecipeSyncPayloadService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function moova_menu_sync_sanitize_public_payload(array $payload): array
{
    $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
    $flags = new RecipeFeatureFlags($config);

    return (new RecipeCostLeakAuditService())->sanitizePayload($payload, 'moova-facing api', $flags);
}

function moova_menu_sync_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    $payload = moova_menu_sync_sanitize_public_payload($payload);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_menu_sync_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function moova_menu_sync_bearer_token(): string
{
    $authorization = moova_menu_sync_header('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function moova_menu_sync_device_token(): string
{
    $headerToken = moova_menu_sync_header('X-Moova-Device-Token');
    if ($headerToken !== '') {
        return $headerToken;
    }

    $posHeaderToken = moova_menu_sync_header('X-Pos-Device-Token');
    if ($posHeaderToken !== '') {
        return $posHeaderToken;
    }

    return moova_menu_sync_bearer_token();
}

function moova_menu_sync_decimal($value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

function moova_menu_sync_recipe_scope(array $config, ?array $link): RecipeScope
{
    return (new RecipeScopeResolver($config))->resolve([
        'pos_tenant' => $link !== null ? ($link['pos_tenant'] ?? null) : null,
        'pos_branch' => $link !== null ? ($link['pos_branch'] ?? null) : null,
        'store_id' => 0,
        'channel' => 'moova',
        'order_type' => 'delivery',
        'source_system' => 'moova_menu_sync',
    ]);
}

function moova_menu_sync_apply_recipe_availability(
    mysqli $conn,
    RecipeSyncPayloadService $recipeSync,
    RecipeScope $scope,
    array $menuItem,
    array $itemRow
): array {
    $recipePayload = $recipeSync->menuItemSnapshotPayload(
        $conn,
        $scope,
        $itemRow,
        'delivery',
        'moova'
    );
    if ($recipePayload === null) {
        return $menuItem;
    }

    $menuItem['recipe_availability'] = $recipePayload;
    foreach ([
        'recipe_enabled',
        'active_recipe_version',
        'computed_available_qty',
        'effective_available_qty',
        'effective_is_available',
        'unavailable_reason',
        'availability_revision',
    ] as $key) {
        if (array_key_exists($key, $recipePayload)) {
            $menuItem[$key] = $recipePayload[$key];
        }
    }

    if (array_key_exists('computed_available_qty', $recipePayload)) {
        $menuItem['computedAvailableQty'] = $recipePayload['computed_available_qty'];
    }
    if (array_key_exists('effective_available_qty', $recipePayload)) {
        $menuItem['effectiveAvailableQty'] = $recipePayload['effective_available_qty'];
    }
    if (array_key_exists('availability_revision', $recipePayload)) {
        $menuItem['availabilityRevision'] = $recipePayload['availability_revision'];
    }

    $effectiveAvailable = (bool) ($recipePayload['effective_is_available'] ?? true);
    $menuItem['effectiveIsAvailable'] = $effectiveAvailable;
    if (!$effectiveAvailable) {
        $reason = trim((string) ($recipePayload['unavailable_reason'] ?? ''));
        $menuItem['available'] = false;
        $menuItem['deliveryAvailable'] = false;
        $menuItem['isOrderable'] = false;
        $menuItem['unavailableReason'] = $reason !== '' ? $reason : 'Recipe availability is unavailable.';
        $menuItem['availabilityReason'] = $menuItem['unavailableReason'];
    }

    return $menuItem;
}

function moova_menu_sync_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
    $cache[$key] = $result && $result->num_rows > 0;
    return $cache[$key];
}

function moova_menu_sync_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        $cache[$table] = false;
        return false;
    }
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    $cache[$table] = $result && $result->num_rows > 0;
    return $cache[$table];
}

function moova_menu_sync_optional_expr(mysqli $conn, string $table, string $column, string $alias, string $fallback = 'NULL', string $prefix = ''): string
{
    if (moova_menu_sync_column_exists($conn, $table, $column)) {
        return $prefix . "`{$column}` AS `{$alias}`";
    }
    return $fallback . " AS `{$alias}`";
}

function moova_menu_sync_changed_expr(mysqli $conn, string $table, string $prefix = ''): string
{
    $parts = [];
    foreach (['mdtime', 'updated_at', 'crtime', 'created_at'] as $column) {
        if (moova_menu_sync_column_exists($conn, $table, $column)) {
            $parts[] = $prefix . "`{$column}`";
        }
    }
    if (!$parts) {
        return "'1970-01-01'";
    }
    return 'COALESCE(' . implode(', ', $parts) . ", '1970-01-01')";
}

function moova_menu_sync_fingerprint(mysqli $conn): array
{
    $itemChangedExpr = moova_menu_sync_changed_expr($conn, 'myitems');
    $itemName2Expr = moova_menu_sync_column_exists($conn, 'myitems', 'name2') ? "COALESCE(name2, '')" : "''";
    $itemInfoExpr = moova_menu_sync_column_exists($conn, 'myitems', 'info') ? "COALESCE(info, '')" : "''";
    $itemPrice2Expr = moova_menu_sync_column_exists($conn, 'myitems', 'price2') ? "COALESCE(price2, 0)" : '0';
    $itemPrice3Expr = moova_menu_sync_column_exists($conn, 'myitems', 'price3') ? "COALESCE(price3, 0)" : '0';
    $itemGroup1Expr = moova_menu_sync_column_exists($conn, 'myitems', 'group1') ? "COALESCE(group1, 0)" : '0';
    $itemGroup2Expr = moova_menu_sync_column_exists($conn, 'myitems', 'group2') ? "COALESCE(group2, 0)" : '0';
    $itemDeletedExpr = moova_menu_sync_column_exists($conn, 'myitems', 'isdeleted') ? "COALESCE(isdeleted, 0)" : '0';
    $items = $conn->query("
        SELECT COUNT(*) AS row_count,
               COALESCE(SUM(CRC32(CONCAT_WS('|',
                   id, COALESCE(iname, ''), {$itemName2Expr}, {$itemInfoExpr}, COALESCE(barcode, ''),
                   COALESCE(price1, 0), {$itemPrice2Expr}, {$itemPrice3Expr},
                   COALESCE(cost_price, 0), {$itemGroup1Expr}, {$itemGroup2Expr},
                   {$itemDeletedExpr}, {$itemChangedExpr}
               ))), 0) AS checksum,
               COALESCE(MAX(UNIX_TIMESTAMP({$itemChangedExpr})), 0) AS max_changed_at
        FROM myitems
    ")->fetch_assoc();

    $categoryChangedExpr = moova_menu_sync_changed_expr($conn, 'item_group');
    $categoryInfoExpr = moova_menu_sync_column_exists($conn, 'item_group', 'info') ? "COALESCE(info, '')" : "''";
    $categoryDeletedExpr = moova_menu_sync_column_exists($conn, 'item_group', 'isdeleted') ? "COALESCE(isdeleted, 0)" : '0';
    $categories = $conn->query("
        SELECT COUNT(*) AS row_count,
               COALESCE(SUM(CRC32(CONCAT_WS('|',
                   id, COALESCE(gname, ''), {$categoryInfoExpr},
                   {$categoryDeletedExpr}, {$categoryChangedExpr}
               ))), 0) AS checksum,
               COALESCE(MAX(UNIX_TIMESTAMP({$categoryChangedExpr})), 0) AS max_changed_at
        FROM item_group
    ")->fetch_assoc();

    $raw = [
        'items' => [
            'count' => (int) ($items['row_count'] ?? 0),
            'checksum' => (string) ($items['checksum'] ?? '0'),
            'max_changed_at' => (int) ($items['max_changed_at'] ?? 0),
        ],
        'categories' => [
            'count' => (int) ($categories['row_count'] ?? 0),
            'checksum' => (string) ($categories['checksum'] ?? '0'),
            'max_changed_at' => (int) ($categories['max_changed_at'] ?? 0),
        ],
    ];

    if (
        moova_menu_sync_table_exists($conn, 'modifier_groups')
        && moova_menu_sync_table_exists($conn, 'modifier_options')
        && moova_menu_sync_table_exists($conn, 'item_modifier_groups')
    ) {
        $modifiers = $conn->query("
            SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(CRC32(CONCAT_WS('|',
                       img.item_id, img.group_id, img.sort_order,
                       mg.name_ar, COALESCE(mg.name_en, ''), mg.selection_min, mg.selection_max,
                       mg.is_required, mg.is_active, mg.sort_order,
                       COALESCE(mo.id, 0), COALESCE(mo.name_ar, ''), COALESCE(mo.name_en, ''),
                       COALESCE(mo.price_delta, 0), COALESCE(mo.is_active, 0), COALESCE(mo.sort_order, 0)
                   ))), 0) AS checksum
            FROM item_modifier_groups img
            JOIN modifier_groups mg ON mg.id = img.group_id
            LEFT JOIN modifier_options mo ON mo.group_id = mg.id
        ")->fetch_assoc();
        $raw['modifiers'] = [
            'count' => (int) ($modifiers['row_count'] ?? 0),
            'checksum' => (string) ($modifiers['checksum'] ?? '0'),
            'max_changed_at' => 0,
        ];
    }
    if (moova_menu_sync_table_exists($conn, 'item_variants')) {
        $variants = $conn->query("
            SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(CRC32(CONCAT_WS('|',
                       parent_item_id, variant_item_id, variant_label, COALESCE(variant_name_en, ''),
                       sort_order, is_default, is_active, COALESCE(updated_at, created_at)
                   ))), 0) AS checksum,
                   COALESCE(MAX(UNIX_TIMESTAMP(COALESCE(updated_at, created_at))), 0) AS max_changed_at
            FROM item_variants
        ")->fetch_assoc();
        $raw['variants'] = [
            'count' => (int) ($variants['row_count'] ?? 0),
            'checksum' => (string) ($variants['checksum'] ?? '0'),
            'max_changed_at' => (int) ($variants['max_changed_at'] ?? 0),
        ];
    }

    return [
        'fingerprint' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'raw' => $raw,
    ];
}

function moova_menu_sync_item_modifier_groups(mysqli $conn, int $itemId): array
{
    if (
        $itemId <= 0
        || !moova_menu_sync_table_exists($conn, 'modifier_groups')
        || !moova_menu_sync_table_exists($conn, 'modifier_options')
        || !moova_menu_sync_table_exists($conn, 'item_modifier_groups')
    ) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            mg.id,
            mg.name_ar,
            mg.name_en,
            mg.selection_min,
            mg.selection_max,
            mg.is_required,
            mg.is_active,
            COALESCE(img.sort_order, mg.sort_order, 0) AS group_sort_order
        FROM item_modifier_groups img
        JOIN modifier_groups mg ON mg.id = img.group_id
        WHERE img.item_id = ?
          AND mg.is_active = 1
        ORDER BY img.sort_order, mg.sort_order, mg.id
    ");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $groupId = (int) $row['id'];
        $providerGroupId = 'pos-mod-group-' . $groupId;
        $groups[$groupId] = [
            'id' => $providerGroupId,
            'providerOptionGroupId' => $providerGroupId,
            'group_id' => $groupId,
            'name' => (string) ($row['name_ar'] ?? ''),
            'name2' => trim((string) ($row['name_en'] ?? '')) ?: null,
            'min' => max(0, (int) ($row['selection_min'] ?? 0)),
            'max' => max(0, (int) ($row['selection_max'] ?? 0)),
            'required' => (int) ($row['is_required'] ?? 0) === 1,
            'isActive' => (int) ($row['is_active'] ?? 1) === 1,
            'sortOrder' => (int) ($row['group_sort_order'] ?? 0),
            'options' => [],
            'values' => [],
        ];
    }
    $stmt->close();

    if (!$groups) {
        return [];
    }

    $groupIds = array_keys($groups);
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $conn->prepare("
        SELECT id, group_id, name_ar, name_en, price_delta, is_active, sort_order
        FROM modifier_options
        WHERE group_id IN ({$placeholders})
          AND is_active = 1
        ORDER BY group_id, sort_order, id
    ");
    $stmt->bind_param(str_repeat('i', count($groupIds)), ...$groupIds);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groupId = (int) $row['group_id'];
        if (!isset($groups[$groupId])) {
            continue;
        }
        $optionId = (int) $row['id'];
        $providerOptionId = 'pos-mod-option-' . $optionId;
        $option = [
            'id' => $providerOptionId,
            'providerOptionId' => $providerOptionId,
            'option_id' => $optionId,
            'name' => (string) ($row['name_ar'] ?? ''),
            'name2' => trim((string) ($row['name_en'] ?? '')) ?: null,
            'price' => moova_menu_sync_decimal($row['price_delta'] ?? 0),
            'priceDelta' => moova_menu_sync_decimal($row['price_delta'] ?? 0),
            'isActive' => (int) ($row['is_active'] ?? 1) === 1,
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
        ];
        $groups[$groupId]['options'][] = $option;
        $groups[$groupId]['values'][] = $option;
    }
    $stmt->close();

    return array_values($groups);
}

function moova_menu_sync_build_menu(mysqli $conn, string $catalogVersion, ?array $link = null): array
{
    $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
    $recipeFlags = new RecipeFeatureFlags($config);
    $recipeSync = null;
    $recipeScope = null;
    if ($recipeFlags->isMoovaSyncEnabled()) {
        $recipeSync = new RecipeSyncPayloadService($recipeFlags);
        $recipeScope = moova_menu_sync_recipe_scope($config, $link);
    }

    $categoryInfoSelect = moova_menu_sync_optional_expr($conn, 'item_group', 'info', 'info', "''");
    $categoryWhere = moova_menu_sync_column_exists($conn, 'item_group', 'isdeleted')
        ? 'WHERE COALESCE(isdeleted, 0) = 0'
        : '';
    $categoryRows = $conn->query("
        SELECT id, gname, {$categoryInfoSelect}
        FROM item_group
        {$categoryWhere}
        ORDER BY id ASC
    ");

    $categories = [];
    while ($row = $categoryRows->fetch_assoc()) {
        $providerId = 'pos-cat-' . (int) $row['id'];
        $name = trim((string) $row['gname']);
        if ($name === '') {
            continue;
        }
        $categories[] = [
            'id' => $providerId,
            'key' => $providerId,
            'providerCategoryId' => $providerId,
            'name' => $name,
            'desc' => (string) ($row['info'] ?? ''),
            'isActive' => true,
        ];
    }

    $itemName2Select = moova_menu_sync_optional_expr($conn, 'myitems', 'name2', 'name2', 'NULL', 'i.');
    $itemInfoSelect = moova_menu_sync_optional_expr($conn, 'myitems', 'info', 'info', "''", 'i.');
    $itemPrice2Select = moova_menu_sync_optional_expr($conn, 'myitems', 'price2', 'price2', '0', 'i.');
    $itemPrice3Select = moova_menu_sync_optional_expr($conn, 'myitems', 'price3', 'price3', '0', 'i.');
    $itemGroup1Select = moova_menu_sync_optional_expr($conn, 'myitems', 'group1', 'group1', '0', 'i.');
    $itemGroup2Select = moova_menu_sync_optional_expr($conn, 'myitems', 'group2', 'group2', '0', 'i.');
    $itemUuidSelect = moova_menu_sync_optional_expr($conn, 'myitems', 'uuid', 'item_uuid', 'NULL', 'i.');
    $itemTypeSelect = moova_menu_sync_optional_expr($conn, 'myitems', 'item_type', 'item_type', "'sellable'", 'i.');
    $itemTrackStockSelect = moova_menu_sync_optional_expr($conn, 'myitems', 'track_stock', 'track_stock', '1', 'i.');
    $itemDeletedWhere = moova_menu_sync_column_exists($conn, 'myitems', 'isdeleted')
        ? 'WHERE COALESCE(i.isdeleted, 0) = 0'
        : 'WHERE 1 = 1';
    $itemCategoryJoin = moova_menu_sync_column_exists($conn, 'myitems', 'group1')
        ? 'LEFT JOIN item_group g ON g.id = i.group1'
        : '';
    $itemCategoryNameSelect = $itemCategoryJoin ? 'g.gname AS category_name' : 'NULL AS category_name';
    $hasVariantTable = moova_menu_sync_table_exists($conn, 'item_variants');
    $variantJoin = $hasVariantTable
        ? 'LEFT JOIN item_variants iv_child ON iv_child.variant_item_id = i.id AND iv_child.is_active = 1'
        : '';
    $variantSelect = $hasVariantTable
        ? 'iv_child.parent_item_id AS parent_item_id, iv_child.variant_label AS variant_label'
        : 'NULL AS parent_item_id, NULL AS variant_label';
    $variantSellableWhere = $hasVariantTable
        ? " AND NOT EXISTS (
                SELECT 1
                FROM item_variants iv_parent
                WHERE iv_parent.parent_item_id = i.id
                  AND iv_parent.is_active = 1
            )
            AND NOT EXISTS (
                SELECT 1
                FROM item_variants iv_inactive
                WHERE iv_inactive.variant_item_id = i.id
                  AND iv_inactive.is_active = 0
            )"
        : '';
    $itemOrder = moova_menu_sync_column_exists($conn, 'myitems', 'group1')
        ? 'ORDER BY COALESCE(i.group1, 0), i.id ASC'
        : 'ORDER BY i.id ASC';
    $itemRows = $conn->query("
        SELECT i.id,
               i.iname,
               {$itemName2Select},
               {$itemInfoSelect},
               i.barcode,
               i.price1,
               {$itemPrice2Select},
               {$itemPrice3Select},
               i.cost_price,
               {$itemGroup1Select},
               {$itemGroup2Select},
               {$itemUuidSelect},
               {$itemTypeSelect},
               {$itemTrackStockSelect},
               {$itemCategoryNameSelect},
               {$variantSelect}
        FROM myitems i
        {$itemCategoryJoin}
        {$variantJoin}
        {$itemDeletedWhere}
        {$variantSellableWhere}
        {$itemOrder}
    ");

    $items = [];
    while ($row = $itemRows->fetch_assoc()) {
        $itemId = (int) $row['id'];
        $name = trim((string) $row['iname']);
        if ($itemId < 1 || $name === '') {
            continue;
        }
        $providerItemId = 'pos-item-' . $itemId;
        $categoryId = (int) ($row['group1'] ?? 0);
        $categoryKey = $categoryId > 0 ? 'pos-cat-' . $categoryId : null;
        $menuItem = [
            'id' => $providerItemId,
            'providerItemId' => $providerItemId,
            'name' => $name,
            'name2' => trim((string) ($row['name2'] ?? '')) ?: null,
            'desc' => (string) ($row['info'] ?? ''),
            'barcode' => trim((string) ($row['barcode'] ?? '')) ?: null,
            'price' => moova_menu_sync_decimal($row['price1'] ?? 0),
            'price2' => moova_menu_sync_decimal($row['price2'] ?? 0),
            'price3' => moova_menu_sync_decimal($row['price3'] ?? 0),
            'cost' => moova_menu_sync_decimal($row['cost_price'] ?? 0),
            'available' => true,
            'deliveryAvailable' => true,
            'categoryId' => $categoryKey,
            'categoryKey' => $categoryKey,
            'categoryName' => $row['category_name'] ?? null,
            'isOrderable' => true,
            'isVariantChild' => !empty($row['parent_item_id']),
            'parentItemId' => !empty($row['parent_item_id']) ? 'pos-item-' . (int) $row['parent_item_id'] : null,
            'variantLabel' => trim((string) ($row['variant_label'] ?? '')) ?: null,
        ];
        if ($recipeSync !== null && $recipeScope !== null) {
            $menuItem = moova_menu_sync_apply_recipe_availability(
                $conn,
                $recipeSync,
                $recipeScope,
                $menuItem,
                [
                    'id' => $itemId,
                    'item_id' => $itemId,
                    'uuid' => $row['item_uuid'] ?? null,
                    'item_type' => $row['item_type'] ?? 'sellable',
                    'track_stock' => $row['track_stock'] ?? 1,
                    'group1' => $categoryId,
                    'category_id' => $categoryId,
                    'updated_at' => $row['mdtime'] ?? null,
                ]
            );
        }
        $items[] = $menuItem;
    }

    return [
        'catalogVersion' => $catalogVersion,
        'menu' => [
            'categories' => $categories,
            'items' => $items,
        ],
        'rawPayload' => [
            'source' => 'posmain_local_menu',
            'catalogVersion' => $catalogVersion,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'priceUnit' => 'major',
            'counts' => [
                'categories' => count($categories),
                'items' => count($items),
            ],
        ],
    ];
}

require __DIR__ . '/../includes/connect.php';

try {
    MoovaPosIntegration::ensureSchema($conn);
    $deviceToken = moova_menu_sync_device_token();
    $incomingBranchId = trim((string) (
        moova_menu_sync_header('X-Moova-Branch-Id')
        ?: ($_GET['branchId'] ?? $_POST['branchId'] ?? '')
    ));
    if ($deviceToken !== '') {
        $link = $incomingBranchId !== ''
            ? MoovaPosIntegration::findActiveLinkByTokenAndBranch($conn, $deviceToken, $incomingBranchId)
            : null;
        if (!$link) {
            $link = MoovaPosIntegration::findActiveLinkByToken($conn, $deviceToken);
        }
    } else {
        if (empty($_SESSION['userid'])) {
            moova_menu_sync_json(401, ['success' => false, 'code' => 'AUTH_REQUIRED']);
        }
        $link = MoovaPosIntegration::findActiveLinkForUser($conn, (int) $_SESSION['userid']);
        $deviceToken = moova_menu_sync_header('X-Moova-Device-Token');
    }
} catch (Throwable $e) {
    moova_menu_sync_json(500, ['success' => false, 'code' => 'MOOVA_MAPPING_UNAVAILABLE']);
}

if (!$link || $deviceToken === '' || !hash_equals((string) ($link['moova_device_token'] ?? ''), $deviceToken)) {
    moova_menu_sync_json(403, ['success' => false, 'code' => 'MOOVA_LINK_NOT_FOUND']);
}

try {
    $fingerprint = moova_menu_sync_fingerprint($conn);
    $mode = strtolower(trim((string) ($_GET['mode'] ?? $_POST['mode'] ?? 'full')));
    if ($mode === 'fingerprint') {
        moova_menu_sync_json(200, [
            'success' => true,
            'catalogVersion' => $fingerprint['fingerprint'],
            'fingerprint' => $fingerprint['fingerprint'],
            'summary' => $fingerprint['raw'],
        ]);
    }

    $menu = moova_menu_sync_build_menu($conn, $fingerprint['fingerprint'], $link);
    moova_menu_sync_json(200, [
        'success' => true,
        'catalogVersion' => $menu['catalogVersion'],
        'fingerprint' => $fingerprint['fingerprint'],
        'menu' => $menu['menu'],
        'rawPayload' => $menu['rawPayload'],
        'summary' => $menu['rawPayload']['counts'],
    ]);
} catch (Throwable $e) {
    moova_menu_sync_json(500, [
        'success' => false,
        'code' => 'MENU_SYNC_FAILED',
        'message' => 'Unable to build POS menu sync payload.',
        'retryable' => true,
    ]);
}
