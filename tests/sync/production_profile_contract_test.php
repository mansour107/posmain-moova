<?php

$root = dirname(__DIR__, 2);
require_once $root . '/config/app_config.php';
require_once $root . '/config/production_profile.php';

foreach ([
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
    'tax' => ['enabled' => true, 'rate' => '14.00', 'inclusive' => true],
    'inventory' => ['ledger_mode' => 'shadow', 'legacy_mirror' => false, 'cutover_certified' => false],
    'recipe' => ['mode' => 'consume_pilot', 'moova_sync' => false, 'reservations' => false, 'consumption' => true, 'accounting' => false, 'availability' => false, 'shadow_ledger' => false, 'enabled' => true, 'rollout_certified' => false],
];
$applied = posmain_production_profile_apply($config);
if (($applied['inventory']['ledger_mode'] ?? '') !== 'shadow' || empty($applied['inventory']['legacy_mirror'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: uncertified inventory must remain shadow with compatibility mirror\n");
    exit(1);
}
if (!empty($applied['inventory']['accounting'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: uncertified shadow inventory must not post journals\n");
    exit(1);
}
if (($applied['recipe']['mode'] ?? '') !== 'read_only' || !empty($applied['recipe']['consumption']) || !empty($applied['recipe']['accounting'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: uncertified recipes must remain read-only\n");
    exit(1);
}
if (empty($applied['production_profile']['enabled'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: profile not marked enabled\n");
    exit(1);
}
if (!empty($applied['tax']['enabled']) || ($applied['tax']['rate'] ?? null) !== '0.00' || !empty($applied['tax']['inclusive'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: tax must be forced off for V1\n");
    exit(1);
}
if (!in_array('production_profile_tax_forced_off', $applied['production_profile_warnings'] ?? [], true)) {
    fwrite(STDERR, "production-profile-contract-FAIL: tax override should be reported\n");
    exit(1);
}

$defaults = posmain_app_config([
    'production_mode' => true,
]);
$defaults = posmain_production_profile_apply($defaults);
if (($defaults['inventory']['ledger_mode'] ?? '') !== 'shadow') {
    fwrite(STDERR, "production-profile-contract-FAIL: uncertified app defaults should use shadow inventory\n");
    exit(1);
}
if (($defaults['recipe']['mode'] ?? '') !== 'read_only') {
    fwrite(STDERR, "production-profile-contract-FAIL: uncertified app defaults should use read-only recipes\n");
    exit(1);
}

$certified = posmain_production_profile_apply([
    'role' => 'branch',
    'tax' => ['enabled' => false],
    'inventory' => ['ledger_mode' => 'live', 'legacy_mirror' => true, 'cutover_certified' => true],
    'recipe' => ['mode' => 'full', 'rollout_certified' => true, 'moova_sync' => false],
]);
if (($certified['inventory']['ledger_mode'] ?? '') !== 'live' || !empty($certified['inventory']['legacy_mirror'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certified inventory should activate live ledger\n");
    exit(1);
}
if (empty($certified['inventory']['accounting'])
    || empty($certified['inventory']['reservations'])
    || empty($certified['inventory']['availability'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certified live inventory must enable accounting, reservations, and availability\n");
    exit(1);
}
if (($certified['recipe']['mode'] ?? '') !== 'full'
    || empty($certified['recipe']['consumption'])
    || empty($certified['recipe']['accounting'])
    || empty($certified['recipe']['availability'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certified recipes should activate full runtime\n");
    exit(1);
}

echo "production-profile-contract-ok\n";
