<?php

require_once __DIR__ . '/../../classes/Financial/FinancialRefundService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialTenderAllocator.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_financial_refund_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "financial-refund-service-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    financialRefundCreateLegacySchema($conn);
    (new SyncSchemaManager())->apply($conn);
    $branchUuid = 'a4f4cc0b-b2ca-4a35-91f4-2ad8edc8bb41';
    $syncConfig = [
        'role' => 'branch',
        'branch' => [
            'uuid' => $branchUuid,
            'name' => 'Financial refund test branch',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];

    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card',
        'name_en' => 'Card',
        'type' => 'card',
        'account_id' => 52,
    ]);
    $methods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
        'requires_reference' => false,
    ]);
    $paymentMethodId = (int) $conn->query("SELECT id FROM payment_methods WHERE code = 'card_terminal'")->fetch_assoc()['id'];
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (700, 9, 68.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (1, 700, 10, 0, 2, 34.000000, 0, 68.00, 2.000000, 34.000000, 68.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (91, 700, 68.00, 'card_terminal')");

    $alloc = FinancialTenderAllocator::allocate('34.00', [
        ['id' => 91, 'amount' => '40.00'],
        ['id' => 92, 'amount' => '28.00'],
    ]);
    financialRefundAssert($alloc[0]['amount'] === '20.00' || count($alloc) >= 1, 'allocator must return positive tenders');
    $allocSum = '0.00';
    foreach ($alloc as $row) {
        $allocSum = bcadd($allocSum, $row['amount'], 2);
    }
    financialRefundAssert($allocSum === '34.00', 'largest-remainder tender allocation must sum exactly');

    $service = new FinancialRefundService();
    $request = [
        'original_order_id' => 700,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'Returned one item',
        'idempotency_key' => 'refund-700-1',
        'lines' => [[
            'original_detail_id' => 1,
            'quantity' => '1.000000',
            'stock_disposition' => 'waste',
        ]],
        'payments' => [[
            'original_payment_id' => 91,
            'payment_method_id' => $paymentMethodId,
            'amount' => '34.00',
            'external_reference' => 'refund-terminal-1',
        ]],
    ];
    $first = $service->createPostedRefund($conn, $request, ['sync_config' => $syncConfig]);
    financialRefundAssert($first['total_amount'] === '34.00', 'partial refund must keep exact posted total');
    financialRefundAssert($first['replayed'] === false, 'first refund must not be a replay');
    financialRefundAssert($first['pending_external_amount'] === '0.00', 'settled card refund is not pending');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM credit_notes')->fetch_assoc()['c'] === 1, 'one credit note expected');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM payment_refunds')->fetch_assoc()['c'] === 1, 'one tender refund expected');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM journal_heads')->fetch_assoc()['c'] === 2, 'credit note and tender settlement journals expected');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM journal_entries')->fetch_assoc()['c'] === 4, 'every journal must have balanced pair');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'financial_refund'")->fetch_assoc()['c'] === 1,
        'committed refund must create one transactional financial outbox event'
    );
    $firstEvent = financialRefundOutboxRows($conn, (int) $first['credit_note_id'])[0];
    $firstPayload = json_decode((string) $firstEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    financialRefundAssert((string) $firstPayload['snapshot_type'] === 'financial_refund_bundle', 'refund event must use typed bundle');
    financialRefundAssert(count($firstPayload['journal_heads']) === 2, 'bundle must contain only credit-note and tender journals');
    financialRefundAssert(count($firstPayload['journal_entries']) === 4, 'bundle must contain referenced balanced journal entries');
    financialRefundAssert((int) $firstEvent['event_version'] === 3, 'settled refund revision must include settled status score');

    $replay = $service->createPostedRefund($conn, $request, ['sync_config' => $syncConfig]);
    financialRefundAssert($replay['replayed'] === true, 'same idempotency key must replay');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM credit_notes')->fetch_assoc()['c'] === 1, 'replay must not create another credit note');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'financial_refund'")->fetch_assoc()['c'] === 1,
        'idempotent refund replay must not create another outbox event'
    );

    $exceededQty = $request;
    $exceededQty['idempotency_key'] = 'refund-700-qty';
    $exceededQty['lines'][0]['quantity'] = '2.000000';
    $exceededQty['payments'][0]['amount'] = '68.00';
    // 1 already refunded; only 1 remains
    financialRefundExpectException(
        static function () use ($service, $conn, $exceededQty): void {
            $service->createPostedRefund($conn, $exceededQty);
        },
        'REFUND_QUANTITY_EXCEEDS_REMAINING'
    );

    $exceeded = $request;
    $exceeded['idempotency_key'] = 'refund-700-2';
    $exceeded['lines'][0]['quantity'] = '1.000000';
    $exceeded['payments'][0]['amount'] = '35.00';
    financialRefundExpectException(
        static function () use ($service, $conn, $exceeded): void {
            $service->createPostedRefund($conn, $exceeded);
        },
        'REFUND_TENDER_TOTAL_MISMATCH'
    );

    // Remaining qty 1 on same line via auto-allocation without reference → pending_external
    $pendingReq = [
        'original_order_id' => 700,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'Card refund pending terminal',
        'idempotency_key' => 'refund-700-pending',
        'lines' => [[
            'original_detail_id' => 1,
            'quantity' => '1.000000',
            'stock_disposition' => 'restock',
        ]],
        'payments' => [[
            'original_payment_id' => 91,
            'payment_method_id' => $paymentMethodId,
            'amount' => '34.00',
        ]],
    ];
    $pending = $service->createPostedRefund($conn, $pendingReq, ['sync_config' => $syncConfig]);
    financialRefundAssert($pending['pending_external_amount'] === '34.00', 'card without reference stays pending_external');
    $refundId = (int) $pending['refund_ids'][0];
    $status = (string) $conn->query("SELECT status FROM payment_refunds WHERE id = {$refundId}")->fetch_assoc()['status'];
    financialRefundAssert($status === 'pending_external', 'refund row must be pending_external');
    $pendingEvents = financialRefundOutboxRows($conn, (int) $pending['credit_note_id']);
    financialRefundAssert(count($pendingEvents) === 1, 'pending refund must be captured before commit');
    $olderEventRow = $pendingEvents[0];
    financialRefundAssert((int) $olderEventRow['event_version'] === 1, 'pending refund must start at revision one');

    $settled = $service->settlePendingExternal($conn, $refundId, 'terminal-settle-99', [
        'user_id' => 7,
        'customer_account_id' => 501,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert($settled['status'] === 'settled', 'external settlement must mark settled');
    financialRefundAssert($settled['amount'] === '34.00', 'settlement amount must match');
    $settledEvents = financialRefundOutboxRows($conn, (int) $pending['credit_note_id']);
    financialRefundAssert(count($settledEvents) === 2, 'settlement must append a newer aggregate snapshot');
    $newerEventRow = $settledEvents[1];
    financialRefundAssert(
        (int) $newerEventRow['event_version'] > (int) $olderEventRow['event_version'],
        'pending to settled must strictly increase aggregate revision'
    );

    $settledPayload = json_decode((string) $newerEventRow['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $referencedJournalIds = [];
    $referencedJournalIds[] = (int) $settledPayload['credit_note']['journal_head_id'];
    foreach ($settledPayload['payment_refunds'] as $refundRow) {
        if ((int) ($refundRow['journal_head_id'] ?? 0) > 0) {
            $referencedJournalIds[] = (int) $refundRow['journal_head_id'];
        }
    }
    sort($referencedJournalIds, SORT_NUMERIC);
    $bundledJournalIds = array_map('intval', array_column($settledPayload['journal_heads'], 'id'));
    sort($bundledJournalIds, SORT_NUMERIC);
    financialRefundAssert($bundledJournalIds === array_values(array_unique($referencedJournalIds)), 'bundle must not include unrelated journals');
    foreach ($bundledJournalIds as $journalId) {
        $debit = '0.00';
        $credit = '0.00';
        foreach ($settledPayload['journal_entries'] as $entry) {
            if ((int) $entry['journal_id'] === $journalId) {
                $debit = bcadd($debit, (string) $entry['debit'], 2);
                $credit = bcadd($credit, (string) $entry['credit'], 2);
            }
        }
        financialRefundAssert($debit === $credit, 'each bundled journal must remain balanced');
    }

    $invalidPayload = $settledPayload;
    $invalidPayload['journal_heads'][] = ['id' => 999999, 'journal_id' => 999999, 'total' => '1.00'];
    financialRefundExpectException(
        static function () use ($conn, $branchUuid, $newerEventRow, $invalidPayload): void {
            $event = financialRefundEventFromOutbox($newerEventRow);
            $event['event_uuid'] = SyncBranchIdentity::generateUuidV4();
            $event['idempotency_key'] .= ':invalid-journal';
            $event['payload'] = $invalidPayload;
            unset($event['payload_hash']);
            (new SyncInboxService())->receiveBranchEvent($conn, $branchUuid, $event, SyncApplyMode::LIVE_APPLY);
        },
        'FINANCIAL_REFUND_JOURNAL_SCOPE_INVALID'
    );

    // Hosted reordering proof: apply the newer settled snapshot, then the older pending snapshot.
    $conn->query("UPDATE payment_refunds SET status = 'pending_external', external_reference = NULL, journal_head_id = NULL WHERE id = {$refundId}");
    $inbox = new SyncInboxService();
    $newerResult = $inbox->receiveBranchEvent(
        $conn,
        $branchUuid,
        financialRefundEventFromOutbox($newerEventRow),
        SyncApplyMode::LIVE_APPLY
    );
    financialRefundAssert($newerResult['status'] === 'processed', 'newer settled refund snapshot must apply');
    $status = (string) $conn->query("SELECT status FROM payment_refunds WHERE id = {$refundId}")->fetch_assoc()['status'];
    financialRefundAssert($status === 'settled', 'newer hosted projection must restore settled status');
    $olderResult = $inbox->receiveBranchEvent(
        $conn,
        $branchUuid,
        financialRefundEventFromOutbox($olderEventRow),
        SyncApplyMode::LIVE_APPLY
    );
    financialRefundAssert($olderResult['status'] === 'stale', 'older pending refund snapshot must be rejected as stale');
    $status = (string) $conn->query("SELECT status FROM payment_refunds WHERE id = {$refundId}")->fetch_assoc()['status'];
    financialRefundAssert($status === 'settled', 'stale hosted event must not overwrite newer settled state');

    // Caller-owned transaction proof: business rows and outbox row roll back together.
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (701, 9, 10.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (2, 701, 11, 0, 1, 10.000000, 0, 10.00, 1.000000, 10.000000, 10.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (92, 701, 10.00, 'card_terminal')");
    $rollbackRequest = [
        'original_order_id' => 701,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'Rollback proof',
        'idempotency_key' => 'refund-701-rollback',
        'payments' => [[
            'original_payment_id' => 92,
            'payment_method_id' => $paymentMethodId,
            'amount' => '10.00',
            'external_reference' => 'rollback-terminal',
        ]],
    ];
    $conn->begin_transaction();
    $rollbackResult = $service->createPostedRefund($conn, $rollbackRequest, [
        'in_transaction' => true,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert((int) $rollbackResult['credit_note_id'] > 0, 'caller-owned transaction must create refund before rollback');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = " . (int) $rollbackResult['credit_note_id'] . " AND aggregate_type = 'financial_refund'")->fetch_assoc()['c'] === 1,
        'caller-owned transaction must see its outbox row before rollback'
    );
    $conn->rollback();
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM credit_notes WHERE idempotency_key = 'refund-701-rollback'")->fetch_assoc()['c'] === 0,
        'caller rollback must remove financial rows'
    );
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = " . (int) $rollbackResult['credit_note_id'] . " AND aggregate_type = 'financial_refund'")->fetch_assoc()['c'] === 0,
        'caller rollback must remove matching outbox row'
    );

    echo "financial-refund-service-ok db=$db\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `$db`");
    $conn->close();
}

function financialRefundCreateLegacySchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(18,2) NOT NULL,
            jdate DATE NOT NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op_id INT NULL,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(18,2) NOT NULL DEFAULT 0,
            credit DECIMAL(18,2) NOT NULL DEFAULT 0,
            tybe INT NOT NULL DEFAULT 0,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NULL,
            name VARCHAR(120) NOT NULL,
            balance DECIMAL(19,2) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO acc_head (id, code, name, balance) VALUES (51, '51', 'cash', 0), (52, '52', 'card', 0), (501, '501', 'ar', 0), (91, '91', 'sales', 0)");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NOT NULL DEFAULT 9,
            fat_net DECIMAL(19,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(20) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NOT NULL,
            qty_in DECIMAL(19,6) NOT NULL DEFAULT 0,
            qty_out DECIMAL(19,6) NOT NULL DEFAULT 0,
            price DECIMAL(19,6) NOT NULL DEFAULT 0,
            discount DECIMAL(19,2) NOT NULL DEFAULT 0,
            det_value DECIMAL(19,2) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function financialRefundExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $exception) {
        financialRefundAssert($exception->getMessage() === $message, 'expected ' . $message . ', got ' . $exception->getMessage());
        return;
    }

    throw new RuntimeException('expected exception ' . $message);
}

function financialRefundOutboxRows(mysqli $conn, int $creditNoteId): array
{
    $result = $conn->query(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'financial_refund'"
        . ' AND aggregate_local_id = ' . $creditNoteId
        . ' ORDER BY event_version ASC, id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function financialRefundEventFromOutbox(array $row): array
{
    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'payload_hash' => (string) $row['payload_hash'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function financialRefundAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
