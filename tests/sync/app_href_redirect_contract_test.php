<?php

require_once __DIR__ . '/../../includes/auth_guard.php';

function appHrefContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$originalScript = $_SERVER['SCRIPT_NAME'] ?? null;

$_SERVER['SCRIPT_NAME'] = '/print/receipt.php';
appHrefContractAssert(posmain_app_href('index.php') === '/index.php', 'print receipt deny must redirect to /index.php not /print/index.php');
appHrefContractAssert(posmain_app_href('pos_barcode.php?logout=1') === '/pos_barcode.php?logout=1', 'print nested POS redirect must stay app-root');
appHrefContractAssert(posmain_app_href('/already/absolute.php') === '/already/absolute.php', 'absolute paths must pass through');

$_SERVER['SCRIPT_NAME'] = '/do/do_logout.php';
appHrefContractAssert(posmain_app_href('index.php') === '/index.php', 'do/ nested redirect must climb to app root');

$_SERVER['SCRIPT_NAME'] = '/pos_barcode.php';
appHrefContractAssert(posmain_app_href('index.php') === '/index.php', 'root script redirect stays /index.php');

$_SERVER['SCRIPT_NAME'] = '/branch/print/receipt.php';
appHrefContractAssert(posmain_app_href('index.php') === '/branch/index.php', 'subdirectory installs must preserve app base');

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
$receipt = $manifest['print/receipt.php'] ?? null;
appHrefContractAssert(is_array($receipt), 'print/receipt.php must be classified');
$anyOf = $receipt['any_of'] ?? [];
appHrefContractAssert(is_array($anyOf) && in_array('pos.payment.take', $anyOf, true), 'receipt route must allow payment-takers for pay-and-print');
appHrefContractAssert(in_array('pos.reprint', $anyOf, true), 'receipt route must still allow reprint capability');

if ($originalScript === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $originalScript;
}

fwrite(STDOUT, "OK app_href_redirect_contract_test\n");
exit(0);
