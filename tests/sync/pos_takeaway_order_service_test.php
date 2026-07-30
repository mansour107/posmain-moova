<?php

const POS_TAKEAWAY_SERVICE_BRANCH_UUID = '22222222-2222-4222-8222-222222222222';
$db = 'posmain_takeaway_service_' . getmypid();

putenv('POSMAIN_ENV=test');
putenv('POSMAIN_PRODUCTION_MODE=0');
putenv('POSMAIN_DB_NAME=' . $db);
putenv('POSMAIN_SYNC_OUTBOX_ENABLED=1');
putenv('POSMAIN_BRANCH_UUID=' . POS_TAKEAWAY_SERVICE_BRANCH_UUID);
putenv('POSMAIN_BRANCH_NAME=Takeaway Service Fixture');
putenv('POSMAIN_POS_TENANT=0');
putenv('POSMAIN_POS_BRANCH=0');
putenv('POSMAIN_CLOUD_BASE_URL=http://127.0.0.1/cloud-fixture');
putenv('POSMAIN_RECIPE_MODE=accounting_pilot');
putenv('POSMAIN_RECIPE_RESERVATIONS=1');
putenv('POSMAIN_RECIPE_CONSUMPTION=1');
putenv('POSMAIN_RECIPE_ACCOUNTING=1');
putenv('POSMAIN_RECIPE_DEFAULT_COGS_ACCOUNT_ID=16');
putenv('POSMAIN_RECIPE_RAW_INVENTORY_ACCOUNT_ID=20');
putenv('POSMAIN_RECIPE_PREPARED_INVENTORY_ACCOUNT_ID=20');
putenv('POSMAIN_RECIPE_PACKAGING_INVENTORY_ACCOUNT_ID=20');
putenv('POSMAIN_RECIPE_WASTE_EXPENSE_ACCOUNT_ID=16');
putenv('POSMAIN_RECIPE_PRODUCTION_VARIANCE_ACCOUNT_ID=16');
putenv('POSMAIN_RECIPE_AVAILABILITY=0');
putenv('POSMAIN_RECIPE_MOOVA_SYNC=0');
putenv('POSMAIN_RECIPE_STRICT_STOCK=0');
putenv('POSMAIN_INVENTORY_CUTOVER_CERTIFIED=1');
putenv('POSMAIN_RECIPE_ROLLOUT_CERTIFIED=1');
putenv('POSMAIN_RECIPE_PILOT_POS_BRANCH=0');
putenv('POSMAIN_RECIPE_PILOT_ITEM_IDS=10');
// This proof owns the recipe accounting path. Direct-item inventory bridge
// accounting is covered separately and must not inherit the developer .env.
putenv('POSMAIN_INVENTORY_LEDGER_MODE=off');

