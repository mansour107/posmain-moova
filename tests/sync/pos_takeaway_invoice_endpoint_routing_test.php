<?php

$source = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read do/doadd_invoice.php');
}

posTakeawayRoutingAssert(strpos($source, "require_once('../classes/Pos/Service/PosOrderMutationService.php');") !== false, 'handler should load PosOrderMutationService');
posTakeawayRoutingAssert(strpos($source, '$route_takeaway_service = $pro_tybe === INVOICE_TYPES[\'POS\']') !== false, 'route should be scoped to POS invoice type');
posTakeawayRoutingAssert(strpos($source, "\$order_type_db === 'takeaway'") !== false, 'route should be scoped to takeaway orders');
posTakeawayRoutingAssert(strpos($source, "\$submit === 'cash'") !== false, 'route should be scoped to paid cash submit');
posTakeawayRoutingAssert(strpos($source, '$selected_order_id <= 0') !== false, 'route should exclude selected edit orders');
posTakeawayRoutingAssert(strpos($source, "(int) (\$_REQUEST['edit_id'] ?? 0) <= 0") !== false, 'route should exclude edit_id updates');
posTakeawayRoutingAssert(strpos($source, '($paid_cash + $paid_bank) > 0') !== false, 'route should accept paid takeaway cash, bank, or split payment amounts');
posTakeawayRoutingAssert(strpos($source, '$paid_bank <= 0') === false, 'route should not force bank/split payments onto the legacy path');
posTakeawayRoutingAssert(strpos($source, '$mutationService->createTakeawayOrder($conn, $takeawayRequest') !== false, 'handler should route through createTakeawayOrder');
posTakeawayRoutingAssert(strpos($source, "\$_SESSION['success_message'] = 'تم حفظ الطلب بنجاح - رقم الفاتورة: ' . \$pro_id;") !== false, 'route should preserve success message');
posTakeawayRoutingAssert(strpos($source, 'header("Location: ../print/receipt.php?id=$last_op");') !== false, 'route should preserve receipt redirect');
posTakeawayRoutingAssert(strpos($source, 'posmain_browser_exception_response(') !== false, 'route should use the central safe browser error response');
posTakeawayRoutingAssert(strpos($source, "'invoice_takeaway_route'") !== false, 'takeaway service errors should have a logging context');
posTakeawayRoutingAssert(strpos($source, "'invoice_transaction'") !== false, 'legacy transaction errors should have a logging context');

echo "pos-takeaway-invoice-endpoint-routing-ok\n";

function posTakeawayRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
