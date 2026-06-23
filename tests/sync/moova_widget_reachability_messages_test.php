<?php

$root = dirname(__DIR__, 2);
$proxySource = file_get_contents($root . '/moova_pos_proxy.php');
$widgetSource = file_get_contents($root . '/assets/moova-pos-widget/pos-widget.js');

function moovaReachabilityAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

moovaReachabilityAssert(is_string($proxySource), 'proxy source missing');
moovaReachabilityAssert(is_string($widgetSource), 'widget source missing');

foreach ([
    'function moova_proxy_reachability_error',
    "'error' => 'moova_unreachable'",
    "'code' => 'MOOVA_UNREACHABLE'",
    "'retryable' => true",
    "'details' => (string) \$details",
    'function moova_proxy_local_passive_bridge_payload',
    "'mode' => 'local_passive_fallback'",
    "'remoteReachable' => false",
    'moova_proxy_json(200, moova_proxy_local_passive_bridge_payload($path, $link, $error))',
    "moova_proxy_json(200, moova_proxy_local_passive_bridge_payload(\$path, \$link, 'http_status_' . \$statusCode))",
    'moova_proxy_json(502, moova_proxy_reachability_error($error))',
] as $needle) {
    moovaReachabilityAssert(strpos($proxySource, $needle) !== false, 'proxy reachability contract missing: ' . $needle);
}

foreach ([
    'function moova_proxy_is_passive_bridge_path',
    "'/api/integrations/pos/local-bridge/widget/bootstrap'",
    "'/api/integrations/pos/local-bridge/heartbeat'",
    "'/api/integrations/pos/local-bridge/pending'",
    'function moova_proxy_timeout_config',
    "'connect_ms' => 800",
    "'total_ms' => 2000",
    'CURLOPT_CONNECTTIMEOUT_MS',
    'CURLOPT_TIMEOUT_MS',
    'CURLOPT_NOSIGNAL',
] as $needle) {
    moovaReachabilityAssert(strpos($proxySource, $needle) !== false, 'proxy passive bridge contract missing: ' . $needle);
}
moovaReachabilityAssert(strpos($proxySource, 'CURLOPT_TIMEOUT, 15') === false, 'proxy should not use legacy CURLOPT_TIMEOUT, 15');

foreach ([
    'moovaUnreachable:',
    'posUnreachable:',
    'تعذر الاتصال بـ Moova',
    'تعذر الاتصال بنظام نقاط البيع',
    'function normalizeApiErrorPayload(payload, status)',
    "case 'MOOVA_UNREACHABLE':",
    "case 'POS_UNREACHABLE':",
    "return t('moovaUnreachable')",
    "return t('posUnreachable')",
    "window.addEventListener('online', handleBrowserOnline)",
    "window.addEventListener('offline', handleBrowserOffline)",
    'function isBrowserOffline()',
    'function isMoovaBridgePath(path)',
    "isMoovaBridgePath(path) && isBrowserOffline()",
    "throw createWidgetError(t('moovaUnreachable'), 0, 'MOOVA_UNREACHABLE')",
    'cleanupRealtime();',
    'initializeWidget(state.activeInitKey);',
    'function applyBridgeHealth(result)',
    'function extractBridgeTransportError(result)',
    'result.fallback !== true && result.remoteReachable !== false',
    'applyBridgeHealth(bootstrap)',
    'applyBridgeHealth(result)',
    "const code = asText(warning.code) || 'MOOVA_UNREACHABLE'",
    'throw createWidgetError(errorInfo.message, response.status, errorInfo.code, payload)',
    'error.errorPayload = payload || null',
] as $needle) {
    moovaReachabilityAssert(strpos($widgetSource, $needle) !== false, 'widget reachability contract missing: ' . $needle);
}
moovaReachabilityAssert(
    strpos($widgetSource, "payload && typeof payload.error === 'string' ? payload.error") === false,
    'widget should not expose raw payload.error directly'
);

echo "moova-widget-reachability-messages-ok\n";
