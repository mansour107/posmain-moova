<?php

require_once __DIR__ . '/../../classes/Pos/Service/OrderPricingService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_order_pricing_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "order-pricing-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("CREATE TABLE myitems (
        id INT PRIMARY KEY,
        price1 DECIMAL(12,3) NOT NULL DEFAULT 0,
        cost_price DECIMAL(12,3) NOT NULL DEFAULT 0,
        isdeleted TINYINT NOT NULL DEFAULT 0
    )");
    $conn->query("INSERT INTO myitems (id, price1, cost_price, isdeleted) VALUES (10, 12.500, 4.000, 0)");

    $service = new OrderPricingService();
    $resolved = $service->resolveTableSaveRequest($conn, [
        'items' => [['id' => 10, 'qty' => '2', 'price' => '12.5', 'discount' => '0']],
        'total' => '25.00',
        'discount' => '0.00',
        'net' => '25.00',
    ]);
    orderPricingAssert(!empty($resolved['pricing_resolved']), 'pricing should mark request resolved');
    orderPricingAssert($resolved['total'] === '25.00', 'pricing must return a decimal-string total');
    orderPricingAssert($resolved['items'][0]['price'] === '12.500000', 'canonical price should be used');

    $failed = false;
    try {
        $service->resolveTableSaveRequest($conn, [
            'items' => [['id' => 10, 'qty' => '2', 'price' => '1', 'discount' => '0']],
            'total' => '2.00',
            'discount' => '0.00',
            'net' => '2.00',
        ]);
    } catch (InvalidArgumentException $e) {
        $failed = $e->getMessage() === 'PRICE_MISMATCH';
    }
    orderPricingAssert($failed, 'tampered line price should be rejected');

    $discountRegression = $service->resolveTableSaveRequest($conn, [
        'items' => [['id' => 10, 'qty' => '2', 'price' => '12.5', 'discount' => '1']],
        'discount' => '0',
    ]);
    orderPricingAssert($discountRegression['net'] === '23.00', 'line discounts must be per-unit and applied consistently');

    $floatCoerced = $service->resolveTableSaveRequest($conn, [
        'items' => [['id' => 10, 'qty' => 2.0, 'price' => 12.5, 'discount' => 0.0]],
        'discount' => 0.0,
    ]);
    orderPricingAssert($floatCoerced['net'] === '25.00', 'HTTP boundary must coerce JSON floats into exact decimal strings');

    $kernelRejectsFloat = false;
    try {
        (new FinancialPricingService())->price([['id' => 1, 'qty' => 2.0, 'price' => '12.5', 'discount' => '0']]);
    } catch (InvalidArgumentException $e) {
        $kernelRejectsFloat = $e->getMessage() === 'FINANCIAL_DECIMAL_STRING_REQUIRED';
    }
    orderPricingAssert($kernelRejectsFloat, 'FinancialPricingService kernel must still reject raw PHP floats');

    echo "order-pricing-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function orderPricingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
