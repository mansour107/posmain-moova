<?php

$root = dirname(__DIR__, 2);
$widgetSource = file_get_contents($root . '/assets/moova-pos-widget/pos-widget.js');
$hostSource = file_get_contents($root . '/elements/pos/cofe_widget.php');
$docSource = file_get_contents($root . '/docs/production/moova_cashier_ux_evidence.md');

phase5UxAssert(is_string($widgetSource), 'widget source missing');
phase5UxAssert(is_string($hostSource), 'host bridge source missing');
phase5UxAssert(is_string($docSource), 'cashier UX doc missing');

foreach ([
    'pendingApprovals',
    'pos-widget-badge',
    'NOTIFICATION_MUTE_STORAGE_KEY',
    'setNotificationMuted',
    'syncNotificationSoundLoop',
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false || strpos($hostSource, $needle) !== false, "pending/sound contract missing {$needle}");
}

foreach ([
    'declineReasonRequired',
    'A reason is required before declining.',
    'سبب الرفض مطلوب قبل المتابعة.',
    "if (!reason)",
    "reasonInput.setAttribute('aria-invalid', 'true')",
    'openChangeDeclineModal',
    'state.declineCommandId = commandId',
    'required',
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "decline reason contract missing {$needle}");
}
phase5UxAssert(strpos($widgetSource, 'Leave blank to decline without a reason.') === false, 'decline reason still claims optional English behavior');
phase5UxAssert(strpos($widgetSource, 'اتركه فارغاً لرفض الطلب بدون سبب.') === false, 'decline reason still claims optional Arabic behavior');

foreach ([
    'staleOrderChangeConflict',
    "case 'POS_ORDER_LINES_CHANGED':",
    "case 'IDEMPOTENCY_PAYLOAD_CONFLICT':",
    'invalidDeviceToken',
    "case 'DEVICE_TOKEN_REQUIRED':",
    "case 'INTEGRATION_NOT_MAPPED':",
    "case 'TENANT_SCOPE_MISMATCH':",
    'normalizeHostBridgeError',
    'messageForPosBridgeCode',
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "cashier error message mapping missing {$needle}");
}

foreach ([
    'moovaUnreachable',
    'posUnreachable',
    "case 'MOOVA_UNREACHABLE':",
    "case 'POS_UNREACHABLE':",
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "reachability message mapping missing {$needle}");
}

foreach ([
    'customerInfo',
    'customerName',
    'customerPhone',
    'customerAddress',
    'function getCustomerRows(ui)',
    'function renderCustomerInfo(ui)',
    '${renderCustomerInfo(ui)}',
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "customer review UI missing {$needle}");
}

foreach ([
    'function buildFulfillmentBridgePayload',
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "widget fulfillment bridge missing {$needle}");
}

foreach ([
    "'orderChannel'",
    "'fulfillmentType'",
    "'customerName'",
    "'customerPhone'",
    "'customerAddress'",
    "'deliveryZone'",
    "'deliveryFee'",
    "'deliveryStatus'",
    "'promisedAt'",
    "'customer'",
    "'delivery'",
] as $needle) {
    phase5UxAssert(strpos($widgetSource, $needle) !== false, "widget fulfillment bridge missing {$needle}");
    phase5UxAssert(strpos($hostSource, $needle) !== false, "host fulfillment bridge missing {$needle}");
}

foreach ([
    'moovaIsRetryableError',
    "'DEVICE_TOKEN_REQUIRED'",
    "'TENANT_SCOPE_MISMATCH'",
    'retryable: error?.retryable !== false',
    'payload: error?.payload || payload',
] as $needle) {
    phase5UxAssert(strpos($hostSource, $needle) !== false, "host retry/error payload contract missing {$needle}");
}

foreach ([
    'Pending badge',
    'Decline reason',
    'Stale edit/cancel',
    'Invalid token/link',
    'Unreachable services',
] as $needle) {
    phase5UxAssert(strpos($docSource, $needle) !== false, "cashier UX evidence doc missing {$needle}");
}

echo "moova-cashier-ux-contract-ok\n";

function phase5UxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
