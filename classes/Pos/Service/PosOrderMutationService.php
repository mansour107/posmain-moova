<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/TableStateService.php';
require_once __DIR__ . '/InventoryMovementService.php';
require_once __DIR__ . '/OrderEventService.php';
require_once __DIR__ . '/IdempotencyService.php';
require_once __DIR__ . '/ItemAvailabilityService.php';
require_once __DIR__ . '/ManagerApprovalService.php';
require_once __DIR__ . '/ModifierLineNoteService.php';
require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../Moova/MoovaNewOrderApplyService.php';
require_once __DIR__ . '/../../Moova/MoovaChangeOrderApplyService.php';

class PosOrderMutationService
{
    const SCOPE_TABLE_SAVE = 'pos.table.save';
    const SCOPE_TABLE_PAYMENT = 'pos.payment.table';
    const SCOPE_SPLIT_PAYMENT = 'pos.payment.split';
    const SCOPE_ORDER_CANCEL = 'pos.order.cancel';
    const SCOPE_TAKEAWAY_CREATE = 'pos.order.create.takeaway';
    const SCOPE_MOOVA_CONFIRM = 'moova.order.confirm';
    const SCOPE_MOOVA_CHANGE = 'moova.order.change';
    const PAYMENT_ROUNDING_TOLERANCE = 0.01;

    private $paymentService;
    private $tableStateService;
    private $tableOrderService;
    private $inventoryMovementService;
    private $orderEventService;
    private $idempotencyService;
    private $itemAvailabilityService;
    private $managerApprovalService;
    private $modifierLineNoteService;

    public function __construct(?PaymentService $paymentService = null, ?TableStateService $tableStateService = null, ?TableOrderService $tableOrderService = null, ?InventoryMovementService $inventoryMovementService = null, ?OrderEventService $orderEventService = null, ?IdempotencyService $idempotencyService = null, ?ItemAvailabilityService $itemAvailabilityService = null, ?ManagerApprovalService $managerApprovalService = null, ?ModifierLineNoteService $modifierLineNoteService = null)
    {
        $this->paymentService = $paymentService ?: new PaymentService();
        $this->tableStateService = $tableStateService ?: new TableStateService();
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
        $this->inventoryMovementService = $inventoryMovementService ?: new InventoryMovementService();
        $this->orderEventService = $orderEventService ?: new OrderEventService();
        $this->idempotencyService = $idempotencyService ?: new IdempotencyService();
        $this->itemAvailabilityService = $itemAvailabilityService ?: new ItemAvailabilityService();
        $this->managerApprovalService = $managerApprovalService ?: new ManagerApprovalService();
        $this->modifierLineNoteService = $modifierLineNoteService ?: new ModifierLineNoteService();
    }

