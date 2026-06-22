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

echo "delivery_validation_contract_test: OK\n";

function deliveryValidationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_validation_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
