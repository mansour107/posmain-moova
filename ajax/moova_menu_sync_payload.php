<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function moova_menu_sync_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
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

    return [
        'fingerprint' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'raw' => $raw,
    ];
}

function moova_menu_sync_build_menu(mysqli $conn, string $catalogVersion): array
{
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
    $itemDeletedWhere = moova_menu_sync_column_exists($conn, 'myitems', 'isdeleted')
        ? 'WHERE COALESCE(i.isdeleted, 0) = 0'
        : '';
    $itemCategoryJoin = moova_menu_sync_column_exists($conn, 'myitems', 'group1')
        ? 'LEFT JOIN item_group g ON g.id = i.group1'
        : '';
    $itemCategoryNameSelect = $itemCategoryJoin ? 'g.gname AS category_name' : 'NULL AS category_name';
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
               {$itemCategoryNameSelect}
        FROM myitems i
        {$itemCategoryJoin}
        {$itemDeletedWhere}
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
        $items[] = [
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
            'options' => [],
        ];
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

    $menu = moova_menu_sync_build_menu($conn, $fingerprint['fingerprint']);
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
