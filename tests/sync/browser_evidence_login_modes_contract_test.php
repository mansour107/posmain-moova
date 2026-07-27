<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/tools/generate_browser_evidence.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: browser evidence generator is unreadable\n");
    exit(1);
}

$required = [
    'needle_groups',
    'id="uname"',
    'id="password"',
    'id="mainPinPad"',
    'ajax/main_pin_login.php',
    'http_get_last_response_headers',
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: browser evidence generator missing {$needle}\n");
        exit(1);
    }
}

echo "browser-evidence-login-modes-contract-ok\n";
