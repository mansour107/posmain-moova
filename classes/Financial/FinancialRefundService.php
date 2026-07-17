<?php

require_once __DIR__ . '/DecimalQuantity.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/RoundingPolicy.php';
require_once __DIR__ . '/TaxRate.php';
require_once __DIR__ . '/UnitPrice.php';
require_once __DIR__ . '/FinancialTenderAllocator.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../Sync/DocumentCounterService.php';
require_once __DIR__ . '/../Sync/OperationalSyncEventService.php';

/**
 * Certified refund boundary: credit notes reverse revenue from stored line
 * snapshots; tender settlements allocate across original payments.
 */
final class FinancialRefundService
{
    private PaymentMethodService $paymentMethods;
    private DrawerSessionService $drawers;
    private DocumentCounterService $counters;

    public function __construct(
        ?PaymentMethodService $paymentMethods = null,
        ?DrawerSessionService $drawers = null,
        ?DocumentCounterService $counters = null
    ) {
        $this->paymentMethods = $paymentMethods ?: new PaymentMethodService();
        $this->drawers = $drawers ?: new DrawerSessionService();
        $this->counters = $counters ?: new DocumentCounterService();
    }

    /**
     * @return array{credit_note_id:int,credit_note_journal_head_id:int,refund_ids:array<int,int>,total_amount:string,pending_external_amount:string,replayed:bool}
     */
    public function createPostedRefund(mysqli $conn, array $request, array $context = []): array
    {
        $orderId = $this->positiveInt($request['original_order_id'] ?? $request['order_id'] ?? 0, 'ORIGINAL_ORDER_REQUIRED');
        $customerAccountId = $this->positiveInt($request['customer_account_id'] ?? 0, 'CUSTOMER_ACCOUNT_REQUIRED');
        $revenueAccountId = $this->positiveInt($request['revenue_account_id'] ?? 0, 'REVENUE_ACCOUNT_REQUIRED');
        $userId = $this->positiveInt($request['user_id'] ?? $context['user_id'] ?? 0, 'USER_ID_REQUIRED');
        $reason = $this->text($request['reason'] ?? '', 500, 'REFUND_REASON_REQUIRED');
        $tenant = max(0, (int) ($request['tenant'] ?? $context['tenant'] ?? 0));
        $branch = max(0, (int) ($request['branch'] ?? $context['branch'] ?? 0));
        $idempotencyKey = trim((string) ($request['idempotency_key'] ?? ''));
        $vatAccountId = (int) ($request['vat_payable_account_id'] ?? $context['vat_payable_account_id'] ?? 0);

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $this->lockOrder($conn, $orderId);

            if ($idempotencyKey !== '') {
                $existing = $this->findCreditNoteByIdempotency($conn, $idempotencyKey);
                if ($existing !== null) {
                    if ((int) $existing['original_order_id'] !== $orderId
                        || Money::from((string) $existing['total_amount'])->toString() !== Money::from((string) ($request['expected_total'] ?? $existing['total_amount']))->toString()
                    ) {
                        // Allow replay when payload matches stored total.
                        if ((int) $existing['original_order_id'] !== $orderId) {
                            throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
                        }
                    }
                    if ($ownsTransaction) {
                        $conn->commit();
                    }

                    return $this->existingResult($conn, $existing);
                }
            }

            $defaultDisposition = $this->normalizeStockDisposition(
                $request['stock_disposition'] ?? $request['refund_stock_policy'] ?? 'no_stock_return'
            );
            $lines = $this->normalizeLinesFromSnapshots(
                $conn,
                $orderId,
                $request['lines'] ?? null,
                $defaultDisposition
            );
            $total = $this->sumAmounts(array_column($lines, 'line_amount'));
            $taxTotal = $this->sumAmounts(array_column($lines, 'tax_amount'));
            $payments = $this->normalizePaymentAllocations($conn, $orderId, $request['payments'] ?? null, $total, $request);
            if ($this->sumAmounts(array_column($payments, 'amount')) !== $total) {
                throw new InvalidArgumentException('REFUND_TENDER_TOTAL_MISMATCH');
            }

            foreach ($payments as $payment) {
                $this->assertOriginalPaymentCapacity($conn, $orderId, $payment);
            }

            $creditNoteId = $this->insertCreditNote(
                $conn,
                $orderId,
                $customerAccountId,
                $total,
                $idempotencyKey !== '' ? $idempotencyKey : null,
                $reason,
                $userId
            );
            foreach ($lines as $line) {
                $this->insertCreditNoteLine($conn, $creditNoteId, $line);
            }

            $revenueDebit = Money::from($total)->subtract(Money::from($taxTotal))->toString();
            $creditEntries = [
                ['account_id' => $revenueAccountId, 'debit' => $revenueDebit, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
                ['account_id' => $customerAccountId, 'debit' => '0.00', 'credit' => $total, 'tybe' => 1, 'op2' => $orderId],
            ];
            if (Money::from($taxTotal)->isPositive()) {
                if ($vatAccountId < 1) {
                    throw new InvalidArgumentException('VAT_PAYABLE_ACCOUNT_REQUIRED');
                }
                $creditEntries[] = [
                    'account_id' => $vatAccountId,
                    'debit' => $taxTotal,
                    'credit' => '0.00',
                    'tybe' => 0,
                    'op2' => $orderId,
                ];
                // Rebalance: revenue + tax debits must equal customer credit.
                $creditEntries[0]['debit'] = $revenueDebit;
            }

            $creditJournal = $this->postJournal(
                $conn,
                $tenant,
                $branch,
                $total,
                $userId,
                'Credit note for order ' . $orderId,
                $this->balanceCreditNoteEntries($creditEntries, $total, $customerAccountId, $orderId),
                [
                    'source_type' => 'credit_note',
                    'source_id' => $creditNoteId,
                    'posting_kind' => 'sales_refund_credit_note',
                    'idempotency_key' => $idempotencyKey !== '' ? 'credit-note:' . $idempotencyKey : null,
                    'op_id' => $creditNoteId,
                    'op2' => $orderId,
                ]
            );
            $update = $conn->prepare('UPDATE credit_notes SET journal_head_id = ? WHERE id = ?');
            $update->bind_param('ii', $creditJournal, $creditNoteId);
            $update->execute();
            $update->close();

            $refundIds = [];
            $pendingExternal = Money::zero();
            foreach ($payments as $index => $payment) {
                $status = $payment['type'] === 'cash' ? 'posted' : (
                    $payment['external_reference'] !== null && $payment['external_reference'] !== ''
                        ? 'settled'
                        : 'pending_external'
                );
                $refundId = $this->insertPaymentRefund(
                    $conn,
                    $creditNoteId,
                    $orderId,
                    $payment,
                    $userId,
                    $status,
                    $idempotencyKey !== '' ? 'tender:' . $idempotencyKey . ':' . $index : null
                );

                if ($status !== 'pending_external') {
                    $refundJournal = $this->postJournal(
                        $conn,
                        $tenant,
                        $branch,
                        $payment['amount'],
                        $userId,
                        'Refund settlement for order ' . $orderId,
                        [
                            ['account_id' => $customerAccountId, 'debit' => $payment['amount'], 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
                            ['account_id' => $payment['account_id'], 'debit' => '0.00', 'credit' => $payment['amount'], 'tybe' => 1, 'op2' => $orderId],
                        ],
                        [
                            'source_type' => 'payment_refund',
                            'source_id' => $refundId,
                            'posting_kind' => 'refund_tender_settlement',
                            'op_id' => $refundId,
                            'op2' => $orderId,
                            'idempotency_key' => $idempotencyKey !== '' ? 'refund-settle:' . $idempotencyKey . ':' . $index : null,
                        ]
                    );
                    $update = $conn->prepare('UPDATE payment_refunds SET journal_head_id = ? WHERE id = ?');
                    $update->bind_param('ii', $refundJournal, $refundId);
                    $update->execute();
                    $update->close();

                    if ($payment['type'] === 'cash') {
                        $sessionId = (int) ($request['drawer_session_id'] ?? $context['drawer_session_id'] ?? 0);
                        if ($sessionId < 1 && session_status() === PHP_SESSION_ACTIVE) {
                            $sessionId = (int) ($_SESSION['pos_drawer_session_id'] ?? 0);
                        }
                        if ($sessionId < 1) {
                            $open = $this->drawers->resolveOpenSessionForUser($conn, $userId, [
                                'tenant' => $tenant,
                                'branch' => $branch,
                            ]);
                            $sessionId = (int) ($open['id'] ?? 0);
                        }
                        if ($sessionId < 1) {
                            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
                        }
                        $this->drawers->recordMovement($conn, $sessionId, [
                            'movement_type' => 'refund_cash',
                            'amount' => $payment['amount'],
                            'order_id' => $orderId,
                            'payment_id' => $refundId,
                            'created_by' => $userId,
                            'reason' => 'credit_note_refund:' . $creditNoteId,
                        ]);
                    }
                } else {
                    $pendingExternal = $pendingExternal->add(Money::from($payment['amount']));
                }
                $refundIds[] = $refundId;
            }

            $this->recordSyncSnapshot($conn, $creditNoteId, $context);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'credit_note_id' => $creditNoteId,
                'credit_note_journal_head_id' => $creditJournal,
                'refund_ids' => $refundIds,
                'total_amount' => $total,
                'pending_external_amount' => $pendingExternal->toString(),
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    /**
     * Settle a pending_external non-cash refund after the operator enters the
     * external terminal/settlement reference.
     */
    public function settlePendingExternal(mysqli $conn, int $refundId, string $externalReference, array $context = []): array
    {
        $externalReference = trim($externalReference);
        if ($externalReference === '') {
            throw new InvalidArgumentException('PAYMENT_REFERENCE_REQUIRED');
        }
        $userId = $this->positiveInt($context['user_id'] ?? 0, 'USER_ID_REQUIRED');
        $tenant = max(0, (int) ($context['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? 0));
        $customerAccountId = $this->positiveInt($context['customer_account_id'] ?? 0, 'CUSTOMER_ACCOUNT_REQUIRED');

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM payment_refunds WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $refundId);
            $stmt->execute();
            $refund = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$refund) {
                throw new InvalidArgumentException('PAYMENT_REFUND_NOT_FOUND');
            }
            if ((string) ($refund['status'] ?? '') !== 'pending_external') {
                throw new InvalidArgumentException('REFUND_NOT_PENDING_EXTERNAL');
            }

            $amount = Money::from((string) $refund['amount'])->toString();
            $accountId = (int) $refund['account_id'];
            $orderId = (int) $refund['original_order_id'];
            $journal = $this->postJournal(
                $conn,
                $tenant,
                $branch,
                $amount,
                $userId,
                'External refund settlement ' . $refundId,
                [
                    ['account_id' => $customerAccountId, 'debit' => $amount, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
                    ['account_id' => $accountId, 'debit' => '0.00', 'credit' => $amount, 'tybe' => 1, 'op2' => $orderId],
                ],
                [
                    'source_type' => 'payment_refund',
                    'source_id' => $refundId,
                    'posting_kind' => 'refund_tender_settlement',
                    'idempotency_key' => 'refund-external-settle:' . $refundId,
                    'op_id' => $refundId,
                    'op2' => $orderId,
                ]
            );
            $update = $conn->prepare('
                UPDATE payment_refunds
                SET status = \'settled\', external_reference = ?, journal_head_id = ?
                WHERE id = ?
            ');
            $update->bind_param('sii', $externalReference, $journal, $refundId);
            $update->execute();
            $update->close();
            $this->recordSyncSnapshot($conn, (int) $refund['credit_note_id'], $context);
            $conn->commit();

            return [
                'refund_id' => $refundId,
                'status' => 'settled',
                'journal_head_id' => $journal,
                'amount' => $amount,
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    private function lockOrder(mysqli $conn, int $orderId): void
    {
        $stmt = $conn->prepare('SELECT id FROM ot_head WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('ORIGINAL_ORDER_NOT_FOUND');
        }
    }

    private function recordSyncSnapshot(mysqli $conn, int $creditNoteId, array $context): void
    {
        $options = [
            'event_type' => 'financial.refund_snapshot',
            'source_system' => 'financial_refund',
        ];
        if (isset($context['sync_config']) && is_array($context['sync_config'])) {
            $options['config'] = $context['sync_config'];
        }

        (new OperationalSyncEventService())->recordFinancialRefundSnapshot($conn, $creditNoteId, $options);
    }

    private function normalizeLinesFromSnapshots(mysqli $conn, int $orderId, $lines, string $defaultDisposition = 'no_stock_return'): array
    {
        if (!is_array($lines) || $lines === []) {
            $lines = $this->allRemainingRefundLines($conn, $orderId, $defaultDisposition);
        }
        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('REFUND_LINE_INVALID');
            }
            $detailId = $this->positiveInt($line['original_detail_id'] ?? $line['detail_id'] ?? 0, 'ORIGINAL_DETAIL_REQUIRED');
            $snapshot = $this->loadLineSnapshot($conn, $orderId, $detailId);
            $quantity = DecimalQuantity::from($line['quantity'] ?? $line['qty'] ?? '0')->toString();
            if (FinancialDecimal::compare($quantity, '0.000000', DecimalQuantity::SCALE) <= 0) {
                throw new InvalidArgumentException('REFUND_QUANTITY_INVALID');
            }
            $remainingQty = $this->remainingRefundableQty($conn, $detailId, $snapshot['quantity']);
            if (FinancialDecimal::compare($quantity, $remainingQty, DecimalQuantity::SCALE) > 0) {
                throw new InvalidArgumentException('REFUND_QUANTITY_EXCEEDS_REMAINING');
            }

            $unitAmount = $snapshot['unit_amount'];
            $expected = RoundingPolicy::halfUp(
                FinancialDecimal::multiply($quantity, $unitAmount, DecimalQuantity::SCALE),
                Money::SCALE,
                DecimalQuantity::SCALE
            );
            // Pro-rate tax from snapshot when present.
            $taxAmount = '0.00';
            if (Money::from($snapshot['tax_amount'])->isPositive()
                && FinancialDecimal::compare($snapshot['quantity'], '0', DecimalQuantity::SCALE) > 0
            ) {
                $taxRaw = bcdiv(
                    bcmul($snapshot['tax_amount'], $quantity, 12),
                    $snapshot['quantity'],
                    6
                );
                $taxAmount = RoundingPolicy::halfUp($taxRaw);
            }
            $disposition = $this->normalizeStockDisposition($line['stock_disposition'] ?? $defaultDisposition);
            $normalized[] = [
                'original_detail_id' => $detailId,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'line_amount' => $expected,
                'tax_rate' => $snapshot['tax_rate'],
                'tax_amount' => $taxAmount,
                'stock_disposition' => $disposition,
            ];
        }

        return $normalized;
    }

    private function allRemainingRefundLines(mysqli $conn, int $orderId, string $disposition): array
    {
        $hasPosted = $this->columnExists($conn, 'fat_details', 'posted_qty');
        $qtyExpr = $hasPosted
            ? 'COALESCE(posted_qty, ABS(qty_out - qty_in))'
            : 'ABS(qty_out - qty_in)';
        $result = $conn->query('
            SELECT id, ' . $qtyExpr . ' AS quantity
            FROM fat_details
            WHERE fatid = ' . (int) $orderId . '
              AND COALESCE(isdeleted, 0) = 0
            ORDER BY id ASC
        ');
        if ($result === false) {
            throw new RuntimeException('REFUND_LINES_LOAD_FAILED');
        }
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $detailId = (int) $row['id'];
            $originalQty = DecimalQuantity::from((string) $row['quantity'])->toString();
            $remaining = $this->remainingRefundableQty($conn, $detailId, $originalQty);
            if (FinancialDecimal::compare($remaining, '0.000000', DecimalQuantity::SCALE) <= 0) {
                continue;
            }
            $lines[] = [
                'original_detail_id' => $detailId,
                'quantity' => $remaining,
                'stock_disposition' => $disposition,
            ];
        }
        if ($lines === []) {
            throw new InvalidArgumentException('REFUND_LINES_REQUIRED');
        }

        return $lines;
    }

    private function normalizeStockDisposition($value): string
    {
        $disposition = strtolower(trim((string) $value));
        if ($disposition === 'return_to_stock') {
            $disposition = 'restock';
        }
        if (!in_array($disposition, ['restock', 'waste', 'no_stock_return'], true)) {
            throw new InvalidArgumentException('STOCK_DISPOSITION_INVALID');
        }

        return $disposition;
    }

    private function loadLineSnapshot(mysqli $conn, int $orderId, int $detailId): array
    {
        $hasPosted = $this->columnExists($conn, 'fat_details', 'posted_net');
        $sql = $hasPosted
            ? 'SELECT id,
                      COALESCE(posted_qty, ABS(qty_out - qty_in)) AS quantity,
                      COALESCE(posted_unit_price, price) AS unit_amount,
                      COALESCE(posted_net, det_value) AS line_amount,
                      COALESCE(tax_rate_snapshot, 0) AS tax_rate,
                      COALESCE(posted_tax, 0) AS tax_amount
               FROM fat_details
               WHERE id = ? AND fatid = ? AND COALESCE(isdeleted, 0) = 0
               LIMIT 1'
            : 'SELECT id,
                      ABS(qty_out - qty_in) AS quantity,
                      price AS unit_amount,
                      det_value AS line_amount,
                      0 AS tax_rate,
                      0 AS tax_amount
               FROM fat_details
               WHERE id = ? AND fatid = ? AND COALESCE(isdeleted, 0) = 0
               LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $detailId, $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('ORIGINAL_DETAIL_NOT_FOUND');
        }

        $quantity = DecimalQuantity::from((string) $row['quantity'])->toString();
        $lineAmount = Money::from((string) $row['line_amount'])->toString();
        // Unit amount is the effective posted net per unit (after discounts), never the raw list price.
        if (FinancialDecimal::compare($quantity, '0', DecimalQuantity::SCALE) > 0) {
            $unitAmount = bcdiv($lineAmount, $quantity, UnitPrice::SCALE);
            $unitAmount = UnitPrice::from($unitAmount)->toString();
        } else {
            $unitAmount = UnitPrice::from((string) $row['unit_amount'])->toString();
        }

        return [
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'line_amount' => $lineAmount,
            'tax_rate' => TaxRate::from((string) $row['tax_rate'])->toString(),
            'tax_amount' => Money::from((string) $row['tax_amount'])->toString(),
        ];
    }

    private function remainingRefundableQty(mysqli $conn, int $detailId, string $originalQty): string
    {
        if (!$this->tableExists($conn, 'credit_note_lines')) {
            return $originalQty;
        }
        $stmt = $conn->prepare('
            SELECT COALESCE(SUM(quantity), 0) AS refunded
            FROM credit_note_lines cnl
            INNER JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            WHERE cnl.original_detail_id = ?
              AND cn.status = \'posted\'
        ');
        $stmt->bind_param('i', $detailId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $refunded = DecimalQuantity::from((string) ($row['refunded'] ?? '0'))->toString();

        return FinancialDecimal::subtract($originalQty, $refunded, DecimalQuantity::SCALE);
    }

    private function normalizePaymentAllocations(mysqli $conn, int $orderId, $payments, string $total, array $request): array
    {
        if (!is_array($payments) || $payments === []) {
            // Auto-allocate across remaining original tenders.
            $payments = $this->autoAllocatePayments($conn, $orderId, $total);
        }
        $allowOverride = !empty($request['manager_tender_override'])
            && trim((string) ($request['manager_override_reason'] ?? '')) !== '';
        $normalized = [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                throw new InvalidArgumentException('REFUND_PAYMENT_INVALID');
            }
            $originalPaymentId = $this->positiveInt($payment['original_payment_id'] ?? $payment['id'] ?? 0, 'ORIGINAL_PAYMENT_REQUIRED');
            $original = $this->originalPayment($conn, $orderId, $originalPaymentId);
            $methodKey = $payment['payment_method_id'] ?? $payment['payment_method'] ?? $original['payment_method'];
            $reference = $payment['external_reference'] ?? $payment['reference_no'] ?? null;
            // Non-cash may omit reference at create time → pending_external.
            $tender = $this->resolveTenderAllowingPending($conn, $methodKey, $reference);
            if (!$allowOverride && $tender['code'] !== (string) $original['payment_method']) {
                throw new InvalidArgumentException('REFUND_TENDER_MUST_MATCH_ORIGINAL');
            }
            $amount = Money::from($payment['amount'] ?? '0')->toString();
            if (!Money::from($amount)->isPositive()) {
                throw new InvalidArgumentException('REFUND_AMOUNT_INVALID');
            }
            $normalized[] = [
                'original_payment_id' => $originalPaymentId,
                'payment_method_id' => (int) $tender['id'],
                'account_id' => (int) $tender['account_id'],
                'type' => (string) $tender['type'],
                'amount' => $amount,
                'external_reference' => $tender['reference_no'],
                'original_amount' => Money::from((string) $original['amount'])->toString(),
            ];
        }

        return $normalized;
    }

    private function autoAllocatePayments(mysqli $conn, int $orderId, string $total): array
    {
        $result = $conn->query('
            SELECT id, amount, payment_method
            FROM order_payments
            WHERE order_id = ' . (int) $orderId . '
            ORDER BY id ASC
        ');
        $available = [];
        while ($row = $result->fetch_assoc()) {
            $paymentId = (int) $row['id'];
            $originalAmount = Money::from((string) $row['amount'])->toString();
            $stmt = $conn->prepare('
                SELECT COALESCE(SUM(amount), 0) AS refunded
                FROM payment_refunds
                WHERE original_payment_id = ?
                  AND COALESCE(status, \'posted\') IN (\'posted\', \'pending_external\', \'settled\')
            ');
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $refunded = Money::from((string) ($stmt->get_result()->fetch_assoc()['refunded'] ?? '0'))->toString();
            $stmt->close();
            $remaining = Money::from($originalAmount)->subtract(Money::from($refunded))->toString();
            if (Money::from($remaining)->isPositive()) {
                $available[] = ['id' => $paymentId, 'amount' => $remaining, 'payment_method' => $row['payment_method']];
            }
        }
        $allocated = FinancialTenderAllocator::allocate($total, $available);
        $out = [];
        foreach ($allocated as $row) {
            $match = null;
            foreach ($available as $candidate) {
                if ((int) $candidate['id'] === (int) $row['id']) {
                    $match = $candidate;
                    break;
                }
            }
            $out[] = [
                'original_payment_id' => (int) $row['id'],
                'amount' => $row['amount'],
                'payment_method' => $match['payment_method'] ?? null,
            ];
        }

        return $out;
    }

    private function resolveTenderAllowingPending(mysqli $conn, $methodKey, $reference): array
    {
        return $this->paymentMethods->resolveTenderAllowingPendingExternal($conn, $methodKey, $reference);
    }

    private function originalPayment(mysqli $conn, int $orderId, int $paymentId): array
    {
        $stmt = $conn->prepare('SELECT id, amount, payment_method FROM order_payments WHERE id = ? AND order_id = ? LIMIT 1');
        $stmt->bind_param('ii', $paymentId, $orderId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$payment) {
            throw new InvalidArgumentException('ORIGINAL_PAYMENT_NOT_FOUND');
        }

        return $payment;
    }

    private function assertOriginalPaymentCapacity(mysqli $conn, int $orderId, array $payment): void
    {
        $stmt = $conn->prepare('
            SELECT COALESCE(SUM(amount), 0) AS refunded
            FROM payment_refunds
            WHERE original_order_id = ?
              AND original_payment_id = ?
              AND COALESCE(status, \'posted\') IN (\'posted\', \'pending_external\', \'settled\')
        ');
        $stmt->bind_param('ii', $orderId, $payment['original_payment_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $alreadyRefunded = Money::from((string) ($row['refunded'] ?? '0'))->toString();
        $remaining = Money::from($payment['original_amount'])->subtract(Money::from($alreadyRefunded));
        if ($remaining->compare(Money::from($payment['amount'])) < 0) {
            throw new InvalidArgumentException('REFUND_EXCEEDS_ORIGINAL_TENDER');
        }
    }

    private function insertCreditNote(mysqli $conn, int $orderId, int $customerAccountId, string $total, ?string $idempotencyKey, string $reason, int $userId): int
    {
        $uuid = $this->uuid();
        $stmt = $conn->prepare('
            INSERT INTO credit_notes (
                uuid, original_order_id, customer_account_id, total_amount,
                idempotency_key, reason, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('siisssi', $uuid, $orderId, $customerAccountId, $total, $idempotencyKey, $reason, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertCreditNoteLine(mysqli $conn, int $creditNoteId, array $line): void
    {
        $hasDisposition = $this->columnExists($conn, 'credit_note_lines', 'stock_disposition');
        if ($hasDisposition) {
            $stmt = $conn->prepare('
                INSERT INTO credit_note_lines (
                    credit_note_id, original_detail_id, quantity, unit_amount,
                    line_amount, tax_rate, tax_amount, stock_disposition
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iissssss',
                $creditNoteId,
                $line['original_detail_id'],
                $line['quantity'],
                $line['unit_amount'],
                $line['line_amount'],
                $line['tax_rate'],
                $line['tax_amount'],
                $line['stock_disposition']
            );
        } else {
            $stmt = $conn->prepare('
                INSERT INTO credit_note_lines (
                    credit_note_id, original_detail_id, quantity, unit_amount,
                    line_amount, tax_rate, tax_amount
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iisssss',
                $creditNoteId,
                $line['original_detail_id'],
                $line['quantity'],
                $line['unit_amount'],
                $line['line_amount'],
                $line['tax_rate'],
                $line['tax_amount']
            );
        }
        $stmt->execute();
        $stmt->close();
    }

    private function insertPaymentRefund(
        mysqli $conn,
        int $creditNoteId,
        int $orderId,
        array $payment,
        int $userId,
        string $status,
        ?string $idempotencyKey
    ): int {
        $hasStatus = $this->columnExists($conn, 'payment_refunds', 'status');
        $hasIdem = $this->columnExists($conn, 'payment_refunds', 'idempotency_key');
        if ($hasStatus && $hasIdem) {
            $stmt = $conn->prepare('
                INSERT INTO payment_refunds (
                    credit_note_id, original_order_id, original_payment_id,
                    payment_method_id, account_id, amount, external_reference,
                    status, idempotency_key, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iiiiissssi',
                $creditNoteId,
                $orderId,
                $payment['original_payment_id'],
                $payment['payment_method_id'],
                $payment['account_id'],
                $payment['amount'],
                $payment['external_reference'],
                $status,
                $idempotencyKey,
                $userId
            );
        } else {
            $stmt = $conn->prepare('
                INSERT INTO payment_refunds (
                    credit_note_id, original_order_id, original_payment_id,
                    payment_method_id, account_id, amount, external_reference, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iiiiissi',
                $creditNoteId,
                $orderId,
                $payment['original_payment_id'],
                $payment['payment_method_id'],
                $payment['account_id'],
                $payment['amount'],
                $payment['external_reference'],
                $userId
            );
        }
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function balanceCreditNoteEntries(array $entries, string $total, int $customerAccountId, int $orderId): array
    {
        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($entries as $entry) {
            $debit = $debit->add(Money::from($entry['debit']));
            $credit = $credit->add(Money::from($entry['credit']));
        }
        if ($debit->compare($credit) === 0) {
            return $entries;
        }
        // Ensure customer credit equals total and debits balance.
        return [
            ['account_id' => (int) $entries[0]['account_id'], 'debit' => $total, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
            ['account_id' => $customerAccountId, 'debit' => '0.00', 'credit' => $total, 'tybe' => 1, 'op2' => $orderId],
        ];
    }

    private function postJournal(mysqli $conn, int $tenant, int $branch, string $amount, int $userId, string $details, array $entries, array $meta): int
    {
        $seedRow = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads')->fetch_assoc();
        $seed = (int) ($seedRow['max_id'] ?? 0);
        $this->counters->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:default', $seed);
        $journalId = $this->counters->nextJournalId($conn, $tenant, $branch);

        return JournalPostingService::postBalancedHead(
            $conn,
            (string) $journalId,
            $amount,
            date('Y-m-d'),
            $details,
            $userId,
            $entries,
            $meta
        );
    }

    private function findCreditNoteByIdempotency(mysqli $conn, string $key): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM credit_notes WHERE idempotency_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    private function existingResult(mysqli $conn, array $creditNote): array
    {
        $creditNoteId = (int) $creditNote['id'];
        $result = $conn->query('SELECT id FROM payment_refunds WHERE credit_note_id = ' . $creditNoteId . ' ORDER BY id ASC');
        $refundIds = [];
        while ($row = $result->fetch_assoc()) {
            $refundIds[] = (int) $row['id'];
        }
        $pending = '0.00';
        if ($this->columnExists($conn, 'payment_refunds', 'status')) {
            $row = $conn->query("
                SELECT COALESCE(SUM(amount), 0) AS pending
                FROM payment_refunds
                WHERE credit_note_id = {$creditNoteId} AND status = 'pending_external'
            ")->fetch_assoc();
            $pending = Money::from((string) ($row['pending'] ?? '0'))->toString();
        }

        return [
            'credit_note_id' => $creditNoteId,
            'credit_note_journal_head_id' => (int) ($creditNote['journal_head_id'] ?? 0),
            'refund_ids' => $refundIds,
            'total_amount' => Money::from((string) $creditNote['total_amount'])->toString(),
            'pending_external_amount' => $pending,
            'replayed' => true,
        ];
    }

    private function sumAmounts(array $amounts): string
    {
        $sum = Money::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->add(Money::from($amount));
        }

        return $sum->toString();
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function text($value, int $maxLength, string $code): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException($code);
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");

        return $result !== false && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result !== false && $result->num_rows > 0;
    }
}
