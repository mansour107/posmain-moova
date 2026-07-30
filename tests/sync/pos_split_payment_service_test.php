<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_OFF);
putenv('POSMAIN_RECIPE_MODE=off');
putenv('POSMAIN_RECIPE_RESERVATIONS=0');
putenv('POSMAIN_RECIPE_CONSUMPTION=0');
putenv('POSMAIN_RECIPE_ACCOUNTING=0');
putenv('POSMAIN_RECIPE_AVAILABILITY=0');
$_ENV['POSMAIN_RECIPE_MODE'] = 'off';
$_SERVER['POSMAIN_RECIPE_MODE'] = 'off';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_split_payment_service_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-split-payment-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posSplitPaymentCreateSchema($conn);
    posSplitPaymentSeedCashDrawer($conn);

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            100, 10, 0, 1, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 50, 50, 0,
            50, 0, 50, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (1000, 9, 3, 100, 10, 1, 0, 2, 10, 5, 0, 0, 0, 20, 10, 100, 9, 0, 0, 0),
            (1001, 9, 3, 100, 11, 1, 0, 3, 10, 5, 0, 0, 0, 30, 15, 100, 9, 0, 0, 0)
    ");

    $service = new PosOrderMutationService();
    $split = $service->splitTablePayment($conn, [
        'order_id' => 100,
        'table_id' => 1,
        'items' => [
            ['detail_id' => 1000],
            ['detail_id' => 1001, 'qty' => 1],
        ],
        'paid_amount' => '32.00',
        'payment_method' => 'mixed',
        'tenders' => [
            ['payment_method' => 'bank', 'amount' => '12.00', 'reference_no' => 'BANK-SPLIT-100'],
            ['payment_method' => 'cash', 'amount' => '20.00'],
        ],
        'mutation_version' => 1,
        'idempotency_key' => 'split-payment-mixed-100',
    ], ['user_id' => 7, 'skip_idempotency' => true]);

    $newOrderId = (int) $split['data']['new_invoice_id'];
    posSplitPaymentAssert($split['success'] === true, 'split should return success envelope');
    posSplitPaymentAssert($newOrderId > 100, 'split should create child order');
    posSplitPaymentAssert($split['data']['active_order_id'] === 100, 'original should remain active after partial split');
    posSplitPaymentAssert(abs($split['data']['remaining_total'] - 20.0) < 0.0001, 'remaining total should reflect leftover line value');
    posSplitPaymentAssert(strlen((string) $split['data']['split_group_id']) === 32, 'split group id should be a 32-character hex token');

    $child = $conn->query("SELECT * FROM ot_head WHERE id = {$newOrderId}")->fetch_assoc();
    posSplitPaymentAssert((int) $child['pro_id'] === 11, 'child order should allocate next pro_id through document counter');
    posSplitPaymentAssert($child['payment_status'] === 'paid', 'child split order should be paid');
    posSplitPaymentAssert($child['order_status'] === 'completed', 'child split order should be completed');
    posSplitPaymentAssert((int) $child['parent_order_id'] === 100, 'child should link to original order');
    posSplitPaymentAssert(abs((float) $child['fat_net'] - 30.0) < 0.0001, 'child net should equal selected value');

    $moved = $conn->query("SELECT fatid, qty_out, det_value FROM fat_details WHERE id = 1000")->fetch_assoc();
    posSplitPaymentAssert((int) $moved['fatid'] === $newOrderId, 'full selected line should move to child order');

    $originalPartial = $conn->query("SELECT fatid, qty_out, det_value, profit FROM fat_details WHERE id = 1001")->fetch_assoc();
    posSplitPaymentAssert((int) $originalPartial['fatid'] === 100, 'partial selected line should remain on original');
    posSplitPaymentAssert(abs((float) $originalPartial['qty_out'] - 2.0) < 0.0001, 'partial split should reduce original quantity');
    posSplitPaymentAssert(abs((float) $originalPartial['det_value'] - 20.0) < 0.0001, 'partial split should reduce original value');
    posSplitPaymentAssert(abs((float) $originalPartial['profit'] - 10.0) < 0.0001, 'partial split should reduce original profit');

    $copied = $conn->query("SELECT qty_out, det_value, profit FROM fat_details WHERE fatid = {$newOrderId} AND item_id = 11")->fetch_assoc();
    posSplitPaymentAssert(abs((float) $copied['qty_out'] - 1.0) < 0.0001, 'partial split should copy requested quantity to child');
    posSplitPaymentAssert(abs((float) $copied['det_value'] - 10.0) < 0.0001, 'partial split should copy proportional value to child');

    $original = $conn->query("SELECT payment_status, order_status, fat_net, remaining_amount FROM ot_head WHERE id = 100")->fetch_assoc();
    posSplitPaymentAssert($original['payment_status'] === 'unpaid', 'original remains unpaid when no prior payment exists');
    posSplitPaymentAssert($original['order_status'] === 'active', 'original remains active with remaining lines');
    posSplitPaymentAssert(abs((float) $original['fat_net'] - 20.0) < 0.0001, 'original total should be recalculated from remaining lines');
    posSplitPaymentAssert(abs((float) $original['remaining_amount'] - 20.0) < 0.0001, 'original remaining should match recalculated net');
    posSplitPaymentAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'table should stay occupied while original remains active');

    $payments = $conn->query("SELECT * FROM order_payments WHERE order_id = {$newOrderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posSplitPaymentAssert(count($payments) === 2, 'mixed split should persist one payment row per tender');
    posSplitPaymentAssert($payments[0]['payment_method'] === 'bank' && $payments[0]['applied_amount'] === '12.00', 'bank split tender must remain distinct');
    posSplitPaymentAssert($payments[1]['payment_method'] === 'cash' && $payments[1]['tendered_amount'] === '20.00', 'cash tendered must be preserved');
    posSplitPaymentAssert($payments[1]['applied_amount'] === '18.00' && $payments[1]['change_due'] === '2.00', 'cash split tender must persist applied amount and change');
    posSplitPaymentAssert(($split['data']['change_due'] ?? null) === '2.00', 'split response must expose exact aggregate change');

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (2, 'T2', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            200, 12, 0, 2, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 10, 10, 0,
            10, 0, 10, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (2000, 9, 3, 200, 12, 1, 0, 3, 3.3333, 1, 0, 0, 0, 10, 7, 200, 9, 0, 0, 0)
    ");

    $roundingSplit = $service->splitTablePayment($conn, [
        'order_id' => 200,
        'table_id' => 2,
        'items' => [
            ['detail_id' => 2000, 'qty' => 1],
        ],
        'paid_amount' => '3.33',
        'payment_method' => 'cash',
        'mutation_version' => 1,
        'idempotency_key' => 'split-payment-rounding-200',
    ], ['user_id' => 7, 'skip_idempotency' => true]);
    posSplitPaymentAssert($roundingSplit['success'] === true, 'split should accept cashier cent-rounded partial item amounts');

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (5, 'T5', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            500, 15, 0, 5, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 50, 50, 5,
            45, 0, 45, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (5000, 9, 3, 500, 20, 1, 0, 2, 10, 5, 0, 0, 0, 20, 10, 500, 9, 0, 0, 0),
            (5001, 9, 3, 500, 21, 1, 0, 3, 10, 5, 0, 0, 0, 30, 15, 500, 9, 0, 0, 0)
    ");

    $discountedSplit = $service->splitTablePayment($conn, [
        'order_id' => 500,
        'table_id' => 5,
        'items' => [
            ['detail_id' => 5000],
            ['detail_id' => 5001, 'qty' => '1.000000'],
        ],
        'paid_amount' => '30.00',
        'payment_method' => 'cash',
        'mutation_version' => 1,
        'idempotency_key' => 'split-payment-discount-500',
    ], ['user_id' => 7, 'skip_idempotency' => true]);

    $discountChildId = (int) $discountedSplit['data']['new_invoice_id'];
    $discountChild = $conn->query("SELECT fat_total, fat_disc, fat_net, paid_amount FROM ot_head WHERE id = {$discountChildId}")->fetch_assoc();
    $discountParent = $conn->query("SELECT fat_total, fat_disc, fat_net, remaining_amount FROM ot_head WHERE id = 500")->fetch_assoc();
    posSplitPaymentAssert($discountedSplit['data']['gross_amount'] === '30.00', 'discounted split response should expose exact selected gross');
    posSplitPaymentAssert($discountedSplit['data']['discount_amount'] === '3.00', 'discounted split should allocate the exact proportional header discount');
    posSplitPaymentAssert($discountedSplit['data']['paid_amount'] === '27.00', 'discounted split should apply payment to child net, not gross');
    posSplitPaymentAssert($discountedSplit['data']['change_due'] === '3.00', 'cash tender over discounted child net should persist exact change');
    posSplitPaymentAssert(
        Money::from($discountChild['fat_total'])->toString() === '30.00'
            && Money::from($discountChild['fat_disc'])->toString() === '3.00',
        'child invoice should retain gross and allocated discount facts'
    );
    posSplitPaymentAssert(
        Money::from($discountChild['fat_net'])->toString() === '27.00'
            && Money::from($discountChild['paid_amount'])->toString() === '27.00',
        'child invoice should be paid at exact discounted net'
    );
    posSplitPaymentAssert(
        Money::from($discountParent['fat_total'])->toString() === '20.00'
            && Money::from($discountParent['fat_disc'])->toString() === '2.00',
        'parent should retain only the remaining gross and discount'
    );
    posSplitPaymentAssert(
        Money::from($discountParent['fat_net'])->toString() === '18.00'
            && Money::from($discountParent['remaining_amount'])->toString() === '18.00',
        'parent remaining balance should reconcile after discount allocation'
    );
    posSplitPaymentAssert(
        Money::from($discountChild['fat_net'])->add(Money::from($discountParent['fat_net']))->toString() === '45.00',
        'child and parent net must reconcile exactly to the pre-split order net'
    );

    $shadowBridge = new InventoryInvoiceBridge(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'shadow',
            'strict_stock' => '1',
        ],
        'branch' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ],
    ]));
    $shadowSplitService = new PosOrderMutationService(null, null, null, null, null, null, null, null, null, null, null, null, $shadowBridge);
    $conn->query("INSERT INTO myitems (id, iname, cost_price, group1, item_type, track_stock) VALUES (4010, 'Split bridge A', 5.000000, 7, 'sellable', 1), (4011, 'Split bridge B', 5.000000, 7, 'sellable', 1)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (4, 'T4', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            400, 14, 0, 4, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 50, 50, 0,
            50, 0, 50, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (4000, 9, 3, 400, 4010, 1, 0, 2, 10, 5, 0, 0, 0, 20, 10, 400, 9, 0, 0, 0),
            (4001, 9, 3, 400, 4011, 1, 0, 3, 10, 5, 0, 0, 0, 30, 15, 400, 9, 0, 0, 0)
    ");
    $conn->begin_transaction();
    $shadowBridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_POS, 400, [
        [
            'id' => 4000,
            'item_id' => 4010,
            'qty_in' => '0.000000',
            'qty_out' => '2.000000',
            'u_val' => '1.000000',
            'cost_price' => '5.000000',
            'det_store' => 3,
        ],
        [
            'id' => 4001,
            'item_id' => 4011,
            'qty_in' => '0.000000',
            'qty_out' => '3.000000',
            'u_val' => '1.000000',
            'cost_price' => '5.000000',
            'det_store' => 3,
        ],
    ], ['user_id' => 7, 'skip_idempotency' => true]);
    $conn->commit();
    $beforeSplitDirectMovements = (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE movement_type = 'sale_direct' AND item_id IN (4010, 4011)")->fetch_assoc()['c'];
    $shadowSplit = $shadowSplitService->splitTablePayment($conn, [
        'order_id' => 400,
        'table_id' => 4,
        'items' => [
            ['detail_id' => 4000],
            ['detail_id' => 4001, 'qty' => 1],
        ],
        'paid_amount' => '30.00',
        'payment_method' => 'cash',
        'mutation_version' => 1,
        'idempotency_key' => 'split-payment-shadow-400',
    ], ['user_id' => 7, 'skip_idempotency' => true]);
    $afterSplitDirectMovements = (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE movement_type = 'sale_direct' AND item_id IN (4010, 4011)")->fetch_assoc()['c'];
    $splitBridgeBalanceA = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 4010);
    $splitBridgeBalanceB = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 4011);
    posSplitPaymentAssert($shadowSplit['success'] === true, 'shadow-aware split should still create paid child order');
    posSplitPaymentAssert($beforeSplitDirectMovements === 2 && $afterSplitDirectMovements === 2, 'split payment should not create duplicate sale_direct shadow movements');
    posSplitPaymentAssert($splitBridgeBalanceA['qty_on_hand'] === '-2.000000', 'full moved split line should keep original shadow quantity only');
    posSplitPaymentAssert($splitBridgeBalanceB['qty_on_hand'] === '-3.000000', 'partial split line should keep original total shadow quantity only');

    $recipeFlags = posSplitPaymentRecipeFlags();
    $recipeLifecycle = new RecipeOrderLifecycleService($recipeFlags);
    $recipeAwareBridge = new InventoryInvoiceBridge(
        new InventoryFeatureFlags([
            'inventory' => [
                'ledger_mode' => 'shadow',
                'strict_stock' => '1',
            ],
            'branch' => [
                'pos_tenant' => 0,
                'pos_branch' => 0,
            ],
        ]),
        null,
        null,
        null,
        $recipeFlags
    );
    $recipeAwareService = new PosOrderMutationService(
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        $recipeLifecycle,
        null,
        null,
        $recipeAwareBridge
    );
    posSplitPaymentCreateRecipeFixture($conn, 30010, 30011, '10.000000', 3);
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (3, 'T3', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            300, 13, 0, 3, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 30, 30, 0,
            30, 0, 30, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (3000, 9, 3, 300, 30010, 2, 0, 6, 5, 4, 0, 0, 0, 30, 18, 300, 9, 0, 0, 0)
    ");
    $recipeLifecycle->onOrderLineAdded([
        'conn' => $conn,
        'order_id' => 300,
        'fat_detail_id' => 3000,
        'store_id' => 3,
        'channel' => 'table',
        'order_type' => 'dine_in',
        'sellable_item_id' => 30010,
        'quantity' => '3.000000',
    ]);

    $recipeSplit = $recipeAwareService->splitTablePayment($conn, [
        'order_id' => 300,
        'table_id' => 3,
        'items' => [
            ['detail_id' => 3000, 'qty' => 2],
        ],
        'paid_amount' => '10.00',
        'payment_method' => 'cash',
        'mutation_version' => 1,
        'idempotency_key' => 'split-payment-recipe-300',
    ], ['user_id' => 7, 'skip_idempotency' => true]);
    $recipeChildOrderId = (int) $recipeSplit['data']['new_invoice_id'];
    $afterSplitBalance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 30011);
    $originalUsages = posSplitPaymentRows($conn, 'recipe_order_line_usage', 'order_id = 300');
    $childUsages = posSplitPaymentRows($conn, 'recipe_order_line_usage', 'order_id = ' . $recipeChildOrderId);
    $originalReservations = posSplitPaymentRows($conn, 'stock_reservations', 'order_id = 300');

    posSplitPaymentAssert($recipeSplit['success'] === true, 'recipe split should still create a paid child order');
    posSplitPaymentAssert($afterSplitBalance['qty_on_hand'] === '9.000000', 'split child payment should consume only paid split quantity');
    posSplitPaymentAssert($afterSplitBalance['qty_reserved'] === '2.000000', 'original reservation should be rebuilt for remaining quantity only');
    posSplitPaymentAssert(array_column($originalUsages, 'status') === ['released', 'reserved'], 'original recipe usage should release old qty and reserve remaining qty');
    posSplitPaymentAssert(array_column($originalUsages, 'order_qty') === ['3.000000', '2.000000'], 'original recipe usage quantities should track old and remaining qty');
    posSplitPaymentAssert(array_column($originalReservations, 'status') === ['released', 'reserved'], 'original stock reservations should release old qty and reserve remaining qty');
    posSplitPaymentAssert(array_column($originalReservations, 'qty_reserved') === ['3.000000', '2.000000'], 'reservation quantities should track old and remaining qty');
    posSplitPaymentAssert(count($childUsages) === 1 && $childUsages[0]['status'] === 'consumed', 'child split order should have exactly one consumed recipe usage');

    $recipeLifecycle->onOrderPaid([
        'conn' => $conn,
        'order_id' => 300,
        'store_id' => 3,
        'channel' => 'table',
        'order_type' => 'dine_in',
        'lines' => [
            [
                'fat_detail_id' => 3000,
                'sellable_item_id' => 30010,
                'quantity' => '2.000000',
            ],
        ],
    ]);
    $afterFinalPaymentBalance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 30011);
    $consumedQty = $conn->query("SELECT COALESCE(SUM(qty_out), 0) AS qty FROM inventory_movements WHERE item_id = 30011 AND movement_type = 'recipe_consumption'")->fetch_assoc();
    posSplitPaymentAssert($afterFinalPaymentBalance['qty_on_hand'] === '7.000000', 'final original payment should consume remaining two units only');
    posSplitPaymentAssert($afterFinalPaymentBalance['qty_reserved'] === '0.000000', 'final original payment should consume remaining reservation');
    posSplitPaymentAssert(abs((float) $consumedQty['qty'] - 3.0) < 0.0001, 'split plus final payment should consume exactly original quantity, not double-consume');

    echo "pos-split-payment-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}


