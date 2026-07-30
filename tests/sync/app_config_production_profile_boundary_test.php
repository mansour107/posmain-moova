<?php

require_once __DIR__ . '/../../config/app_config.php';

function appConfigProductionProfileBoundaryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$previous = [];
$names = [
    'POSMAIN_ENV',
    'POSMAIN_PRODUCTION_MODE',
    'POSMAIN_INVENTORY_CUTOVER_CERTIFIED',
    'POSMAIN_INVENTORY_QUANTITY_TRACKING',
    'POSMAIN_RECIPE_ROLLOUT_CERTIFIED',
    'POSMAIN_RECIPE_MODE',
    'POSMAIN_RECIPE_RESERVATIONS',
    'POSMAIN_RECIPE_CONSUMPTION',
];
foreach ($names as $name) {
    $value = getenv($name);
    $previous[$name] = $value === false ? null : $value;
}

try {
    putenv('POSMAIN_ENV=test');
    putenv('POSMAIN_PRODUCTION_MODE=0');
    putenv('POSMAIN_INVENTORY_CUTOVER_CERTIFIED=0');
    putenv('POSMAIN_INVENTORY_QUANTITY_TRACKING=0');
    putenv('POSMAIN_RECIPE_ROLLOUT_CERTIFIED=0');
    putenv('POSMAIN_RECIPE_MODE=consume_pilot');
    putenv('POSMAIN_RECIPE_RESERVATIONS=1');
    putenv('POSMAIN_RECIPE_CONSUMPTION=1');

    $testConfig = posmain_app_config();
    appConfigProductionProfileBoundaryAssert(
        ($testConfig['recipe']['mode'] ?? '') === 'consume_pilot',
        'non-production config must preserve the explicitly requested recipe mode'
    );
    appConfigProductionProfileBoundaryAssert(
        !empty($testConfig['recipe']['reservations']) && !empty($testConfig['recipe']['consumption']),
        'non-production config must preserve explicit recipe runtime flags'
    );
    appConfigProductionProfileBoundaryAssert(
        !isset($testConfig['production_profile']),
        'non-production config must not claim that the production profile was applied'
    );

    putenv('POSMAIN_ENV=production');
    putenv('POSMAIN_PRODUCTION_MODE=1');
    $productionConfig = posmain_app_config();
    appConfigProductionProfileBoundaryAssert(
        ($productionConfig['recipe']['mode'] ?? '') === 'read_only',
        'uncertified production config must force recipe runtime to read-only'
    );
    appConfigProductionProfileBoundaryAssert(
        !empty($productionConfig['production_profile']['recipe_activation_blocked']),
        'uncertified production config must expose the recipe activation block'
    );
    appConfigProductionProfileBoundaryAssert(
        ($productionConfig['inventory']['ledger_mode'] ?? '') === 'shadow',
        'uncertified production config must not activate the live inventory ledger'
    );
} finally {
    foreach ($previous as $name => $value) {
        putenv($value === null ? $name : $name . '=' . $value);
    }
}

echo "app-config-production-profile-boundary-ok\n";
