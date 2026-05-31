<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryReasonCodeService.php';

inventoryPhase9ReasonAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase9-reason-code-admin-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase9_reason_admin_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        INSERT INTO inventory_reason_codes (id, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active)
        VALUES
            (9901, 'SYSTEM_WASTE', 'System waste', 'waste', 'out', 0, 1, 1),
            (9902, 'OTHER_BRANCH', 'Other branch only', 'adjustment', 'in', 0, 0, 1)
    ");
    $conn->query("UPDATE inventory_reason_codes SET pos_tenant = 7, pos_branch = 9 WHERE id = 9902");

    $service = new InventoryReasonCodeService();
    $scope = ['pos_tenant' => 0, 'pos_branch' => 0];
    $created = $service->save($conn, $scope, [
        'reason_code' => 'custom waste',
        'reason_name' => 'كسر أثناء التحضير',
        'reason_group' => 'waste',
        'direction' => 'out',
        'requires_approval' => 1,
        'is_active' => 1,
    ], ['user_id' => 11]);
    inventoryPhase9ReasonAssert($created['success'] === true && $created['action'] === 'created', 'custom reason code should be created');
    $createdId = (int) $created['id'];
    $row = inventoryPhase9ReasonOne($conn, 'SELECT * FROM inventory_reason_codes WHERE id = ' . $createdId . ' LIMIT 1');
    inventoryPhase9ReasonAssert($row['reason_code'] === 'CUSTOM_WASTE', 'reason code should be normalized');
    inventoryPhase9ReasonAssert($row['reason_name'] === 'كسر أثناء التحضير', 'Arabic reason name should persist');
    inventoryPhase9ReasonAssert((int) $row['requires_approval'] === 1 && (int) $row['is_system'] === 0, 'custom approval/system flags should persist');

    $allRows = $service->listAll($conn, $scope, true);
    $allCodes = array_column($allRows, 'reason_code');
    inventoryPhase9ReasonAssert(in_array('SYSTEM_WASTE', $allCodes, true), 'admin list should include global system rows');
    inventoryPhase9ReasonAssert(in_array('CUSTOM_WASTE', $allCodes, true), 'admin list should include scoped custom rows');
    inventoryPhase9ReasonAssert(!in_array('OTHER_BRANCH', $allCodes, true), 'admin list should not include another branch custom rows');

    $operationRows = $service->listForOperation($conn, $scope, 'waste', 'decrease');
    inventoryPhase9ReasonAssert(in_array('CUSTOM_WASTE', array_column($operationRows, 'reason_code'), true), 'active custom waste reason should be selectable');

    $updated = $service->save($conn, $scope, [
        'id' => $createdId,
        'reason_code' => 'custom spoilage',
        'reason_name' => 'تلف مخزني',
        'reason_group' => 'waste',
        'direction' => 'out',
        'requires_approval' => 0,
        'is_active' => 1,
    ], ['user_id' => 11]);
    inventoryPhase9ReasonAssert($updated['success'] === true && $updated['action'] === 'updated', 'custom reason code should be updateable');
    $row = inventoryPhase9ReasonOne($conn, 'SELECT reason_code, reason_name, requires_approval FROM inventory_reason_codes WHERE id = ' . $createdId . ' LIMIT 1');
    inventoryPhase9ReasonAssert($row['reason_code'] === 'CUSTOM_SPOILAGE' && $row['reason_name'] === 'تلف مخزني', 'custom reason update should persist');
    inventoryPhase9ReasonAssert((int) $row['requires_approval'] === 0, 'approval flag update should persist');

    try {
        $service->save($conn, $scope, [
            'reason_code' => 'CUSTOM_SPOILAGE',
            'reason_name' => 'Duplicate',
            'reason_group' => 'waste',
            'direction' => 'out',
        ], ['user_id' => 11]);
        inventoryPhase9ReasonAssert(false, 'duplicate reason code should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase9ReasonAssert($exception->getMessage() === 'REASON_CODE_DUPLICATE', 'duplicate reason code should return expected code');
    }

    try {
        $service->save($conn, $scope, [
            'id' => 9901,
            'reason_code' => 'SYSTEM_CHANGED',
            'reason_name' => 'Changed',
            'reason_group' => 'waste',
            'direction' => 'out',
        ], ['user_id' => 11]);
        inventoryPhase9ReasonAssert(false, 'system reason code edits should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase9ReasonAssert($exception->getMessage() === 'SYSTEM_REASON_CODE_LOCKED', 'system reason code should be locked');
    }

    $retired = $service->setActive($conn, $scope, $createdId, false);
    inventoryPhase9ReasonAssert($retired['success'] === true && $retired['action'] === 'retired', 'custom reason code should retire');
    $operationRows = $service->listForOperation($conn, $scope, 'waste', 'decrease');
    inventoryPhase9ReasonAssert(!in_array('CUSTOM_SPOILAGE', array_column($operationRows, 'reason_code'), true), 'retired reason should not be selectable');

    $reactivated = $service->setActive($conn, $scope, $createdId, true);
    inventoryPhase9ReasonAssert($reactivated['success'] === true && $reactivated['action'] === 'reactivated', 'custom reason code should reactivate');
    inventoryPhase9ReasonAssert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === 0, 'reason-code admin should not write inventory movements');

    echo "inventory-phase9-reason-code-admin-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase9ReasonOne(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase9ReasonAssert(is_array($row), 'Expected row for query: ' . $sql);
    return $row;
}

