<?php

require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../../Sync/DocumentCounterService.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Financial/FinancialInvoicePostingService.php';
require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';

final class DeliveryAccountingService
{
    public function finalizeCodOrder(mysqli $conn, int $orderId, string $codAmount, int $userId, array $context = []): array
    {
        $stmt = $conn->prepare('SELECT id, pro_id, fat_net, paid_amount, remaining_amount, acc2, payment_status, pro_date FROM ot_head WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) {
            throw new InvalidArgumentException('ORDER_NOT_FOUND');
        }
        if ((string) $order['payment_status'] === 'paid') {
            return ['replayed' => true, 'order_id' => $orderId];
        }
        $cod = Money::from($codAmount);
        $remaining = Money::from($order['remaining_amount'] ?? '0');
        if ($cod->compare($remaining) !== 0 || !$cod->isPositive()) {
            throw new InvalidArgumentException('DELIVERY_COD_AMOUNT_MUST_MATCH_REMAINING');
        }
        // Delivery orders are accrual invoices: the sales journal is normally posted
        // when the cashier creates the order. Reuse it at COD completion so revenue
        // is never recognized twice. The fallback keeps imported/legacy orders safe.
        $posting = JournalPostingService::findByIdempotencyKey($conn, 'takeaway-invoice:' . $orderId)
            ?: JournalPostingService::findByIdempotencyKey($conn, 'invoice-finalization:' . $orderId);
        if (!$posting) {
            $revenueAccountId = posmain_ensure_sales_account($conn, 91);
            $posting = (new FinancialInvoicePostingService())->postInvoiceFinalization(
                $conn,
                $orderId,
                ['net' => Money::from($order['fat_net'])->toString()],
                (int) $order['acc2'],
                $revenueAccountId,
                $userId,
                [
                    'tenant' => (int) ($context['tenant'] ?? 0),
                    'branch' => (int) ($context['branch'] ?? 0),
                    'jdate' => (string) ($order['pro_date'] ?? date('Y-m-d')),
                    'idempotency_key' => 'invoice-finalization:' . $orderId,
                ]
            );
        }
        $update = $conn->prepare("UPDATE ot_head SET paid_amount = fat_net, remaining_amount = 0, payment_status = 'paid', invoice_status = 'completed', order_status = 'completed', payment_date = COALESCE(payment_date, NOW()), completed_at = COALESCE(completed_at, NOW()), mdtime = NOW() WHERE id = ?");
        $update->bind_param('i', $orderId);
        $update->execute();
        $update->close();
        return ['replayed' => false, 'order_id' => $orderId, 'journal_head_id' => (int) ($posting['journal_head_id'] ?? $posting['id'] ?? 0)];
    }