// app_config.php resolves environment-backed branch identity when it is first
// included. Seed the isolated runtime profile before loading POS services so
// recipe movements and immutable sync snapshots share one branch UUID.
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-takeaway-order-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayServiceCreateSchema($conn);
    posTakeawayServiceSeedFixtures($conn);
    posTakeawayServiceSeedRecipe($conn);

    $service = new PosOrderMutationService();
    $request = [
        'store_id' => 3,
        'idempotency_key' => 'phpunit:takeaway-service:create:1',
        'pro_serial' => 'TAKEAWAY-SERVICE-1',
        'pro_date' => '2026-05-12',
        'accural_date' => '2026-05-12',
        'acc2_id' => 501,
        'emp_id' => 4,
        'headtotal' => 28,
        'headdisc' => 0,
        'headplus' => 0,
        'headnet' => 28,
        'fund_id' => 51,
        'payment_fund_id' => 51,
        'paid' => 28,
        'paid_cash' => 28,
        'paid_bank' => 0,
        'info' => 'paid takeaway service fixture',
        'itmname' => [10, 11],
        'itmqty' => [2, 1],
        'itmprice' => [10, 8],
        'itmdisc' => [0, 0],
        'u_val' => [1, 1],
    ];
    $context = ['user_id' => 7];

    $result = $service->createTakeawayOrder($conn, $request, $context);

    posTakeawayServiceAssert($result['success'] === true, 'service should return success envelope');
    posTakeawayServiceAssert($result['code'] === 'OK', 'service should return OK code');
    posTakeawayServiceAssert($result['message'] === 'TAKEAWAY_ORDER_CREATED', 'service message expected');
    posTakeawayServiceAssert((int) $result['data']['pro_id'] === 1, 'service should allocate POS pro_id');
    posTakeawayServiceAssert($result['data']['payment_status'] === 'paid', 'service should mark paid');
    posTakeawayServiceAssert($result['data']['order_status'] === 'completed', 'service should complete paid takeaway order');

    $replay = $service->createTakeawayOrder($conn, $request, $context);
    posTakeawayServiceAssert(($replay['idempotency_replayed'] ?? false) === true, 'same key/hash should replay stored response');
    posTakeawayServiceAssert((int) $replay['data']['order_id'] === (int) $result['data']['order_id'], 'replay should return same order id');
    posTakeawayServiceAssert((int) $conn->query("SELECT COUNT(*) AS c FROM ot_head WHERE pro_tybe = 9 AND op2 IS NULL")->fetch_assoc()['c'] === 1, 'replay should not create a second POS order');
    posTakeawayServiceAssert((int) $conn->query("SELECT COUNT(*) AS c FROM process WHERE type = 'add cash'")->fetch_assoc()['c'] === 1, 'replay should not create a second process row');
    posTakeawayServiceAssertRecipeConsumedOnce($conn, (int) $result['data']['order_id'], '8.000000');

    $conflictingRequest = $request;
    $conflictingRequest['headnet'] = 29;
    try {
        $service->createTakeawayOrder($conn, $conflictingRequest, $context);
        posTakeawayServiceAssert(false, 'same key/different payload should conflict');
    } catch (RuntimeException $exception) {
        posTakeawayServiceAssert($exception->getMessage() === 'IDEMPOTENCY_CONFLICT', 'conflict should return IDEMPOTENCY_CONFLICT');
    }

    posTakeawayServiceAssertCommittedSale($conn, (int) $result['data']['order_id']);

    $mixedRequest = $request;
    $mixedRequest['idempotency_key'] = 'phpunit:takeaway-service:create:mixed:1';
    $mixedRequest['pro_serial'] = 'TAKEAWAY-SERVICE-MIXED-1';
    $mixedRequest['paid'] = 28;
    $mixedRequest['paid_cash'] = 10;
    $mixedRequest['paid_bank'] = 18;
    $mixedRequest['payment_bank_id'] = 61;
    $mixedRequest['info'] = 'mixed takeaway service fixture';
    $mixed = $service->createTakeawayOrder($conn, $mixedRequest, $context);
    posTakeawayServiceAssert($mixed['success'] === true, 'mixed service should return success envelope');
    posTakeawayServiceAssert((int) $mixed['data']['pro_id'] === 2, 'mixed service should allocate next POS pro_id');
    posTakeawayServiceAssert(count($mixed['data']['receipt_ids']) === 2, 'mixed service should create cash and bank receipts');
    posTakeawayServiceAssertCommittedMixedSale($conn, (int) $mixed['data']['order_id']);
    posTakeawayServiceAssertRecipeConsumedOnce($conn, (int) $mixed['data']['order_id'], '6.000000');
    echo "pos-takeaway-order-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posTakeawayServiceCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(80) NULL,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            last_price DECIMAL(15,4) NULL,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            crtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            mdtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            pro_tybe INT NULL,
            is_stock INT NULL,
            is_journal INT NULL,
            journal_tybe INT NULL,
            info TEXT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            pro_pattren INT NULL,
            pro_serial VARCHAR(80) NULL,
            price_list INT NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_center INT NULL,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_plus_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_tax DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_tax_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            user INT NULL,
            jal_name VARCHAR(255) NULL,
            jal_notes TEXT NULL,
            jal_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            waiter_id INT NULL,
            completed_at DATETIME NULL,
            payment_method VARCHAR(40) NULL,
            payment_date DATETIME NULL,
            op2 INT NULL,
            branch_id INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            closed TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            crtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            mdtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ot_head_op2 (op2),
            KEY idx_ot_head_pro_tybe (pro_tybe)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fatid INT NOT NULL,
            fat_tybe INT NULL,
            det_store INT NULL,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            KEY idx_fat_details_fatid (fatid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0,
            jdate DATE NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op_id INT NULL,
            op2 INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            KEY idx_journal_heads_op_id (op_id),
            KEY idx_journal_heads_op2 (op2)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(15,4) NOT NULL DEFAULT 0,
            credit DECIMAL(15,4) NOT NULL DEFAULT 0,
            tybe INT NOT NULL DEFAULT 0,
            op_id INT NULL,
            op2 INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            KEY idx_journal_entries_journal_id (journal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE process (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40) NULL,
            reference_no VARCHAR(80) NULL,
            created_by INT NULL,
            created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    (new SyncSchemaManager())->apply($conn);
}

function posTakeawayServiceSeedFixtures(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, edit_pass, isdeleted) VALUES (1, 501, 3, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES
            (3, '130003', 'Operational Store', 1, 0),
            (16, '42', 'Cost of Goods Sold', 0, 0),
            (20, '123', 'Inventory Asset', 0, 0),
            (91, '400001', 'Sales', 0, 0),
            (501, '122001', 'Walk-in Customer', 0, 0),
            (51, '101001', 'Cash Drawer', 0, 0),
            (61, '102001', 'Bank Account', 0, 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, price1, isdeleted) VALUES
            (10, 'Coffee', 'COF10', 20, 4, 10, 0),
            (11, 'Cake', 'CAK11', 15, 2, 8, 0),
            (12, 'Coffee Beans', 'BEAN12', 10, 3, 0, 0)
    ");
    $drawer = (new DrawerSessionService())->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 0,
        'branch' => 0,
        'opening_cash' => '100.00',
    ]);
    $_SESSION['pos_drawer_session_id'] = (int) ($drawer['id'] ?? 0);
}

