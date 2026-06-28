<?php

require_once __DIR__ . '/../classes/Pos/Service/PosCustomerOrderSideEffects.php';

if (!function_exists('posmain_apply_crm_order_side_effects')) {
    /**
     * Apply CRM link + payment rollup for a saved/paid order (legacy invoice path).
     */
    function posmain_apply_crm_order_side_effects(
        mysqli $conn,
        int $orderId,
        array $request,
        string $orderTypeDb,
        string $paymentStatus,
        float $paidAmount
    ): void {
        if ($orderId < 1 || (int) ($request['pro_tybe'] ?? 9) !== 9) {
            return;
        }

        $fulfillmentType = match (strtolower(trim($orderTypeDb))) {
            'table' => 'table',
            'delivery' => 'delivery',
            default => 'takeaway',
        };

        $sideEffects = new PosCustomerOrderSideEffects();
        $sideEffects->afterOrderSaved($conn, $orderId, $request, $fulfillmentType, [
            'paid_amount' => $paidAmount,
            'payment_status' => $paymentStatus,
        ]);
    }
}
