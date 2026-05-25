<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_paid_reversal_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    paidReversalCreateSchema($conn);

    $recipeSpy = new PaidReversalRecipeSpy();
    $service = new PosOrderMutationService(
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        $recipeSpy,
        new RecipeSettingsService([
            'recipe' => [
                'refund_stock_policy' => 'manager_choice',
            ],
        ])
    );

    paidReversalSeedOrder($conn, 501, 'takeaway');
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

    try {
        $service->reversePaidOrder($conn, [
            'order_id' => 501,
            'action' => 'refund',
        ], ['user_id' => 7]);
        throw new RuntimeException('second refund should fail');
    } catch (RuntimeException $exception) {
        paidReversalAssert($exception->getMessage() === 'ORDER_ALREADY_REVERSED', 'second refund should be blocked');
    }

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
    paidReversalAssert((int) $voidedOrder['isdeleted'] === 1, 'void should hide from active POS list');
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
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, u_val, det_store, isdeleted)
        VALUES ({$detailId}, {$orderId}, 3001, 0.000000, 2.000000, 1.000000, 0, 0)
    ");
}

function paidReversalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
