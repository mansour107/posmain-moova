<?php

require_once __DIR__ . '/AccountingPostingService.php';

class OrderAccountingService
{
    private $postingService;

    public function __construct(?AccountingPostingService $postingService = null)
    {
        $this->postingService = $postingService ?: new AccountingPostingService();
    }

    public function postTablePaymentReceipt(mysqli $conn, array $request, array $context = []): array
    {
        return $this->postingService->postTablePaymentReceipt($conn, $request, $context);
    }

    public function shouldPostSalesRecognitionOnTablePayment(array $orderRow): bool
    {
        $paymentStatus = (string) ($orderRow['payment_status'] ?? 'unpaid');
        $invoiceStatus = (string) ($orderRow['invoice_status'] ?? 'draft');

        return in_array($paymentStatus, ['paid', 'partial'], true)
            || in_array($invoiceStatus, ['paid', 'completed'], true);
    }
}
