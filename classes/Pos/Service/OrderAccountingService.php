<?php

require_once __DIR__ . '/AccountingPostingService.php';
require_once __DIR__ . '/../../Financial/FinancialInvoicePostingService.php';

class OrderAccountingService
{
    private $postingService;
    private $invoicePostingService;

    public function __construct(
        ?AccountingPostingService $postingService = null,
        ?FinancialInvoicePostingService $invoicePostingService = null
    )
    {
        $this->postingService = $postingService ?: new AccountingPostingService();
        $this->invoicePostingService = $invoicePostingService ?: new FinancialInvoicePostingService();
    }

    public function postTablePaymentReceipt(mysqli $conn, array $request, array $context = []): array
    {
        return $this->postingService->postTablePaymentReceipt($conn, $request, $context);
    }

    public function shouldPostSalesRecognitionOnTablePayment(array $orderRow): bool
    {
        $paymentStatus = (string) ($orderRow['payment_status'] ?? 'unpaid');
        $invoiceStatus = (string) ($orderRow['invoice_status'] ?? 'draft');

        return $paymentStatus === 'paid' && in_array($invoiceStatus, ['paid', 'completed'], true);
    }

    public function postTableInvoiceFinalization(
        mysqli $conn,
        array $orderRow,
        int $revenueAccountId,
        int $userId,
        array $context = []
    ): array {
        $orderId = (int) ($orderRow['id'] ?? 0);
        $customerAccountId = (int) ($orderRow['acc2'] ?? 0);
        if ($orderId < 1 || $customerAccountId < 1 || $revenueAccountId < 1) {
            throw new InvalidArgumentException('INVOICE_ACCOUNTS_REQUIRED');
        }

        return $this->invoicePostingService->postInvoiceFinalization(
            $conn,
            $orderId,
            [
                'net' => (string) ($orderRow['fat_net'] ?? '0'),
                'tax' => (string) ($orderRow['fat_tax'] ?? '0'),
                'taxable' => (string) ($orderRow['fat_total'] ?? $orderRow['fat_net'] ?? '0'),
            ],
            $customerAccountId,
            $revenueAccountId,
            $userId,
            array_merge($context, [
                'idempotency_key' => 'table-invoice:' . $orderId,
                'jdate' => (string) ($orderRow['pro_date'] ?? date('Y-m-d')),
            ])
        );
    }
}
