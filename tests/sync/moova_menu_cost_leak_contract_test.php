<?php

$root = dirname(__DIR__, 2);
$items = moovaMenuCostLeakSource($root . '/api/items.php');
$moovaPayload = moovaMenuCostLeakSource($root . '/ajax/moova_menu_sync_payload.php');
$auditService = moovaMenuCostLeakSource($root . '/classes/Recipe/RecipeCostLeakAuditService.php');

moovaMenuCostLeakAssert(
    strpos($items, 'RecipeCostLeakAuditService.php') !== false,
    'public items API should load recipe cost-leak masking service'
);
moovaMenuCostLeakAssert(
    strpos($items, 'posmain_items_api_sanitize_public_payload') !== false,
    'public items API should sanitize the response payload before JSON output'
);
moovaMenuCostLeakAssert(
    strpos($items, "sanitizePayload(\$payload, 'moova-facing api'") !== false,
    'public items API should classify the payload as Moova/customer facing'
);

moovaMenuCostLeakAssert(
    strpos($moovaPayload, 'RecipeCostLeakAuditService.php') !== false,
    'Moova menu sync payload should load recipe cost-leak masking service'
);
moovaMenuCostLeakAssert(
    strpos($moovaPayload, 'moova_menu_sync_sanitize_public_payload($payload)') !== false,
    'Moova menu sync payload should sanitize every JSON response'
);
moovaMenuCostLeakAssert(
    strpos($auditService, "'cost_price'") !== false && strpos($auditService, "'purchase_price'") !== false,
    'cost-leak audit service should classify cost and purchase price as sensitive'
);
moovaMenuCostLeakAssert(
    strpos($auditService, 'normalizeKey') !== false,
    'cost-leak audit service should normalize sensitive keys so camelCase cost fields are masked'
);

echo "moova-menu-cost-leak-contract-ok\n";

function moovaMenuCostLeakSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function moovaMenuCostLeakAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