function posSplitPaymentSeedCashDrawer(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT IGNORE INTO settings (id, def_pos_client, def_pos_store, isdeleted) VALUES (1, 501, 3, 0)");
    $conn->query("INSERT IGNORE INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES (3, 'ST001', 'Main Store', 1, 0), (51, '101001', 'Cash', 0, 0), (52, '102001', 'Bank', 0, 0), (501, '122001', 'Customer', 0, 0)");

    $paymentMethods = new PaymentMethodService();
    try {
        $paymentMethods->saveMethod($conn, [
            'code' => 'cash',
            'name_ar' => 'Cash',
            'name_en' => 'Cash',
            'type' => 'cash',
            'account_id' => 51,
        ]);
        $paymentMethods->saveMethod($conn, [
            'code' => 'bank',
            'name_ar' => 'Bank',
            'name_en' => 'Bank',
            'type' => 'bank',
            'account_id' => 52,
        ]);
    } catch (Throwable $exception) {
        // Already seeded in this disposable DB.
    }
    (new DrawerSessionService())->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 0,
        'branch' => 0,
        'opening_cash' => '100.000',
    ]);
}

function posSplitPaymentCreateSchema(mysqli $conn): void
{
    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS document_counters (
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
        CREATE TABLE IF NOT EXISTS ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            branch_id INT NULL,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            pro_tybe INT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            parent_order_id INT NULL,
            split_group_id VARCHAR(64) NULL,
            info TEXT NULL,
            user INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            det_store INT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            stock_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            fatid INT NOT NULL,
            fat_tybe INT NULL,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            tendered_amount DECIMAL(19,2) NULL,
            applied_amount DECIMAL(19,2) NULL,
            change_due DECIMAL(19,2) NULL,
            payment_method VARCHAR(50) NULL,
            reference_no VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posSplitPaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function posSplitPaymentRecipeFlags(): RecipeFeatureFlags
{
    return new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'consume_pilot',
            'reservations' => true,
            'consumption' => true,
            'pilot' => [
                'pos_branch' => '0',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ],
    ]);
}

