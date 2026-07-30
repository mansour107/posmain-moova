<?php

require_once __DIR__ . '/DecimalQuantity.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/RoundingPolicy.php';
require_once __DIR__ . '/TaxRate.php';
require_once __DIR__ . '/UnitPrice.php';
require_once __DIR__ . '/FinancialTenderAllocator.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../Pos/Service/BusinessDayService.php';
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
     * @return array{credit_note_id:int,credit_note_journal_head_id:int,refund_ids:array<int,int>,refund_tenders:array<int,array>,total_amount:string,cumulative_refunded_amount:string,remaining_refundable_amount:string,reversal_status:string,pending_external_amount:string,tenant:int,branch:int,business_day:?string,drawer_session_id:?int,manager_approval_id:?int,replayed:bool}
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
        $requestFingerprint = $this->refundRequestFingerprint($orderId, $request);

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $this->lockOrder($conn, $orderId);

            if ($idempotencyKey !== '') {
                $existing = $this->findCreditNoteByIdempotency($conn, $idempotencyKey);
                if ($existing !== null) {
                    if ((int) $existing['original_order_id'] !== $orderId) {
                        throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
                    }
                    $storedFingerprint = trim((string) ($existing['request_fingerprint'] ?? ''));
                    if ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $requestFingerprint)) {
                        throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
                    }
                    if ($ownsTransaction) {
                        $conn->commit();
                    }

                    return $this->existingResult($conn, $existing);
                }
            }

            if ($tenant === 0 && $branch === 0) {
                $scope = $this->orderScope($conn, $orderId);
                $tenant = $scope['tenant'];
                $branch = $scope['branch'];
            }
            $attribution = $this->resolveRefundAttribution(
                $conn,
                $userId,
                $tenant,
                $branch,
                $request,
                $context
            );
            $tenant = $attribution['tenant'];
            $branch = $attribution['branch'];
            $drawerSessionId = $attribution['drawer_session_id'];

            $preview = $this->previewRefund($conn, $orderId, $request);
            $lines = $preview['lines'];
            $total = $preview['total_amount'];
            $taxTotal = $this->sumAmounts(array_column($lines, 'tax_amount'));
            $payments = $this->normalizePaymentAllocations($conn, $orderId, $request['payments'] ?? null, $total, $request);
            if ($this->sumAmounts(array_column($payments, 'amount')) !== $total) {
                throw new InvalidArgumentException('REFUND_TENDER_TOTAL_MISMATCH');
            }
            $hasCashTender = false;
            foreach ($payments as $payment) {
                if (($payment['type'] ?? '') === 'cash') {
                    $hasCashTender = true;
                    break;
                }
            }
            if ($hasCashTender && !empty($context['require_drawer_session']) && $drawerSessionId === null) {
                throw new RuntimeException('DRAWER_SESSION_REQUIRED');
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
                $preview['refund_mode'],
                $requestFingerprint,
                $reason,
                $userId,
                $attribution
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
                $settlementPolicy = (string) ($payment['settlement_policy'] ?? '');
                $status = $settlementPolicy === 'cash_drawer'
                    ? 'posted'
                    : (
                        $settlementPolicy === 'manual_external'
                        || ($payment['external_reference'] !== null && $payment['external_reference'] !== '')
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

                    if ($settlementPolicy === 'cash_drawer') {
                        $sessionId = (int) ($drawerSessionId ?? 0);
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
                            'idempotency_key' => $this->boundedDrawerIdempotencyKey(
                                $idempotencyKey,
                                $creditNoteId,
                                $refundId,
                                (int) $index
                            ),
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

            $state = $this->reversalStateForOrder($conn, $orderId);

            return [
                'credit_note_id' => $creditNoteId,
                'credit_note_journal_head_id' => $creditJournal,
                'refund_ids' => $refundIds,
                'refund_tenders' => $this->refundTenderRows($conn, $creditNoteId),
                'refund_mode' => $preview['refund_mode'],
                'total_amount' => $total,
                'cumulative_refunded_amount' => $state['cumulative_refunded_amount'],
                'remaining_refundable_amount' => $state['remaining_refundable_amount'],
                'reversal_status' => $state['reversal_status'],
                'pending_external_amount' => $pendingExternal->toString(),
                'tenant' => $tenant,
                'branch' => $branch,
                'business_day' => $attribution['business_day'],
                'drawer_session_id' => $drawerSessionId,
                'manager_approval_id' => $attribution['manager_approval_id'],
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
     * Return an already-posted refund without re-running its side effects.
     */
    public function findPostedRefundByIdempotency(mysqli $conn, string $key, int $orderId, ?array $request = null): ?array
    {
        $key = trim($key);
        if ($key === '' || $orderId < 1) {
            return null;
        }
        $existing = $this->findCreditNoteByIdempotency($conn, $key);
        if ($existing === null) {
            return null;
        }
        if ((int) ($existing['original_order_id'] ?? 0) !== $orderId) {
            throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
        }
        if ($request !== null) {
            $storedFingerprint = trim((string) ($existing['request_fingerprint'] ?? ''));
            if ($storedFingerprint !== ''
                && !hash_equals($storedFingerprint, $this->refundRequestFingerprint($orderId, $request))
            ) {
                throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
            }
        }

        return $this->existingResult($conn, $existing);
    }

    private function boundedDrawerIdempotencyKey(
        string $requestKey,
        int $creditNoteId,
        int $refundId,
        int $index
    ): string {
        $requestKey = trim($requestKey);
        if ($requestKey === '') {
            throw new InvalidArgumentException('REFUND_IDEMPOTENCY_KEY_REQUIRED');
        }

        $key = $requestKey . ':drawer:refund:' . $creditNoteId . ':' . $refundId . ':' . $index;
        if (strlen($key) <= 191) {
            return $key;
        }

        return substr($requestKey, 0, 96) . ':drawer-sha256:' . hash('sha256', $key);
    }

    /**
     * Resolve a cashier refund selection against immutable posted line
     * snapshots. This is also used before manager-limit evaluation so approval
     * is based on the requested partial amount, not the original sale total.
     *
     * @return array{refund_mode:string,total_amount:string,lines:array<int,array>}
     */
    public function previewRefund(mysqli $conn, int $orderId, array $request): array
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORIGINAL_ORDER_REQUIRED');
        }
        $defaultDisposition = $this->normalizeStockDisposition(
            $request['stock_disposition'] ?? $request['refund_stock_policy'] ?? 'no_stock_return'
        );
        $mode = $this->refundModeFromRequest($request);
        if ($mode === 'amount') {
            $amount = Money::from((string) ($request['refund_amount'] ?? '0'))->toString();
            if (!Money::from($amount)->isPositive()) {
                throw new InvalidArgumentException('REFUND_AMOUNT_INVALID');
            }
            $lines = $this->allocateAmountToRemainingLines($conn, $orderId, $amount, $defaultDisposition);
        } else {
            $requestedLines = $mode === 'items' ? ($request['lines'] ?? null) : null;
            $lines = $this->normalizeLinesFromSnapshots($conn, $orderId, $requestedLines, $defaultDisposition);
        }
        $total = $this->sumAmounts(array_column($lines, 'line_amount'));
        if (!Money::from($total)->isPositive()) {
            throw new InvalidArgumentException('REFUND_AMOUNT_INVALID');
        }

        return [
            'refund_mode' => $mode,
            'total_amount' => $total,
            'lines' => $lines,
        ];
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
        $seenDetailIds = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('REFUND_LINE_INVALID');
            }
            $detailId = $this->positiveInt($line['original_detail_id'] ?? $line['detail_id'] ?? 0, 'ORIGINAL_DETAIL_REQUIRED');
            if (isset($seenDetailIds[$detailId])) {
                throw new InvalidArgumentException('REFUND_LINE_DUPLICATE');
            }
            $seenDetailIds[$detailId] = true;
            $snapshot = $this->loadLineSnapshot($conn, $orderId, $detailId);
            $quantity = DecimalQuantity::from($line['quantity'] ?? $line['qty'] ?? '0')->toString();
            if (FinancialDecimal::compare($quantity, '0.000000', DecimalQuantity::SCALE) <= 0) {
                throw new InvalidArgumentException('REFUND_QUANTITY_INVALID');
            }
            $refunded = $this->refundedLineState($conn, $detailId);
            $remainingQty = FinancialDecimal::subtract(
                $snapshot['quantity'],
                $refunded['quantity'],
                DecimalQuantity::SCALE
            );
            if (FinancialDecimal::compare($quantity, $remainingQty, DecimalQuantity::SCALE) > 0) {
                throw new InvalidArgumentException('REFUND_QUANTITY_EXCEEDS_REMAINING');
            }

            $unitAmount = $snapshot['unit_amount'];
            $remainingAmount = Money::from($snapshot['line_amount'])
                ->subtract(Money::from($refunded['line_amount']))
                ->toString();
            $remainingTax = Money::from($snapshot['tax_amount'])
                ->subtract(Money::from($refunded['tax_amount']))
                ->toString();
            $remainingEvidence = [];
            foreach ([
                'gross_amount',
                'line_discount_amount',
                'order_discount_amount',
                'taxable_amount',
            ] as $column) {
                $remainingEvidence[$column] = Money::from($snapshot[$column])
                    ->subtract(Money::from($refunded[$column]))
                    ->toString();
            }
            $takesRemainder = FinancialDecimal::compare(
                $quantity,
                $remainingQty,
                DecimalQuantity::SCALE
            ) === 0;
            $expected = $takesRemainder
                ? $remainingAmount
                : $this->proRateMoney(
                    $snapshot['line_amount'],
                    $quantity,
                    $snapshot['quantity']
                );
            if (Money::from($expected)->compare(Money::from($remainingAmount)) > 0) {
                $expected = $remainingAmount;
            }
            $taxAmount = $takesRemainder
                ? $remainingTax
                : $this->proRateMoney(
                    $snapshot['tax_amount'],
                    $quantity,
                    $snapshot['quantity']
                );
            if (Money::from($taxAmount)->compare(Money::from($remainingTax)) > 0) {
                $taxAmount = $remainingTax;
            }
            $evidence = [];
            foreach ($remainingEvidence as $column => $remainingValue) {
                $value = $takesRemainder
                    ? $remainingValue
                    : $this->proRateMoney(
                        $snapshot[$column],
                        $quantity,
                        $snapshot['quantity']
                    );
                if (Money::from($value)->compare(Money::from($remainingValue)) > 0) {
                    $value = $remainingValue;
                }
                $evidence[$column] = $value;
            }
            $disposition = $this->normalizeStockDisposition($line['stock_disposition'] ?? $defaultDisposition);
            $normalized[] = [
                'original_detail_id' => $detailId,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'line_amount' => $expected,
                'gross_amount' => $evidence['gross_amount'],
                'line_discount_amount' => $evidence['line_discount_amount'],
                'order_discount_amount' => $evidence['order_discount_amount'],
                'taxable_amount' => $evidence['taxable_amount'],
                'tax_rate' => $snapshot['tax_rate'],
                'tax_amount' => $taxAmount,
                'stock_disposition' => $disposition,
            ];
        }

        return $normalized;
    }

    private function allocateAmountToRemainingLines(
        mysqli $conn,
        int $orderId,
        string $requestedAmount,
        string $disposition
    ): array {
        $remainingOrderAmount = Money::from(
            $this->reversalStateForOrder($conn, $orderId)['remaining_refundable_amount']
        );
        $requested = Money::from($requestedAmount);
        if ($requested->compare($remainingOrderAmount) > 0) {
            throw new InvalidArgumentException('REFUND_AMOUNT_EXCEEDS_REMAINING');
        }

        $remaining = $requested;
        $lines = [];
        foreach ($this->remainingLineSnapshots($conn, $orderId) as $snapshot) {
            if (!$remaining->isPositive()) {
                break;
            }
            $available = Money::from($snapshot['remaining_amount']);
            if (!$available->isPositive()) {
                continue;
            }
            $allocated = $remaining->compare($available) >= 0 ? $available : $remaining;
            $takesRemainder = $allocated->compare($available) === 0;
            $quantity = $takesRemainder
                ? $snapshot['remaining_quantity']
                : $this->proRateQuantity(
                    $snapshot['remaining_quantity'],
                    $allocated->toString(),
                    $available->toString()
                );
            if (FinancialDecimal::compare($quantity, '0', DecimalQuantity::SCALE) <= 0) {
                throw new InvalidArgumentException('REFUND_AMOUNT_TOO_SMALL_FOR_LINE');
            }
            $taxAmount = $takesRemainder
                ? $snapshot['remaining_tax']
                : $this->proRateMoney(
                    $snapshot['remaining_tax'],
                    $allocated->toString(),
                    $available->toString()
                );
            if (Money::from($taxAmount)->compare(Money::from($snapshot['remaining_tax'])) > 0) {
                $taxAmount = $snapshot['remaining_tax'];
            }
            $evidence = [];
            foreach ([
                'gross_amount',
                'line_discount_amount',
                'order_discount_amount',
                'taxable_amount',
            ] as $column) {
                $remainingColumn = 'remaining_' . $column;
                $evidence[$column] = $takesRemainder
                    ? $snapshot[$remainingColumn]
                    : $this->proRateMoney(
                        $snapshot[$remainingColumn],
                        $allocated->toString(),
                        $available->toString()
                    );
                if (Money::from($evidence[$column])->compare(Money::from($snapshot[$remainingColumn])) > 0) {
                    $evidence[$column] = $snapshot[$remainingColumn];
                }
            }
            $lines[] = [
                'original_detail_id' => $snapshot['original_detail_id'],
                'quantity' => $quantity,
                'unit_amount' => $snapshot['unit_amount'],
                'line_amount' => $allocated->toString(),
                'gross_amount' => $evidence['gross_amount'],
                'line_discount_amount' => $evidence['line_discount_amount'],
                'order_discount_amount' => $evidence['order_discount_amount'],
                'taxable_amount' => $evidence['taxable_amount'],
                'tax_rate' => $snapshot['tax_rate'],
                'tax_amount' => $taxAmount,
                'stock_disposition' => $disposition,
            ];
            $remaining = $remaining->subtract($allocated);
        }
        if ($remaining->isPositive()) {
            throw new InvalidArgumentException('REFUND_AMOUNT_EXCEEDS_REMAINING');
        }

        return $lines;
    }

    private function proRateMoney(string $total, string $part, string $whole): string
    {
        if (!Money::from($total)->isPositive()
            || FinancialDecimal::compare($whole, '0', DecimalQuantity::SCALE) <= 0
        ) {
            return '0.00';
        }

        return RoundingPolicy::halfUp(
            bcdiv(bcmul($total, $part, 12), $whole, 12)
        );
    }

    private function proRateQuantity(string $quantity, string $part, string $whole): string
    {
        if (FinancialDecimal::compare($whole, '0', Money::SCALE) <= 0) {
            return '0.000000';
        }
        $raw = bcdiv(bcmul($quantity, $part, 12), $whole, 12);
        $rounded = RoundingPolicy::halfUp($raw, DecimalQuantity::SCALE, 12);
        if (FinancialDecimal::compare($part, $whole, Money::SCALE) < 0
            && FinancialDecimal::compare($rounded, $quantity, DecimalQuantity::SCALE) >= 0
        ) {
            $rounded = bcsub(
                DecimalQuantity::from($quantity)->toString(),
                '0.000001',
                DecimalQuantity::SCALE
            );
        }

        return DecimalQuantity::from($rounded)->toString();
    }

    private function refundModeFromRequest(array $request): string
    {
        $explicit = strtolower(trim((string) ($request['refund_mode'] ?? '')));
        $hasLines = is_array($request['lines'] ?? null) && $request['lines'] !== [];
        $hasAmount = array_key_exists('refund_amount', $request)
            && trim((string) $request['refund_amount']) !== '';
        if ($explicit === '') {
            $explicit = $hasLines ? 'items' : ($hasAmount ? 'amount' : 'full');
        }
        if (!in_array($explicit, ['full', 'items', 'amount'], true)) {
            throw new InvalidArgumentException('REFUND_MODE_INVALID');
        }
        if (($explicit === 'full' && ($hasLines || $hasAmount))
            || ($explicit === 'items' && (!$hasLines || $hasAmount))
            || ($explicit === 'amount' && ($hasLines || !$hasAmount))
        ) {
            throw new InvalidArgumentException('REFUND_SELECTION_CONFLICT');
        }

        return $explicit;
    }

    private function refundRequestFingerprint(int $orderId, array $request): string
    {
        $mode = $this->refundModeFromRequest($request);
        $lines = [];
        if ($mode === 'items') {
            foreach ((array) ($request['lines'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lines[] = [
                    'original_detail_id' => (int) ($line['original_detail_id'] ?? $line['detail_id'] ?? 0),
                    'quantity' => DecimalQuantity::from($line['quantity'] ?? $line['qty'] ?? '0')->toString(),
                    'stock_disposition' => $this->normalizeStockDisposition(
                        $line['stock_disposition']
                        ?? $request['stock_disposition']
                        ?? $request['refund_stock_policy']
                        ?? 'no_stock_return'
                    ),
                ];
            }
            usort($lines, static fn (array $a, array $b): int => $a['original_detail_id'] <=> $b['original_detail_id']);
        }
        $payments = [];
        foreach ((array) ($request['payments'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $payments[] = [
                'original_payment_id' => (int) ($payment['original_payment_id'] ?? $payment['id'] ?? 0),
                'payment_method' => (string) ($payment['payment_method_id'] ?? $payment['payment_method'] ?? ''),
                'amount' => Money::from((string) ($payment['amount'] ?? '0'))->toString(),
                'external_reference' => trim((string) ($payment['external_reference'] ?? $payment['reference_no'] ?? '')),
            ];
        }
        $payload = [
            'order_id' => $orderId,
            'refund_mode' => $mode,
            'refund_amount' => $mode === 'amount'
                ? Money::from((string) ($request['refund_amount'] ?? '0'))->toString()
                : null,
            'lines' => $lines,
            'refund_payment_method' => trim((string) (
                $request['refund_payment_method'] ?? $request['refund_tender'] ?? ''
            )),
            'refund_external_reference' => trim((string) (
                $request['refund_external_reference'] ?? $request['external_reference'] ?? ''
            )),
            'payments' => $payments,
            'stock_disposition' => $this->normalizeStockDisposition(
                $request['stock_disposition'] ?? $request['refund_stock_policy'] ?? 'no_stock_return'
            ),
            'reason' => trim((string) ($request['reason'] ?? '')),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function remainingLineSnapshots(mysqli $conn, int $orderId): array
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
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $detailId = (int) $row['id'];
            $snapshot = $this->loadLineSnapshot($conn, $orderId, $detailId);
            $refunded = $this->refundedLineState($conn, $detailId);
            $remainingQty = FinancialDecimal::subtract(
                $snapshot['quantity'],
                $refunded['quantity'],
                DecimalQuantity::SCALE
            );
            $remainingAmount = Money::from($snapshot['line_amount'])
                ->subtract(Money::from($refunded['line_amount']))
                ->toString();
            if (FinancialDecimal::compare($remainingQty, '0', DecimalQuantity::SCALE) <= 0
                || !Money::from($remainingAmount)->isPositive()
            ) {
                continue;
            }
            $out[] = [
                'original_detail_id' => $detailId,
                'remaining_quantity' => $remainingQty,
                'remaining_amount' => $remainingAmount,
                'remaining_tax' => Money::from($snapshot['tax_amount'])
                    ->subtract(Money::from($refunded['tax_amount']))
                    ->toString(),
                'remaining_gross_amount' => Money::from($snapshot['gross_amount'])
                    ->subtract(Money::from($refunded['gross_amount']))
                    ->toString(),
                'remaining_line_discount_amount' => Money::from($snapshot['line_discount_amount'])
                    ->subtract(Money::from($refunded['line_discount_amount']))
                    ->toString(),
                'remaining_order_discount_amount' => Money::from($snapshot['order_discount_amount'])
                    ->subtract(Money::from($refunded['order_discount_amount']))
                    ->toString(),
                'remaining_taxable_amount' => Money::from($snapshot['taxable_amount'])
                    ->subtract(Money::from($refunded['taxable_amount']))
                    ->toString(),
                'unit_amount' => $snapshot['unit_amount'],
                'tax_rate' => $snapshot['tax_rate'],
            ];
        }

        return $out;
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
                      COALESCE(posted_gross, posted_net, det_value) AS gross_amount,
                      COALESCE(posted_line_discount, 0) AS line_discount_amount,
                      COALESCE(posted_order_discount, 0) AS order_discount_amount,
                      COALESCE(posted_taxable, posted_net, det_value) AS taxable_amount,
                      COALESCE(tax_rate_snapshot, 0) AS tax_rate,
                      COALESCE(posted_tax, 0) AS tax_amount
               FROM fat_details
               WHERE id = ? AND fatid = ? AND COALESCE(isdeleted, 0) = 0
               LIMIT 1'
            : 'SELECT id,
                      ABS(qty_out - qty_in) AS quantity,
                      price AS unit_amount,
                      det_value AS line_amount,
                      det_value AS gross_amount,
                      0 AS line_discount_amount,
                      0 AS order_discount_amount,
                      det_value AS taxable_amount,
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
            'gross_amount' => Money::from((string) $row['gross_amount'])->toString(),
            'line_discount_amount' => Money::from((string) $row['line_discount_amount'])->toString(),
            'order_discount_amount' => Money::from((string) $row['order_discount_amount'])->toString(),
            'taxable_amount' => Money::from((string) $row['taxable_amount'])->toString(),
            'tax_rate' => TaxRate::from((string) $row['tax_rate'])->toString(),
            'tax_amount' => Money::from((string) $row['tax_amount'])->toString(),
        ];
    }

    private function remainingRefundableQty(mysqli $conn, int $detailId, string $originalQty): string
    {
        $refunded = $this->refundedLineState($conn, $detailId)['quantity'];

        return FinancialDecimal::subtract($originalQty, $refunded, DecimalQuantity::SCALE);
    }

    /** @return array{quantity:string,line_amount:string,tax_amount:string,gross_amount:string,line_discount_amount:string,order_discount_amount:string,taxable_amount:string} */
    private function refundedLineState(mysqli $conn, int $detailId): array
    {
        $empty = [
            'quantity' => '0.000000',
            'line_amount' => '0.00',
            'tax_amount' => '0.00',
            'gross_amount' => '0.00',
            'line_discount_amount' => '0.00',
            'order_discount_amount' => '0.00',
            'taxable_amount' => '0.00',
        ];
        if (!$this->tableExists($conn, 'credit_note_lines')) {
            return $empty;
        }
        $hasEvidence = $this->columnExists($conn, 'credit_note_lines', 'gross_amount');
        $evidenceSelect = $hasEvidence
            ? ',
                   COALESCE(SUM(cnl.gross_amount), 0) AS refunded_gross,
                   COALESCE(SUM(cnl.line_discount_amount), 0) AS refunded_line_discount,
                   COALESCE(SUM(cnl.order_discount_amount), 0) AS refunded_order_discount,
                   COALESCE(SUM(cnl.taxable_amount), 0) AS refunded_taxable'
            : '';
        $stmt = $conn->prepare('
            SELECT COALESCE(SUM(cnl.quantity), 0) AS refunded_quantity,
                   COALESCE(SUM(cnl.line_amount), 0) AS refunded_amount,
                   COALESCE(SUM(cnl.tax_amount), 0) AS refunded_tax
                   ' . $evidenceSelect . '
            FROM credit_note_lines cnl
            INNER JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            WHERE cnl.original_detail_id = ?
              AND cn.status = \'posted\'
        ');
        $stmt->bind_param('i', $detailId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'quantity' => DecimalQuantity::from((string) ($row['refunded_quantity'] ?? '0'))->toString(),
            'line_amount' => Money::from((string) ($row['refunded_amount'] ?? '0'))->toString(),
            'tax_amount' => Money::from((string) ($row['refunded_tax'] ?? '0'))->toString(),
            'gross_amount' => Money::from((string) ($row['refunded_gross'] ?? '0'))->toString(),
            'line_discount_amount' => Money::from((string) ($row['refunded_line_discount'] ?? '0'))->toString(),
            'order_discount_amount' => Money::from((string) ($row['refunded_order_discount'] ?? '0'))->toString(),
            'taxable_amount' => Money::from((string) ($row['refunded_taxable'] ?? '0'))->toString(),
        ];
    }

    private function normalizePaymentAllocations(mysqli $conn, int $orderId, $payments, string $total, array $request): array
    {
        $cashierSelectedMethod = trim((string) (
            $request['refund_payment_method']
            ?? $request['refund_tender']
            ?? ''
        ));
        $cashierSelectedReference = $request['refund_external_reference']
            ?? $request['external_reference']
            ?? null;
        $usesCashierSelection = (!is_array($payments) || $payments === [])
            && $cashierSelectedMethod !== '';
        if (!is_array($payments) || $payments === []) {
            // The original payment rows remain the capacity/provenance links.
            // The cashier-selected method is the authoritative outgoing tender.
            $payments = $this->autoAllocatePayments($conn, $orderId, $total);
            if ($usesCashierSelection) {
                foreach ($payments as &$payment) {
                    $payment['payment_method'] = $cashierSelectedMethod;
                    $payment['external_reference'] = $cashierSelectedReference;
                }
                unset($payment);
            }
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
            // Only reference_required tenders may remain pending_external.
            $tender = $this->resolveTenderAllowingPending($conn, $methodKey, $reference);
            if (!$usesCashierSelection && !$allowOverride && $tender['code'] !== (string) $original['payment_method']) {
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
                'settlement_policy' => (string) $tender['settlement_policy'],
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

    private function insertCreditNote(
        mysqli $conn,
        int $orderId,
        int $customerAccountId,
        string $total,
        ?string $idempotencyKey,
        string $refundMode,
        string $requestFingerprint,
        string $reason,
        int $userId,
        array $attribution
    ): int
    {
        $uuid = $this->uuid();
        $hasAttribution = $this->columnExists($conn, 'credit_notes', 'tenant')
            && $this->columnExists($conn, 'credit_notes', 'branch')
            && $this->columnExists($conn, 'credit_notes', 'business_day')
            && $this->columnExists($conn, 'credit_notes', 'drawer_session_id')
            && $this->columnExists($conn, 'credit_notes', 'manager_approval_id');
        if ($hasAttribution) {
            $tenant = (int) ($attribution['tenant'] ?? 0);
            $branch = (int) ($attribution['branch'] ?? 0);
            $businessDay = $attribution['business_day'] ?? null;
            $drawerSessionId = $attribution['drawer_session_id'] ?? null;
            $managerApprovalId = $attribution['manager_approval_id'] ?? null;
            $hasPartialMetadata = $this->columnExists($conn, 'credit_notes', 'refund_mode')
                && $this->columnExists($conn, 'credit_notes', 'request_fingerprint');
            if ($hasPartialMetadata) {
                $stmt = $conn->prepare('
                    INSERT INTO credit_notes (
                        uuid, tenant, branch, business_day, drawer_session_id,
                        manager_approval_id, original_order_id, customer_account_id,
                        total_amount, idempotency_key, refund_mode, request_fingerprint,
                        reason, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->bind_param(
                    'siisiiiisssssi',
                    $uuid,
                    $tenant,
                    $branch,
                    $businessDay,
                    $drawerSessionId,
                    $managerApprovalId,
                    $orderId,
                    $customerAccountId,
                    $total,
                    $idempotencyKey,
                    $refundMode,
                    $requestFingerprint,
                    $reason,
                    $userId
                );
            } else {
                $stmt = $conn->prepare('
                    INSERT INTO credit_notes (
                        uuid, tenant, branch, business_day, drawer_session_id,
                        manager_approval_id, original_order_id, customer_account_id,
                        total_amount, idempotency_key, reason, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->bind_param(
                    'siisiiiisssi',
                    $uuid,
                    $tenant,
                    $branch,
                    $businessDay,
                    $drawerSessionId,
                    $managerApprovalId,
                    $orderId,
                    $customerAccountId,
                    $total,
                    $idempotencyKey,
                    $reason,
                    $userId
                );
            }
        } else {
            $stmt = $conn->prepare('
                INSERT INTO credit_notes (
                    uuid, original_order_id, customer_account_id, total_amount,
                    idempotency_key, reason, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param('siisssi', $uuid, $orderId, $customerAccountId, $total, $idempotencyKey, $reason, $userId);
        }
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertCreditNoteLine(mysqli $conn, int $creditNoteId, array $line): void
    {
        $hasDisposition = $this->columnExists($conn, 'credit_note_lines', 'stock_disposition');
        $hasEvidence = $this->columnExists($conn, 'credit_note_lines', 'gross_amount');
        if ($hasDisposition && $hasEvidence) {
            $stmt = $conn->prepare('
                INSERT INTO credit_note_lines (
                    credit_note_id, original_detail_id, quantity, unit_amount,
                    line_amount, gross_amount, line_discount_amount,
                    order_discount_amount, taxable_amount, tax_rate, tax_amount,
                    stock_disposition
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iissssssssss',
                $creditNoteId,
                $line['original_detail_id'],
                $line['quantity'],
                $line['unit_amount'],
                $line['line_amount'],
                $line['gross_amount'],
                $line['line_discount_amount'],
                $line['order_discount_amount'],
                $line['taxable_amount'],
                $line['tax_rate'],
                $line['tax_amount'],
                $line['stock_disposition']
            );
        } elseif ($hasDisposition) {
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
        $hasSettlementPolicy = $this->columnExists($conn, 'payment_refunds', 'settlement_policy');
        if ($hasStatus && $hasIdem && $hasSettlementPolicy) {
            $settlementPolicy = (string) ($payment['settlement_policy'] ?? 'reference_required');
            $declaredBy = $status === 'settled' ? $userId : null;
            $declaredAt = $status === 'settled' ? date('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare('
                INSERT INTO payment_refunds (
                    credit_note_id, original_order_id, original_payment_id,
                    payment_method_id, account_id, amount, external_reference,
                    settlement_policy, settlement_declared_by, settlement_declared_at,
                    status, idempotency_key, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param(
                'iiiiisssisssi',
                $creditNoteId,
                $orderId,
                $payment['original_payment_id'],
                $payment['payment_method_id'],
                $payment['account_id'],
                $payment['amount'],
                $payment['external_reference'],
                $settlementPolicy,
                $declaredBy,
                $declaredAt,
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
        $meta['tenant'] = $tenant;
        $meta['branch'] = $branch;

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

        $state = $this->reversalStateForOrder($conn, (int) $creditNote['original_order_id']);

        return [
            'credit_note_id' => $creditNoteId,
            'credit_note_journal_head_id' => (int) ($creditNote['journal_head_id'] ?? 0),
            'refund_ids' => $refundIds,
            'refund_tenders' => $this->refundTenderRows($conn, $creditNoteId),
            'refund_mode' => (string) ($creditNote['refund_mode'] ?? 'full'),
            'total_amount' => Money::from((string) $creditNote['total_amount'])->toString(),
            'cumulative_refunded_amount' => $state['cumulative_refunded_amount'],
            'remaining_refundable_amount' => $state['remaining_refundable_amount'],
            'reversal_status' => $state['reversal_status'],
            'pending_external_amount' => $pending,
            'tenant' => (int) ($creditNote['tenant'] ?? 0),
            'branch' => (int) ($creditNote['branch'] ?? 0),
            'business_day' => $creditNote['business_day'] ?? null,
            'drawer_session_id' => isset($creditNote['drawer_session_id']) ? (int) $creditNote['drawer_session_id'] : null,
            'manager_approval_id' => isset($creditNote['manager_approval_id']) ? (int) $creditNote['manager_approval_id'] : null,
            'replayed' => true,
        ];
    }

    /** @return array<int,array{refund_id:int,payment_method_id:int,code:string,label:string,type:string,amount:string,status:string,external_reference:?string}> */
    private function refundTenderRows(mysqli $conn, int $creditNoteId): array
    {
        $stmt = $conn->prepare('
            SELECT pr.id AS refund_id,
                   pr.payment_method_id,
                   pr.amount,
                   pr.status,
                   pr.external_reference,
                   pr.settlement_policy,
                   pr.settlement_declared_by,
                   pr.settlement_declared_at,
                   pr.journal_head_id,
                   COALESCE(pm.code, CONCAT(\'method_\', pr.payment_method_id)) AS method_code,
                   COALESCE(NULLIF(pm.name_ar, \'\'), NULLIF(pm.name_en, \'\'), pm.code, CONCAT(\'Method \', pr.payment_method_id)) AS method_label,
                   COALESCE(pm.type, \'\') AS method_type
            FROM payment_refunds pr
            LEFT JOIN payment_methods pm ON pm.id = pr.payment_method_id
            WHERE pr.credit_note_id = ?
            ORDER BY pr.id ASC
        ');
        $stmt->bind_param('i', $creditNoteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'refund_id' => (int) $row['refund_id'],
                'payment_method_id' => (int) $row['payment_method_id'],
                'code' => (string) $row['method_code'],
                'label' => (string) $row['method_label'],
                'type' => (string) $row['method_type'],
                'amount' => Money::from((string) $row['amount'])->toString(),
                'status' => (string) $row['status'],
                'settlement_policy' => (string) $row['settlement_policy'],
                'settlement_declared_by' => $row['settlement_declared_by'] !== null
                    ? (int) $row['settlement_declared_by']
                    : null,
                'settlement_declared_at' => $row['settlement_declared_at'] !== null
                    ? (string) $row['settlement_declared_at']
                    : null,
                'journal_head_id' => $row['journal_head_id'] !== null
                    ? (int) $row['journal_head_id']
                    : null,
                'external_reference' => $row['external_reference'] !== null
                    ? (string) $row['external_reference']
                    : null,
            ];
        }
        $stmt->close();

        return $rows;
    }

    /** @return array{tenant:int,branch:int,business_day:string,drawer_session_id:?int,manager_approval_id:?int} */
    private function resolveRefundAttribution(
        mysqli $conn,
        int $userId,
        int $tenant,
        int $branch,
        array $request,
        array $context
    ): array {
        $drawerSession = null;
        $requestedSessionId = (int) ($request['drawer_session_id'] ?? $context['drawer_session_id'] ?? 0);
        if ($this->tableExists($conn, 'drawer_sessions')) {
            $drawerSession = $this->drawers->resolveOpenSessionForUser($conn, $userId, [
                'drawer_session_id' => $requestedSessionId,
                'tenant' => $tenant,
                'branch' => $branch,
            ]);
        }

        if ($drawerSession !== null) {
            $sessionTenant = max(0, (int) ($drawerSession['tenant'] ?? 0));
            $sessionBranch = max(0, (int) ($drawerSession['branch'] ?? 0));
            if (($tenant > 0 && $sessionTenant !== $tenant) || ($branch > 0 && $sessionBranch !== $branch)) {
                throw new RuntimeException('DRAWER_SESSION_SCOPE_MISMATCH');
            }
            $tenant = $sessionTenant;
            $branch = $sessionBranch;
        }

        $businessDay = trim((string) ($drawerSession['business_day'] ?? ''));
        if ($businessDay === '') {
            $businessDay = (new BusinessDayService())->currentBusinessDayForBranch($conn, $tenant, $branch);
        }
        $managerApprovalId = (int) ($request['manager_approval_id'] ?? $context['manager_approval_id'] ?? 0);

        return [
            'tenant' => $tenant,
            'branch' => $branch,
            'business_day' => $businessDay,
            'drawer_session_id' => $drawerSession !== null ? (int) $drawerSession['id'] : null,
            'manager_approval_id' => $managerApprovalId > 0 ? $managerApprovalId : null,
        ];
    }

    /** @return array{tenant:int,branch:int} */
    private function orderScope(mysqli $conn, int $orderId): array
    {
        if (!$this->columnExists($conn, 'ot_head', 'tenant') || !$this->columnExists($conn, 'ot_head', 'branch')) {
            return ['tenant' => 0, 'branch' => 0];
        }
        $stmt = $conn->prepare('SELECT tenant, branch FROM ot_head WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'tenant' => max(0, (int) ($row['tenant'] ?? 0)),
            'branch' => max(0, (int) ($row['branch'] ?? 0)),
        ];
    }

    /** @return array{cumulative_refunded_amount:string,remaining_refundable_amount:string,reversal_status:string} */
    private function reversalStateForOrder(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare('
            SELECT COALESCE(oh.fat_net, 0) AS original_total,
                   COALESCE(SUM(CASE WHEN cn.status = \'posted\' THEN cn.total_amount ELSE 0 END), 0) AS refunded_total
            FROM ot_head oh
            LEFT JOIN credit_notes cn ON cn.original_order_id = oh.id
            WHERE oh.id = ?
            GROUP BY oh.id, oh.fat_net
        ');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $original = Money::from((string) ($row['original_total'] ?? '0'));
        $refunded = Money::from((string) ($row['refunded_total'] ?? '0'));
        $remaining = $original->compare($refunded) > 0
            ? $original->subtract($refunded)
            : Money::zero();
        $status = !$refunded->isPositive()
            ? 'none'
            : ($remaining->isPositive() ? 'partial' : 'full');

        return [
            'cumulative_refunded_amount' => $refunded->toString(),
            'remaining_refundable_amount' => $remaining->toString(),
            'reversal_status' => $status,
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
