<?php

$source = file_get_contents(__DIR__ . '/certification_receipt_runtime_integration_test.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('certification runtime integration test must be readable');
}

foreach ([
    'POSMAIN_CERTIFICATION_TEST_DISPOSABLE',
    'CERTIFICATION_TEST_LOCAL_DATABASE_REQUIRED',
    "'posmain_certification_test_' . getmypid()",
    "'/^posmain_certification_test_[0-9]+_[a-f0-9]{8}$/'",
    'DROP DATABASE IF EXISTS',
    'finally',
] as $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('missing certification fixture safety control: ' . $needle);
    }
}
if (strpos($source, 'kody2') !== false || strpos($source, 'POSMAIN_DB_NAME') !== false) {
    throw new RuntimeException('certification fixture must not inherit the standing application database');
}

echo "certification-receipt-runtime-isolation-ok\n";
