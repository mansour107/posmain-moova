<?php

$endpoints = [
    'ajax/delete_order.php' => [
        'messages' => ['تم إلغاء الطلب بنجاح'],
    ],
    'ajax/clear_table.php' => [
        'messages' => ['تم تفريغ الطاولة وإلغاء الطلب بدون حذف نهائي'],
    ],
    'ajax/clear_table_normal.php' => [
        'messages' => ['الطاولة فارغة بالفعل', 'تم تفريغ الطاولة بنجاح'],
    ],
    'ajax/update_table_status.php' => [
        'messages' => ['تم تفريغ الطاولة بنجاح', 'تم تشغيل الطاولة بنجاح'],
    ],
];

foreach ($endpoints as $path => $expectations) {
    $sourcePath = __DIR__ . '/../../' . $path;
    $source = file_get_contents($sourcePath);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    tableCancelRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, $path . ' should require PosOrderMutationService');
    tableCancelRoutingAssert(strpos($source, '$posMutationService = new PosOrderMutationService()') !== false, $path . ' should instantiate PosOrderMutationService');
    tableCancelRoutingAssert(strpos($source, '$posMutationService->cancelTableOrder') !== false, $path . ' should route cancel through PosOrderMutationService');
    tableCancelRoutingAssert(strpos($source, 'SyncOutboxEventService') !== false, $path . ' should preserve sync outbox recording');
    tableCancelRoutingAssert(strpos($source, 'order.cancelled') !== false || $path === 'ajax/update_table_status.php', $path . ' should preserve order cancellation outbox event');
    tableCancelRoutingAssert(strpos($source, 'table.updated') !== false, $path . ' should preserve table update outbox event');

    foreach ($expectations['messages'] as $message) {
        tableCancelRoutingAssert(strpos($source, $message) !== false, $path . ' should preserve Arabic response message: ' . $message);
    }
}

echo "table-cancel-endpoint-routing-ok\n";

function tableCancelRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
