<?php

$root = dirname(__DIR__, 2);

function moovaOfflineLocalFirstAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$proxy = file_get_contents($root . '/moova_pos_proxy.php');
$widget = file_get_contents($root . '/assets/moova-pos-widget/pos-widget.js');

moovaOfflineLocalFirstAssert(is_string($proxy), 'Unable to read Moova POS proxy');
moovaOfflineLocalFirstAssert(is_string($widget), 'Unable to read Moova POS widget');

foreach ([
    'function moova_proxy_is_passive_bridge_path',
    "'/api/integrations/pos/local-bridge/widget/bootstrap'",
    "'/api/integrations/pos/local-bridge/heartbeat'",
    "'/api/integrations/pos/local-bridge/pending'",
    'CURLOPT_CONNECTTIMEOUT_MS',
    'CURLOPT_TIMEOUT_MS',
    "'connect_ms' => 800",
    "'total_ms' => 2000",
    'function moova_proxy_local_passive_bridge_payload',
    "'mode' => 'local_passive_fallback'",
    "'remoteReachable' => false",
    'moova_proxy_json(200, moova_proxy_local_passive_bridge_payload($path, $link, $error))',
] as $needle) {
    moovaOfflineLocalFirstAssert(
        strpos($proxy, $needle) !== false,
        'Moova proxy should fail fast for offline passive bridge requests: ' . $needle
    );
}

foreach ([
    "window.addEventListener('online', handleBrowserOnline)",
    "window.addEventListener('offline', handleBrowserOffline)",
    'function isBrowserOffline()',
    'function isMoovaBridgePath(path)',
    "isMoovaBridgePath(path) && isBrowserOffline()",
    "throw createWidgetError(t('moovaUnreachable'), 0, 'MOOVA_UNREACHABLE')",
    'cleanupRealtime();',
    'initializeWidget(state.activeInitKey);',
] as $needle) {
    moovaOfflineLocalFirstAssert(
        strpos($widget, $needle) !== false,
        'Moova widget should pause offline bridge calls and recover online: ' . $needle
    );
}

echo "moova-offline-local-first-contract-ok\n";
