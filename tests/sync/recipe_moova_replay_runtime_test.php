<?php

require_once __DIR__ . '/../../classes/Moova/MoovaChangeOrderApplyService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaNewOrderApplyService.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';

class RecipeMoovaReplayNoopOutbox extends SyncOutboxEventService
{
    public function recordOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
    {
        return null;
    }

    public function recordTableSnapshot(mysqli $conn, int $tableId, array $options = []): ?array
    {
        return null;
    }

    public function recordMenuItemSnapshot(mysqli $conn, int $itemId, array $options = []): ?array
    {
        return null;
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('POSMAIN_INVENTORY_LEDGER_MODE=shadow');
putenv('POSMAIN_INVENTORY_STRICT_STOCK=0');

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_moova_replay_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeMoovaReplayCreateSchema($conn);
    recipeMoovaReplaySeedData($conn);

    $flags = new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'consume_pilot',
            'reservations' => true,
            'consumption' => true,
            'accounting' => false,
            'availability' => false,
            'moova_sync' => false,
            'pilot' => [
                'item_ids' => [7001],
                'category_ids' => [],
                'pos_branch' => '',
            ],
        ],
    ]);
    $outbox = new RecipeMoovaReplayNoopOutbox();
    $lifecycle = new RecipeOrderLifecycleService(
        $flags,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        $outbox
    );
    $posOrders = new PosOrderService($lifecycle);
    $newApply = new MoovaNewOrderApplyService($posOrders, $outbox);
    $changeApply = new MoovaChangeOrderApplyService($posOrders, $outbox);
    $link = [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'branch_uuid' => '11111111-1111-4111-8111-111111111111',
        'moova_branch_id' => 'moova-branch-1',
    ];

    $firstPayload = recipeMoovaReplayPayload('mv-replay-A', 'provider-line-A', '1.000000');
    $secondPayload = recipeMoovaReplayPayload('mv-replay-B', 'provider-line-B', '2.000000');

    $first = recipeMoovaReplayApplyNew($conn, $newApply, $link, $firstPayload, 'phpunit:recipe-moova:new:A');
    $firstReplay = recipeMoovaReplayApplyNew($conn, $newApply, $link, $firstPayload, 'phpunit:recipe-moova:new:A');
    recipeMoovaReplayAssert($firstReplay['existing'] === true, 'new-order replay should return existing link response');
    recipeMoovaReplayAssert((int) $firstReplay['order_id'] === (int) $first['order_id'], 'new-order replay should not create another POS order');
    recipeMoovaReplayAssert(recipeMoovaReplayCount($conn, "SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE source_order_uuid = 'mv-replay-A'") === 1, 'new-order replay should not duplicate recipe usage');

    $second = recipeMoovaReplayApplyNew($conn, $newApply, $link, $secondPayload, 'phpunit:recipe-moova:new:B');
    recipeMoovaReplayAssert((int) $second['order_id'] === (int) $first['order_id'], 'same-table Moova orders should share the active POS receipt');

    $orderId = (int) $first['order_id'];
    $detailRows = recipeMoovaReplayRows($conn, "SELECT id, qty_out FROM fat_details WHERE fatid = {$orderId} AND item_id = 7001 AND isdeleted = 0");
    recipeMoovaReplayAssert(count($detailRows) === 1, 'same item from multiple Moova orders should share the legacy detail row');
    recipeMoovaReplayAssert(recipeMoovaReplayDecimalEquals($detailRows[0]['qty_out'], '3.000000'), 'legacy detail row should contain both Moova quantities');
    $directSaleRows = recipeMoovaReplayRows($conn, "SELECT id, qty_out, order_line_uuid FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'sale_direct' ORDER BY id");
    recipeMoovaReplayAssert(
        count($directSaleRows) === 0,
        'recipe-owned Moova lines must not also write sellable sale_direct movements and double-deplete stock/COGS'
    );

    $usageRows = recipeMoovaReplayRows($conn, "SELECT source_order_uuid, source_line_uuid, status, fat_detail_id FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY source_order_uuid");
    recipeMoovaReplayAssert(count($usageRows) === 2, 'two Moova source lines should create two recipe usage rows');
    recipeMoovaReplayAssert(array_column($usageRows, 'source_line_uuid') === ['moova:provider-line-A', 'moova:provider-line-B'], 'recipe usage rows should preserve provider line identities');
    recipeMoovaReplayAssert(count(array_unique(array_column($usageRows, 'fat_detail_id'))) === 1, 'runtime test should exercise shared fat_detail_id isolation');

    $reservedBeforeCancel = recipeMoovaReplayRows($conn, "SELECT status, qty_reserved, recipe_order_line_usage_id FROM stock_reservations WHERE order_id = {$orderId} ORDER BY id");
    recipeMoovaReplayAssert(count($reservedBeforeCancel) === 2, 'two source lines should create two reservations');
    recipeMoovaReplayAssert(recipeMoovaReplayBalance($conn, 7002)['qty_reserved'] === '3.000000', 'both Moova orders should reserve three ingredient units');

    $cancelPayload = [
        'action' => 'cancel',
        'moovaOrderId' => 'mv-replay-B',
        'branchId' => 'moova-branch-1',
        'requestEventId' => 'cancel-provider-line-B',
    ];
    $cancel = recipeMoovaReplayApplyChange($conn, $changeApply, $link, $cancelPayload, 'phpunit:recipe-moova:cancel:B');
    recipeMoovaReplayAssert($cancel['status'] === 'applied', 'Moova cancel should apply');
    $cancelReplay = recipeMoovaReplayApplyChange($conn, $changeApply, $link, $cancelPayload, 'phpunit:recipe-moova:cancel:B');
    recipeMoovaReplayAssert($cancelReplay['existing'] === true, 'Moova cancel replay should return existing change response');

    $usageAfterCancel = recipeMoovaReplayRows($conn, "SELECT source_order_uuid, status FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY source_order_uuid");
    recipeMoovaReplayAssert(array_column($usageAfterCancel, 'status') === ['reserved', 'released'], 'cancel should release only the cancelled Moova source line');
    $reservationsAfterCancel = recipeMoovaReplayRows($conn, "SELECT status, qty_reserved FROM stock_reservations WHERE order_id = {$orderId} ORDER BY id");
    recipeMoovaReplayAssert(array_column($reservationsAfterCancel, 'status') === ['reserved', 'released'], 'cancel should not release the other Moova order reservation on the shared detail row');
    recipeMoovaReplayAssert(recipeMoovaReplayBalance($conn, 7002)['qty_reserved'] === '1.000000', 'cancel should leave the first Moova order reservation intact');
    recipeMoovaReplayAssert(
        recipeMoovaReplayCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE movement_type = 'reservation_release'") === 1,
        'cancel replay should not duplicate reservation release movement'
    );
    recipeMoovaReplayAssert(
        recipeMoovaReplayCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'refund_reversal' AND item_id = 7001") === 0,
        'cancelling a recipe-owned Moova line must not create a sellable reversal without a matching direct sale'
    );

    $conn->begin_transaction();
    try {
        $paid = $lifecycle->onOrderPaid([
            'conn' => $conn,
            'order_id' => $orderId,
            'channel' => 'moova',
            'order_type' => 'dine_in',
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    recipeMoovaReplayAssert($paid['noop'] === false, 'payment finalization should consume the remaining Moova recipe usage');

    $conn->begin_transaction();
    try {
        $lifecycle->onOrderPaid([
            'conn' => $conn,
            'order_id' => $orderId,
            'channel' => 'moova',
            'order_type' => 'dine_in',
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    recipeMoovaReplayAssert(
        recipeMoovaReplayCount($conn, "SELECT COUNT(*) AS c FROM inventory_movements WHERE movement_type = 'recipe_consumption'") === 1,
        'payment replay should not duplicate recipe consumption movement'
    );
    recipeMoovaReplayAssert(recipeMoovaReplayBalance($conn, 7002)['qty_on_hand'] === '19.000000', 'payment should consume one ingredient unit for the remaining order');
    recipeMoovaReplayAssert(recipeMoovaReplayBalance($conn, 7002)['qty_reserved'] === '0.000000', 'payment should clear the remaining reservation');

    echo "recipe-moova-replay-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeMoovaReplayApplyNew(mysqli $conn, MoovaNewOrderApplyService $service, array $link, array $payload, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = $service->applyInTransaction($conn, $link, $payload, [
            'idempotency_key' => $idempotencyKey,
            'request_hash' => MoovaPosIntegration::payloadHash($payload),
            'request_json' => recipeMoovaReplayJson($payload),
            'moova_order_id' => (string) $payload['cofeOrderId'],
            'moova_branch_id' => (string) $payload['branchId'],
            'branch_uuid' => (string) $link['branch_uuid'],
            'user_id' => 1,
            'response_mode' => 'direct',
        ]);
        $conn->commit();

        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function recipeMoovaReplayApplyChange(mysqli $conn, MoovaChangeOrderApplyService $service, array $link, array $payload, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = $service->applyInTransaction($conn, $link, $payload, [
            'idempotency_key' => $idempotencyKey,
            'request_hash' => MoovaPosIntegration::changePayloadHash($payload),
            'request_json' => recipeMoovaReplayJson($payload),
            'moova_order_id' => (string) $payload['moovaOrderId'],
            'moova_branch_id' => (string) $payload['branchId'],
            'branch_uuid' => (string) $link['branch_uuid'],
            'request_event_id' => (string) ($payload['requestEventId'] ?? ''),
            'action' => (string) $payload['action'],
            'user_id' => 1,
            'response_mode' => 'direct',
        ]);
        $conn->commit();

        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function recipeMoovaReplayPayload(string $orderId, string $lineId, string $qty): array
{
    return [
        'cofeOrderId' => $orderId,
        'branchId' => 'moova-branch-1',
        'tableNumber' => '1',
        'sourceChannel' => 'moova',
        'fulfillmentType' => 'dine_in',
        'items' => [
            [
                'externalLineId' => $lineId,
                'itemId' => '7001',
                'qty' => $qty,
            ],
        ],
    ];
}

function recipeMoovaReplayCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            def_pos_store INT NOT NULL DEFAULT 1,
            def_pos_employee INT NOT NULL DEFAULT 2,
            def_pos_client INT NOT NULL DEFAULT 3,
            def_pos_fund INT NOT NULL DEFAULT 4
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            parent_id INT NOT NULL DEFAULT 0,
            code VARCHAR(64) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(120) NULL,
            branch VARCHAR(64) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(191) NULL,
            price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id BIGINT UNSIGNED NULL,
            op2 BIGINT UNSIGNED NULL,
            branch_id INT NULL,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            pro_tybe INT NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_journal TINYINT(1) NOT NULL DEFAULT 0,
            journal_tybe INT NULL,
            info TEXT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            pro_pattren INT NULL,
            pro_serial VARCHAR(64) NULL,
            price_list INT NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            cost_center INT NULL,
            profit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_disc DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_disc_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_plus DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_plus_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_tax DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_tax_per DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fat_net DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            paid_amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            remaining_amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            payment_status VARCHAR(32) NULL,
            invoice_status VARCHAR(32) NULL,
            order_status VARCHAR(32) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            user INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            pro_id BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            discount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            det_value DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            fatid BIGINT UNSIGNED NULL,
            fat_tybe INT NULL,
            det_store INT NOT NULL DEFAULT 0,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            profit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id BIGINT UNSIGNED NULL,
            total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            jdate DATE NULL,
            details TEXT NULL,
            user INT NULL,
            op_id BIGINT UNSIGNED NULL,
            pro_tybe INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id BIGINT UNSIGNED NULL,
            account_id BIGINT UNSIGNED NULL,
            debit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            credit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            tybe INT NULL,
            op_id BIGINT UNSIGNED NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE process (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(191) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    MoovaPosIntegration::ensureSchema($conn);
    (new SyncSchemaManager())->apply($conn);
}

function recipeMoovaReplaySeedData(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (tenant, branch, isdeleted, def_pos_store, def_pos_employee, def_pos_client, def_pos_fund) VALUES (0, 0, 0, 1, 2, 3, 4)");
    $conn->query("
        INSERT INTO acc_head (id, tenant, branch, isdeleted, is_stock, is_fund, is_basic, parent_id, code) VALUES
        (1, 0, 0, 0, 1, 0, 0, 0, '1301'),
        (2, 0, 0, 0, 0, 0, 0, 35, '2101'),
        (3, 0, 0, 0, 0, 0, 0, 0, '122001'),
        (4, 0, 0, 0, 0, 1, 0, 0, '1101')
    ");
    $conn->query("INSERT INTO tables (id, tname, branch, table_case, isdeleted) VALUES (1, '1', '0', 0, 0)");
    MoovaPosIntegration::upsertTableLink(
        $conn,
        ['tenant' => 0, 'branch' => 0],
        'moova-branch-1',
        '1',
        1
    );
    $conn->query("
        INSERT INTO myitems (id, tenant, branch, iname, barcode, price1, cost_price, itmqty, group1, item_type, track_stock, isdeleted) VALUES
        (7001, 0, 0, 'Moova Replay Burger', '7001', '10.000000', '0.000000', '0.000000', 7, 'sellable', 1, 0),
        (7002, 0, 0, 'Moova Replay Patty', '7002', '0.000000', '4.000000', '20.000000', 7, 'ingredient', 1, 0)
    ");
    $conn->query("
        INSERT INTO recipe_headers (
            id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name,
            recipe_type, status, version_number, yield_qty, costing_method, created_by, approved_by, approved_at
        ) VALUES (
            701, '00000000-0000-4000-8000-000000007001', 0, 0, 7001, 'Moova Replay Burger Recipe',
            'make_to_order', 'active', 1, '1.000000', 'item_cost_price', 1, 1, NOW()
        )
    ");
    $conn->query("
        INSERT INTO recipe_lines (
            id, recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield,
            unit_conversion_to_base, wastage_percent, is_required, order_type, channel, sort_order
        ) VALUES (
            702, 701, '00000000-0000-4000-8000-000000007002', 7002, 'ingredient', '1.000000',
            '1.00000000', '0.0000', 1, 'any', 'any', 1
        )
    ");
    (new InventoryBalanceRepository())->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'branch_uuid' => '11111111-1111-4111-8111-111111111111',
        'store_id' => 1,
        'item_id' => 7002,
        'qty_on_hand' => '20.000000',
        'qty_reserved' => '0.000000',
        'qty_available' => '20.000000',
        'moving_average_cost' => '4.000000',
    ]);
}

function recipeMoovaReplayRows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function recipeMoovaReplayCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

function recipeMoovaReplayBalance(mysqli $conn, int $itemId): array
{
    $row = $conn->query("SELECT * FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1")->fetch_assoc();
    if (!is_array($row)) {
        throw new RuntimeException('Missing inventory balance for item ' . $itemId);
    }

    return $row;
}

function recipeMoovaReplayJson(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

function recipeMoovaReplayDecimalEquals($actual, string $expected): bool
{
    return abs((float) $actual - (float) $expected) < 0.0001;
}

function recipeMoovaReplayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
