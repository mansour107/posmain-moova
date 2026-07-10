<?php

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/FinancialCertifiedMode.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../Sync/DocumentCounterService.php';

/**
 * Posts invoice finalization journals (AR / revenue / VAT) through the single
 * JournalPostingService boundary. Payment and COGS journals are posted by their
 * own services using the same posting helper.
 */
final class FinancialInvoicePostingService
{
    private DocumentCounterService $counters;

    public function __construct(?DocumentCounterService $counters = null)
    {
        $this->counters = $counters ?: new DocumentCounterService();
    }

    /**
     * @param array{net:string,tax?:string,taxable?:string} $totals
     * @return array{journal_head_id:int,journal_id:string,replayed:bool}
     */
    public function postInvoiceFinalization(
        mysqli $conn,
        int $orderId,
        array $totals,
        int $customerAccountId,
        int $revenueAccountId,
        int $userId,
        array $context = []
    ): array {
        $net = Money::from($totals['net'] ?? '0')->toString();
        $tax = Money::from($totals['tax'] ?? '0')->toString();
        $vatAccountId = (int) ($context['vat_payable_account_id'] ?? 0);
        $tenant = max(0, (int) ($context['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? 0));
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ('invoice-finalization:' . $orderId)));

        if ($customerAccountId < 1 || $revenueAccountId < 1) {
            throw new InvalidArgumentException('INVOICE_ACCOUNTS_REQUIRED');
        }
        if (!Money::from($net)->isPositive() && Money::from($tax)->toString() === '0.00') {
            throw new InvalidArgumentException('INVOICE_TOTAL_INVALID');
        }

        $existing = JournalPostingService::findByIdempotencyKey($conn, $idempotencyKey);
        if ($existing !== null) {
            return [
                'journal_head_id' => (int) $existing['id'],
                'journal_id' => (string) $existing['journal_id'],
                'replayed' => true,
            ];
        }

        $revenueCredit = Money::from($net)->subtract(Money::from($tax))->toString();
        if (Money::from($tax)->isPositive()) {
            if ($vatAccountId < 1) {
                throw new InvalidArgumentException('VAT_PAYABLE_ACCOUNT_REQUIRED');
            }
            // Inclusive net already includes tax; exclusive net is pre-tax.
            if (!empty($context['tax_inclusive'])) {
                $revenueCredit = Money::from($net)->subtract(Money::from($tax))->toString();
            } else {
                $revenueCredit = Money::from($totals['taxable'] ?? $revenueCredit)->toString();
                $net = Money::from($revenueCredit)->add(Money::from($tax))->toString();
            }
        } else {
            $revenueCredit = $net;
        }

        $entries = [
            ['account_id' => $customerAccountId, 'debit' => $net, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
            ['account_id' => $revenueAccountId, 'debit' => '0.00', 'credit' => $revenueCredit, 'tybe' => 1, 'op2' => $orderId],
        ];
        if (Money::from($tax)->isPositive()) {
            $entries[] = [
                'account_id' => $vatAccountId,
                'debit' => '0.00',
                'credit' => $tax,
                'tybe' => 1,
                'op2' => $orderId,
            ];
        }

        $seedRow = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads')->fetch_assoc();
        $seed = (int) ($seedRow['max_id'] ?? 0);
        $this->counters->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:default', $seed);
        $journalId = (string) $this->counters->nextJournalId($conn, $tenant, $branch);

        $headId = JournalPostingService::postBalancedHead(
            $conn,
            $journalId,
            $net,
            (string) ($context['jdate'] ?? date('Y-m-d')),
            'Invoice finalization order ' . $orderId,
            $userId,
            $entries,
            [
                'source_type' => 'invoice',
                'source_id' => $orderId,
                'posting_kind' => 'invoice_finalization',
                'idempotency_key' => $idempotencyKey,
                'op_id' => $orderId,
                'op2' => $orderId,
            ]
        );

        return [
            'journal_head_id' => $headId,
            'journal_id' => $journalId,
            'replayed' => false,
        ];
    }
}
