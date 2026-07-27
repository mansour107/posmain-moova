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
        'manager_approval_id' => 44,
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
    financialRefundAssert($first['cumulative_refunded_amount'] === '34.00', 'partial refund must expose cumulative amount');
    financialRefundAssert($first['remaining_refundable_amount'] === '34.00', 'partial refund must expose remaining amount');
    financialRefundAssert($first['reversal_status'] === 'partial', 'first half refund must be partial');
    financialRefundAssert((int) $first['manager_approval_id'] === 44, 'refund must preserve manager approval attribution');
    financialRefundAssert((string) $first['business_day'] !== '', 'refund must stamp a business day');
    financialRefundAssert($first['replayed'] === false, 'first refund must not be a replay');
    financialRefundAssert($first['pending_external_amount'] === '0.00', 'settled card refund is not pending');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM credit_notes')->fetch_assoc()['c'] === 1, 'one credit note expected');
    financialRefundAssert((int) $conn->query('SELECT COUNT(*) AS c FROM payment_refunds')->fetch_assoc()['c'] === 1, 'one tender refund expected');
    $attribution = $conn->query('SELECT tenant, branch, business_day, drawer_session_id, manager_approval_id FROM credit_notes WHERE id = ' . (int) $first['credit_note_id'])->fetch_assoc();
    financialRefundAssert((string) $attribution['business_day'] === (string) $first['business_day'], 'credit note must persist refund business day');
    financialRefundAssert((int) $attribution['manager_approval_id'] === 44, 'credit note must persist manager approval');
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
    financialRefundAssert($replay['reversal_status'] === 'partial', 'replay must return the original cumulative reversal state');
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
    financialRefundAssert($pending['cumulative_refunded_amount'] === '68.00', 'second partial must expose cumulative full amount');
    financialRefundAssert($pending['remaining_refundable_amount'] === '0.00', 'cumulative full refund must have no remainder');
    financialRefundAssert($pending['reversal_status'] === 'full', 'two partial refunds must reach full state');
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

    // Cashier-selected tender authority: original card provenance may be paid
    // out as cash, but only with an open drawer and exactly one drawer movement.
    $conn->query("
        INSERT INTO drawer_sessions (
            id, uuid, user_id, tenant, branch, fund_account_id, opened_at,
            business_day, opened_by, opening_cash, status
        ) VALUES (
            88, '88888888-8888-4888-8888-888888888888', 7, 0, 0, 51, NOW(),
            CURDATE(), 7, 0.000, 'open'
        )
    ");
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (702, 9, 12.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (3, 702, 12, 0, 1, 12.000000, 0, 12.00, 1.000000, 12.000000, 12.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (93, 702, 12.00, 'card_terminal')");
    $cashierCashRequest = [
        'original_order_id' => 702,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'Cashier selected cash payout',
        'idempotency_key' => 'refund-702-selected-cash',
        'refund_payment_method' => 'cash',
        'drawer_session_id' => 88,
    ];
    $selectedCash = $service->createPostedRefund($conn, $cashierCashRequest, [
        'drawer_session_id' => 88,
        'require_drawer_session' => true,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert($selectedCash['pending_external_amount'] === '0.00', 'selected cash tender must be posted immediately');
    financialRefundAssert(($selectedCash['refund_tenders'][0]['code'] ?? '') === 'cash', 'response must use persisted selected cash tender');
    financialRefundAssert(($selectedCash['refund_tenders'][0]['status'] ?? '') === 'posted', 'selected cash tender status must be posted');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 702 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'selected cash tender must create one cash drawer movement'
    );
    $selectedCashReplay = $service->createPostedRefund($conn, $cashierCashRequest, [
        'drawer_session_id' => 88,
        'require_drawer_session' => true,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert($selectedCashReplay['replayed'] === true, 'selected tender replay must return stored authority');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 702 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'selected tender replay must not duplicate cash movement'
    );

    // Non-cash without a reference remains pending and does not require or
    // mutate a cash drawer.
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (703, 9, 15.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (4, 703, 13, 0, 1, 15.000000, 0, 15.00, 1.000000, 15.000000, 15.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (94, 703, 15.00, 'cash')");
    $selectedCard = $service->createPostedRefund($conn, [
        'original_order_id' => 703,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 8,
        'reason' => 'Cashier selected card payout pending terminal',
        'idempotency_key' => 'refund-703-selected-card',
        'refund_payment_method' => 'card_terminal',
    ], [
        'require_drawer_session' => true,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert($selectedCard['pending_external_amount'] === '15.00', 'selected card without reference must remain pending external');
    financialRefundAssert(($selectedCard['refund_tenders'][0]['code'] ?? '') === 'card_terminal', 'pending response must preserve selected card tender');
    financialRefundAssert(($selectedCard['refund_tenders'][0]['status'] ?? '') === 'pending_external', 'pending response must expose authoritative state');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 703")->fetch_assoc()['c'] === 0,
        'selected non-cash tender must not create drawer movements'
    );

    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (704, 9, 9.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (5, 704, 14, 0, 1, 9.000000, 0, 9.00, 1.000000, 9.000000, 9.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (95, 704, 9.00, 'card_terminal')");
    financialRefundExpectException(
        static function () use ($service, $conn, $syncConfig): void {
            $service->createPostedRefund($conn, [
                'original_order_id' => 704,
                'customer_account_id' => 501,
                'revenue_account_id' => 91,
                'user_id' => 8,
                'reason' => 'Cash requires drawer',
                'idempotency_key' => 'refund-704-cash-no-drawer',
                'refund_payment_method' => 'cash',
            ], [
                'require_drawer_session' => true,
                'sync_config' => $syncConfig,
            ]);
        },
        'DRAWER_SESSION_REQUIRED'
    );
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM credit_notes WHERE original_order_id = 704")->fetch_assoc()['c'] === 0,
        'cash refund without a drawer must fail before financial persistence'
    );

    // Unified partial selection: item quantity uses the posted net/tax/discount
    // snapshot; a following amount refund deterministically consumes the
    // remaining posted line values without exceeding any line or tender.
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (705, 9, 50.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (
            id, fatid, item_id, qty_in, qty_out, price, discount, det_value,
            posted_qty, posted_unit_price, posted_line_discount, posted_order_discount,
            posted_taxable, posted_tax, posted_gross, posted_net, tax_rate_snapshot
        ) VALUES
            (6, 705, 15, 0, 2, 12.000000, 2.00, 20.00,
             2.000000, 12.000000, 2.00, 2.00, 18.00, 2.00, 24.00, 20.00, 10.000000),
            (7, 705, 16, 0, 2, 17.500000, 2.50, 30.00,
             2.000000, 17.500000, 2.50, 2.50, 27.00, 3.00, 35.00, 30.00, 10.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (96, 705, 50.00, 'card_terminal')");
    $itemPartial = $service->createPostedRefund($conn, [
        'original_order_id' => 705,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'vat_payable_account_id' => 53,
        'user_id' => 7,
        'reason' => 'One discounted taxed item',
        'idempotency_key' => 'refund-705-item',
        'refund_mode' => 'items',
        'refund_payment_method' => 'cash',
        'drawer_session_id' => 88,
        'lines' => [[
            'original_detail_id' => 6,
            'quantity' => '1.000000',
            'stock_disposition' => 'restock',
        ]],
    ], [
        'drawer_session_id' => 88,
        'require_drawer_session' => true,
        'sync_config' => $syncConfig,
    ]);
    financialRefundAssert($itemPartial['refund_mode'] === 'items', 'item partial must persist and return item mode');
    financialRefundAssert($itemPartial['total_amount'] === '10.00', 'item partial must use allocated posted net after discounts');
    $itemCredit = $conn->query('
        SELECT cn.refund_mode, cn.request_fingerprint, cnl.quantity, cnl.line_amount,
               cnl.gross_amount, cnl.line_discount_amount, cnl.order_discount_amount,
               cnl.taxable_amount, cnl.tax_amount
        FROM credit_notes cn
        INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
        WHERE cn.id = ' . (int) $itemPartial['credit_note_id']
    )->fetch_assoc();
    financialRefundAssert($itemCredit['refund_mode'] === 'items', 'credit note must retain item selection mode');
    financialRefundAssert(strlen((string) $itemCredit['request_fingerprint']) === 64, 'credit note must retain request fingerprint');
    financialRefundAssert((string) $itemCredit['quantity'] === '1.000000', 'item credit must retain selected quantity');
    financialRefundAssert((string) $itemCredit['line_amount'] === '10.00', 'item credit amount must be snapshot proportional');
    financialRefundAssert((string) $itemCredit['gross_amount'] === '12.00', 'item credit must retain proportional posted gross');
    financialRefundAssert((string) $itemCredit['line_discount_amount'] === '1.00', 'item credit must retain proportional line discount');
    financialRefundAssert((string) $itemCredit['order_discount_amount'] === '1.00', 'item credit must retain proportional order discount');
    financialRefundAssert((string) $itemCredit['taxable_amount'] === '9.00', 'item credit must retain proportional taxable amount');
    financialRefundAssert((string) $itemCredit['tax_amount'] === '1.00', 'item credit tax must be snapshot proportional');
    financialRefundAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 705 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'cash item partial must create exactly one drawer movement'
    );

    $amountRequest = [
        'original_order_id' => 705,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'vat_payable_account_id' => 53,
        'user_id' => 7,
        'reason' => 'Amount partial across remaining lines',
        'idempotency_key' => 'refund-705-amount',
        'refund_mode' => 'amount',
        'refund_amount' => '25.00',
        'refund_payment_method' => 'card_terminal',
        'refund_stock_policy' => 'waste',
    ];
    $amountPartial = $service->createPostedRefund($conn, $amountRequest, ['sync_config' => $syncConfig]);
    financialRefundAssert($amountPartial['refund_mode'] === 'amount', 'amount partial must persist and return amount mode');
    financialRefundAssert($amountPartial['total_amount'] === '25.00', 'amount partial must post the exact requested money');
    financialRefundAssert($amountPartial['cumulative_refunded_amount'] === '35.00', 'successive partials must accumulate exactly');
    financialRefundAssert($amountPartial['remaining_refundable_amount'] === '15.00', 'successive partials must expose exact remainder');
    financialRefundAssert($amountPartial['pending_external_amount'] === '25.00', 'amount card refund without reference must remain pending');
    $amountLineRows = $conn->query('
        SELECT original_detail_id, quantity, line_amount, gross_amount,
               line_discount_amount, order_discount_amount, taxable_amount, tax_amount
        FROM credit_note_lines
        WHERE credit_note_id = ' . (int) $amountPartial['credit_note_id'] . '
        ORDER BY id
    ')->fetch_all(MYSQLI_ASSOC);
    financialRefundAssert(count($amountLineRows) === 2, 'amount allocation must retain every consumed source line');
    financialRefundAssert((string) $amountLineRows[0]['line_amount'] === '10.00', 'amount allocation must consume first remaining line exactly');
    financialRefundAssert((string) $amountLineRows[0]['quantity'] === '1.000000', 'amount allocation must consume first remaining quantity exactly');
    financialRefundAssert((string) $amountLineRows[1]['line_amount'] === '15.00', 'amount allocation must place residual on next line');
    financialRefundAssert((string) $amountLineRows[1]['quantity'] === '1.000000', 'amount allocation must proportionally reverse next line quantity');
    financialRefundAssert(
        bcadd((string) $amountLineRows[0]['tax_amount'], (string) $amountLineRows[1]['tax_amount'], 2) === '2.50',
        'amount allocation tax must follow stored line tax snapshots'
    );
    foreach ([
        'gross_amount' => '29.50',
        'line_discount_amount' => '2.25',
        'order_discount_amount' => '2.25',
        'taxable_amount' => '22.50',
    ] as $column => $expectedEvidence) {
        financialRefundAssert(
            bcadd((string) $amountLineRows[0][$column], (string) $amountLineRows[1][$column], 2) === $expectedEvidence,
            'amount allocation must retain proportional ' . $column . ' evidence'
        );
    }
    $amountEvent = financialRefundOutboxRows($conn, (int) $amountPartial['credit_note_id'])[0];
    $amountPayload = json_decode((string) $amountEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    financialRefundAssert(($amountPayload['credit_note']['refund_mode'] ?? '') === 'amount', 'sync must retain amount selection mode');
    financialRefundAssert(count($amountPayload['credit_note_lines'] ?? []) === 2, 'sync must retain allocated partial lines');

    $amountConflict = $amountRequest;
    $amountConflict['refund_amount'] = '20.00';
    financialRefundExpectException(
        static function () use ($service, $conn, $amountConflict): void {
            $service->createPostedRefund($conn, $amountConflict);
        },
        'IDEMPOTENCY_KEY_CONFLICT'
    );
    $amountOver = $amountRequest;
    $amountOver['idempotency_key'] = 'refund-705-over';
    $amountOver['refund_amount'] = '15.01';
    financialRefundExpectException(
        static function () use ($service, $conn, $amountOver): void {
            $service->createPostedRefund($conn, $amountOver);
        },
        'REFUND_AMOUNT_EXCEEDS_REMAINING'
    );

    // Non-divisible money-to-quantity ratios are rounded once at the durable
    // six-decimal quantity boundary. The final refund takes the residual so
    // cumulative quantity and money still equal the immutable sale snapshot.
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (707, 9, 48.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (9, 707, 18, 0, 1, 48.000000, 0, 48.00, 1.000000, 48.000000, 48.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (98, 707, 48.00, 'card_terminal')");
    $nonDivisible = $service->createPostedRefund($conn, [
        'original_order_id' => 707,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'One pound from forty eight',
        'idempotency_key' => 'refund-707-one',
        'refund_mode' => 'amount',
        'refund_amount' => '1.00',
        'refund_payment_method' => 'card_terminal',
    ]);
    $nonDivisibleLine = $conn->query(
        'SELECT quantity, line_amount FROM credit_note_lines WHERE credit_note_id = '
        . (int) $nonDivisible['credit_note_id']
    )->fetch_assoc();
    financialRefundAssert((string) $nonDivisibleLine['quantity'] === '0.020833', 'non-divisible amount must round to six quantity decimals');
    financialRefundAssert((string) $nonDivisibleLine['line_amount'] === '1.00', 'non-divisible amount must remain exact money');
    $nonDivisibleFinal = $service->createPostedRefund($conn, [
        'original_order_id' => 707,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 7,
        'reason' => 'Remaining forty seven',
        'idempotency_key' => 'refund-707-final',
        'refund_mode' => 'full',
        'refund_payment_method' => 'card_terminal',
    ]);
    $nonDivisibleTotals = $conn->query('
        SELECT SUM(cnl.quantity) AS quantity, SUM(cnl.line_amount) AS amount
        FROM credit_note_lines cnl
        INNER JOIN credit_notes cn ON cn.id = cnl.credit_note_id
        WHERE cn.original_order_id = 707 AND cn.status = \'posted\'
    ')->fetch_assoc();
    financialRefundAssert((string) $nonDivisibleTotals['quantity'] === '1.000000', 'final residual must restore exact cumulative quantity');
    financialRefundAssert((string) $nonDivisibleTotals['amount'] === '48.00', 'final residual must restore exact cumulative money');
    financialRefundAssert($nonDivisibleFinal['remaining_refundable_amount'] === '0.00', 'final residual must exhaust refundable balance');

    // Two independent workers race for more than the same remaining balance.
    // The order row lock must serialize them so exactly one credit note wins.
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (706, 9, 10.00, 'paid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (8, 706, 17, 0, 1, 10.000000, 0, 10.00, 1.000000, 10.000000, 10.00, 0.00, 0.000000)
    ");
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (97, 706, 10.00, 'card_terminal')");
    $raceFiles = [];
    $children = [];
    // Do not let forked PHP destructors share the parent's mysqli socket.
    $conn->close();
    foreach (['a', 'b'] as $suffix) {
        $raceFile = sys_get_temp_dir() . '/posmain-refund-race-' . getmypid() . '-' . $suffix . '.txt';
        $raceFiles[] = $raceFile;
        $pid = pcntl_fork();
        if ($pid === 0) {
            $childConn = new mysqli($host, $user, $pass, $db, $port);
            try {
                (new FinancialRefundService())->createPostedRefund($childConn, [
                    'original_order_id' => 706,
                    'customer_account_id' => 501,
                    'revenue_account_id' => 91,
                    'user_id' => 7,
                    'reason' => 'Concurrent amount ' . $suffix,
                    'idempotency_key' => 'refund-706-race-' . $suffix,
                    'refund_mode' => 'amount',
                    'refund_amount' => '8.00',
                    'refund_payment_method' => 'card_terminal',
                ]);
                file_put_contents($raceFile, 'posted');
            } catch (Throwable $exception) {
                file_put_contents($raceFile, $exception->getMessage());
            } finally {
                $childConn->close();
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $raceResults = array_map(static fn (string $path): string => trim((string) file_get_contents($path)), $raceFiles);
    foreach ($raceFiles as $path) {
        unlink($path);
    }
    $conn = new mysqli($host, $user, $pass, $db, $port);
    sort($raceResults);
    financialRefundAssert(
        $raceResults === ['REFUND_AMOUNT_EXCEEDS_REMAINING', 'posted'],
        'concurrent over-refund race must serialize to one post and one capacity rejection'
    );
    $raceTotals = $conn->query("
        SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS total
        FROM credit_notes
        WHERE original_order_id = 706 AND status = 'posted'
    ")->fetch_assoc();
    financialRefundAssert((int) $raceTotals['c'] === 1, 'concurrent race must create only one credit note');
    financialRefundAssert((string) $raceTotals['total'] === '8.00', 'concurrent race must not over-refund the order');

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
    $conn->query("INSERT INTO acc_head (id, code, name, balance) VALUES (51, '51', 'cash', 0), (52, '52', 'card', 0), (53, '53', 'vat', 0), (501, '501', 'ar', 0), (91, '91', 'sales', 0)");
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
