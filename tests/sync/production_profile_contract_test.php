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
    'certification' => [
        'valid' => true,
        'requested' => true,
        'gates' => ['financial' => 1, 'sync' => 1, 'inventory' => 1, 'recipe' => 1],
    ],
    'inventory' => [
        'ledger_mode' => 'live',
        'quantity_tracking' => true,
        'legacy_mirror' => true,
        'cutover_certified' => true,
        'accounting' => false,
        'reservations' => false,
        'availability' => true,
    ],
    'recipe' => [
        'mode' => 'consume_pilot',
        'rollout_certified' => true,
        'moova_sync' => false,
        'consumption' => true,
        'accounting' => false,
        'availability' => true,
    ],
]);
if (($certified['inventory']['ledger_mode'] ?? '') !== 'live' || !empty($certified['inventory']['legacy_mirror'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certified inventory should activate live ledger\n");
    exit(1);
}
if (!empty($certified['inventory']['accounting'])
    || !empty($certified['inventory']['reservations'])
    || empty($certified['inventory']['availability'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certification must preserve independent inventory capabilities\n");
    exit(1);
}
if (($certified['recipe']['mode'] ?? '') !== 'consume_pilot'
    || empty($certified['recipe']['consumption'])
    || !empty($certified['recipe']['accounting'])
    || empty($certified['recipe']['availability'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certification must preserve requested recipe capabilities\n");
    exit(1);
}

$noOptionalModules = posmain_production_profile_apply([
    'role' => 'branch',
    'tax' => ['enabled' => false],
    'certification' => [
        'valid' => true,
        'requested' => true,
        'gates' => ['financial' => 1, 'sync' => 1, 'inventory' => 1, 'recipe' => 1],
    ],
    'inventory' => [
        'ledger_mode' => 'off',
        'quantity_tracking' => false,
        'cutover_certified' => true,
        'accounting' => false,
        'reservations' => false,
        'availability' => false,
    ],
    'recipe' => ['mode' => 'off', 'rollout_certified' => true],
]);
if (($noOptionalModules['inventory']['ledger_mode'] ?? '') !== 'off'
    || !empty($noOptionalModules['inventory']['quantity_tracking'])
    || ($noOptionalModules['recipe']['mode'] ?? '') !== 'off'
    || !empty($noOptionalModules['recipe']['enabled'])) {
    fwrite(STDERR, "production-profile-contract-FAIL: certified basic POS must not force inventory or recipes\n");
    exit(1);
}

$recipeWithoutQuantity = posmain_production_profile_apply([
    'role' => 'branch',
    'tax' => ['enabled' => false],
    'certification' => [
        'valid' => true,
        'requested' => true,
        'gates' => ['financial' => 1, 'sync' => 1, 'inventory' => 1, 'recipe' => 1],
    ],
    'inventory' => [
        'ledger_mode' => 'off',
        'quantity_tracking' => false,
        'cutover_certified' => true,
        'accounting' => false,
    ],
    'recipe' => [
        'mode' => 'consume_pilot',
        'rollout_certified' => true,
        'enabled' => true,
        'consumption' => true,
        'pilot' => ['pos_branch' => '1'],
    ],
]);
if (($recipeWithoutQuantity['recipe']['mode'] ?? '') !== 'read_only'
    || !empty($recipeWithoutQuantity['recipe']['consumption'])
    || empty($recipeWithoutQuantity['recipe']['shadow_ledger'])
    || empty($recipeWithoutQuantity['production_profile']['recipe_activation_blocked'])
    || !in_array(
        'production_profile_active_recipe_requires_inventory_quantity_tracking',
        $recipeWithoutQuantity['production_profile_warnings'] ?? [],
        true
    )) {
    fwrite(STDERR, "production-profile-contract-FAIL: active recipes must downgrade when quantity tracking is disabled\n");
    exit(1);
}

$legacyFlagsOnly = posmain_production_profile_apply([
    'role' => 'branch',
    'tax' => ['enabled' => false],
    'certification' => ['valid' => false, 'requested' => false, 'gates' => []],
    'inventory' => [
        'ledger_mode' => 'live',
        'quantity_tracking' => true,
        'cutover_certified' => true,
    ],
    'recipe' => [
        'mode' => 'full',
        'rollout_certified' => true,
        'consumption' => true,
        'accounting' => true,
    ],
]);
if (($legacyFlagsOnly['inventory']['ledger_mode'] ?? '') !== 'shadow'
    || !empty($legacyFlagsOnly['inventory']['quantity_tracking'])
    || ($legacyFlagsOnly['recipe']['mode'] ?? '') !== 'read_only'
    || !in_array(
        'production_profile_legacy_inventory_attestation_not_certification',
        $legacyFlagsOnly['production_profile_warnings'] ?? [],
        true
    )
    || !in_array(
        'production_profile_legacy_recipe_attestation_not_certification',
        $legacyFlagsOnly['production_profile_warnings'] ?? [],
        true
    )) {
    fwrite(STDERR, "production-profile-contract-FAIL: legacy booleans alone must not activate certified capabilities\n");
    exit(1);
}

echo "production-profile-contract-ok\n";