function inventoryPhase9ReasonAssertSourceContracts(string $root): void
{
    $page = inventoryPhase9ReasonSource($root . '/inventory_reason_codes.php');
    foreach (['أسباب عمليات المخزون', 'inventory-reason-code-csrf', 'ajax/inventory_reason_code.php', 'inventoryReasonCodeRows', 'data-inventory-reason-retire', 'نظامي', 'html,body{overflow-x:hidden}', 'الرمز الداخلي', 'اختياري عند إنشاء سبب جديد', 'inventoryReasonCodeGenerateCode'] as $needle) {
        inventoryPhase9ReasonAssert(strpos($page, $needle) !== false, 'reason-code admin page should include: ' . $needle);
    }

    $endpoint = inventoryPhase9ReasonSource($root . '/ajax/inventory_reason_code.php');
    foreach (['InventoryReasonCodeService.php', "require_permission('inventory.edit'", "require_csrf('inventory_reason_code'", 'setActive', 'REASON_CODE_DUPLICATE', 'SYSTEM_REASON_CODE_LOCKED'] as $needle) {
        inventoryPhase9ReasonAssert(strpos($endpoint, $needle) !== false, 'reason-code endpoint should include: ' . $needle);
    }

    $sidebar = inventoryPhase9ReasonSource($root . '/includes/sidebar.php');
    inventoryPhase9ReasonAssert(strpos($sidebar, 'inventory_reason_codes.php') !== false && strpos($sidebar, 'أسباب عمليات المخزون') !== false, 'sidebar should link Arabic reason-code admin page');

    $docs = inventoryPhase9ReasonSource($root . '/docs/inventory/phase9_adjustment_contracts.md');
    foreach (['inventory_reason_codes.php', 'creating, editing, retiring, and reactivating', 'reason-code page can auto-generate internal codes', 'never writes inventory movements', 'System reason codes are visible but locked'] as $needle) {
        inventoryPhase9ReasonAssert(strpos($docs, $needle) !== false, 'phase9 docs should include: ' . $needle);
    }
}

function inventoryPhase9ReasonSource(string $path): string
{
    $source = file_get_contents($path);
    inventoryPhase9ReasonAssert(is_string($source), 'Unable to read source: ' . $path);
    return $source;
}

function inventoryPhase9ReasonAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
