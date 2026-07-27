<?php
/**
 * Production-level delivery flow integration test (Phases 1–6).
 * Isolated database with full sync schema + delivery fixtures.
 */

putenv('POSMAIN_ENV=test');
putenv('POSMAIN_PRODUCTION_MODE=0');
putenv('POSMAIN_SYNC_OUTBOX_ENABLED=0');
putenv('POSMAIN_RECIPE_MODE=off');
putenv('POSMAIN_DELIVERY_V2=1');

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_delivery_prod_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "delivery-production-integration-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function deliveryProdAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function deliveryProdCreateBaseSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rollname VARCHAR(100) NULL,
            info VARCHAR(255) NULL,
            role_key VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(100) NOT NULL,
            display_name VARCHAR(255) NULL,
            userrole INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE delivery_clients (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL UNIQUE,
            address TEXT NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(80) NULL,
            group1 INT NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_item_units_item_unit (item_id, unit_id)
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
            payment_date DATETIME NULL,
            op2 INT NULL,
            branch_id INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            crtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            mdtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
            branch INT NOT NULL DEFAULT 0
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
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE process (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40) NULL,
            created_by INT NULL,
            created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    (new SyncSchemaManager())->apply($conn);
}

function deliveryProdSeedFixtures(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, display_name, userrole, isdeleted) VALUES (7, 'delivery_test', 'Delivery Test User', 0, 0)");
    $conn->query("INSERT INTO drawer_sessions (uuid, user_id, tenant, branch, fund_account_id, opened_at, business_day, opened_by, opening_cash, status) VALUES ('fd3dd079-9b92-4db0-868d-b445f7862db4', 7, 0, 0, 51, NOW(), CURRENT_DATE, 7, 100.000, 'open')");
    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, def_pos_employee, def_pos_fund, edit_pass, isdeleted)
                  VALUES (1, 501, 3, 4, 51, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, price1, isdeleted) VALUES
            (10, 'Delivery Burger', 'BRG10', 50, 5, 25, 0),
            (11, 'Delivery Fries', 'FRS11', 50, 2, 10, 0)
    ");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, price1, isdeleted) VALUES
            (10, 1, 1.000000, 25, 0),
            (11, 1, 1.000000, 10, 0)
    ");
    $conn->query("
        INSERT INTO delivery_zones (name, fee, is_active, sort_order) VALUES
            ('Maadi', 15.000, 1, 1),
            ('Nasr City', 20.000, 1, 2)
    ");
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    deliveryProdCreateBaseSchema($conn);
    deliveryProdSeedFixtures($conn);

    $prefix = 'prod-' . bin2hex(random_bytes(3));
    $phone = '0100' . random_int(1000000, 9999999);
    $clientService = new PosCustomerService();

    // Phase 1/3: customer upsert reliability
    $upsert1 = $clientService->upsertForDelivery($conn, $phone, 'Production Test User', 'Maadi Street 1');
    deliveryProdAssert((int) ($upsert1['id'] ?? 0) > 0, 'client upsert should succeed');
    $upsert2 = $clientService->upsertForDelivery($conn, $phone, 'Production Test User Updated', 'Maadi Street 2');
    deliveryProdAssert((int) $upsert2['id'] === (int) $upsert1['id'], 'duplicate phone should upsert same client');
    $clientCount = (int) $conn->query('SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0')->fetch_assoc()['c'];
    deliveryProdAssert($clientCount === 1, 'only one pos_customers row after upsert');

    // Phase 1: reject create without customer
    $mutation = new PosOrderMutationService();
    $badRequest = [
        'idempotency_key' => $prefix . ':delivery:bad',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'headtotal' => 25,
        'headnet' => 25,
        'submit' => 'save',
        'itmname' => [10],
        'itmqty' => [1],
        'itmprice' => [25],
        'itmdisc' => [0],
        'u_val' => [1],
    ];
    $rejected = false;
    try {
        $mutation->createDeliveryOrder($conn, $badRequest, ['user_id' => 7]);
    } catch (InvalidArgumentException $e) {
        $rejected = strpos($e->getMessage(), 'عميل الدليفري') !== false
            || strpos($e->getMessage(), 'DELIVERY_CLIENT') !== false;
    }
    deliveryProdAssert($rejected, 'createDeliveryOrder must reject missing customer fields');

    // Phase 1/3: server recomputes net when delivery fee is present but headnet is stale
    $tamperedPhone = '0100' . random_int(1000000, 9999999);
    $clientService->upsertForDelivery($conn, $tamperedPhone, 'Tampered Net User', 'Zone 9');
    $tamperedRequest = [
        'idempotency_key' => $prefix . ':delivery:tampered-net',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'pro_serial' => $prefix . '-TAMPER',
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'headtotal' => 35,
        'headdisc' => 0,
        'headplus' => 0,
        'headnet' => 35,
        'delivery_fee' => 15.0,
        'delivery_zone_name' => 'Maadi',
        'delivery_customer_name' => 'Tampered Net User',
        'delivery_customer_phone' => $tamperedPhone,
        'delivery_customer_address' => 'Zone 9',
        'submit' => 'save',
        'itmname' => [10, 11],
        'itmqty' => [1, 1],
        'itmprice' => [25, 10],
        'itmdisc' => [0, 0],
        'u_val' => [1, 1],
    ];
    $tamperedResult = $mutation->createDeliveryOrder($conn, $tamperedRequest, ['user_id' => 7, 'record_outbox' => false]);
    $tamperedOrderId = (int) ($tamperedResult['data']['order_id'] ?? 0);
    $tamperedOrder = $conn->query("SELECT fat_plus, fat_net FROM ot_head WHERE id = {$tamperedOrderId}")->fetch_assoc();
    deliveryProdAssert(abs((float) $tamperedOrder['fat_plus'] - 15.0) < 0.01, 'tampered request should still persist delivery fee in fat_plus');
    deliveryProdAssert(abs((float) $tamperedOrder['fat_net'] - 50.0) < 0.01, 'tampered request should recompute fat_net with delivery fee');

    // Phase 5: zone id resolves authoritative fee (ignore tampered delivery_fee=0)
    $zoneRow = $conn->query("SELECT id, fee, name FROM delivery_zones WHERE name = 'Maadi' LIMIT 1")->fetch_assoc();
    deliveryProdAssert(is_array($zoneRow), 'Maadi zone fixture required');
    $zonePhone = '0100' . random_int(1000000, 9999999);
    $clientService->upsertForDelivery($conn, $zonePhone, 'Zone Fee User', 'Maadi Block 1');
    $zoneRequest = [
        'idempotency_key' => $prefix . ':delivery:zone-id',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'pro_serial' => $prefix . '-ZONE',
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'headtotal' => 25,
        'headdisc' => 0,
        'headplus' => 0,
        'headnet' => 25,
        'delivery_zone_id' => (int) $zoneRow['id'],
        'delivery_zone_name' => 'Ignored Name',
        'delivery_fee' => 0,
        'delivery_customer_name' => 'Zone Fee User',
        'delivery_customer_phone' => $zonePhone,
        'delivery_customer_address' => 'Maadi Block 1',
        'submit' => 'save',
        'itmname' => [10],
        'itmqty' => [1],
        'itmprice' => [25],
        'itmdisc' => [0],
        'u_val' => [1],
    ];
    $zoneResult = $mutation->createDeliveryOrder($conn, $zoneRequest, ['user_id' => 7, 'record_outbox' => false]);
    $zoneOrderId = (int) ($zoneResult['data']['order_id'] ?? 0);
    $zoneOrder = $conn->query("SELECT fat_plus, fat_net FROM ot_head WHERE id = {$zoneOrderId}")->fetch_assoc();
    $expectedZoneFee = (float) $zoneRow['fee'];
    deliveryProdAssert(abs((float) $zoneOrder['fat_plus'] - $expectedZoneFee) < 0.01, 'zone id should set authoritative delivery fee');
    deliveryProdAssert(abs((float) $zoneOrder['fat_net'] - (25 + $expectedZoneFee)) < 0.01, 'zone fee should be included in fat_net');

    // Phase 3: per-unit line discount should match detail totals (qty=2, price=10, disc=1 => 18)
    $discPhone = '0100' . random_int(1000000, 9999999);
    $clientService->upsertForDelivery($conn, $discPhone, 'Discount Qty User', 'Nasr Block 2');
    $discRequest = [
        'idempotency_key' => $prefix . ':delivery:disc-qty',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'pro_serial' => $prefix . '-DISC',
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'headtotal' => 19,
        'headdisc' => 0,
        'headplus' => 20,
        'headnet' => 39,
        'delivery_customer_name' => 'Discount Qty User',
        'delivery_customer_phone' => $discPhone,
        'delivery_customer_address' => 'Nasr Block 2',
        'delivery_zone_name' => 'Nasr City',
        'delivery_fee' => 20,
        'submit' => 'save',
        'itmname' => [11],
        'itmqty' => [2],
        'itmprice' => [10],
        'itmdisc' => [1],
        'u_val' => [1],
    ];
    $discResult = $mutation->createDeliveryOrder($conn, $discRequest, ['user_id' => 7, 'record_outbox' => false]);
    $discOrderId = (int) ($discResult['data']['order_id'] ?? 0);
    $discLine = $conn->query("SELECT det_value FROM fat_details WHERE fatid = {$discOrderId} AND isdeleted = 0 LIMIT 1")->fetch_assoc();
    $discHead = $conn->query("SELECT fat_total, fat_net FROM ot_head WHERE id = {$discOrderId}")->fetch_assoc();
    deliveryProdAssert(abs((float) $discLine['det_value'] - 18.0) < 0.01, 'detail line should use per-unit discount');
    deliveryProdAssert(abs((float) $discHead['fat_net'] - 38.0) < 0.01, 'header net should match discounted line subtotal plus authoritative zone fee');

    // Phase 3/5: save-only delivery with zone fee
    $deliveryFee = 15.0;
    $itemTotal = 35.0;
    $headNet = $itemTotal + $deliveryFee;
    $saveRequest = [
        'idempotency_key' => $prefix . ':delivery:save',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'pro_serial' => $prefix . '-SAVE',
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'headtotal' => $itemTotal,
        'headdisc' => 0,
        'headplus' => $deliveryFee,
        'headnet' => $headNet,
        'delivery_fee' => $deliveryFee,
        'delivery_zone_name' => 'Maadi',
        'courier_source' => 'external',
        'delivery_customer_name' => 'Production Test User Updated',
        'delivery_customer_phone' => $phone,
        'delivery_customer_address' => 'Maadi Street 2',
        'submit' => 'save',
        'info' => 'delivery production save test',
        'itmname' => [10, 11],
        'itmqty' => [1, 1],
        'itmprice' => [25, 10],
        'itmdisc' => [0, 0],
        'u_val' => [1, 1],
    ];
    $saveResult = $mutation->createDeliveryOrder($conn, $saveRequest, ['user_id' => 7, 'record_outbox' => false]);
    deliveryProdAssert($saveResult['message'] === 'DELIVERY_ORDER_CREATED', 'save delivery order should be created');
    $orderId = (int) $saveResult['data']['order_id'];
    deliveryProdAssert($orderId > 0, 'order id required');

    $order = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
    deliveryProdAssert($order['order_type'] === 'delivery', 'ot_head.order_type must be delivery');
    deliveryProdAssert($order['table_id'] === null || (int) $order['table_id'] === 0, 'delivery order must not have table');
    deliveryProdAssert($order['payment_status'] === 'unpaid', 'save-only delivery should be unpaid');
    deliveryProdAssert(abs((float) $order['fat_plus'] - $deliveryFee) < 0.01, 'delivery fee must be in fat_plus');
    deliveryProdAssert(abs((float) $order['fat_net'] - $headNet) < 0.01, 'fat_net must include delivery fee');

    $fulfillment = $conn->query("SELECT * FROM order_fulfillment WHERE order_id = {$orderId}")->fetch_assoc();
    deliveryProdAssert(is_array($fulfillment), 'order_fulfillment row required');
    deliveryProdAssert($fulfillment['fulfillment_type'] === 'delivery', 'fulfillment_type delivery');
    deliveryProdAssert($fulfillment['order_channel'] === 'cashier', 'order_channel cashier');
    deliveryProdAssert($fulfillment['delivery_status'] === 'pending', 'initial delivery_status pending');
    deliveryProdAssert((int) $fulfillment['pos_customer_id'] === (int) $upsert2['id'], 'pos_customer_id linked');
    deliveryProdAssert($fulfillment['customer_phone'] === $phone, 'customer phone persisted');
    deliveryProdAssert($fulfillment['delivery_zone'] === 'Maadi', 'delivery zone persisted');
    deliveryProdAssert(abs((float) $fulfillment['delivery_fee'] - $deliveryFee) < 0.01, 'delivery fee persisted');

    $lineCount = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'];
    deliveryProdAssert($lineCount === 2, 'two order lines expected');

    // Phase 6: dispatch lifecycle
    $fulfillmentService = new OrderFulfillmentService();
    $transitionOptions = [
        'actor_user_id' => 7,
        'tenant' => 0,
        'branch' => 0,
        'config' => [
            'role' => 'branch',
            'sync' => [
                'outbox_enabled' => false,
                'cloud_to_branch_publish_enabled' => false,
            ],
        ],
    ];
    $accepted = $fulfillmentService->transitionDeliveryStatus($conn, $orderId, 'accepted', $transitionOptions);
    deliveryProdAssert($accepted['delivery_status'] === 'accepted', 'pending -> accepted');
    $preparing = $fulfillmentService->transitionDeliveryStatus($conn, $orderId, 'preparing', $transitionOptions);
    deliveryProdAssert($preparing['delivery_status'] === 'preparing', 'accepted -> preparing');
    $ready = $fulfillmentService->transitionDeliveryStatus($conn, $orderId, 'ready', $transitionOptions);
    deliveryProdAssert($ready['delivery_status'] === 'ready', 'preparing -> ready');
    $pickedUp = $fulfillmentService->transitionDeliveryStatus($conn, $orderId, 'picked_up', $transitionOptions + [
        'driver_name' => 'Driver A',
        'driver_phone' => '01001112233',
    ]);
    deliveryProdAssert($pickedUp['delivery_status'] === 'picked_up', 'ready -> picked_up');
    deliveryProdAssert(($pickedUp['metadata']['driver_name'] ?? '') === 'Driver A', 'driver metadata stored');
    $delivered = $fulfillmentService->transitionDeliveryStatus($conn, $orderId, 'delivered', $transitionOptions);
    deliveryProdAssert($delivered['delivery_status'] === 'delivered', 'picked_up -> delivered');

    $activeList = $fulfillmentService->listActiveDeliveryOrders($conn, ['include_terminal' => false]);
    $stillListed = false;
    foreach ($activeList as $row) {
        if ((int) $row['order_id'] === $orderId) {
            $stillListed = true;
            break;
        }
    }
    deliveryProdAssert($stillListed === false, 'delivered order should not appear in active list');

    $terminalList = $fulfillmentService->listActiveDeliveryOrders($conn, ['include_terminal' => true, 'limit' => 50]);
    $foundTerminal = false;
    foreach ($terminalList as $row) {
        if ((int) $row['order_id'] === $orderId && $row['delivery_status'] === 'delivered') {
            $foundTerminal = true;
            break;
        }
    }
    deliveryProdAssert($foundTerminal, 'delivered order should appear when include_terminal=true');

    // Phase 6b: dispatch cancel voids unpaid delivery order
    $cancelPhone = '0100' . random_int(1000000, 9999999);
    $clientService->upsertForDelivery($conn, $cancelPhone, 'Cancel Delivery User', 'Heliopolis Block 9');
    $cancelRequest = [
        'idempotency_key' => $prefix . ':delivery:cancel',
        'store_id' => 3,
        'acc2_id' => 501,
        'emp_id' => 4,
        'fund_id' => 51,
        'pro_serial' => $prefix . '-CANCEL',
        'pro_date' => date('Y-m-d'),
        'accural_date' => date('Y-m-d'),
        'headtotal' => 25,
        'headdisc' => 0,
        'headplus' => 15,
        'headnet' => 40,
        'delivery_zone_name' => 'Maadi',
        'delivery_fee' => 15,
        'delivery_customer_name' => 'Cancel Delivery User',
        'delivery_customer_phone' => $cancelPhone,
        'delivery_customer_address' => 'Heliopolis Block 9',
        'submit' => 'save',
        'itmname' => [10],
        'itmqty' => [1],
        'itmprice' => [25],
        'itmdisc' => [0],
        'u_val' => [1],
    ];
    $cancelCreate = $mutation->createDeliveryOrder($conn, $cancelRequest, ['user_id' => 7, 'record_outbox' => false]);
    $cancelOrderId = (int) ($cancelCreate['data']['order_id'] ?? 0);
    deliveryProdAssert($cancelOrderId > 0, 'cancel fixture order required');
    $cancelResult = $mutation->cancelDeliveryOrder($conn, [
        'order_id' => $cancelOrderId,
        'user_id' => 7,
        'reason' => 'integration cancel test',
        'force' => true,
    ], ['record_outbox' => false]);
    deliveryProdAssert(($cancelResult['data']['payment_status'] ?? '') === 'voided', 'cancel should return voided payment status');
    $voidedOrder = $conn->query("SELECT isdeleted, payment_status, order_status, invoice_status FROM ot_head WHERE id = {$cancelOrderId}")->fetch_assoc();
    deliveryProdAssert((int) ($voidedOrder['isdeleted'] ?? 0) === 1, 'cancel should mark ot_head deleted');
    deliveryProdAssert($voidedOrder['payment_status'] === 'voided', 'cancel should void payment_status');
    deliveryProdAssert($voidedOrder['order_status'] === 'cancelled', 'cancel should cancel order_status');
    $activeLineCount = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$cancelOrderId} AND isdeleted = 0")->fetch_assoc()['c'];
    deliveryProdAssert($activeLineCount === 0, 'cancel should soft-delete order lines');
    $cancelFulfillment = $conn->query("SELECT delivery_status FROM order_fulfillment WHERE order_id = {$cancelOrderId}")->fetch_assoc();
    deliveryProdAssert(($cancelFulfillment['delivery_status'] ?? '') === 'cancelled', 'cancel should set fulfillment cancelled');

    $pendingCountBefore = $fulfillmentService->countPendingDeliveryOrders($conn);

    // Phase 3: paid delivery order + idempotency replay
    $paidPhone = '0100' . random_int(1000000, 9999999);
    $clientService->upsertForDelivery($conn, $paidPhone, 'Paid Delivery User', 'Nasr City Block 3');
    $paidRequest = $saveRequest;
    $paidRequest['idempotency_key'] = $prefix . ':delivery:paid';
    $paidRequest['pro_serial'] = $prefix . '-PAID';
    $paidRequest['delivery_customer_phone'] = $paidPhone;
    $paidRequest['delivery_customer_name'] = 'Paid Delivery User';
    $paidRequest['delivery_customer_address'] = 'Nasr City Block 3';
    $paidRequest['delivery_zone_name'] = 'Nasr City';
    $paidRequest['delivery_fee'] = 20.0;
    $paidRequest['headplus'] = 20.0;
    $paidRequest['headnet'] = 55.0;
    $paidRequest['submit'] = 'cash';
    $paidRequest['paid'] = 55.0;
    $paidRequest['paid_cash'] = 55.0;
    $paidRequest['payment_fund_id'] = 51;

    $paidResult = $mutation->createDeliveryOrder($conn, $paidRequest, ['user_id' => 7, 'record_outbox' => false]);
    deliveryProdAssert($paidResult['data']['payment_status'] === 'paid', 'paid delivery should be paid');
    $replay = $mutation->createDeliveryOrder($conn, $paidRequest, ['user_id' => 7, 'record_outbox' => false]);
    deliveryProdAssert(($replay['idempotency_replayed'] ?? false) === true, 'idempotency replay should work for delivery');

    $processTypes = $conn->query("SELECT type FROM process ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    $hasDeliveryProcess = false;
    foreach ($processTypes as $row) {
        if ($row['type'] === 'add delivery') {
            $hasDeliveryProcess = true;
        }
    }
    deliveryProdAssert($hasDeliveryProcess, 'process row add delivery expected');

    echo "delivery-production-integration-ok db={$db}\n";
    echo json_encode([
        'phases' => ['1_customer', '3_create_order', '5_zone_fee', '6_dispatch'],
        'save_order_id' => $orderId,
        'paid_order_id' => (int) ($paidResult['data']['order_id'] ?? 0),
        'pending_count_before_second_pending' => $pendingCountBefore,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'delivery-production-integration-FAIL: ' . $e->getMessage()
        . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n"
        . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