    public function postDeliveryFeeReclassification(mysqli $conn, int $orderId, string $deliveryFee, int $userId, array $context = []): ?int
    {
        $fee = Money::from($deliveryFee);
        if (!$fee->isPositive()) {
            return null;
        }
        $salesAccountId = posmain_ensure_sales_account($conn, 91);
        $tenant = max(0, (int) ($context['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? 0));
        $deliveryRevenueId = posmain_insert_acc_head_if_missing($conn, ['code' => '412010', 'aname' => 'إيراد رسوم التوصيل', 'tenant' => $tenant, 'branch' => $branch]);
        return $this->post($conn, $fee->toString(), 'Delivery fee revenue order ' . $orderId, $userId, [
            ['account_id' => $salesAccountId, 'debit' => $fee->toString(), 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
            ['account_id' => $deliveryRevenueId, 'debit' => '0.00', 'credit' => $fee->toString(), 'tybe' => 1, 'op2' => $orderId],
        ], [
            'source_type' => 'delivery_order',
            'source_id' => $orderId,
            'posting_kind' => 'delivery_fee_reclassification',
            'idempotency_key' => 'delivery-fee-reclassification:' . $orderId,
            'op_id' => $orderId,
            'op2' => $orderId,
            'tenant' => $tenant,
            'branch' => $branch,
        ]);
    }

    public function postDeliveredAccrual(mysqli $conn, array $financial, int $userId, array $context = []): ?int
    {
        $compensation = Money::from($financial['compensation_amount'] ?? '0');
        $tip = Money::from($financial['tip_amount'] ?? '0');
        $cod = Money::from($financial['cod_amount'] ?? '0');
        $payable = $compensation->add($tip);
        if (!$payable->isPositive() && !$cod->isPositive()) {
            return null;
        }

        $accounts = $this->accounts($conn, $context);
        $entries = [];
        if ($payable->isPositive()) {
            $entries[] = ['account_id' => $accounts['expense'], 'debit' => $payable->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $accounts['payable'], 'debit' => '0.00', 'credit' => $payable->toString(), 'tybe' => 1];
        }
        if ($cod->isPositive()) {
            $orderId = (int) $financial['order_id'];
            $customerId = $this->customerAccountForOrder($conn, $orderId);
            $entries[] = ['account_id' => $accounts['courier_cash'], 'debit' => $cod->toString(), 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId];
            $entries[] = ['account_id' => $customerId, 'debit' => '0.00', 'credit' => $cod->toString(), 'tybe' => 1, 'op2' => $orderId];
        }

        $total = $payable->add($cod)->toString();
        return $this->post($conn, $total, 'Delivery accrual order ' . (int) $financial['order_id'], $userId, $entries, [
            'source_type' => 'delivery_order',
            'source_id' => (int) $financial['id'],
            'posting_kind' => 'delivery_accrual',
            'idempotency_key' => 'delivery-accrual:' . (int) $financial['order_id'],
            'op_id' => (int) $financial['order_id'],
            'op2' => (int) $financial['order_id'],
            'tenant' => (int) ($context['tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? 0),
        ]);
    }

    public function postSettlement(mysqli $conn, array $settlement, int $userId, array $context = []): ?int
    {
        $accounts = $this->accounts($conn, $context);
        $earnings = Money::from($settlement['delivery_earnings'] ?? '0')
            ->add(Money::from($settlement['tips'] ?? '0'));
        $baseAndBonus = Money::from($settlement['base_pay'] ?? '0')
            ->add(Money::from($settlement['bonuses'] ?? '0'));
        $deductions = Money::from($settlement['deductions'] ?? '0');
        $cod = Money::from($settlement['cod_held'] ?? '0');
        $grossPayable = $earnings->add($baseAndBonus);
        $net = Money::from($settlement['net_amount'] ?? '0', true);
        $entries = [];

        if ($baseAndBonus->isPositive()) {
            $entries[] = ['account_id' => $accounts['expense'], 'debit' => $baseAndBonus->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $accounts['payable'], 'debit' => '0.00', 'credit' => $baseAndBonus->toString(), 'tybe' => 1];
        }
        if ($deductions->isPositive()) {
            $entries[] = ['account_id' => $accounts['payable'], 'debit' => $deductions->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $accounts['adjustments'], 'debit' => '0.00', 'credit' => $deductions->toString(), 'tybe' => 1];
        }
        $payableAfterDeductions = $grossPayable->subtract($deductions);
        if ($payableAfterDeductions->compare(Money::from('0')) < 0) {
            $payableAfterDeductions = Money::from('0');
        }
        $offset = $cod->compare($payableAfterDeductions) > 0 ? $payableAfterDeductions : $cod;
        if ($offset->isPositive()) {
            $entries[] = ['account_id' => $accounts['payable'], 'debit' => $offset->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $accounts['courier_cash'], 'debit' => '0.00', 'credit' => $offset->toString(), 'tybe' => 1];
        }

        $fundAccountId = (int) ($settlement['fund_account_id'] ?? 0);
        if ($net->isPositive()) {
            if ($fundAccountId < 1) {
                throw new InvalidArgumentException('DELIVERY_SETTLEMENT_FUND_REQUIRED');
            }
            $entries[] = ['account_id' => $accounts['payable'], 'debit' => $net->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $fundAccountId, 'debit' => '0.00', 'credit' => $net->toString(), 'tybe' => 1];
        } elseif ($net->compare(Money::from('0')) < 0) {
            if ($fundAccountId < 1) {
                throw new InvalidArgumentException('DELIVERY_SETTLEMENT_FUND_REQUIRED');
            }
            $received = Money::from('0')->subtract($net);
            $entries[] = ['account_id' => $fundAccountId, 'debit' => $received->toString(), 'credit' => '0.00', 'tybe' => 0];
            $entries[] = ['account_id' => $accounts['courier_cash'], 'debit' => '0.00', 'credit' => $received->toString(), 'tybe' => 1];
        }

        if ($entries === []) {
            return null;
        }
        $debitTotal = Money::from('0');
        foreach ($entries as $entry) {
            $debitTotal = $debitTotal->add(Money::from($entry['debit']));
        }
        return $this->post($conn, $debitTotal->toString(), 'Delivery settlement #' . (int) $settlement['id'], $userId, $entries, [
            'source_type' => 'delivery_settlement',
            'source_id' => (int) $settlement['id'],
            'posting_kind' => 'delivery_settlement',
            'idempotency_key' => (string) $settlement['idempotency_key'],
            'op_id' => (int) $settlement['id'],
            'tenant' => (int) ($context['tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? 0),
        ]);
    }

    public function reverseSettlementJournal(mysqli $conn, array $settlement, int $userId, string $reason, array $context = []): ?int
    {
        $originalJournalId = (int) ($settlement['journal_head_id'] ?? 0);
        if ($originalJournalId < 1) {
            return null;
        }
        $idempotencyKey = 'delivery-settlement-reversal:' . (int) $settlement['id'];
        $existing = JournalPostingService::findByIdempotencyKey($conn, $idempotencyKey);
        if ($existing) {
            return (int) $existing['id'];
        }
        $result = $conn->query('SELECT account_id, debit, credit, tybe, op2 FROM journal_entries WHERE journal_id = ' . $originalJournalId . ' ORDER BY id');
        $entries = [];
        $total = Money::from('0');
        while ($row = $result->fetch_assoc()) {
            $debit = Money::from((string) $row['credit']);
            $credit = Money::from((string) $row['debit']);
            $entries[] = [
                'account_id' => (int) $row['account_id'],
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'tybe' => $debit->isPositive() ? 0 : 1,
                'op2' => (int) ($row['op2'] ?? 0),
            ];
            $total = $total->add($debit);
        }
        if ($entries === []) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_JOURNAL_ENTRIES_REQUIRED');
        }
        return $this->post($conn, $total->toString(), 'Reverse delivery settlement #' . (int) $settlement['id'] . ': ' . $reason, $userId, $entries, [
            'source_type' => 'delivery_settlement',
            'source_id' => (int) $settlement['id'],
            'posting_kind' => 'delivery_settlement_reversal',
            'idempotency_key' => $idempotencyKey,
            'reversal_of_journal_id' => $originalJournalId,
            'op_id' => (int) $settlement['id'],
            'tenant' => (int) ($context['tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? 0),
        ]);
    }

    private function accounts(mysqli $conn, array $context): array
    {
        $tenant = max(0, (int) ($context['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? 0));
        return [
            'expense' => posmain_insert_acc_head_if_missing($conn, ['code' => '612010', 'aname' => 'تكلفة عمال التوصيل', 'tenant' => $tenant, 'branch' => $branch]),
            'payable' => posmain_insert_acc_head_if_missing($conn, ['code' => '214010', 'aname' => 'مستحقات عمال التوصيل', 'tenant' => $tenant, 'branch' => $branch]),
            'courier_cash' => posmain_insert_acc_head_if_missing($conn, ['code' => '123090', 'aname' => 'نقدية لدى عمال التوصيل', 'tenant' => $tenant, 'branch' => $branch]),
            'adjustments' => posmain_insert_acc_head_if_missing($conn, ['code' => '419010', 'aname' => 'تسويات عمال التوصيل', 'tenant' => $tenant, 'branch' => $branch]),
        ];
    }

    private function customerAccountForOrder(mysqli $conn, int $orderId): int
    {
        $stmt = $conn->prepare('SELECT acc2 FROM ot_head WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $accountId = (int) ($row['acc2'] ?? 0);
        if ($accountId < 1) {
            throw new InvalidArgumentException('DELIVERY_CUSTOMER_ACCOUNT_REQUIRED');
        }
        return $accountId;
    }

    private function post(mysqli $conn, string $total, string $details, int $userId, array $entries, array $meta): int
    {
        $existing = JournalPostingService::findByIdempotencyKey($conn, (string) $meta['idempotency_key']);
        if ($existing) {
            return (int) $existing['id'];
        }
        $seedRow = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads')->fetch_assoc();
        $counters = new DocumentCounterService();
        $counters->ensureCounterRow($conn, (int) ($meta['tenant'] ?? 0), (int) ($meta['branch'] ?? 0), 'journal_id', 'journal:default', (int) ($seedRow['max_id'] ?? 0));
        $journalId = (string) $counters->nextJournalId($conn, (int) ($meta['tenant'] ?? 0), (int) ($meta['branch'] ?? 0));
        return JournalPostingService::postBalancedHead($conn, $journalId, $total, date('Y-m-d'), $details, $userId, $entries, $meta);
    }
}
