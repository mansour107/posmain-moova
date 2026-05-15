<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_payment_methods_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new PaymentMethodService();
    phase4PaymentAssert($service->normalizeCode(' Card Terminal ') === 'card_terminal', 'code normalization should trim and underscore words');

    $cash = $service->saveMethod($conn, [
        'code' => ' Cash Drawer ',
        'name_ar' => 'نقدي',
        'name_en' => 'Cash drawer',
        'account_id' => 1001,
        'type' => 'cash',
        'requires_reference' => false,
        'sort_order' => 2,
    ]);
    phase4PaymentAssert($cash['code'] === 'cash_drawer', 'cash code should be normalized');
    phase4PaymentAssert($cash['type'] === 'cash', 'cash type expected');
    phase4PaymentAssert($cash['account_id'] === 1001, 'cash account expected');
    phase4PaymentAssert($cash['requires_reference'] === false, 'cash should not require reference');

    $cashUpdated = $service->saveMethod($conn, [
        'code' => 'cash drawer',
        'name_ar' => 'درج نقدي',
        'name_en' => null,
        'account_id' => null,
        'type' => 'cash',
        'requires_reference' => false,
        'sort_order' => 3,
    ]);
    phase4PaymentAssert($cashUpdated['id'] === $cash['id'], 'same code should update existing row');
    phase4PaymentAssert($cashUpdated['name_ar'] === 'درج نقدي', 'cash name should update');
    phase4PaymentAssert($cashUpdated['account_id'] === null, 'cash account should allow null');
    phase4PaymentAssert(phase4PaymentCount($conn) === 1, 'upsert should not duplicate payment method rows');

    $card = $service->saveMethod($conn, [
        'code' => 'card terminal',
        'name_ar' => 'فيزا',
        'name_en' => 'Card terminal',
        'account_id' => 2002,
        'type' => 'card',
        'requires_reference' => true,
        'sort_order' => 1,
    ]);
    $wallet = $service->saveMethod($conn, [
        'code' => 'wallet',
        'name_ar' => 'محفظة',
        'type' => 'wallet',
        'is_active' => false,
        'sort_order' => 0,
    ]);

    $active = $service->listActive($conn);
    phase4PaymentAssert(count($active) === 2, 'inactive methods should be excluded from active list');
    phase4PaymentAssert($active[0]['code'] === 'card_terminal', 'active list should sort by sort_order');
    phase4PaymentAssert($active[1]['code'] === 'cash_drawer', 'cash should be second after sort update');

    $resolvedByCode = $service->resolveActive($conn, 'CARD TERMINAL');
    phase4PaymentAssert($resolvedByCode['id'] === $card['id'], 'resolveActive should find normalized code');
    $resolvedById = $service->resolveActive($conn, $cash['id']);
    phase4PaymentAssert($resolvedById['code'] === 'cash_drawer', 'resolveActive should find active id');

    phase4PaymentAssert($service->validateReference($card, ' POS-123 ') === 'POS-123', 'reference should be trimmed');
    phase4PaymentAssert($service->validateReference($cash, '') === null, 'cash empty reference should be optional');

    phase4PaymentExpectException(function () use ($service, $card) {
        $service->validateReference($card, '');
    }, 'PAYMENT_REFERENCE_REQUIRED');

    phase4PaymentExpectException(function () use ($service, $conn, $wallet) {
        $service->resolveActive($conn, $wallet['id']);
    }, 'PAYMENT_METHOD_NOT_FOUND');

    phase4PaymentExpectException(function () use ($service, $conn) {
        $service->saveMethod($conn, [
            'code' => 'crypto',
            'name_ar' => 'عملات',
            'type' => 'crypto',
        ]);
    }, 'PAYMENT_METHOD_TYPE_INVALID');

    phase4PaymentExpectException(function () use ($service) {
        $service->normalizeCode('بطاقة');
    }, 'PAYMENT_METHOD_CODE_INVALID');

    echo "phase4-payment-method-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4PaymentCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM payment_methods")->fetch_assoc()['c'];
}

function phase4PaymentExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4PaymentAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4PaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
