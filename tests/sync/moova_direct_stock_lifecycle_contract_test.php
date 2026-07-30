<?php

require_once __DIR__ . '/../../classes/PosOrderService.php';

final class MoovaDirectStockLifecycleSpy extends InventoryInvoiceBridge
{
    public array $reserved = [];
    public array $released = [];
    public array $reserveResult = ['success' => true, 'movements' => [], 'errors' => []];
    public array $releaseResult = ['success' => true, 'movements' => [], 'errors' => []];

    public function __construct()
    {
    }

    public function reserveInvoiceLines(mysqli $conn, int $invoiceType, int $invoiceId, array $lines, array $context = []): array
    {
        $this->reserved[] = compact('invoiceType', 'invoiceId', 'lines', 'context');

        return $this->reserveResult;
    }

    public function releaseInvoiceReservations(mysqli $conn, int $invoiceType, int $invoiceId, array $lines, string $reason, array $context = []): array
    {
        $this->released[] = compact('invoiceType', 'invoiceId', 'lines', 'reason', 'context');

        return $this->releaseResult;
    }
}

$bridge = new MoovaDirectStockLifecycleSpy();
$service = new PosOrderService(null, $bridge);
$conn = new mysqli();
$mapped = [[
    'mapping_id' => 801,
    'fat_detail_id' => 901,
    'item_id' => 1001,
    'qty_in' => '0.000000',
    'qty_out' => '2.000000',
    'u_val' => '1.000000',
    'cost_price' => '3.500000',
    'det_store' => 27,
]];
$scope = ['user_id' => 51, 'branch_uuid' => '00000000-0000-4000-8000-000000000027'];
$payload = ['orderType' => 'delivery'];

$reserve = new ReflectionMethod($service, 'recordMoovaInventoryBridgeLines');
$reserve->invoke($service, $conn, 3, 5, $scope, $payload, 'moova-order-1', 7001, $mapped);

moovaDirectStockAssert(count($bridge->reserved) === 1, 'unpaid Moova order must reserve direct stock');
moovaDirectStockAssert($bridge->reserved[0]['invoiceId'] === 7001, 'reservation must retain order identity');
moovaDirectStockAssert($bridge->reserved[0]['lines'][0]['order_line_uuid'] === 'moova_pos_order_lines:801', 'reservation must retain stable line identity');
moovaDirectStockAssert($bridge->reserved[0]['context']['source_system'] === 'moova', 'reservation must retain source identity');

$release = new ReflectionMethod($service, 'recordMoovaInventoryBridgeReversalLines');
$release->invoke($service, $conn, 3, 5, $scope, $payload, 'moova-order-1', 7001, $mapped, 'moova_order_replaced');

moovaDirectStockAssert(count($bridge->released) === 1, 'Moova edit/cancel must release direct-stock reservation');
moovaDirectStockAssert($bridge->released[0]['reason'] === 'moova_order_replaced', 'reservation release must retain lifecycle reason');
moovaDirectStockAssert($bridge->released[0]['lines'][0]['qty_out'] === '2.000000', 'release quantity must match reserved quantity');

$bridge->reserveResult = [
    'success' => false,
    'mode' => 'live',
    'movements' => [],
    'errors' => ['accounting_required'],
];
moovaDirectStockAssertThrows(
    static fn () => $reserve->invoke($service, $conn, 3, 5, $scope, $payload, 'moova-order-2', 7002, $mapped),
    'MOOVA_INVENTORY_RESERVATION_FAILED',
    'authoritative reservation failure must escape the catch and roll back the caller transaction'
);

$bridge->releaseResult = [
    'success' => false,
    'mode' => 'bridge',
    'movements' => [],
    'errors' => ['movement_reversal_failed'],
];
moovaDirectStockAssertThrows(
    static fn () => $release->invoke($service, $conn, 3, 5, $scope, $payload, 'moova-order-2', 7002, $mapped, 'moova_order_cancelled'),
    'MOOVA_INVENTORY_RESERVATION_RELEASE_FAILED',
    'authoritative release failure must escape the catch and roll back the caller transaction'
);

echo "moova-direct-stock-lifecycle-contract-ok\n";

function moovaDirectStockAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function moovaDirectStockAssertThrows(callable $callback, string $expectedMessage, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        $actual = $exception instanceof ReflectionException && $exception->getPrevious()
            ? $exception->getPrevious()
            : $exception;
        moovaDirectStockAssert($actual->getMessage() === $expectedMessage, $message . ': ' . $actual->getMessage());
        return;
    }

    throw new RuntimeException($message . ': no exception');
}
