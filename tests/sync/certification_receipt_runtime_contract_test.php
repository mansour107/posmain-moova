<?php

$root = dirname(__DIR__, 2);
$appConfig = file_get_contents($root . '/config/app_config.php');
$profile = file_get_contents($root . '/config/production_profile.php');
$runtime = file_get_contents($root . '/classes/Release/CertificationReceiptRuntime.php');
$receipt = file_get_contents($root . '/classes/Release/CertificationReceipt.php');

foreach ([
    'POSMAIN_CERTIFICATION_RECEIPT_PATH',
    'POSMAIN_RELEASE_MANIFEST_PATH',
    'CertificationReceiptRuntime::evaluate',
] as $needle) {
    if (!is_string($appConfig) || strpos($appConfig, $needle) === false) {
        throw new RuntimeException('app config missing certification boundary: ' . $needle);
    }
}

foreach ([
    'certification_receipt_valid',
    'production_profile_legacy_inventory_attestation_not_certification',
    'production_profile_legacy_recipe_attestation_not_certification',
    "'financial'",
    "'sync'",
    "'inventory'",
    "'recipe'",
] as $needle) {
    if (!is_string($profile) || strpos($profile, $needle) === false) {
        throw new RuntimeException('production profile missing receipt gate: ' . $needle);
    }
}

foreach ([
    'CERTIFICATION_ROUTER_DATABASE_UNSUPPORTED',
    'POSMAIN_CERTIFICATION_RECEIPT_KEY',
    'verifyReleaseManifest',
    'databaseEvidence',
    'CERTIFICATION_RUNTIME_IDENTITY_MISSING',
] as $needle) {
    if (!is_string($runtime) || strpos($runtime, $needle) === false) {
        throw new RuntimeException('runtime verifier missing fail-closed condition: ' . $needle);
    }
}

foreach ([
    'INFORMATION_SCHEMA.TABLES',
    'INFORMATION_SCHEMA.COLUMNS',
    'INFORMATION_SCHEMA.STATISTICS',
    'CERTIFICATION_RELEASE_FILE_MISMATCH',
    'hash_hmac',
] as $needle) {
    if (!is_string($receipt) || strpos($receipt, $needle) === false) {
        throw new RuntimeException('receipt verifier missing evidence binding: ' . $needle);
    }
}

echo "certification-receipt-runtime-contract-ok\n";
