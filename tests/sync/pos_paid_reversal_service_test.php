<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class PaidReversalApprovalStub extends ManagerApprovalService
{
    public array $amounts = [];

    public function requireApprovedIfNeeded(
        mysqli $conn,
        string $actionType,
        string $targetType,
        ?int $targetId,
        float $amount,
        array $request = [],
        array $context = []
    ): ?array {
        $this->amounts[] = $amount;
        return null;
    }
}

class PaidReversalRecipeSpy extends RecipeOrderLifecycleService
{
    public array $refunded = [];
    public array $voided = [];

    public function onOrderRefunded($order, $refund): array
    {
        $this->refunded[] = [
            'order' => (array) $order,
            'reverse' => (array) $refund,
        ];

        return [
            'success' => true,
            'action' => 'order_refunded',
            'noop' => false,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }

    public function onOrderVoided($order, $void): array
    {
        $this->voided[] = [
            'order' => (array) $order,
            'reverse' => (array) $void,
        ];

        return [
            'success' => true,
            'action' => 'order_voided',
            'noop' => false,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }
}

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_paid_reversal_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-paid-reversal-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    paidReversalCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);
    $conn->query("CREATE TABLE IF NOT EXISTS myitems (
        id INT NOT NULL PRIMARY KEY,
        iname VARCHAR(120) NOT NULL,
        item_type VARCHAR(30) NOT NULL DEFAULT 'sellable',
        track_stock TINYINT(1) NOT NULL DEFAULT 1,
        base_unit_id BIGINT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("INSERT INTO myitems (id, iname, item_type, track_stock) VALUES (3001, 'Refund stock item', 'sellable', 1)");
    (new PaymentMethodService())->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card',
        'name_en' => 'Card',
        'type' => 'card',
        'account_id' => 52,
    ]);

    $recipeSpy = new PaidReversalRecipeSpy();
    $inventoryBridge = new InventoryInvoiceBridge(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'shadow',
            'strict_stock' => '1',
        ],
        'branch' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ],
    ]));
    $approvalStub = new PaidReversalApprovalStub();
    $service = new PosOrderMutationService(
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        $approvalStub,
        null,
        $recipeSpy,
        new RecipeSettingsService([
            'recipe' => [
                'refund_stock_policy' => 'manager_choice',
            ],
        ]),
        null,
        $inventoryBridge
    );

    paidReversalSeedOrder($conn, 501, 'takeaway');
    paidReversalSeedInventorySale($conn, $inventoryBridge, 501);
    $refund = $service->reversePaidOrder($conn, [
        'order_id' => 501,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'customer refund',
    ], ['user_id' => 7]);

    $refundedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 501')->fetch_assoc();
    paidReversalAssert($refund['success'] === true, 'refund should succeed');
    paidReversalAssert($refundedOrder['payment_status'] === 'refunded', 'refund should set payment status');
    paidReversalAssert($refundedOrder['invoice_status'] === 'cancelled', 'refund should cancel invoice status');
    paidReversalAssert($refundedOrder['order_status'] === 'cancelled', 'refund should cancel order status');
    paidReversalAssert((int) $refundedOrder['isdeleted'] === 0, 'refund should keep order visible for audit');
    paidReversalAssert($recipeSpy->refunded[0]['reverse']['policy'] === 'return_to_stock', 'refund should pass manager selected recipe stock policy');
    paidReversalAssert((int) $recipeSpy->refunded[0]['order']['lines'][0]['sellable_item_id'] === 3001, 'refund should load fat_details through service context');
    $fullStockReturn = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(qty_in), 0) AS qty FROM inventory_movements WHERE order_id = 501 AND movement_type = 'refund_reversal'")->fetch_assoc();
    paidReversalAssert((int) $fullStockReturn['c'] === 1, 'full restock refund must record one direct inventory reversal');
    paidReversalAssert(abs((float) $fullStockReturn['qty'] - 2.0) < 0.000001, 'full restock refund must restore the sold quantity');

    try {
        $service->reversePaidOrder($conn, [
            'order_id' => 501,
            'action' => 'refund',
        ], ['user_id' => 7]);
        throw new RuntimeException('second refund should fail');
    } catch (RuntimeException $exception) {
        paidReversalAssert($exception->getMessage() === 'ORDER_ALREADY_REVERSED', 'second refund should be blocked');
    }

    paidReversalSeedOrder($conn, 503, 'takeaway');
    paidReversalSeedInventorySale($conn, $inventoryBridge, 503);
    $partialRequest = [
        'order_id' => 503,
        'action' => 'refund',
        'idempotency_key' => 'paid-reversal-partial-503-a',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'partial item refund',
        'lines' => [[
            'original_detail_id' => 1503,
            'quantity' => '1.000000',
            'stock_disposition' => 'restock',
        ]],
        'payments' => [[
            'original_payment_id' => 2503,
            'payment_method' => 'card_terminal',
            'amount' => '10.00',
        ]],
    ];
    $partial = $service->reversePaidOrder($conn, $partialRequest, ['user_id' => 7]);
    $partialOrder = $conn->query('SELECT * FROM ot_head WHERE id = 503')->fetch_assoc();
    paidReversalAssert($partial['data']['reversal_status'] === 'partial', 'first half refund should be partial');
    paidReversalAssert($partial['data']['refund_amount'] === '10.00', 'partial response must expose current refund amount');
    paidReversalAssert($partial['data']['cumulative_refunded_amount'] === '10.00', 'partial response must expose cumulative amount');
    paidReversalAssert($partial['data']['remaining_refundable_amount'] === '10.00', 'partial response must expose remaining amount');
    paidReversalAssert($partialOrder['payment_status'] === 'paid', 'partial refund must keep order paid');
    paidReversalAssert($partialOrder['invoice_status'] === 'completed', 'partial refund must keep invoice completed');
    paidReversalAssert($recipeSpy->refunded[1]['order']['lines'][0]['quantity'] === '1.000000', 'partial recipe reversal must use refunded quantity only');

    $recipeCountBeforeReplay = count($recipeSpy->refunded);
    $partialReplay = $service->reversePaidOrder($conn, $partialRequest, ['user_id' => 7]);
    paidReversalAssert($partialReplay['data']['replayed'] === true, 'partial idempotency replay must return stored result');
    paidReversalAssert(count($recipeSpy->refunded) === $recipeCountBeforeReplay, 'partial replay must not repeat recipe compensation');

    $finalPartialRequest = $partialRequest;
    $finalPartialRequest['idempotency_key'] = 'paid-reversal-partial-503-b';
    $finalPartialRequest['reason'] = 'remaining item refund';
    $fullFromPartials = $service->reversePaidOrder($conn, $finalPartialRequest, ['user_id' => 7]);
    $fullyRefundedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 503')->fetch_assoc();
    paidReversalAssert($fullFromPartials['data']['reversal_status'] === 'full', 'second half refund must reach full state');
    paidReversalAssert($fullFromPartials['data']['cumulative_refunded_amount'] === '20.00', 'full cumulative amount must match sale');
    paidReversalAssert($fullFromPartials['data']['remaining_refundable_amount'] === '0.00', 'full cumulative refund must have no remainder');
    paidReversalAssert($fullyRefundedOrder['payment_status'] === 'refunded', 'cumulative full refund must transition order status');
    $partialStockReturn = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(qty_in), 0) AS qty FROM inventory_movements WHERE order_id = 503 AND movement_type = 'refund_reversal'")->fetch_assoc();
    paidReversalAssert((int) $partialStockReturn['c'] === 2, 'separate partial restock refunds must keep distinct credit-note reversal identities');
    paidReversalAssert(abs((float) $partialStockReturn['qty'] - 2.0) < 0.000001, 'partial restock refunds must restore exactly the cumulative refunded quantity');

    paidReversalSeedOrder($conn, 505, 'takeaway');
    paidReversalSeedInventorySale($conn, $inventoryBridge, 505);
    $approvalCountBeforeAmount = count($approvalStub->amounts);
    $recipeCountBeforeAmount = count($recipeSpy->refunded);
    $amountPartial = $service->reversePaidOrder($conn, [
        'order_id' => 505,
        'action' => 'refund',
        'idempotency_key' => 'paid-reversal-amount-505',
        'refund_mode' => 'amount',
        'refund_amount' => '5.00',
        'refund_payment_method' => 'card_terminal',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'amount refund',
    ], ['user_id' => 7]);
    paidReversalAssert($amountPartial['data']['refund_mode'] === 'amount', 'amount refund response must expose its durable mode');
    paidReversalAssert($amountPartial['data']['refund_amount'] === '5.00', 'amount refund must post exact selected money');
    paidReversalAssert($amountPartial['data']['remaining_refundable_amount'] === '15.00', 'amount refund must preserve exact remainder');
    paidReversalAssert(
        count($approvalStub->amounts) === $approvalCountBeforeAmount + 1
            && abs((float) end($approvalStub->amounts) - 5.0) < 0.001,
        'manager limit evaluation must use the requested partial amount'
    );
    paidReversalAssert(count($recipeSpy->refunded) === $recipeCountBeforeAmount + 1, 'amount refund must run recipe compensation once');
    paidReversalAssert(
        (string) $recipeSpy->refunded[$recipeCountBeforeAmount]['order']['lines'][0]['quantity'] === '0.500000',
        'amount refund recipe/COGS compensation must use the quantity posted on the credit note'
    );
    $amountStockReturn = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(qty_in), 0) AS qty FROM inventory_movements WHERE order_id = 505 AND movement_type = 'refund_reversal'")->fetch_assoc();
    paidReversalAssert((int) $amountStockReturn['c'] === 1, 'amount restock refund must create one inventory reversal');
    paidReversalAssert(abs((float) $amountStockReturn['qty'] - 0.5) < 0.000001, 'amount restock refund must reverse stock only to allocated quantity');
    $amountCreditLine = $conn->query("
        SELECT cn.refund_mode, cnl.quantity, cnl.line_amount
        FROM credit_notes cn
        INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
        WHERE cn.original_order_id = 505
    ")->fetch_assoc();
    paidReversalAssert((string) $amountCreditLine['refund_mode'] === 'amount', 'amount mode must persist on credit note');
    paidReversalAssert((string) $amountCreditLine['quantity'] === '0.500000', 'allocated amount quantity must persist');
    paidReversalAssert((string) $amountCreditLine['line_amount'] === '5.00', 'allocated amount line value must persist exactly');

    $fixedWasteRecipeService = new PosOrderMutationService(
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        new PaidReversalApprovalStub(),
        null,
        $recipeSpy,
        new RecipeSettingsService([
            'recipe' => [
                'refund_stock_policy' => 'waste',
            ],
        ]),
        null,
        $inventoryBridge
    );
    paidReversalSeedOrder($conn, 504, 'takeaway');
    paidReversalSeedInventorySale($conn, $inventoryBridge, 504);
    $fixedWasteRecipeService->reversePaidOrder($conn, [
        'order_id' => 504,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'direct item restock with fixed recipe waste policy',
    ], ['user_id' => 7]);
    $fixedWasteStockReturn = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(qty_in), 0) AS qty FROM inventory_movements WHERE order_id = 504 AND movement_type = 'refund_reversal'")->fetch_assoc();
    paidReversalAssert((int) $fixedWasteStockReturn['c'] === 1, 'posted restock credit note must reverse direct inventory even when recipe ingredients use fixed waste policy');
    paidReversalAssert(abs((float) $fixedWasteStockReturn['qty'] - 2.0) < 0.000001, 'credit-note stock disposition must be the direct inventory source of truth');

    paidReversalSeedOrder($conn, 502, 'table');
    $void = $service->reversePaidOrder($conn, [
        'order_id' => 502,
        'action' => 'void',
        'refund_stock_policy' => 'waste',
    ], ['user_id' => 7]);

    $voidedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 502')->fetch_assoc();
    $table = $conn->query('SELECT * FROM tables WHERE id = 12')->fetch_assoc();
    paidReversalAssert($void['success'] === true, 'void should succeed');
    paidReversalAssert($voidedOrder['payment_status'] === 'voided', 'void should set payment status');
    paidReversalAssert((int) $voidedOrder['isdeleted'] === 0, 'void must retain the original paid order as immutable audit evidence');
    paidReversalAssert((int) $table['table_case'] === 0, 'void should free table when no active order remains');
    paidReversalAssert($recipeSpy->voided[0]['reverse']['policy'] === 'waste', 'void should pass recipe waste policy');

    echo "pos-paid-reversal-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function paidReversalCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (def_pos_client, def_pos_store, def_pos_employee, def_pos_fund) VALUES (501, 61, 71, 51)");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(120) NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            balance DECIMAL(19,2) NOT NULL DEFAULT 0.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund)
        VALUES
            (35, '213', 'Employees', 0, 1, 0, 0),
            (51, '121001', 'Cash', 0, 0, 0, 1),
            (52, '124001', 'Card', 0, 0, 0, 0),
            (61, '123001', 'Store', 0, 0, 1, 0),
            (71, '213001', 'Employee', 35, 0, 0, 0),
            (91, '3111', 'Sales', 0, 0, 0, 0),
            (501, '122001', 'Customer', 0, 0, 0, 0)
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(19,2) NOT NULL,
            jdate DATE NOT NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op_id INT NULL,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
            tybe INT NOT NULL DEFAULT 0,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(19,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            table_id INT NULL,
            pro_tybe INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            fat_net DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            remaining_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            updated_by INT NULL,
            mdtime DATETIME NULL,
            completed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NOT NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            det_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            det_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function paidReversalSeedOrder(mysqli $conn, int $orderId, string $orderType): void
{
    $tableId = $orderType === 'table' ? 12 : 0;
    if ($tableId > 0) {
        $conn->query("INSERT INTO tables (id, table_case, isdeleted) VALUES ({$tableId}, 1, 0) ON DUPLICATE KEY UPDATE table_case = 1");
    }

    $conn->query("
        INSERT INTO ot_head (
            id, table_id, pro_tybe, order_type, payment_status, invoice_status,
            order_status, fat_net, paid_amount, remaining_amount, isdeleted
        ) VALUES (
            {$orderId}, {$tableId}, 9, '{$orderType}', 'paid', 'completed',
            'completed', 20.00, 20.00, 0.00, 0
        )
    ");
    $detailId = $orderId + 1000;
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, det_value, u_val, det_store, isdeleted)
        VALUES ({$detailId}, {$orderId}, 3001, 0.000000, 2.000000, 10.000000, 20.00, 1.000000, 0, 0)
    ");
    $paymentId = $orderId + 2000;
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES ({$paymentId}, {$orderId}, 20.00, 'card_terminal')");
}

function paidReversalSeedInventorySale(mysqli $conn, InventoryInvoiceBridge $bridge, int $orderId): void
{
    $detailId = $orderId + 1000;
    $conn->begin_transaction();
    $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_POS, $orderId, [[
        'id' => $detailId,
        'item_id' => 3001,
        'qty_in' => '0.000000',
        'qty_out' => '2.000000',
        'u_val' => '1.000000',
        'cost_price' => '1.000000',
        'det_store' => 61,
    ]], ['user_id' => 7]);
    $conn->commit();
}

function paidReversalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
