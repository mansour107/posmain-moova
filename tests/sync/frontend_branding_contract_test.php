<?php

$root = dirname(__DIR__, 2);

$uiSources = [
    'index.php',
    'change_pin.php',
    'news.php',
    'pos_time.php',
    'pre_start.php',
    'sql_error.php',
    'includes/header.php',
    'includes/head.php',
    'includes/pos_simple_header.php',
    'includes/posheader.php',
    'includes/sidebar.php',
    'print/contracta4.php',
    'print/daily_sales_receipt.php',
    'print/presc_print.php',
    'print/receipt.php',
    'print/receipt_waiter.php',
    'print/shift_sales_receipt.php',
    'language/ar.php',
    'language/ch.php',
    'language/en.php',
    'language/fr.php',
    'language/gr.php',
    'language/hn.php',
    'language/sp.php',
    'language/trk.php',
    'language/urd.php',
];

$uiContent = '';
foreach ($uiSources as $relativePath) {
    $path = $root . '/' . $relativePath;
    frontendBrandingAssert(is_file($path), "missing UI source {$relativePath}");
    $uiContent .= "\n" . file_get_contents($path);
}

frontendBrandingAssert(is_file($root . '/assets/logo/moova.png'), 'Moova logo asset must exist');
frontendBrandingAssert(filesize($root . '/assets/logo/moova.png') > 0, 'Moova logo asset must not be empty');

foreach ([
    'Kody POS',
    'KODY',
    'كودي 2',
    'HORS TECH',
    '<h1>HORSTEC</h1>',
    'assets/logo/hors.png',
    'assets/logo/logo.jpg',
    'assets/favicon/favicon.png',
    '22947314.png',
    'assets/print/header.jpeg',
    'assets/print/footer.jpeg',
    'assets/footer.jpeg',
] as $legacyBrand) {
    frontendBrandingAssert(
        strpos($uiContent, $legacyBrand) === false,
        "legacy frontend branding remains: {$legacyBrand}"
    );
}

frontendBrandingAssert(strpos($uiContent, 'assets/logo/moova.png') !== false, 'UI must reference the Moova logo asset');

echo "frontend-branding-contract-ok\n";

function frontendBrandingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "frontend_branding_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