function posSplitPaymentCreateRecipeFixture(mysqli $conn, int $sellableItemId, int $ingredientItemId, string $stock, int $storeId): void
{
    $conn->query("
        INSERT INTO myitems (id, iname, cost_price, group1, item_type, track_stock)
        VALUES
            ({$sellableItemId}, 'Split recipe item', 0.000000, 7, 'sellable', 1),
            ({$ingredientItemId}, 'Split recipe ingredient', 4.000000, 7, 'ingredient', 1)
    ");
    (new InventoryBalanceRepository())->putBalance($conn, [
        'store_id' => $storeId,
        'item_id' => $ingredientItemId,
        'qty_on_hand' => $stock,
        'qty_reserved' => '0.000000',
        'qty_available' => $stock,
    ]);

    $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'shadow',
        ],
    ]));
    $actor = new RecipeActorContext(7, 0, 0, null, ['recipe.manage', 'recipe.approve']);
    $recipe = $definition->createDraft($conn, [
        'sellable_item_id' => $sellableItemId,
        'recipe_name' => 'Split payment recipe',
    ], $actor);
    $definition->addLine($conn, (int) $recipe['id'], [
        'ingredient_item_id' => $ingredientItemId,
        'qty_per_yield' => '1.000000',
    ], $actor);
    $definition->activate($conn, (int) $recipe['id'], $actor);
}

function posSplitPaymentRows(mysqli $conn, string $table, string $where): array
{
    $result = $conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}
