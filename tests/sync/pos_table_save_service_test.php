<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_save_service_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-table-save-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

putenv('POSMAIN_INVENTORY_LEDGER_MODE=shadow');
putenv('POSMAIN_INVENTORY_STRICT_STOCK=0');

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTableSaveCreateSchema($conn);

    $service = new PosOrderMutationService();
    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, def_pos_employee, def_pos_fund, isdeleted) VALUES (1, 501, 3, 4, 51, 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
            (3, '123001', 'Main store', 0, 0, 1, 0, 0),
            (4, '213001', 'Employee 1', 35, 0, 0, 0, 0),
            (35, '213', 'Employees', 0, 1, 0, 0, 0),
            (51, '121001', 'Default fund', 0, 0, 0, 1, 0),
            (501, '122001', 'Default client', 0, 0, 0, 0, 0),
            (91, '3111', 'Sales', 0, 0, 0, 0, 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, price1, item_type, track_stock, isdeleted) VALUES
            (10, 'Table item 10', 15, 'sellable', 1, 0),
            (11, 'Table item 11', 8, 'sellable', 1, 0),
            (12, 'Table item 12', 12, 'sellable', 1, 0),
            (13, 'Table item 13', 20, 'sellable', 1, 0)
    ");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 0, 0), (2, 'T2', 0, 0), (3, 'T3', 1, 0), (4, 'T4', 0, 0)");

    $new = $service->saveTableOrder($conn, [
        'table_id' => 1,
        'order_date' => '2026-05-12',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 15],
            ['id' => 11, 'qty' => 1, 'price' => 8],
        ],
        'total' => 38,
        'discount' => 0,
        'net' => 38,
    ], ['user_id' => 7]);

    $orderId = (int) $new['data']['order_id'];
    posTableSaveAssert($new['success'] === true, 'new save should return success envelope');
    posTableSaveAssert($new['data']['is_update'] === false, 'new save should report create mode');
    posTableSaveAssert($new['data']['payment_status'] === 'unpaid', 'new table order should be unpaid');
    posTableSaveAssert(abs($new['data']['net'] - 38.0) < 0.0001, 'new save should recalculate net from details');
    posTableSaveAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'new save should occupy table');
    posTableSaveAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'] === 2, 'new save should insert detail rows');
    posTableSaveAssert((int) $conn->query("SELECT current_value FROM document_counters WHERE counter_type = 'pro_id' AND counter_key = 'pro_tybe:9'")->fetch_assoc()['current_value'] === 1, 'new save should allocate pro_id through document counter');
    posTableSaveAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'sale_direct'")->fetch_assoc()['c'] === 2, 'new save should shadow-write direct sale movements');
    $item10Balance = $conn->query("SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 10 AND store_id = 3 LIMIT 1")->fetch_assoc();
    posTableSaveAssert(is_array($item10Balance) && (string) $item10Balance['qty_on_hand'] === '-2.000000', 'new save should shadow-track item 10 balance');

    try {
        $service->saveTableOrder($conn, [
            'table_id' => 1,
            'order_date' => '2026-05-12',
            'store_id' => 3,
            'emp_id' => 4,
            'fund_id' => 51,
            'items' => [['id' => 12, 'qty' => 1, 'price' => 5]],
            'total' => 5,
            'discount' => 0,
            'net' => 5,
        ], ['user_id' => 7]);
        throw new RuntimeException('occupied table save should fail');
    } catch (RuntimeException $exception) {
        posTableSaveAssert(strpos($exception->getMessage(), 'هذه الطاولة لديها طلب نشط بالفعل') !== false, 'occupied table rejection should match current endpoint');
    }

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, pro_value, paid_amount,
            remaining_amount, acc2
        ) VALUES (
            200, 20, 2, 9, 0, 'active', 'partial',
            'draft', 30, 0, 30, 30, 10,
            20, 501
        )
    ");
    $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (2000, 200, 0, 30, 0)");

    $updated = $service->saveTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 200,
        'order_date' => '2026-05-12',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 12, 'qty' => 2, 'price' => 12],
        ],
        'total' => 24,
        'discount' => 0,
        'net' => 24,
    ], ['user_id' => 7]);

    posTableSaveAssert($updated['data']['is_update'] === true, 'update should report update mode');
    posTableSaveAssert($updated['data']['payment_status'] === 'partial', 'update should preserve existing partial payment');
    posTableSaveAssert(abs($updated['data']['paid_amount'] - 10.0) < 0.0001, 'update should preserve paid amount up to net');
    posTableSaveAssert(abs($updated['data']['remaining_amount'] - 14.0) < 0.0001, 'update should recompute remaining amount');
    posTableSaveAssert((int) $conn->query("SELECT isdeleted FROM fat_details WHERE id = 2000")->fetch_assoc()['isdeleted'] === 1, 'update should soft-delete replaced detail rows');
    posTableSaveAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 2")->fetch_assoc()['table_case'] === 1, 'partial updated order should keep table occupied');
    posTableSaveAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE order_id = 200 AND movement_type = 'sale_direct'")->fetch_assoc()['c'] === 1, 'update should shadow-add replacement table line');

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, pro_value, paid_amount,
            remaining_amount, acc2
        ) VALUES (
            300, 30, 3, 9, 0, 'active', 'partial',
            'draft', 20, 0, 20, 20, 25,
            0, 501
        )
    ");
    $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (3000, 300, 0, 20, 0)");

    $belowPaidBlocked = false;
    try {
        $service->saveTableOrder($conn, [
            'table_id' => 3,
            'order_id' => 300,
            'order_date' => '2026-05-12',
            'store_id' => 3,
            'emp_id' => 4,
            'fund_id' => 51,
            'items' => [
                ['id' => 13, 'qty' => 1, 'price' => 20],
            ],
            'total' => 20,
            'discount' => 0,
            'net' => 20,
        ], ['user_id' => 7]);
    } catch (Throwable $exception) {
        $belowPaidBlocked = $exception->getMessage() === 'ORDER_TOTAL_BELOW_PAID_AMOUNT_USE_REFUND';
    }
    posTableSaveAssert($belowPaidBlocked, 'table edit must not silently reduce a collected payment');
    $blockedOrder = $conn->query("SELECT paid_amount, fat_net, payment_status FROM ot_head WHERE id = 300")->fetch_assoc();
    posTableSaveAssert(abs((float) $blockedOrder['paid_amount'] - 25.0) < 0.0001, 'blocked edit preserves collected amount');
    posTableSaveAssert(abs((float) $blockedOrder['fat_net'] - 20.0) < 0.0001, 'blocked edit preserves original order net');
    posTableSaveAssert((int) $conn->query("SELECT isdeleted FROM fat_details WHERE id = 3000")->fetch_assoc()['isdeleted'] === 0, 'blocked edit preserves original item rows');

    $conn->query("INSERT INTO pos_customers (id, display_name, notes, isdeleted) VALUES (1, 'CRM Customer', '', 0)");
    $conn->query("INSERT INTO pos_customer_phones (id, customer_id, phone_normalized, phone_display, is_primary, isdeleted) VALUES (1, 1, '201001234567', '01001234567', 1, 0)");
    $conn->query("UPDATE pos_customers SET primary_phone_id = 1 WHERE id = 1");

    $customerSave = $service->saveTableOrder($conn, [
        'table_id' => 4,
        'order_date' => '2026-05-12',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 1, 'price' => 15],
        ],
        'total' => 15,
        'discount' => 0,
        'net' => 15,
        'pos_customer_id' => 1,
    ], ['user_id' => 7]);
    $customerOrderId = (int) ($customerSave['data']['order_id'] ?? 0);
    posTableSaveAssert($customerOrderId > 0, 'table save with CRM customer should create order');
    $fulfillment = $conn->query("SELECT pos_customer_id, customer_name, customer_phone FROM order_fulfillment WHERE order_id = {$customerOrderId} LIMIT 1")->fetch_assoc();
    posTableSaveAssert(is_array($fulfillment), 'CRM table save should write order_fulfillment row');
    posTableSaveAssert((int) ($fulfillment['pos_customer_id'] ?? 0) === 1, 'order_fulfillment should store pos_customer_id');
    posTableSaveAssert((string) ($fulfillment['customer_name'] ?? '') === 'CRM Customer', 'order_fulfillment should snapshot customer name');

    echo "pos-table-save-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posTableSaveCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            parent_id INT NULL,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            group1 INT NOT NULL DEFAULT 0,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_units (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            unit_id INT NOT NULL DEFAULT 1,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE document_counters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            counter_type VARCHAR(50) NOT NULL,
            counter_key VARCHAR(100) NOT NULL,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            pro_tybe INT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            waiter_id INT NULL,
            info TEXT NULL,
            user INT NULL,
            crtime DATETIME NULL,
            completed_at DATETIME NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            userrole INT NULL,
            permission_mode ENUM('role_only','role_with_overrides') NOT NULL DEFAULT 'role_only',
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            edit_sales TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, edit_sales, isdeleted) VALUES (3, 'Cashier', 0, 0)");
    $conn->query("INSERT INTO users (id, uname, userrole, isdeleted) VALUES (7, 'cashier7', 3, 0)");
}

function posTableSaveAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
