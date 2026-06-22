<?php

require_once __DIR__ . '/../../classes/Pos/Service/DeliveryClientService.php';

$root = dirname(__DIR__, 2);
$saveSource = file_get_contents($root . '/do/save_customer.php');
$searchSource = file_get_contents($root . '/do/search_customer.php');
$posDeliveryJs = file_get_contents($root . '/js/pos_delivery.js');
$posBarcodeJs = file_get_contents($root . '/js/pos_barcode.js');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$doaddInvoice = file_get_contents($root . '/do/doadd_invoice.php');

deliveryValidationAssert(strpos($saveSource, 'DeliveryClientService') !== false, 'save_customer should use DeliveryClientService');
deliveryValidationAssert(strpos($searchSource, 'json_encode') !== false || strpos($searchSource, 'DeliveryClientService') !== false, 'search_customer should return safe JSON');
deliveryValidationAssert(strpos($posDeliveryJs, 'تأكيد بيانات العميل') !== false || strpos($posContent, 'تأكيد بيانات العميل') !== false, 'confirm button should be renamed');
deliveryValidationAssert(strpos($posDeliveryJs, 'isCustomerFormComplete') !== false, 'delivery JS should gate confirm on complete form');
deliveryValidationAssert(strpos($posDeliveryJs, 'response.success') !== false, 'delivery JS should check response.success');
deliveryValidationAssert(strpos($posBarcodeJs, "orderMode === '3'") !== false, 'validatePOSForm should validate delivery mode');
deliveryValidationAssert(strpos($posBarcodeJs, 'posDeliveryIsReadyForSubmit') !== false, 'validatePOSForm should call delivery readiness helper');
deliveryValidationAssert(strpos($doaddInvoice, 'يجب إدخال بيانات عميل الدليفري') !== false, 'backend should require delivery customer fields');
deliveryValidationAssert(strpos($doaddInvoice, 'createDeliveryOrder') !== false, 'delivery orders should route through createDeliveryOrder when v2 enabled');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'sumPostedItemSubtotal') !== false, 'delivery create should recompute net from line subtotal and fee');
deliveryValidationAssert(strpos(file_get_contents($root . '/ajax/delivery_status_update.php'), "require_permission('delivery.dispatch'") !== false || strpos(file_get_contents($root . '/ajax/delivery_status_update.php'), "auth_guard_has_permission('delivery.dispatch'") !== false, 'delivery status API should require dispatch permission');
deliveryValidationAssert(strpos(file_get_contents($root . '/do/doedit_delivery_zone.php'), "require_permission('delivery.zones.manage'") !== false, 'delivery zone writes should require zones permission');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/PosOrderService.php'), 'replaceMoovaDeliveryOrder') !== false, 'Moova delivery edits should have dedicated replace path');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/Pos/Service/OrderFulfillmentService.php'), 'mergeMoovaFulfillmentData') !== false, 'Moova fulfillment should merge partial edits');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/Pos/Service/DeliveryZoneService.php'), 'resolvePostedZone') !== false, 'delivery zone fee should resolve server-side');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'qty * ($price - $discount)') !== false, 'line subtotal should apply per-unit discount');
deliveryValidationAssert(strpos(file_get_contents($root . '/delivery_board.php'), "require_permission('delivery.dispatch'") !== false && strpos(file_get_contents($root . '/delivery_board.php'), "include('includes/header.php')") !== false, 'delivery board should guard before header include');
deliveryValidationAssert(strpos(file_get_contents($root . '/ajax/delivery_status_update.php'), 'cancelDeliveryOrder') !== false, 'delivery cancel should void through cancelDeliveryOrder');
deliveryValidationAssert(strpos(file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php'), 'resolveDeliveryPostedTotals') !== false, 'delivery edit should resolve posted totals server-side');
deliveryValidationAssert(strpos(file_get_contents($root . '/do/doedit_invoice.php'), 'resolveDeliveryPostedTotals') !== false, 'doedit_invoice should harden delivery totals');

echo "delivery_validation_contract_test: OK\n";

function deliveryValidationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_validation_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
