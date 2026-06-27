<?php

$root = dirname(__DIR__, 2);
require_once $root . '/config/app_config.php';
require_once $root . '/config/production_profile.php';

foreach ([
    'posmain_production_profile_enabled',
    'posmain_production_profile_matrix',
    'posmain_production_profile_apply',
    'ledger_mode',
    'legacy_mirror',
] as $needle) {
    $source = file_get_contents($root . '/config/production_profile.php');
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "production-profile-contract-FAIL: missing {$needle}\n");
        exit(1);
    }
}

$config = [
    'role' => 'branch',
    'inventory' => ['ledger_mode' => 'shadow', 'legacy_mirror' => false],
    'recipe' => ['mode' => 'consume_pilot', 'moova_sync' => false, 'reservations' => false, 'consumption' => true, 'accounting' => false, 'availability' => false, 'shadow_ledger' => false, 'enabled' => true],
];
putenv('POSMAIN_USE_PRODUCTION_PROFILE=1');
$_ENV['POSMAIN_USE_PRODUCTION_PROFILE'] = '1';
$applied = posmain_production_profile_apply($config);
if (($applied['inventory']['ledger_mode'] ?? '') !== 'live') {
    fwrite(STDERR, "production-profile-contract-FAIL: expected live ledger\n");
    exit(1);
}
if (($applied['recipe']['mode'] ?? '') !== 'full') {
    fwrite(STDERR, "production-profile-contract-FAIL: expected full recipe mode\n");
    exit(1);
}
if (empty($applied['production_profile']['enabled'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: profile not marked enabled\n");
    exit(1);
}

putenv('POSMAIN_USE_PRODUCTION_PROFILE');
unset($_ENV['POSMAIN_USE_PRODUCTION_PROFILE']);
echo "production-profile-contract-ok\n";
