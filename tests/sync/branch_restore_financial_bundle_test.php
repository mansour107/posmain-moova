<?php

require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../../classes/Sync/CloudLegacyPosMirrorService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$dbName = 'posmain_restore_financial_' . getmypid();
$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_error) {
    echo "branch-restore-financial-bundle-skipped mysql-unavailable\n";
    exit(0);
}

$admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn = new mysqli($host, $user, $pass, $dbName, $port);
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    branchRestoreFinancialSchema($conn);
    $event = branchRestoreFinancialEvent();
    $mirror = new CloudLegacyPosMirrorService();
    $result = $mirror->mirrorFromBranchEvent($conn, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $event);

    branchRestoreFinancialAssert((int) ($result['journal_count'] ?? 0) === 1, 'one scoped journal should restore');
    branchRestoreFinancialAssert((int) ($result['journal_entry_count'] ?? 0) === 2, 'two balanced entries should restore');
    branchRestoreFinancialAssert((int) ($result['receipt_count'] ?? 0) === 1, 'receipt should restore before journal references');
    branchRestoreFinancialAssert((int) ($result['payment_count'] ?? 0) === 1, 'order payment should restore');
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'journal_heads') === 1, 'journal replay set should contain one head');
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'journal_entries') === 2, 'journal replay set should contain two entries');
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'order_payments') === 1, 'order payment should be present');
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'ot_head') === 2, 'order and receipt should be present');
    $restoredOrder = $conn->query('SELECT fat_tax, profit FROM ot_head WHERE id = 400')->fetch_assoc();
    branchRestoreFinancialAssert((string) $restoredOrder['fat_tax'] === '1.2345', 'header tax must restore at four decimals');
    branchRestoreFinancialAssert((string) $restoredOrder['profit'] === '12.345678', 'header profit must restore at six decimals');

    $cash = $conn->query('SELECT balance, phone, address, e_mail, info FROM acc_head WHERE id = 10')->fetch_assoc();
    $sales = $conn->query('SELECT balance FROM acc_head WHERE id = 11')->fetch_assoc();
    branchRestoreFinancialAssert((string) $cash['balance'] === '50.000000', 'cash balance must be derived from restored entries');
    branchRestoreFinancialAssert((string) $sales['balance'] === '-50.000000', 'sales balance must be derived from restored entries');
    branchRestoreFinancialAssert($cash['phone'] === null && $cash['address'] === null && $cash['e_mail'] === null && $cash['info'] === null, 'private account fields must not be restored');

    $mirror->mirrorFromBranchEvent($conn, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $event);
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'journal_heads') === 1, 'exact journal replay must be idempotent');
    branchRestoreFinancialAssert(branchRestoreFinancialCount($conn, 'journal_entries') === 2, 'exact entry replay must be idempotent');

    $accountConflict = $event;
    $accountConflict['payload']['financial_bundle']['accounts'][1]['aname'] = 'Wrong account identity';
    unset($accountConflict['payload']['financial_bundle']['bundle_hash']);
    $accountConflict['payload']['financial_bundle']['bundle_hash'] = hash(
        'sha256',
        json_encode($accountConflict['payload']['financial_bundle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    try {
        $mirror->mirrorFromBranchEvent($conn, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $accountConflict);
        throw new RuntimeException('Expected immutable account conflict.');
    } catch (RuntimeException $exception) {
        branchRestoreFinancialAssert($exception->getMessage() === 'ORDER_FINANCIAL_ACCOUNT_CONFLICT', 'account identity conflict must fail closed');
    }

    $conflict = $event;
    $conflict['payload']['financial_bundle']['journal_heads'][0]['details'] = 'Changed immutable journal';
    unset($conflict['payload']['financial_bundle']['bundle_hash']);
    $conflict['payload']['financial_bundle']['bundle_hash'] = hash(
        'sha256',
        json_encode($conflict['payload']['financial_bundle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $conn->begin_transaction();
    try {
        $mirror->mirrorFromBranchEvent($conn, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $conflict);
        throw new RuntimeException('Expected immutable journal conflict.');
    } catch (RuntimeException $exception) {
        branchRestoreFinancialAssert($exception->getMessage() === 'ORDER_FINANCIAL_JOURNAL_HEAD_CONFLICT', 'immutable head conflict must fail closed');
        $conn->rollback();
    }

    $legacy = $event;
    $legacy['aggregate_uuid'] = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    $legacy['entity_uuid'] = $legacy['aggregate_uuid'];
    $legacy['payload'] = [
        'schema_version' => 1,
        'order' => array_merge($event['payload']['order'], [
            'order_uuid' => $legacy['aggregate_uuid'],
            'local_order_id' => 401,
            'pro_id' => 'LEGACY-401',
        ]),
        'lines' => [],
    ];
    unset($legacy['payload']['order']['fat_tax'], $legacy['payload']['order']['profit']);
    $legacyResult = $mirror->mirrorFromBranchEvent($conn, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $legacy);
    branchRestoreFinancialAssert(($legacyResult['legacy_entity_id'] ?? '') === 'ot_head:401', 'schema-v1 order restore must remain compatible');
    $legacyFinancials = $conn->query('SELECT fat_tax, profit FROM ot_head WHERE id = 401')->fetch_assoc();
    branchRestoreFinancialAssert($legacyFinancials['fat_tax'] === null, 'schema-v1 missing tax must remain unknown');
    branchRestoreFinancialAssert($legacyFinancials['profit'] === null, 'schema-v1 missing profit must remain unknown');

    echo "branch-restore-financial-bundle-ok db={$dbName}\n";
} finally {
    $conn->close();
    mysqli_report(MYSQLI_REPORT_OFF);
    $admin->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $admin->close();
}

function branchRestoreFinancialSchema(mysqli $conn): void
{
    $statements = [
        "CREATE TABLE acc_head (
            id INT PRIMARY KEY, code VARCHAR(20) NOT NULL, deletable INT DEFAULT 1, editable INT DEFAULT 1,
            aname VARCHAR(50) NOT NULL, phone VARCHAR(200) NULL, address VARCHAR(200) NULL, e_mail VARCHAR(100) NULL,
            constant INT DEFAULT 0, is_stock INT DEFAULT 0, is_fund INT DEFAULT 0, rentable INT NULL,
            parent_id INT NOT NULL DEFAULT 0, nature INT NULL, kind INT NULL, is_basic INT NOT NULL DEFAULT 0,
            balance DECIMAL(24,6) NOT NULL DEFAULT 0, secret INT NOT NULL DEFAULT 0, info VARCHAR(250) NULL,
            isdeleted TINYINT NOT NULL DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE ot_head (
            id INT PRIMARY KEY, uuid CHAR(36) NULL, pro_id VARCHAR(100) NULL, pro_tybe INT NULL,
            order_type VARCHAR(50) NULL, table_id INT NULL, info VARCHAR(250) NULL, pro_date DATE NULL,
            crtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, mdtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL, payment_date DATETIME NULL, pro_value DECIMAL(19,2) DEFAULT 0,
            fat_total DECIMAL(19,4) NULL DEFAULT NULL, fat_net DECIMAL(19,2) DEFAULT 0, fat_disc DECIMAL(19,2) DEFAULT 0,
            fat_tax DECIMAL(19,4) NULL DEFAULT NULL, profit DECIMAL(19,6) NULL DEFAULT NULL,
            paid_amount DECIMAL(19,2) DEFAULT 0, remaining_amount DECIMAL(19,2) DEFAULT 0,
            payment_status VARCHAR(50) NULL, invoice_status VARCHAR(50) NULL, order_status VARCHAR(50) NULL,
            isdeleted TINYINT DEFAULT 0, closed TINYINT DEFAULT 0, user INT NULL, waiter_id INT NULL,
            tenant INT DEFAULT 0, branch INT DEFAULT 0, store_id INT NULL, acc1 INT NULL, acc2 INT NULL,
            is_journal TINYINT DEFAULT 0, journal_tybe INT NULL, emp_id INT NULL, op2 INT DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE journal_heads (
            id INT PRIMARY KEY, journal_id INT NOT NULL, total DECIMAL(19,2) NOT NULL, jdate DATE NOT NULL,
            op_id INT NULL, pro_tybe INT NULL, details VARCHAR(250) NULL, op2 INT DEFAULT 0, isdeleted TINYINT DEFAULT 0,
            user INT NULL, tenant INT DEFAULT 0, branch INT DEFAULT 0, source_type VARCHAR(64) NULL,
            source_id BIGINT NULL, posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL,
            reversal_of_journal_id BIGINT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE journal_entries (
            id INT PRIMARY KEY, journal_id INT NOT NULL, account_id INT NOT NULL, debit DECIMAL(19,2) NOT NULL DEFAULT 0,
            credit DECIMAL(19,2) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 INT DEFAULT 0,
            op_id INT DEFAULT 0, isdeleted TINYINT DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE order_payments (
            id INT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(15,4) NOT NULL,
            payment_method VARCHAR(50) NULL, reference_no VARCHAR(100) NULL, paid_by_customer_id INT NULL,
            created_by INT NULL, is_voided TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
    ];
    foreach ($statements as $statement) {
        $conn->query($statement);
    }
}

function branchRestoreFinancialEvent(): array
{
    $bundle = [
        'schema_version' => 1,
        'scope' => 'pos_order',
        'complete' => true,
        'local_order_id' => 400,
        'accounts' => [
            ['id' => 1, 'code' => '1', 'aname' => 'Assets', 'parent_id' => 0, 'is_basic' => 1],
            ['id' => 10, 'code' => '111', 'aname' => 'Cash', 'parent_id' => 1, 'is_basic' => 0],
            ['id' => 11, 'code' => '31', 'aname' => 'Sales', 'parent_id' => 0, 'is_basic' => 0],
        ],
        'journal_heads' => [[
            'id' => 100,
            'journal_id' => 900,
            'total' => '50.00',
            'jdate' => '2026-07-16',
            'op_id' => 400,
            'op2' => 400,
            'details' => 'Restored invoice 400',
            'source_type' => 'invoice',
            'source_id' => 400,
            'posting_kind' => 'invoice_finalization',
            'idempotency_key' => 'restore-order-400',
        ]],
        'journal_entries' => [
            ['id' => 1000, 'journal_id' => 100, 'account_id' => 10, 'debit' => '50.00', 'credit' => '0.00', 'tybe' => 0, 'op2' => 400],
            ['id' => 1001, 'journal_id' => 100, 'account_id' => 11, 'debit' => '0.00', 'credit' => '50.00', 'tybe' => 1, 'op2' => 400],
        ],
        'totals' => ['journal_count' => 1, 'entry_count' => 2, 'debit' => '50.00', 'credit' => '50.00'],
    ];
    $bundle['bundle_hash'] = hash('sha256', json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $orderUuid = PosOrderSnapshotBuilder::deterministicUuid('aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'ot_head:400');
    return [
        'event_uuid' => SyncBranchIdentity::generateUuidV4(),
        'event_type' => 'order.saved',
        'event_version' => 1,
        'aggregate_type' => 'order',
        'aggregate_uuid' => $orderUuid,
        'entity_type' => 'order',
        'entity_uuid' => $orderUuid,
        'payload' => [
            'schema_version' => 2,
            'order_uuid' => $orderUuid,
            'local_order_id' => 400,
            'order' => [
                'order_uuid' => $orderUuid,
                'local_order_id' => 400,
                'pro_id' => 'POS-400',
                'pro_tybe' => 9,
                'order_type' => 'takeaway',
                'pro_date' => '2026-07-16',
                'pro_value' => '50.00',
                'fat_total' => '50.00',
                'fat_net' => '50.00',
                'fat_tax' => '1.2345',
                'profit' => '12.345678',
                'paid_amount' => '50.00',
                'remaining_amount' => '0.00',
                'payment_status' => 'paid',
                'invoice_status' => 'completed',
                'order_status' => 'completed',
                'sync_revision' => 1,
                'legacy' => ['acc1' => 10, 'acc2' => 11],
            ],
            'lines' => [],
            'payments' => [[
                'payment_uuid' => PosOrderSnapshotBuilder::deterministicUuid($orderUuid, 'order_payment:200'),
                'order_uuid' => $orderUuid,
                'source' => 'ot_head',
                'local_payment_id' => 200,
                'amount' => '50.00',
                'payment_method' => 'cash',
                'paid_by_customer_id' => 11,
                'created_by' => 1,
                'voided' => 0,
            ], [
                'payment_uuid' => PosOrderSnapshotBuilder::deterministicUuid($orderUuid, 'order_payments:300'),
                'order_uuid' => $orderUuid,
                'source' => 'order_payments',
                'local_payment_id' => 300,
                'amount' => '50.00',
                'payment_method' => 'cash',
                'paid_by_customer_id' => 11,
                'created_by' => 1,
                'voided' => 0,
            ]],
            'receipts' => [[
                'receipt_uuid' => PosOrderSnapshotBuilder::deterministicUuid($orderUuid, 'payment_receipt:200'),
                'order_uuid' => $orderUuid,
                'local_receipt_id' => 200,
                'local_order_id' => 400,
                'pro_id' => 'RCPT-400',
                'pro_tybe' => 1,
                'amount' => '50.00',
                'acc_fund' => 10,
                'acc_customer' => 11,
                'created_by' => 1,
                'payment_date' => '2026-07-16 12:00:00',
            ]],
            'financial_bundle' => $bundle,
        ],
    ];
}

function branchRestoreFinancialCount(mysqli $conn, string $table): int
{
    return (int) ($conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'] ?? 0);
}

function branchRestoreFinancialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
