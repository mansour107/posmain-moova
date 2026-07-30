<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Recipe/RecipeInventoryMovementService.php';

function recipeQuantityDependencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recipeQuantityDependencyFlags(array $inventory, string $mode = 'consume_pilot'): RecipeFeatureFlags
{
    return new RecipeFeatureFlags([
        'inventory' => $inventory,
        'recipe' => [
            'enabled' => true,
            'mode' => $mode,
            'consumption' => true,
            'pilot' => [
                'pos_branch' => '1',
            ],
        ],
    ]);
}

function recipeQuantityDependencyConsumption(RecipeFeatureFlags $flags): RecipeMovementResult
{
    $service = new RecipeInventoryMovementService($flags);
    $conn = mysqli_init();
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('mysqli init failed');
    }

    return $service->recordRecipeConsumption(
        $conn,
        new RecipeExplosionResult([
            'sellable_item_id' => 101,
            'recipe_id' => 201,
            'has_recipe' => true,
            'requirements' => [],
        ]),
        [
            'pos_tenant' => 0,
            'pos_branch' => 1,
            'store_id' => 1,
            'order_id' => 301,
        ]
    );
}

$blocked = null;
try {
    recipeQuantityDependencyConsumption(recipeQuantityDependencyFlags([
        'ledger_mode' => 'off',
        'quantity_tracking' => false,
    ]));
} catch (RuntimeException $e) {
    $blocked = $e->getMessage();
}
recipeQuantityDependencyAssert(
    $blocked === 'RECIPE_QUANTITY_TRACKING_REQUIRED',
    'explicit quantity-off active recipe consumption must fail closed'
);

$quantityOnly = recipeQuantityDependencyConsumption(recipeQuantityDependencyFlags([
    'ledger_mode' => 'off',
    'quantity_tracking' => true,
    'accounting' => false,
]));
recipeQuantityDependencyAssert(
    $quantityOnly->movementIds === [],
    'quantity-only recipe configuration should reach the movement path without requiring accounting'
);

$legacy = recipeQuantityDependencyConsumption(new RecipeFeatureFlags([
    'recipe' => [
        'enabled' => true,
        'mode' => 'consume_pilot',
        'consumption' => true,
        'pilot' => [
            'pos_branch' => '1',
        ],
    ],
]));
recipeQuantityDependencyAssert(
    $legacy->movementIds === [],
    'legacy recipe-only configuration should retain its historical movement contract'
);

$readOnly = recipeQuantityDependencyConsumption(recipeQuantityDependencyFlags([
    'ledger_mode' => 'off',
    'quantity_tracking' => false,
], 'read_only'));
recipeQuantityDependencyAssert(
    $readOnly->noop,
    'read-only recipes must remain usable without quantity tracking'
);

echo "recipe-quantity-tracking-dependency-ok\n";
