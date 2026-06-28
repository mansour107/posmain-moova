<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerPhoneService.php';

$service = new PosCustomerPhoneService();

posCustomerPhoneAssert($service->normalizePhone('01001234567') === '201001234567', 'egypt local mobile should normalize to 20 prefix');
posCustomerPhoneAssert($service->normalizePhone('+20 100 123 4567') === '201001234567', 'formatted international should normalize');
posCustomerPhoneAssert($service->normalizePhone('') === '', 'empty phone should normalize to empty');
posCustomerPhoneAssert($service->isValidPhone('01001234567'), 'valid egypt mobile should pass validation');
posCustomerPhoneAssert(!$service->isValidPhone('123'), 'short phone should fail validation');
posCustomerPhoneAssert($service->displayPhone('201001234567') === '01001234567', 'display should restore leading zero');
posCustomerPhoneAssert(str_ends_with($service->maskPhone('01001234567'), '4567'), 'mask should keep last four digits');

echo "pos-customer-phone-service-contract-ok\n";

function posCustomerPhoneAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