function posTakeawayServiceSeedRecipe(mysqli $conn): void
{
    (new InventoryBalanceRepository())->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 3,
        'item_id' => 11,
        'qty_on_hand' => '15.000000',
        'qty_reserved' => '0.000000',
        'qty_available' => '15.000000',
        'moving_average_cost' => '2.000000',
    ]);
    (new InventoryBalanceRepository())->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 3,
        'item_id' => 12,
        'qty_on_hand' => '10.000000',
        'qty_reserved' => '0.000000',
        'qty_available' => '10.000000',
        'moving_average_cost' => '3.000000',
    ]);

    $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'shadow',
        ],
    ]));
    $actor = new RecipeActorContext(7, 0, 0, null, ['recipe.manage', 'recipe.approve']);
    $recipe = $definition->createDraft($conn, [
        'sellable_item_id' => 10,
        'recipe_name' => 'Takeaway coffee recipe',
    ], $actor);
    $definition->addLine($conn, (int) $recipe['id'], [
        'ingredient_item_id' => 12,
        'qty_per_yield' => '1.000000',
    ], $actor);
    $definition->activate($conn, (int) $recipe['id'], $actor);
}

function posTakeawayServiceAssertCommittedSale(mysqli $conn, int $orderId): void
{
    $order = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId} LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($order), 'main POS order should exist');
    posTakeawayServiceAssert((int) $order['pro_id'] === 1, 'POS pro_id expected');
    posTakeawayServiceAssert($order['order_type'] === 'takeaway', 'order_type expected');
    posTakeawayServiceAssert($order['payment_status'] === 'paid', 'payment_status expected');
    posTakeawayServiceAssert($order['invoice_status'] === 'completed', 'invoice_status expected');
    posTakeawayServiceAssert($order['order_status'] === 'completed', 'order_status expected');
    posTakeawayServiceAssertFloat((float) $order['fat_net'], 28.0, 'fat_net expected');
    posTakeawayServiceAssertFloat((float) $order['paid_amount'], 28.0, 'paid amount expected');
    posTakeawayServiceAssertFloat((float) $order['remaining_amount'], 0.0, 'remaining amount expected');
    posTakeawayServiceAssertFloat((float) $order['profit'], 18.0, 'profit expected');
    posTakeawayServiceAssertUuid((string) $order['uuid'], 'takeaway order uuid expected');

    $details = $conn->query("SELECT * FROM fat_details WHERE fatid = {$orderId} ORDER BY item_id ASC")->fetch_all(MYSQLI_ASSOC);
    posTakeawayServiceAssert(count($details) === 2, 'two details expected');
    posTakeawayServiceAssertLine($details[0], 10, 2.0, 10.0, 4.0, 20.0, 12.0);
    posTakeawayServiceAssertLine($details[1], 11, 1.0, 8.0, 2.0, 8.0, 6.0);

    $invoiceJournal = $conn->query("SELECT * FROM journal_heads WHERE op_id = {$orderId} ORDER BY id ASC LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($invoiceJournal), 'sales journal expected');
    posTakeawayServiceAssert((int) $invoiceJournal['journal_id'] === 1, 'sales journal counter expected');
    posTakeawayServiceAssertFloat((float) $invoiceJournal['total'], 28.0, 'sales journal total expected');

    $invoiceEntries = $conn->query("SELECT * FROM journal_entries WHERE journal_id = " . (int) $invoiceJournal['id'] . " ORDER BY tybe ASC")->fetch_all(MYSQLI_ASSOC);
    posTakeawayServiceAssert(count($invoiceEntries) === 2, 'sales journal entries expected');
    posTakeawayServiceAssert((int) $invoiceEntries[0]['account_id'] === 501, 'customer debit expected');
    posTakeawayServiceAssertFloat((float) $invoiceEntries[0]['debit'], 28.0, 'customer debit amount expected');
    posTakeawayServiceAssert((int) $invoiceEntries[1]['account_id'] === 91, 'sales credit expected');
    posTakeawayServiceAssertFloat((float) $invoiceEntries[1]['credit'], 28.0, 'sales credit amount expected');

    $receipt = $conn->query("SELECT * FROM ot_head WHERE pro_tybe = 1 AND op2 = {$orderId} ORDER BY id ASC LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($receipt), 'cash receipt expected');
    posTakeawayServiceAssert((int) $receipt['pro_id'] === 1, 'receipt pro_id expected');
    posTakeawayServiceAssert((int) $receipt['acc1'] === 51, 'receipt fund account expected');
    posTakeawayServiceAssert((int) $receipt['acc2'] === 501, 'receipt customer account expected');
    posTakeawayServiceAssertFloat((float) $receipt['pro_value'], 28.0, 'receipt amount expected');
    posTakeawayServiceAssertUuid((string) $receipt['uuid'], 'cash receipt uuid expected');

    $receiptJournal = $conn->query("SELECT * FROM journal_heads WHERE op_id = " . (int) $receipt['id'] . " AND op2 = {$orderId} LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($receiptJournal), 'cash receipt journal expected');
    posTakeawayServiceAssert((int) $receiptJournal['journal_id'] === 2, 'cash receipt journal counter expected');
    posTakeawayServiceAssertFloat((float) $receiptJournal['total'], 28.0, 'cash receipt journal total expected');

    posTakeawayServiceAssertCounter($conn, 'pro_id', 'pro_tybe:9', 1);
    posTakeawayServiceAssertCounter($conn, 'pro_id', 'pro_tybe:1', 1);
    posTakeawayServiceAssertCounter($conn, 'journal_id', 'journal:default', 3);
    posTakeawayServiceAssert($conn->query("SELECT type FROM process LIMIT 1")->fetch_assoc()['type'] === 'add cash', 'process row expected');

    $outbox = $conn->query("
        SELECT *
        FROM sync_outbox
        WHERE aggregate_type = 'order'
          AND entity_type = 'order'
          AND event_type = 'order.saved'
          AND aggregate_local_id = {$orderId}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayServiceAssert(is_array($outbox), 'sync outbox event expected');
    posTakeawayServiceAssert(
        $outbox['branch_uuid'] === POS_TAKEAWAY_SERVICE_BRANCH_UUID,
        'outbox branch expected actual=' . (string) $outbox['branch_uuid']
    );
    posTakeawayServiceAssert($outbox['event_type'] === 'order.saved', 'outbox event type expected');
    posTakeawayServiceAssert($outbox['source_system'] === 'pos_cashier', 'outbox source expected');
    posTakeawayServiceAssert($outbox['payload_hash'] === hash('sha256', $outbox['payload_json']), 'outbox payload hash expected');
    $payload = json_decode($outbox['payload_json'], true);
    posTakeawayServiceAssert((int) $payload['local_order_id'] === $orderId, 'payload order id expected');
    posTakeawayServiceAssert($payload['order']['payment_status'] === 'paid', 'payload payment status expected');
    posTakeawayServiceAssert(count($payload['lines']) === 2, 'payload lines expected');
    posTakeawayServiceAssert(count($payload['payments']) === 2, 'payload should include receipt compatibility plus authoritative tender payment');
    posTakeawayServiceAssert(
        array_column($payload['payments'], 'source') === ['ot_head', 'order_payments'],
        'payload payment sources should preserve receipt compatibility and order_payments tender authority'
    );
    posTakeawayServiceAssert(count($payload['receipts']) === 1, 'payload receipt expected');

    $event = $conn->query("SELECT * FROM order_events WHERE order_id = {$orderId} ORDER BY id ASC LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($event), 'takeaway order event expected');
    posTakeawayServiceAssert($event['event_type'] === 'order.saved', 'takeaway order event type expected');
    posTakeawayServiceAssert($event['event_source'] === 'pos_cashier', 'takeaway order event source expected');
    posTakeawayServiceAssert((int) $event['actor_user_id'] === 7, 'takeaway order event actor expected');
    $metadata = json_decode($event['metadata_json'], true);
    posTakeawayServiceAssert($metadata['order_type'] === 'takeaway', 'takeaway order event metadata type expected');
    posTakeawayServiceAssert($metadata['payment_status'] === 'paid', 'takeaway order event metadata payment status expected');
}

function posTakeawayServiceAssertCommittedMixedSale(mysqli $conn, int $orderId): void
{
    $order = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId} LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($order), 'mixed POS order should exist');
    posTakeawayServiceAssert((int) $order['pro_id'] === 2, 'mixed POS pro_id expected');
    posTakeawayServiceAssertFloat((float) $order['paid_amount'], 28.0, 'mixed order paid amount expected');
    posTakeawayServiceAssertFloat((float) $order['remaining_amount'], 0.0, 'mixed order remaining amount expected');
    posTakeawayServiceAssert($order['payment_status'] === 'paid', 'mixed order payment status expected');

    $receipts = $conn->query("SELECT * FROM ot_head WHERE pro_tybe = 1 AND op2 = {$orderId} ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    posTakeawayServiceAssert(count($receipts) === 2, 'mixed order should have two receipt operations');
    posTakeawayServiceAssert((int) $receipts[0]['acc1'] === 51, 'mixed cash receipt fund account expected');
    posTakeawayServiceAssertFloat((float) $receipts[0]['pro_value'], 10.0, 'mixed cash receipt amount expected');
    posTakeawayServiceAssert(
        (int) $receipts[1]['acc1'] === 61,
        'mixed bank receipt account expected actual=' . json_encode(array_column($receipts, 'acc1'))
    );
    posTakeawayServiceAssertFloat((float) $receipts[1]['pro_value'], 18.0, 'mixed bank receipt amount expected');

    posTakeawayServiceAssertCounter($conn, 'pro_id', 'pro_tybe:9', 2);
    posTakeawayServiceAssertCounter($conn, 'pro_id', 'pro_tybe:1', 3);
    posTakeawayServiceAssertCounter($conn, 'journal_id', 'journal:default', 7);
    posTakeawayServiceAssert((int) $conn->query("SELECT COUNT(*) AS c FROM process WHERE type = 'add cash'")->fetch_assoc()['c'] === 2, 'mixed route should add one process row per new order');

    $outbox = $conn->query("
        SELECT *
        FROM sync_outbox
        WHERE aggregate_type = 'order'
          AND entity_type = 'order'
          AND event_type = 'order.saved'
          AND aggregate_local_id = {$orderId}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayServiceAssert(is_array($outbox), 'mixed sync outbox event expected');
    $payload = json_decode($outbox['payload_json'], true);
    posTakeawayServiceAssert(count($payload['payments']) === 4, 'mixed payload should include receipt compatibility plus two authoritative tender payments');
    posTakeawayServiceAssert(count($payload['receipts']) === 2, 'mixed payload should include two receipts');
    $authoritativePayments = array_values(array_filter(
        $payload['payments'],
        static fn (array $payment): bool => ($payment['source'] ?? '') === 'order_payments'
    ));
    posTakeawayServiceAssert(count($authoritativePayments) === 2, 'mixed payload should carry exactly two authoritative tender rows');
    posTakeawayServiceAssert($authoritativePayments[0]['payment_method'] === 'cash', 'mixed payload first authoritative payment method expected');
    posTakeawayServiceAssert($authoritativePayments[1]['payment_method'] === 'bank', 'mixed payload second authoritative payment method expected');
}

function posTakeawayServiceAssertRecipeConsumedOnce(mysqli $conn, int $orderId, string $expectedIngredientOnHand): void
{
    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTakeawayServiceAssert(count($usages) === 1, 'paid takeaway order should create one recipe usage row');
    posTakeawayServiceAssert((string) $usages[0]['status'] === 'consumed', 'paid takeaway recipe usage should be consumed');
    posTakeawayServiceAssert((int) $usages[0]['sellable_item_id'] === 10, 'recipe usage should belong to the pilot sellable item');
    posTakeawayServiceAssert((int) $usages[0]['fat_detail_id'] > 0, 'recipe usage should reference the cashier fat_details line');

    $movements = $conn->query("SELECT * FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'recipe_consumption' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTakeawayServiceAssert(count($movements) === 1, 'paid takeaway order should write one recipe consumption movement');
    posTakeawayServiceAssert((int) $movements[0]['item_id'] === 12, 'recipe movement should consume the ingredient item');
    posTakeawayServiceAssert((string) $movements[0]['qty_out'] === '2.000000', 'recipe movement should consume ingredient quantity once per paid line quantity');
    posTakeawayServiceAssert((string) $movements[0]['source_type'] === 'recipe_order_line_usage', 'recipe movement should be sourced from recipe usage');
    posTakeawayServiceAssert((int) $movements[0]['recipe_order_line_usage_id'] === (int) $usages[0]['id'], 'recipe movement should link to usage row');
    $recipeJournalId = (int) ($movements[0]['accounting_journal_id'] ?? 0);
    posTakeawayServiceAssert($recipeJournalId > 0, 'certified paid takeaway recipe consumption should post COGS accounting');
    $recipeJournal = $conn->query("SELECT total FROM journal_heads WHERE journal_id = {$recipeJournalId} LIMIT 1")->fetch_assoc();
    posTakeawayServiceAssert(is_array($recipeJournal), 'recipe COGS journal should exist');
    posTakeawayServiceAssert((string) $recipeJournal['total'] === '6.0000', 'recipe COGS journal should equal exact consumed ingredient cost');
    $recipeJournalTotals = $conn->query("
        SELECT COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit
        FROM journal_entries
        WHERE journal_id = {$recipeJournalId}
    ")->fetch_assoc();
    posTakeawayServiceAssert(
        (string) ($recipeJournalTotals['debit'] ?? '') === (string) ($recipeJournalTotals['credit'] ?? ''),
        'recipe COGS journal must be balanced'
    );

    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    $storeZeroBalance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 0, 12);
    posTakeawayServiceAssert(is_array($balance), 'ingredient balance should exist after recipe consumption');
    posTakeawayServiceAssert(
        (string) $balance['qty_on_hand'] === $expectedIngredientOnHand,
        'ingredient stock should be deducted exactly once for this paid takeaway order actual=' . (string) $balance['qty_on_hand']
            . ' movement_store=' . (string) ($movements[0]['store_id'] ?? '')
            . ' store0=' . (string) ($storeZeroBalance['qty_on_hand'] ?? 'missing')
    );
}

function posTakeawayServiceAssertLine(array $line, int $itemId, float $qtyOut, float $price, float $cost, float $value, float $profit): void
{
    posTakeawayServiceAssert((int) $line['item_id'] === $itemId, 'line item expected');
    posTakeawayServiceAssertFloat((float) $line['qty_out'], $qtyOut, 'line qty_out expected');
    posTakeawayServiceAssertFloat((float) $line['price'], $price, 'line price expected');
    posTakeawayServiceAssertFloat((float) $line['cost_price'], $cost, 'line cost expected');
    posTakeawayServiceAssertFloat((float) $line['det_value'], $value, 'line value expected');
    posTakeawayServiceAssertFloat((float) $line['profit'], $profit, 'line profit expected');
    posTakeawayServiceAssertUuid((string) $line['uuid'], 'line uuid expected');
}

function posTakeawayServiceAssertUuid(string $uuid, string $message): void
{
    posTakeawayServiceAssert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid), $message);
}

function posTakeawayServiceAssertCounter(mysqli $conn, string $type, string $key, int $expected): void
{
    $escapedType = $conn->real_escape_string($type);
    $escapedKey = $conn->real_escape_string($key);
    $row = $conn->query("
        SELECT current_value
        FROM document_counters
        WHERE pos_tenant = 0
          AND pos_branch = 0
          AND counter_type = '{$escapedType}'
          AND counter_key = '{$escapedKey}'
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayServiceAssert(is_array($row), "counter {$type}:{$key} expected");
    posTakeawayServiceAssert(
        (int) $row['current_value'] === $expected,
        "counter {$type}:{$key} value expected actual=" . (int) $row['current_value'] . " expected={$expected}"
    );
}

function posTakeawayServiceAssertFloat(float $actual, float $expected, string $message): void
{
    posTakeawayServiceAssert(abs($actual - $expected) < 0.0001, $message . " actual={$actual} expected={$expected}");
}

function posTakeawayServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
