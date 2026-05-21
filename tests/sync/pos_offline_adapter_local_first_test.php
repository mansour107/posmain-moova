<?php

$root = dirname(__DIR__, 2);

function posOfflineAdapterLocalFirstAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$adapter = file_get_contents($root . '/js/pos_offline_adapter.js');
posOfflineAdapterLocalFirstAssert(is_string($adapter), 'Unable to read POS offline adapter');

foreach ([
    'shouldTryLocalFirst',
    'isSameOriginRequest',
    'tryLocalAjaxRequest',
    'this.originalAjax.apply(context, originalArgs)',
    'Local request failed while offline - trying fallback',
] as $needle) {
    posOfflineAdapterLocalFirstAssert(
        strpos($adapter, $needle) !== false,
        'Offline adapter should try local POS requests before cached fallback: ' . $needle
    );
}

$offlineGate = strpos($adapter, 'if (!self.isOnline && self.shouldTryLocalFirst(requestOptions))');
$fallbackGate = strpos($adapter, 'if (!self.isOnline) {');
posOfflineAdapterLocalFirstAssert(
    $offlineGate !== false && $fallbackGate !== false && $offlineGate < $fallbackGate,
    'Offline adapter should route same-origin local requests before generic offline fallback'
);

foreach ([
    'get_tables.php',
    'handleTablesRequest',
    'this.offlineData.tables = data.tables',
] as $needle) {
    posOfflineAdapterLocalFirstAssert(
        strpos($adapter, $needle) !== false,
        'Offline adapter should cache/fallback table data without blocking local table AJAX: ' . $needle
    );
}

echo "pos-offline-adapter-local-first-ok\n";
