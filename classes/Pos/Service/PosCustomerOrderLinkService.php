<?php

require_once __DIR__ . '/PosCustomerService.php';
require_once __DIR__ . '/OrderFulfillmentService.php';

class PosCustomerOrderLinkService
{
    private PosCustomerService $customerService;
    private OrderFulfillmentService $fulfillmentService;

    public function __construct(?PosCustomerService $customerService = null, ?OrderFulfillmentService $fulfillmentService = null)
    {
        $this->customerService = $customerService ?: new PosCustomerService();
        $this->fulfillmentService = $fulfillmentService ?: new OrderFulfillmentService();
    }

    public function resolveCustomerIdFromRequest(array $request): int
    {
        $customerId = (int) ($request['pos_customer_id'] ?? $request['customer_id'] ?? 0);
        if ($customerId > 0) {
            return $customerId;
        }

        return 0;
    }

    public function linkOrder(mysqli $conn, int $orderId, array $request, string $fulfillmentType, array $options = []): ?array
    {
        if ($orderId < 1) {
            return null;
        }

        $customerId = $this->resolveCustomerIdFromRequest($request);
        if ($customerId < 1) {
            return null;
        }

        $profile = $this->customerService->getProfile($conn, $customerId, false);
        if (!$profile) {
            return null;
        }

        $primaryPhone = (string) ($profile['primary_phone'] ?? '');
        $address = '';
        if (!empty($profile['addresses'])) {
            foreach ($profile['addresses'] as $addr) {
                if (!empty($addr['is_default'])) {
                    $address = (string) $addr['address_text'];
                    break;
                }
            }
            if ($address === '') {
                $address = (string) ($profile['addresses'][0]['address_text'] ?? '');
            }
        }

        if ($address === '' && !empty($request['delivery_customer_address'])) {
            $address = trim((string) $request['delivery_customer_address']);
        }
        if ($primaryPhone === '' && !empty($request['delivery_customer_phone'])) {
            $primaryPhone = trim((string) $request['delivery_customer_phone']);
        }

        $data = [
            'order_channel' => (string) ($options['order_channel'] ?? 'cashier'),
            'fulfillment_type' => $fulfillmentType,
            'customer_name' => (string) ($profile['display_name'] ?? ''),
            'customer_phone' => $primaryPhone,
            'customer_address' => $address !== '' ? $address : null,
            'pos_customer_id' => $customerId,
            'delivery_zone' => trim((string) ($request['delivery_zone_name'] ?? '')),
            'delivery_fee' => (float) ($request['delivery_fee'] ?? 0),
            'delivery_status' => $fulfillmentType === 'delivery' ? 'pending' : 'none',
        ];

        return $this->fulfillmentService->upsertForOrder($conn, $orderId, $data, $options);
    }

    public function recordPaidOrder(mysqli $conn, int $orderId, float $paidAmount): void
    {
        if ($orderId < 1) {
            return;
        }

        require_once __DIR__ . '/PosCustomerOrderSideEffects.php';
        (new PosCustomerOrderSideEffects($this))->applyPaymentRollup($conn, $orderId, [
            'paid_amount' => $paidAmount > 0 ? $paidAmount : null,
        ]);
    }
}
