<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Items/ItemUnitColumnSupport.php';
require_once __DIR__ . '/../classes/Items/ItemInventoryUnitSync.php';

$options = getopt('', ['db::', 'dry-run', 'item::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/migrate_item_unit_profiles.php [--db=name] [--item=id] [--dry-run]\n");
    exit(0);
}

$config = posmain_app_config();
$dbConfig = $config['database'];
$dryRun = array_key_exists('dry-run', $options);
$itemFilter = isset($options['item']) ? (int) $options['item'] : 0;
$database = isset($options['db']) ? (string) $options['db'] : trim((string) ($dbConfig['name'] ?? ''));

if ($database === '') {
    fwrite(STDERR, "POSMAIN_DB_NAME is not configured\n");
    exit(1);
}

$conn = posmain_raw_db_connect([
    'host' => $dbConfig['host'],
    'user' => $dbConfig['user'],
    'pass' => $dbConfig['pass'],
    'name' => $database,
    'port' => $dbConfig['port'],
    'charset' => $dbConfig['charset'] ?? 'utf8mb4',
]);

if (!tableExists($conn, 'item_units')) {
    fwrite(STDERR, "item_units table not found\n");
    exit(1);
}

if (!$dryRun) {
    ItemUnitColumnSupport::ensureDefFlags($conn);
}

$hasDefFlags = ItemUnitColumnSupport::hasDefFlags($conn);
if (!$hasDefFlags) {
    fwrite(STDERR, "def_* columns are unavailable\n");
    exit(1);
}

$hasPreferredUnit = columnExists($conn, 'myitems', 'preferred_unit_id');
$hasItemType = columnExists($conn, 'myitems', 'item_type');

$sql = '
    SELECT iu.item_id
    FROM item_units iu
    WHERE COALESCE(iu.isdeleted, 0) = 0
';
if ($itemFilter > 0) {
    $sql .= ' AND iu.item_id = ' . $itemFilter;
}
$sql .= ' GROUP BY iu.item_id ORDER BY iu.item_id ASC';

$result = $conn->query($sql);
if (!$result) {
    fwrite(STDERR, "Failed to list items\n");
    exit(1);
}

$migrated = 0;
$skipped = 0;

while ($row = $result->fetch_assoc()) {
    $itemId = (int) ($row['item_id'] ?? 0);
    if ($itemId < 1) {
        continue;
    }

    $unitRows = loadUnitRows($conn, $itemId);
    if (!$unitRows) {
        continue;
    }

    if (hasExistingDefFlags($unitRows)) {
        $skipped++;
        continue;
    }

    $itemMeta = loadItemMeta($conn, $itemId, $hasItemType, $hasPreferredUnit);
    $plan = buildMigrationPlan($unitRows, $itemMeta);

    fwrite(STDOUT, sprintf(
        "item %d: stock=%d sell=%d buy=%d units=%d%s\n",
        $itemId,
        $plan['stock_unit_id'],
        $plan['sell_unit_id'],
        $plan['buy_unit_id'],
        count($unitRows),
        $dryRun ? ' (dry-run)' : ''
    ));

    if ($dryRun) {
        $migrated++;
        continue;
    }

    applyMigrationPlan($conn, $itemId, $plan);
    if ($hasPreferredUnit && $plan['stock_unit_id'] > 0) {
        $stmt = $conn->prepare('UPDATE myitems SET preferred_unit_id = ? WHERE id = ?');
        $stockUnitId = $plan['stock_unit_id'];
        $stmt->bind_param('ii', $stockUnitId, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    if ($plan['buy_unit_id'] > 0) {
        ItemInventoryUnitSync::syncPurchaseUnitPreference($conn, $itemId, $plan['buy_unit_id']);
    }

    $migrated++;
}

fwrite(STDOUT, sprintf("done: migrated=%d skipped=%d dry_run=%s\n", $migrated, $skipped, $dryRun ? 'yes' : 'no'));

function loadUnitRows(mysqli $conn, int $itemId): array
{
    $stmt = $conn->prepare('
        SELECT id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3, def_sale, def_buy, def_stock
        FROM item_units
        WHERE item_id = ?
          AND COALESCE(isdeleted, 0) = 0
        ORDER BY id ASC
    ');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return is_array($rows) ? $rows : [];
}

function hasExistingDefFlags(array $rows): bool
{
    foreach ($rows as $row) {
        if ((int) ($row['def_sale'] ?? 0) === 1
            || (int) ($row['def_stock'] ?? 0) === 1
            || (int) ($row['def_buy'] ?? 0) === 1) {
            return true;
        }
    }

    return false;
}

function loadItemMeta(mysqli $conn, int $itemId, bool $hasItemType, bool $hasPreferredUnit): array
{
    $columns = ['id'];
    if ($hasItemType) {
        $columns[] = 'item_type';
    }
    if ($hasPreferredUnit) {
        $columns[] = 'preferred_unit_id';
    }
    $columns[] = 'price1';

    $stmt = $conn->prepare('SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : ['id' => $itemId, 'item_type' => 'sellable', 'price1' => 0];
}

function buildMigrationPlan(array $rows, array $itemMeta): array
{
    $stockRow = $rows[0];
    foreach ($rows as $row) {
        if ((float) ($row['u_val'] ?? 1) <= (float) ($stockRow['u_val'] ?? 1)) {
            $stockRow = $row;
        }
    }

    $buyRow = null;
    foreach ($rows as $row) {
        if ($buyRow === null || (float) ($row['u_val'] ?? 1) > (float) ($buyRow['u_val'] ?? 1)) {
            $buyRow = $row;
        }
    }
    if ($buyRow !== null && (int) $buyRow['unit_id'] === (int) $stockRow['unit_id']) {
        $buyRow = count($rows) > 1 ? $rows[1] : null;
        if ($buyRow !== null && (int) $buyRow['unit_id'] === (int) $stockRow['unit_id']) {
            $buyRow = null;
        }
    }

    $itemType = (string) ($itemMeta['item_type'] ?? 'sellable');
    $sellable = in_array($itemType, ['sellable', 'service'], true);
    $sellRow = $stockRow;
    foreach ($rows as $row) {
        if ((float) ($row['price1'] ?? 0) > 0) {
            $sellRow = $row;
            break;
        }
    }

    $flags = [];
    foreach ($rows as $row) {
        $unitId = (int) $row['unit_id'];
        $flags[$unitId] = [
            'def_stock' => 0,
            'def_sale' => 0,
            'def_buy' => 0,
        ];
    }

    $flags[(int) $stockRow['unit_id']]['def_stock'] = 1;
    if ($sellable || (float) ($sellRow['price1'] ?? 0) > 0 || (float) ($itemMeta['price1'] ?? 0) > 0) {
        $flags[(int) $sellRow['unit_id']]['def_sale'] = 1;
    }
    if ($buyRow !== null && ((float) ($buyRow['cost_price'] ?? 0) > 0 || (float) ($buyRow['u_val'] ?? 1) > 1)) {
        $flags[(int) $buyRow['unit_id']]['def_buy'] = 1;
    }

    return [
        'stock_unit_id' => (int) $stockRow['unit_id'],
        'sell_unit_id' => (int) $sellRow['unit_id'],
        'buy_unit_id' => $buyRow ? (int) $buyRow['unit_id'] : 0,
        'flags' => $flags,
    ];
}

function applyMigrationPlan(mysqli $conn, int $itemId, array $plan): void
{
    $stmt = $conn->prepare('
        UPDATE item_units
        SET def_stock = ?, def_sale = ?, def_buy = ?
        WHERE item_id = ? AND unit_id = ?
    ');

    foreach ($plan['flags'] as $unitId => $flags) {
        $defStock = (int) ($flags['def_stock'] ?? 0);
        $defSale = (int) ($flags['def_sale'] ?? 0);
        $defBuy = (int) ($flags['def_buy'] ?? 0);
        $stmt->bind_param('iiiii', $defStock, $defSale, $defBuy, $itemId, $unitId);
        $stmt->execute();
    }

    $stmt->close();
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0) > 0;
}
