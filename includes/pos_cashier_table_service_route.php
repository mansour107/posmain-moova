<?php

require_once __DIR__ . '/../classes/Pos/Http/PosOrderController.php';
require_once __DIR__ . '/pos_user_context.php';

if (!function_exists('posmain_should_route_cashier_table_save')) {
    function posmain_should_route_cashier_table_save(array $context): bool
    {
        return (int) ($context['pro_tybe'] ?? 0) === 9
            && (string) ($context['order_type_db'] ?? '') === 'table'
            && !empty($context['is_save_only'])
            && (int) ($context['table_id'] ?? 0) > 0;
    }
}

if (!function_exists('posmain_build_cashier_table_save_payload')) {
    function posmain_build_cashier_table_save_payload(array $post): array
    {
        $items = [];
        if (isset($post['itmname']) && is_array($post['itmname'])) {
            foreach ($post['itmname'] as $index => $itemId) {
                if ((int) $itemId <= 0) {
                    continue;
                }
                $items[] = [
                    'id' => (int) $itemId,
                    'qty' => (float) ($post['itmqty'][$index] ?? 1),
                    'price' => (float) ($post['itmprice'][$index] ?? 0),
                    'discount' => (float) ($post['itmdisc'][$index] ?? 0),
                    'note' => (string) ($post['itmnote'][$index] ?? ''),
                ];
            }
        }

        return [
            'table_id' => (int) ($post['selected_table_id'] ?? $post['table_id'] ?? 0),
            'order_id' => (int) ($post['edit_id'] ?? $post['selected_order_id'] ?? 0),
            'order_date' => (string) ($post['pro_date'] ?? date('Y-m-d')),
            'store_id' => (int) ($post['store_id'] ?? 0),
            'emp_id' => (int) ($post['emp_id'] ?? 0),
            'fund_id' => (int) ($post['fund_id'] ?? 0),
            'items' => $items,
            'total' => (float) ($post['headtotal'] ?? 0),
            'discount' => (float) ($post['headdisc'] ?? 0),
            'net' => (float) ($post['headnet'] ?? 0),
            'idempotency_key' => (string) ($post['idempotency_key'] ?? ''),
            'pos_customer_id' => (int) ($post['pos_customer_id'] ?? 0),
        ];
    }
}

if (!function_exists('posmain_route_cashier_table_save')) {
    function posmain_route_cashier_table_save(mysqli $conn, array $post, int $userId): void
    {
        $controller = new PosOrderController();
        $result = $controller->saveTable($conn, posmain_build_cashier_table_save_payload($post), $_SERVER, $userId);
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $orderId = (int) ($payload['order_id'] ?? 0);

        if (!empty($payload['success'])) {
            $_SESSION['success_message'] = 'تم حفظ الطلب بنجاح - رقم الفاتورة: ' . $orderId;
            header('Location: ../pos_barcode.php?edit=' . $orderId);
            exit;
        }

        throw new RuntimeException((string) ($payload['message'] ?? 'فشل حفظ طلب الطاولة'));
    }
}