    public function payTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $result = $this->paymentService->payTableOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->recordOrderEvent($conn, $orderId, 'order.payment_recorded', $context['event_source'] ?? 'pos_table_payment', $context, [
                'payment_status' => $result['data']['payment_status'] ?? null,
                'order_status' => $result['data']['order_status'] ?? null,
                'paid_amount' => $result['data']['paid_amount'] ?? null,
                'remaining_amount' => $result['data']['remaining_amount'] ?? null,
                'applied_amount' => $result['data']['applied_amount'] ?? null,
            ]);
        }

        return $result;
    }

    public function cancelTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $result = $this->tableStateService->cancelActiveOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->recordOrderEvent($conn, $orderId, 'order.cancelled', $context['event_source'] ?? 'pos_order_cancel', $context, [
                'table_id' => $result['data']['table_id'] ?? null,
                'table_freed' => $result['data']['table_freed'] ?? null,
                'reason' => $request['reason'] ?? $request['cancellation_reason'] ?? null,
            ]);
        }

        return $result;
    }

    public function saveTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->saveTableOrderInsideTransaction($conn, $request, $context);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    public function splitTablePayment(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->splitTablePaymentInsideTransaction($conn, $request, $context);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    public function createTakeawayOrder(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_TAKEAWAY_CREATE, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->createTakeawayOrderInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete($conn, self::SCOPE_TAKEAWAY_CREATE, $idempotency['key'], $idempotency['hash'], $result);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    public function confirmMoovaOrder(mysqli $conn, array $request, array $context = []): array
    {
        $link = $request['link'] ?? $context['link'] ?? null;
        $payload = $request['payload'] ?? $request;
        if (!is_array($link)) {
            throw new InvalidArgumentException('MOOVA_LINK_REQUIRED');
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('INVALID_PAYLOAD');
        }

        return (new MoovaNewOrderApplyService())->applyInTransaction($conn, $link, $payload, $context);
    }

    public function changeMoovaOrder(mysqli $conn, array $request, array $context = []): array
    {
        $link = $request['link'] ?? $context['link'] ?? null;
        $payload = $request['payload'] ?? $request;
        if (!is_array($link)) {
            throw new InvalidArgumentException('MOOVA_LINK_REQUIRED');
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('INVALID_PAYLOAD');
        }

        $result = (new MoovaChangeOrderApplyService())->applyInTransaction($conn, $link, $payload, $context);
        if (
            strtolower((string) ($context['action'] ?? $payload['action'] ?? '')) === 'cancel'
            && (string) ($result['status'] ?? '') === 'applied'
        ) {
            $tableId = (int) ($result['response']['tableId'] ?? $result['response']['table_id'] ?? 0);
            if ($tableId > 0) {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
            }
        }

        return $result;
    }

    private function beginIdempotency(mysqli $conn, string $scope, array $request, array $context): array
    {
        $key = $this->idempotencyService->resolveKey($request, [], $context);
        $hash = $this->idempotencyService->requestHashForPayload($request);
        $begin = $this->idempotencyService->begin($conn, $scope, $key, $hash, [
            'user_id' => $context['user_id'] ?? $request['user_id'] ?? null,
            'tenant' => $context['tenant'] ?? $request['tenant'] ?? 0,
            'branch' => $context['branch'] ?? $request['branch'] ?? 0,
            'stale_after_seconds' => $context['idempotency_stale_after_seconds'] ?? 300,
        ]);

        if (($begin['status'] ?? '') === 'conflict') {
            throw new RuntimeException('IDEMPOTENCY_CONFLICT');
        }
        if (!in_array($begin['status'] ?? '', ['started', 'reclaimed', 'completed'], true)) {
            throw new RuntimeException('IDEMPOTENCY_PROCESSING');
        }

        return [
            'key' => $key,
            'hash' => $hash,
            'status' => $begin['status'],
            'response' => $begin['response'] ?? null,
        ];
    }

    private function createTakeawayOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات مطلوبة مفقودة - المخزن');
        $customerId = $this->requiredPositiveInt($request, 'acc2_id', 'بيانات مطلوبة مفقودة - العميل');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات مطلوبة مفقودة - الموظف');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات مطلوبة مفقودة - الصندوق');
        $items = $this->normalizeTakeawayItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date']);
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = (float) ($request['headtotal'] ?? $request['total'] ?? 0);
        $headDiscount = (float) ($request['headdisc'] ?? $request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, null, $headDiscount, $request, $context);
        $headPlus = (float) ($request['headplus'] ?? $request['plus'] ?? 0);
        $headNet = (float) ($request['headnet'] ?? $request['net'] ?? max(0, $headTotal - $headDiscount + $headPlus));
        if ($headNet < 0) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $paidCash = max(0, (float) ($request['paid_cash'] ?? $request['paid'] ?? 0));
        $paidBank = max(0, (float) ($request['paid_bank'] ?? 0));
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($payment['cash'] > 0 && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($payment['bank'] > 0 && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = $this->nextInvoiceProId($conn, InventoryMovementService::TYPE_POS, 0, 0);
        $info = $this->tableOrderService->buildInfo('takeaway', '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $headTotal > 0 && $headDiscount > 0 ? round($headDiscount / $headTotal * 100, 2) : 0.0;
        $fatPlusPer = $headTotal > 0 && $headPlus > 0 ? round($headPlus / $headTotal * 100, 2) : 0.0;

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount,
                table_id, order_type, payment_status, invoice_status, order_status,
                paid_amount, remaining_amount, waiter_id, payment_date, completed_at
            ) VALUES (
                ?, 9, 1, 1, 9, ?, ?,
                ?, 1, ?, 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?,
                0, 0, ?, ?, ?, ?, ?,
                NULL, 'takeaway', ?, ?, ?,
                ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
            )
        ", [
            $proId,
            $info,
            $date,
            $accrualDate,
            trim((string) ($request['pro_serial'] ?? '')),
            $storeId,
            $empId,
            $empId,
            $fundId,
            $customerId,
            $headTotal,
            $headTotal,
            $headDiscount,
            $fatDiscPer,
            $headPlus,
            $fatPlusPer,
            $headNet,
            $userId,
            $this->nullableString($request['jal_name'] ?? null),
            $this->nullableString($request['jal_notes'] ?? null),
            (float) ($request['jal_amount'] ?? 0),
            $status['payment_status'],
            $status['invoice_status'],
            $status['order_status'],
            $status['paid_amount'],
            $status['remaining_amount'],
            $empId,
            $status['payment_status'],
            $status['order_status'],
        ]);
        $orderId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        $salesJournal = $this->insertTakeawaySalesJournal($conn, $orderId, $proId, $headNet, $date, $customerId, $userId);
        $receipts = [];
        if ($payment['cash'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
        }
        if ($payment['bank'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
        }

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        foreach ($lineResult['lines'] as $index => $line) {
            $line['note'] = $this->lineNoteFromItem($items[$index] ?? []);
            $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context);
        }
        $this->tableOrderService->execute($conn, "UPDATE ot_head SET profit = ? WHERE id = ?", [
            (float) $lineResult['totals']['profit'],
            $orderId,
        ]);

        $outboxResult = null;
        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            $syncOutbox = new SyncOutboxEventService();
            $options = [
                'event_type' => 'order.saved',
                'source_system' => 'pos_cashier',
            ];
            if (isset($context['config']) && is_array($context['config'])) {
                $options['config'] = $context['config'];
            }
            $outboxResult = $syncOutbox->recordOrderSnapshot($conn, $orderId, $options);
        }

        $this->recordOrderEvent($conn, $orderId, 'order.saved', $context['event_source'] ?? 'pos_cashier', $context, [
            'order_type' => 'takeaway',
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'line_count' => count($lineResult['lines']),
            'outbox_id' => $outboxResult['outbox_id'] ?? null,
        ]);

        $this->tableOrderService->execute($conn, "INSERT INTO process (type) VALUES ('add cash')");

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'TAKEAWAY_ORDER_CREATED',
            'data' => [
                'order_id' => $orderId,
                'pro_id' => $proId,
                'payment_status' => $status['payment_status'],
                'invoice_status' => $status['invoice_status'],
                'order_status' => $status['order_status'],
                'paid_amount' => $status['paid_amount'],
                'remaining_amount' => $status['remaining_amount'],
                'profit' => (float) $lineResult['totals']['profit'],
                'journal_head_id' => $salesJournal['journal_head_id'],
                'journal_id' => $salesJournal['journal_id'],
                'receipt_ids' => array_column($receipts, 'receipt_id'),
                'outbox_id' => $outboxResult['outbox_id'] ?? null,
            ],
        ];
    }

    private function normalizeTakeawayItems(array $request): array
    {
        if (isset($request['items']) && is_array($request['items']) && $request['items']) {
            return $request['items'];
        }

        if (!isset($request['itmname']) || !is_array($request['itmname'])) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        $items = [];
        foreach ($request['itmname'] as $index => $itemId) {
            if ((int) $itemId <= 0) {
                continue;
            }
            $items[] = [
                'item_id' => (int) $itemId,
                'qty' => (float) ($request['itmqty'][$index] ?? 1),
                'price' => (float) ($request['itmprice'][$index] ?? 0),
                'discount' => (float) ($request['itmdisc'][$index] ?? 0),
                'u_val' => (float) ($request['u_val'][$index] ?? 1),
                'note' => (string) ($request['itmnote'][$index] ?? ''),
            ];
        }

        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        return $items;
    }

    private function calculateTakeawayPayment(float $headNet, float $paidCash, float $paidBank): array
    {
        $totalPaid = $paidCash + $paidBank;
        $change = max(0, $totalPaid - $headNet);
        $cash = max(0, $paidCash - $change);
        $bank = $paidBank;

        if ($change > $paidCash) {
            $remainingChange = $change - $paidCash;
            $cash = 0;
            $bank = max(0, $paidBank - $remainingChange);
        }

        $applied = min($headNet, max(0, $cash + $bank));

        return [
            'cash' => $cash,
            'bank' => $bank,
            'applied' => $applied,
            'change' => $change,
        ];
    }

    private function paidStatusForNet(float $headNet, float $paidAmount): array
    {
        $appliedPaid = min(max(0, $paidAmount), max(0, $headNet));
        $remaining = max(0, $headNet - $appliedPaid);
        if ($appliedPaid <= 0) {
            $paymentStatus = 'unpaid';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        } elseif ($remaining <= 0.0001) {
            $paymentStatus = 'paid';
            $invoiceStatus = 'completed';
            $orderStatus = 'completed';
        } else {
            $paymentStatus = 'partial';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        }

        return [
            'paid_amount' => $appliedPaid,
            'remaining_amount' => $remaining,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function insertTakeawaySalesJournal(mysqli $conn, int $orderId, int $proId, float $amount, string $date, int $customerId, int $userId): array
    {
        $journalId = $this->tableOrderService->nextJournalId($conn, 0, 0);
        $details = 'فاتورة ريسيت _ ' . $orderId;
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$journalId, $amount, $date, $details, $userId, $orderId]);
        $journalHeadId = (int) $conn->insert_id;

        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
            VALUES (?, ?, ?, 0, 0, ?)
        ", [$journalHeadId, $customerId, $amount, $orderId]);
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
            VALUES (?, 91, 0, ?, 1, ?)
        ", [$journalHeadId, $amount, $orderId]);

        return [
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
            'pro_id' => $proId,
        ];
    }

    private function insertTakeawayReceipt(mysqli $conn, int $orderId, int $proId, string $info, string $date, int $empId, int $fundAccountId, int $customerId, float $amount, string $methodLabel, int $userId): array
    {
        $receiptProId = $this->nextInvoiceProId($conn, 1, 0, 0);
        $receiptInfo = $info . ' - دفع ' . $methodLabel;
        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
            ) VALUES (?, 1, 1, 1, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
        ", [$receiptProId, $receiptInfo, $date, $empId, $fundAccountId, $customerId, $amount, $userId, $orderId]);
        $receiptId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $receiptId);

        $journalId = $this->tableOrderService->nextJournalId($conn, 0, 0);
        $details = 'سند قبض ' . $methodLabel . ' _ ' . $proId;
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [$journalId, $receiptId, $amount, $date, $details, $userId, $orderId]);
        $journalHeadId = (int) $conn->insert_id;

        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, ?, 0, 0, ?)
        ", [$journalHeadId, $fundAccountId, $amount, $orderId]);
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, 0, ?, 1, ?)
        ", [$journalHeadId, $customerId, $amount, $orderId]);

        return [
            'receipt_id' => $receiptId,
            'receipt_pro_id' => $receiptProId,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
        ];
    }

    private function insertTakeawayDetailLine(mysqli $conn, int $orderId, int $storeId, array $line, array $context = []): void
    {
        $this->tableOrderService->execute($conn, "
            INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
            ) VALUES (9, ?, ?, ?, ?, ?, ?, ?, ?, ?, 9, ?, ?, ?)
        ", [
            $orderId,
            (int) $line['item_id'],
            (float) $line['u_val'],
            (float) $line['qty_in'],
            (float) $line['qty_out'],
            (float) $line['price'],
            (float) $line['discount'],
            (float) $line['det_value'],
            $orderId,
            $storeId,
            (float) $line['cost_price'],
            (float) $line['profit'],
        ]);
        $detailId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
        $this->persistLineNoteIfAvailable($conn, $orderId, $detailId, (int) $line['item_id'], $line['note'] ?? '', $context);
    }

    private function nextInvoiceProId(mysqli $conn, int $invoiceType, int $tenant, int $branch): int
    {
        return $this->tableOrderService->nextPosProId($conn, $invoiceType, $tenant, $branch);
    }

    private function requestDate(array $request, array $keys, ?string $default = null): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($request[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $default ?: date('Y-m-d');
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function saveTableOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $tableId = $this->requiredPositiveInt($request, 'table_id', 'الرجاء اختيار طاولة');
        $orderId = (int) ($request['order_id'] ?? 0);
        $orderDate = trim((string) ($request['order_date'] ?? date('Y-m-d')));
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $items = $this->requiredItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $total = (float) ($request['total'] ?? 0);
        $discount = (float) ($request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, $orderId > 0 ? $orderId : null, $discount, $request, $context);
        $net = (float) ($request['net'] ?? max(0, $total - $discount));
        $userId = $this->contextUserId($request, $context);
        $isUpdate = $orderId > 0;

        $table = $this->tableOrderService->requireTable($conn, $tableId);
        $existingPaid = 0.0;
        if ($orderId > 0) {
            $activeOrder = $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
            if (!$activeOrder) {
                throw new RuntimeException('الطلب المحدد لا يخص هذه الطاولة أو لم يعد نشطاً');
            }
            $existingPaid = (float) ($activeOrder['paid_amount'] ?? 0);
        } else {
            $existingOrder = $this->tableOrderService->findActiveOrderByTableId($conn, $tableId, true);
            if ($existingOrder) {
                throw new RuntimeException('هذه الطاولة لديها طلب نشط بالفعل. أعد تحميل الطلب قبل الحفظ.');
            }
        }

        $clientId = $this->resolveDefaultClientId($conn);
        $info = $this->tableOrderService->buildInfo('table', $table['tname'] ?? '', '');
        if ($orderId > 0) {
            $this->updateTableOrderHeader(
                $conn,
                $orderId,
                $tableId,
                $orderDate,
                $storeId,
                $empId,
                $fundId,
                $clientId,
                $total,
                $discount,
                $net,
                $info
            );
            $this->tableOrderService->execute($conn, "UPDATE fat_details SET isdeleted = 1 WHERE fatid = ?", [$orderId]);
        } else {
            $orderId = $this->insertTableOrderHeader(
                $conn,
                $tableId,
                $orderDate,
                $storeId,
                $empId,
                $fundId,
                $clientId,
                $total,
                $discount,
                $net,
                $info,
                $userId
            );
        }
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        $this->insertTableOrderItems($conn, $orderId, $storeId, $items, $context);
        $totals = $this->tableOrderService->recalculateOrderTotals($conn, $orderId);
        $status = $this->applyPaidState($conn, $orderId, $tableId, $existingPaid, (float) $totals['net']);

        if ($status['order_status'] === 'completed') {
            $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
        } else {
            $this->tableOrderService->markTableOccupied($conn, $tableId);
        }

        $this->recordOrderEvent($conn, $orderId, $isUpdate ? 'order.updated' : 'order.saved', $context['event_source'] ?? 'pos_table_save', $context, [
            'table_id' => $tableId,
            'is_update' => $isUpdate,
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'invoice_status' => $status['invoice_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'net' => (float) $totals['net'],
        ]);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'TABLE_ORDER_SAVED',
            'data' => [
                'order_id' => $orderId,
                'table_id' => $tableId,
                'is_update' => $isUpdate,
                'payment_status' => $status['payment_status'],
                'order_status' => $status['order_status'],
                'invoice_status' => $status['invoice_status'],
                'remaining_amount' => $status['remaining_amount'],
                'paid_amount' => $status['paid_amount'],
                'total' => (float) $totals['total'],
                'net' => (float) $totals['net'],
            ],
        ];
    }

    private function splitTablePaymentInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $originalOrderId = (int) ($request['order_id'] ?? $request['original_order_id'] ?? 0);
        $tableId = (int) ($request['table_id'] ?? 0);
        $splitRequests = $this->normalizeSplitRequests($request['items'] ?? []);
        $selectedItems = array_keys($splitRequests);
        $paidAmount = (float) ($request['paid_amount'] ?? $request['paid'] ?? 0);
        $paymentMethod = trim((string) ($request['payment_method'] ?? 'cash'));
        $userId = $this->contextUserId($request, $context);

        if ($originalOrderId <= 0 || $tableId <= 0 || !$selectedItems || $paidAmount <= 0) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $this->tableOrderService->requireTable($conn, $tableId);
        $originalOrder = $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $originalOrderId, true);
        if (!$originalOrder) {
            throw new RuntimeException('الطلب الأصلي غير موجود أو لم يعد نشطاً لهذه الطاولة');
        }

        $details = $this->loadSplitDetails($conn, $originalOrderId, $selectedItems);
        if (count($details) !== count($selectedItems)) {
            throw new RuntimeException('بعض الأصناف المختارة لا تخص الطلب الأصلي');
        }

        $splitLines = $this->buildSplitLines($selectedItems, $splitRequests, $details);
        $childTotal = 0.0;
        foreach ($splitLines as $line) {
            $childTotal += (float) $line['value'];
        }
        if ($childTotal <= 0) {
            throw new RuntimeException('قيمة الأصناف المختارة غير صحيحة');
        }
        if ($paidAmount + self::PAYMENT_ROUNDING_TOLERANCE < $childTotal) {
            throw new RuntimeException('المبلغ المدفوع أقل من قيمة الأصناف المختارة');
        }

        $drawerContext = array_merge($request, $context, ['drawer_reason' => 'split_payment']);
        $drawerSession = $this->paymentService->preflightCashDrawerForPayment($conn, $paymentMethod, $childTotal, $userId, $drawerContext);
        $newHeadId = $this->insertSplitChildOrder($conn, $originalOrder, $tableId, $originalOrderId, $childTotal, $paymentMethod, $userId);
        foreach ($splitLines as $line) {
            $this->moveOrCopySplitLine($conn, $newHeadId, $line);
        }

        $remainingTotals = $this->tableOrderService->recalculateOrderTotals($conn, $originalOrderId);
        $activeTableOrderId = $this->refreshOriginalAfterSplit($conn, $originalOrder, $originalOrderId, $tableId, (float) $remainingTotals['net']);
        $paymentId = $this->insertSplitPaymentRecordIfAvailable($conn, $newHeadId, $childTotal, $paymentMethod, $userId);
        $this->paymentService->recordCashDrawerMovementForPayment($conn, $paymentMethod, $childTotal, $newHeadId, $userId, $drawerContext, $drawerSession, $paymentId);
        $this->recordOrderEvent($conn, $originalOrderId, 'order.updated', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'split_child_order_id' => $newHeadId,
            'remaining_total' => (float) $remainingTotals['net'],
            'active_order_id' => $activeTableOrderId,
        ]);
        $this->recordOrderEvent($conn, $newHeadId, 'order.split_paid', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'original_order_id' => $originalOrderId,
            'paid_amount' => $childTotal,
            'payment_method' => $paymentMethod,
        ]);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'SPLIT_PAYMENT_CREATED',
            'data' => [
                'new_invoice_id' => $newHeadId,
                'order_id' => $newHeadId,
                'original_order_id' => $originalOrderId,
                'table_id' => $tableId,
                'split_group_id' => $this->splitGroupIdForOrder($conn, $newHeadId),
                'remaining_total' => (float) $remainingTotals['net'],
                'active_order_id' => $activeTableOrderId,
                'paid_amount' => $childTotal,
            ],
        ];
    }

    private function normalizeSplitRequests($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $splitRequests = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $detailId = (int) ($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0);
                $qty = isset($item['qty']) ? (float) $item['qty'] : (isset($item['quantity']) ? (float) $item['quantity'] : null);
            } else {
                $detailId = (int) $item;
                $qty = null;
            }

            if ($detailId > 0) {
                if (!isset($splitRequests[$detailId])) {
                    $splitRequests[$detailId] = ['qty' => null];
                }
                if ($qty !== null) {
                    $splitRequests[$detailId]['qty'] = ($splitRequests[$detailId]['qty'] ?? 0) + $qty;
                }
            }
        }

        return $splitRequests;
    }

    private function loadSplitDetails(mysqli $conn, int $originalOrderId, array $selectedItems): array
    {
        $placeholders = implode(',', array_fill(0, count($selectedItems), '?'));
        $detailParams = array_merge([$originalOrderId], $selectedItems);
        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT *
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND id IN ($placeholders)
            FOR UPDATE
        ", $detailParams);

        $detailsById = [];
        foreach ($rows as $row) {
            $detailsById[(int) $row['id']] = $row;
        }

        return $detailsById;
    }

    private function buildSplitLines(array $selectedItems, array $splitRequests, array $detailsById): array
    {
        $splitLines = [];
        foreach ($selectedItems as $detailId) {
            $detail = $detailsById[$detailId] ?? null;
            if (!$detail) {
                throw new RuntimeException('بعض الأصناف المختارة لا تخص الطلب الأصلي');
            }

            $availableQty = max(0, (float) ($detail['qty_out'] ?? 0) - (float) ($detail['qty_in'] ?? 0));
            $requestedQty = $splitRequests[$detailId]['qty'];
            if ($requestedQty === null) {
                $requestedQty = $availableQty;
            }
            if ($availableQty <= 0 || $requestedQty <= 0 || $requestedQty > $availableQty + 0.0001) {
                throw new RuntimeException('كمية الصنف المختارة غير صحيحة');
            }

            $ratio = min(1, $requestedQty / $availableQty);
            $splitLines[] = [
                'detail' => $detail,
                'qty' => $requestedQty,
                'value' => round((float) ($detail['det_value'] ?? 0) * $ratio, 4),
                'profit' => round((float) ($detail['profit'] ?? 0) * $ratio, 4),
                'is_full' => abs($requestedQty - $availableQty) <= 0.0001,
            ];
        }

        return $splitLines;
    }

    private function insertSplitChildOrder(mysqli $conn, array $originalOrder, int $tableId, int $originalOrderId, float $childTotal, string $paymentMethod, int $userId): int
    {
        $newInvoiceNum = $this->tableOrderService->nextPosProId($conn, TableOrderService::POS_TYPE, 0, 0);
        $splitGroupId = bin2hex(random_bytes(16));
        $date = date('Y-m-d');
        $info = 'سداد جزئي من طاولة ' . $tableId . ' - أصل الطلب ' . $originalOrderId;
        $emp2Id = (int) ($originalOrder['emp2_id'] ?? ($originalOrder['emp_id'] ?? 0));

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
                store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
                fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
                order_status, payment_method, payment_date, completed_at, parent_order_id,
                split_group_id, info, user
            ) VALUES (
                ?, ?, ?, 'table', 9, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, 0,
                ?, ?, 0, 'paid', 'completed',
                'completed', ?, NOW(), NOW(), ?,
                ?, ?, ?
            )
        ", [
            $newInvoiceNum,
            (int) ($originalOrder['branch_id'] ?? 0),
            $tableId,
            $date,
            $date,
            (int) ($originalOrder['store_id'] ?? 0),
            (int) ($originalOrder['emp_id'] ?? 0),
            $emp2Id,
            (int) ($originalOrder['acc1'] ?? 0),
            (int) ($originalOrder['acc2'] ?? 0),
            $childTotal,
            $childTotal,
            $childTotal,
            $childTotal,
            $paymentMethod,
            $originalOrderId,
            $splitGroupId,
            $info,
            $userId,
        ]);

        $orderId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        return $orderId;
    }

    private function moveOrCopySplitLine(mysqli $conn, int $newHeadId, array $line): void
    {
        $detailId = (int) $line['detail']['id'];
        if ($line['is_full']) {
            $this->tableOrderService->execute($conn, "
                UPDATE fat_details
                SET fatid = ?,
                    pro_id = ?,
                    pro_tybe = 9,
                    fat_tybe = 9
                WHERE id = ?
            ", [$newHeadId, $newHeadId, $detailId]);
            $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
            return;
        }

        $this->tableOrderService->execute($conn, "
            INSERT INTO fat_details (
                pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
                price, cost_price, stock_value, discount, plus, det_value,
                profit, fatid, fat_tybe, tenant, branch
            )
            SELECT
                9, det_store, ?, item_id, u_val, 0, ?,
                price, cost_price, stock_value, discount, plus, ?,
                ?, ?, 9, tenant, branch
            FROM fat_details
            WHERE id = ?
        ", [$newHeadId, $line['qty'], $line['value'], $line['profit'], $newHeadId, $detailId]);
        $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', (int) $conn->insert_id);

        $this->tableOrderService->execute($conn, "
            UPDATE fat_details
            SET qty_out = qty_out - ?,
                det_value = GREATEST(0, det_value - ?),
                profit = profit - ?
            WHERE id = ?
        ", [$line['qty'], $line['value'], $line['profit'], $detailId]);
    }

    private function refreshOriginalAfterSplit(mysqli $conn, array $originalOrder, int $originalOrderId, int $tableId, float $remainingNet): ?int
    {
        $remainingLines = $this->tableOrderService->queryOne($conn, "
            SELECT COUNT(*) AS c
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND qty_out > qty_in
        ", [$originalOrderId]);

        if ((int) ($remainingLines['c'] ?? 0) > 0 && $remainingNet > 0) {
            $originalPaid = min((float) ($originalOrder['paid_amount'] ?? 0), $remainingNet);
            $originalRemaining = max(0, $remainingNet - $originalPaid);
            if ($originalPaid <= 0) {
                $paymentStatus = 'unpaid';
                $invoiceStatus = 'draft';
                $orderStatus = 'active';
            } elseif ($originalRemaining <= 0.0001) {
                $paymentStatus = 'paid';
                $invoiceStatus = 'completed';
                $orderStatus = 'completed';
            } else {
                $paymentStatus = 'partial';
                $invoiceStatus = 'draft';
                $orderStatus = 'active';
            }

            $this->tableOrderService->execute($conn, "
                UPDATE ot_head
                SET payment_status = ?,
                    invoice_status = ?,
                    order_status = ?,
                    paid_amount = ?,
                    remaining_amount = ?
                WHERE id = ?
                  AND table_id = ?
            ", [$paymentStatus, $invoiceStatus, $orderStatus, $originalPaid, $originalRemaining, $originalOrderId, $tableId]);

            if ($orderStatus === 'completed') {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
                return null;
            }

            $this->tableOrderService->markTableOccupied($conn, $tableId);
            return $originalOrderId;
        }

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET payment_status = 'paid',
                invoice_status = 'completed',
                order_status = 'completed',
                paid_amount = 0,
                remaining_amount = 0,
                completed_at = NOW()
            WHERE id = ?
              AND table_id = ?
        ", [$originalOrderId, $tableId]);
        $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);

        return null;
    }

    private function insertSplitPaymentRecordIfAvailable(mysqli $conn, int $newHeadId, float $childTotal, string $paymentMethod, int $userId): ?int
    {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_payments'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $this->tableOrderService->execute($conn, "
                INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$newHeadId, $childTotal, $paymentMethod, $userId]);
            $paymentId = (int) $conn->insert_id;
            $this->tableOrderService->assignUuidIfPresent($conn, 'order_payments', $paymentId);

            return $paymentId;
        }

        return null;
    }

    private function splitGroupIdForOrder(mysqli $conn, int $orderId): ?string
    {
        $row = $this->tableOrderService->queryOne($conn, "SELECT split_group_id FROM ot_head WHERE id = ? LIMIT 1", [$orderId]);
        if (!$row || !array_key_exists('split_group_id', $row)) {
            return null;
        }

        return (string) $row['split_group_id'];
    }

    private function updateTableOrderHeader(
        mysqli $conn,
        int $orderId,
        int $tableId,
        string $orderDate,
        int $storeId,
        int $empId,
        int $fundId,
        int $clientId,
        float $total,
        float $discount,
        float $net,
        string $info
    ): void {
        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET pro_date = ?,
                accural_date = ?,
                store_id = ?,
                emp_id = ?,
                emp2_id = ?,
                acc1 = ?,
                acc2 = ?,
                fat_total = ?,
                fat_disc = ?,
                fat_net = ?,
                pro_value = ?,
                remaining_amount = ?,
                table_id = ?,
                order_type = 'table',
                payment_status = 'unpaid',
                invoice_status = 'draft',
                order_status = 'active',
                waiter_id = ?,
                info = ?
            WHERE id = ?
        ", [
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $orderId,
        ]);
    }

    private function insertTableOrderHeader(
        mysqli $conn,
        int $tableId,
        string $orderDate,
        int $storeId,
        int $empId,
        int $fundId,
        int $clientId,
        float $total,
        float $discount,
        float $net,
        string $info,
        int $userId
    ): int {
        $proId = $this->tableOrderService->nextPosProId($conn, TableOrderService::POS_TYPE, 0, 0);
        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, pro_date, accural_date, store_id, emp_id, emp2_id,
                acc1, acc2, fat_total, fat_disc, fat_net, pro_value, remaining_amount,
                table_id, order_type, payment_status, invoice_status, order_status, waiter_id,
                info, user, crtime
            ) VALUES (
                ?, 9, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, 'table', 'unpaid', 'draft', 'active', ?,
                ?, ?, CURRENT_TIMESTAMP
            )
        ", [
            $proId,
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $userId,
        ]);

        return (int) $conn->insert_id;
    }

    private function insertTableOrderItems(mysqli $conn, int $orderId, int $storeId, array $items, array $context = []): void
    {
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            $detValue = $qty * $price;
            $this->tableOrderService->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store
                ) VALUES (9, ?, ?, 1, 0, ?, ?, 0, ?, ?, 9, ?)
            ", [$orderId, $itemId, $qty, $price, $detValue, $orderId, $storeId]);
            $detailId = (int) $conn->insert_id;
            $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
            $this->persistLineNoteIfAvailable($conn, $orderId, $detailId, $itemId, $this->lineNoteFromItem($item), $context);
        }
    }

    private function applyPaidState(mysqli $conn, int $orderId, int $tableId, float $existingPaid, float $net): array
    {
        $appliedPaid = min($existingPaid, $net);
        $remaining = max(0, $net - $appliedPaid);
        if ($appliedPaid <= 0) {
            $paymentStatus = 'unpaid';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        } elseif ($remaining <= 0.0001) {
            $paymentStatus = 'paid';
            $invoiceStatus = 'completed';
            $orderStatus = 'completed';
        } else {
            $paymentStatus = 'partial';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        }

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET paid_amount = ?,
                remaining_amount = ?,
                payment_status = ?,
                invoice_status = ?,
                order_status = ?,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
            WHERE id = ?
              AND table_id = ?
        ", [$appliedPaid, $remaining, $paymentStatus, $invoiceStatus, $orderStatus, $orderStatus, $orderId, $tableId]);

        return [
            'paid_amount' => $appliedPaid,
            'remaining_amount' => $remaining,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function resolveDefaultClientId(mysqli $conn): int
    {
        $settings = $conn->query("SELECT def_pos_client FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1");
        $settingsRow = $settings ? $settings->fetch_assoc() : null;

        return $this->tableOrderService->resolveDefaultCustomerId($conn, (int) ($settingsRow['def_pos_client'] ?? 0));
    }

    private function recordOrderEvent(mysqli $conn, int $orderId, string $eventType, string $eventSource, array $context, array $metadata = []): ?array
    {
        try {
            return $this->orderEventService->recordIfAvailable($conn, $orderId, $eventType, $eventSource, [
                'actor_user_id' => $context['user_id'] ?? null,
                'tenant' => $context['tenant'] ?? $context['pos_tenant'] ?? 0,
                'branch' => $context['branch'] ?? $context['pos_branch'] ?? 0,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            error_log('Order event recording skipped: ' . $exception->getMessage());

            return null;
        }
    }

    private function requiredItems(array $request): array
    {
        $items = $request['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        return $items;
    }

    private function lineNoteFromItem(array $item): string
    {
        foreach (['note', 'kitchen_note', 'notes', 'line_note'] as $key) {
            if (array_key_exists($key, $item)) {
                if (is_array($item[$key])) {
                    continue;
                }

                return trim((string) $item[$key]);
            }
        }

        return '';
    }

    private function persistLineNoteIfAvailable(mysqli $conn, int $orderId, int $detailId, int $itemId, $note, array $context = []): void
    {
        $note = trim((string) $note);
        if ($note === '' || !$this->tableExists($conn, 'order_line_notes')) {
            return;
        }

        if ($this->lineNoteServiceTablesAvailable($conn)) {
            try {
                $this->modifierLineNoteService->saveLineCustomizations(
                    $conn,
                    $orderId,
                    $detailId,
                    $itemId,
                    [],
                    [['note_type' => 'kitchen', 'note_text' => $note]],
                    [
                        'modifiers_enabled' => true,
                        'user_id' => (int) ($context['user_id'] ?? 0),
                    ]
                );
                return;
            } catch (Throwable $exception) {
                error_log('Modifier line note service skipped: ' . $exception->getMessage());
            }
        }

        $this->replaceKitchenLineNoteDirectly($conn, $orderId, $detailId, $note, (int) ($context['user_id'] ?? 0));
    }

    private function lineNoteServiceTablesAvailable(mysqli $conn): bool
    {
        foreach (['order_line_modifiers', 'item_modifier_groups', 'modifier_groups', 'modifier_options'] as $tableName) {
            if (!$this->tableExists($conn, $tableName)) {
                return false;
            }
        }

        return true;
    }

    private function replaceKitchenLineNoteDirectly(mysqli $conn, int $orderId, int $detailId, string $note, int $userId): void
    {
        try {
            if (function_exists('mb_substr')) {
                $note = mb_substr($note, 0, 500);
            } else {
                $note = substr($note, 0, 500);
            }

            $delete = $conn->prepare("DELETE FROM order_line_notes WHERE order_id = ? AND detail_id = ? AND note_type = 'kitchen'");
            $delete->bind_param('ii', $orderId, $detailId);
            $delete->execute();
            $delete->close();

            $type = 'kitchen';
            $createdBy = $userId > 0 ? $userId : null;
            $insert = $conn->prepare("
                INSERT INTO order_line_notes (order_id, detail_id, note_type, note_text, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert->bind_param('iissi', $orderId, $detailId, $type, $note, $createdBy);
            $insert->execute();
            $insert->close();
        } catch (Throwable $exception) {
            error_log('Direct line note persistence skipped: ' . $exception->getMessage());
        }
    }

    private function assertItemsAvailable(mysqli $conn, array $items, array $request, array $context): void
    {
        if (!$this->tableExists($conn, 'item_availability')) {
            return;
        }

        $scope = [
            'tenant' => $this->scopeNonNegativeInt($request, $context, ['tenant', 'pos_tenant']),
            'branch' => $this->scopeNonNegativeInt($request, $context, ['branch', 'pos_branch']),
            'channel' => $request['availability_channel'] ?? $request['channel'] ?? $context['availability_channel'] ?? $context['channel'] ?? 'pos',
        ];
        foreach ($this->itemIdsFromLines($items) as $itemId) {
            $this->itemAvailabilityService->assertSellable($conn, $itemId, $scope);
        }
    }

    private function requireDiscountApprovalIfNeeded(mysqli $conn, ?int $orderId, float $discount, array $request, array $context): void
    {
        $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'discount.override',
            'pos_order',
            $orderId,
            max(0, $discount),
            $request,
            $context
        );
    }

    private function itemIdsFromLines(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($itemId > 0) {
                $ids[$itemId] = $itemId;
            }
        }

        return array_values($ids);
    }

    private function scopeNonNegativeInt(array $request, array $context, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request) && $request[$key] !== '' && $request[$key] !== null) {
                return max(0, (int) $request[$key]);
            }
            if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null) {
                return max(0, (int) $context[$key]);
            }
        }

        return 0;
    }

    private function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

        return $result && $result->num_rows > 0;
    }

    private function requiredPositiveInt(array $request, string $key, string $message): int
    {
        $value = (int) ($request[$key] ?? 0);
        if ($value <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function contextUserId(array $request, array $context): int
    {
        $userId = (int) ($request['user_id'] ?? $context['user_id'] ?? 1);
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }

        return $userId;
    }
}
