<?php

$root = dirname(__DIR__, 2);

function posLocalFirstAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$posAssets = file_get_contents($root . '/includes/pos_assets.php');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$moovaWidget = file_get_contents($root . '/elements/pos/cofe_widget.php');
$moovaWidgetCss = file_get_contents($root . '/assets/moova-pos-widget/pos-widget.css');

posLocalFirstAssert(is_string($posAssets), 'Unable to read POS assets include');
posLocalFirstAssert(is_string($posContent), 'Unable to read POS content include');
posLocalFirstAssert(is_string($posJs), 'Unable to read POS barcode JS');
posLocalFirstAssert(is_string($moovaWidget), 'Unable to read Moova POS widget');
posLocalFirstAssert(is_string($moovaWidgetCss), 'Unable to read Moova POS widget CSS');

foreach ([
    'assets/libs/jquery/jquery-3.6.0.min.js',
    'assets/libs/sweetalert2/sweetalert2.min.js',
    'assets/libs/bootstrap.bundle.min.js',
] as $localAsset) {
    posLocalFirstAssert(
        strpos($posAssets . "\n" . $posContent, $localAsset) !== false,
        'POS should load local asset: ' . $localAsset
    );
}

foreach ([
    'https://code.jquery.com',
    'https://cdn.jsdelivr.net',
    'https://unpkg.com',
    'https://cdnjs.cloudflare.com',
    'https://fonts.googleapis.com',
] as $remoteAssetHost) {
    posLocalFirstAssert(
        strpos($posAssets . "\n" . $posContent . "\n" . $moovaWidgetCss, $remoteAssetHost) === false,
        'POS cashier screen must not block local work on remote asset host: ' . $remoteAssetHost
    );
}
posLocalFirstAssert(
    strpos($moovaWidgetCss, '@import url(') === false,
    'Moova widget CSS should not import internet-hosted fonts in the local POS shell'
);

posLocalFirstAssert(
    strpos($posContent, 'POS scripts stay local-first') !== false,
    'POS content should document the local-first asset contract'
);
posLocalFirstAssert(
    strpos($posJs, "url: 'ajax/get_tables.php'") !== false,
    'Table loading should use the local AJAX endpoint'
);
posLocalFirstAssert(
    strpos($moovaWidget, 'ajax/moova_menu_sync_payload.php') !== false,
    'Connected update checks should remain separate from local table loading'
);

echo "pos-local-first-assets-ok\n";
