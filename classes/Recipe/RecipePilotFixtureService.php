<?php

require_once __DIR__ . '/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/Repository/ProductionBatchRepository.php';
require_once __DIR__ . '/Repository/RecipeAvailabilityCacheRepository.php';
require_once __DIR__ . '/RecipeDecimal.php';

class RecipePilotFixtureService
{
    private const REQUIRED_TABLES = [
        'myitems',
        'modifier_groups',
        'modifier_options',
        'item_modifier_groups',
        'recipe_headers',
        'recipe_lines',
        'recipe_cost_snapshots',
        'inventory_movements',
        'inventory_item_balances',
        'recipe_availability_cache',
        'production_batches',
    ];

    private InventoryBalanceRepository $balances;
    private InventoryMovementRepository $movements;
    private RecipeAvailabilityCacheRepository $availabilityCache;
    private ProductionBatchRepository $productionBatches;

    public function __construct(
        ?InventoryBalanceRepository $balances = null,
        ?InventoryMovementRepository $movements = null,
        ?RecipeAvailabilityCacheRepository $availabilityCache = null,
        ?ProductionBatchRepository $productionBatches = null
    ) {
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->movements = $movements ?: new InventoryMovementRepository();
        $this->availabilityCache = $availabilityCache ?: new RecipeAvailabilityCacheRepository();
        $this->productionBatches = $productionBatches ?: new ProductionBatchRepository();
    }

    public function run(mysqli $conn, array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $plan = $this->plan($conn, $options);
        if (empty($options['apply'])) {
            return $plan;
        }
        if (!empty($plan['blockers'])) {
            $plan['applied'] = false;
            return $plan;
        }

        $conn->begin_transaction();
        try {
            $created = [];
            $reused = [];
            $items = $this->ensureItems($conn, $options, $created, $reused);
            $modifier = $this->ensureModifier($conn, $options, $items['latte']['id'], $created, $reused);
            $recipes = $this->ensureRecipes($conn, $options, $items, $modifier, $created, $reused);
            $draftRecipes = $this->ensureDraftRecipes($conn, $options, $items, $modifier, $created, $reused);
            $this->ensureBalancesAndOpeningMovements($conn, $options, $items, $created, $reused);
            $this->ensureCostSnapshots($conn, $options, $recipes, $items, $created, $reused);
            $this->ensureAvailabilityCache($conn, $options, $recipes, $items, $created, $reused);
            $this->ensureDraftProductionBatch($conn, $options, $recipes, $items, $created, $reused);
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $after = $this->plan($conn, $options);
        $after['applied'] = true;
        $after['created'] = $created;
        $after['reused'] = $reused;

        return $after;
    }

    public function plan(mysqli $conn, array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $blockers = array_merge(
            $this->schemaBlockers($conn),
            $this->fixtureConflictBlockers($conn, $options)
        );

        $items = $this->inspectFixtureItems($conn, $options);
        $modifier = $this->inspectModifier($conn, $options);
        $recipes = $this->inspectRecipes($conn, $options, $items);
        $draftRecipes = $this->inspectDraftRecipes($conn, $options, $items);
        $draftProductionBatch = $this->inspectDraftProductionBatch($conn, $options, $recipes, $items);

        return [
            'ok' => $blockers === [],
            'applied' => false,
            'dry_run' => empty($options['apply']),
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => [
                'pos_tenant' => $options['pos_tenant'],
                'pos_branch' => $options['pos_branch'],
                'store_id' => $options['store_id'],
            ],
            'fixture' => [
                'prefix' => $options['prefix'],
                'barcode_prefix' => $options['barcode_prefix'],
                'items' => $items,
                'modifier' => $modifier,
                'recipes' => $recipes,
                'draft_recipes' => $draftRecipes,
                'draft_production_batch' => $draftProductionBatch,
            ],
            'would_write_on_apply' => [
                'myitems fixture rows',
                'modifier group/option/link rows',
                'active fixture recipe headers and lines',
                'draft fixture recipe header and lines for selected recipe editor QA',
                'fixture inventory balances and opening-balance ledger rows',
                'fixture recipe cost snapshots',
                'fixture availability cache rows',
                'draft fixture production batch for selected production UI QA',
            ],
            'does_not_write' => [
                'feature flags',
                'customer orders',
                'payments',
                'accounting journals',
                'sync outbox rows',
                'router metadata',
            ],
            'pilot_env' => $this->pilotEnvSuggestions($items, $options),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    public function verify(mysqli $conn, array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $plan = $this->plan($conn, $options);
        $blockers = $plan['blockers'];
        $items = $plan['fixture']['items'];
        $modifier = $plan['fixture']['modifier'];
        $recipes = $this->fixtureRecipes($options, $items, $modifier);
        $counts = [
            'items' => 0,
            'modifier_groups' => 0,
            'modifier_options' => 0,
            'item_modifier_links' => 0,
            'recipes' => 0,
            'recipe_lines' => 0,
            'cost_snapshots' => 0,
            'balances' => 0,
            'opening_movements' => 0,
            'availability_cache_rows' => 0,
            'draft_recipes' => 0,
            'draft_recipe_lines' => 0,
            'draft_production_batches' => 0,
        ];
        $expectedCounts = [
            'items' => count($this->fixtureItems($options)),
            'modifier_groups' => 1,
            'modifier_options' => 1,
            'item_modifier_links' => 1,
            'recipes' => count($recipes),
            'recipe_lines' => array_sum(array_map(static fn(array $recipe): int => count($recipe['lines']), $recipes)),
            'cost_snapshots' => count($recipes),
            'balances' => count($this->fixtureItems($options)),
            'opening_movements' => count(array_filter($this->fixtureItems($options), function (array $item): bool {
                return $this->decimalPositive((string) ($item['qty_on_hand'] ?? '0'));
            })),
            'availability_cache_rows' => array_sum(array_map(static fn(array $recipe): int => count($recipe['cache']), $recipes)),
            'draft_recipes' => count($this->fixtureDraftRecipes($options, $items, $modifier)),
            'draft_recipe_lines' => array_sum(array_map(static fn(array $recipe): int => count($recipe['lines']), $this->fixtureDraftRecipes($options, $items, $modifier))),
            'draft_production_batches' => 1,
        ];

        foreach ($items as $key => $item) {
            if (empty($item['exists']) || (int) ($item['id'] ?? 0) < 1) {
                $blockers[] = 'recipe_pilot_fixture_missing_item_' . $key;
                continue;
            }
            $counts['items']++;
            $expected = $this->fixtureItems($options)[$key] ?? [];
            foreach (['item_type', 'track_stock'] as $field) {
                if (array_key_exists($field, $expected) && (string) ($item[$field] ?? '') !== (string) $expected[$field]) {
                    $blockers[] = 'recipe_pilot_fixture_item_mismatch_' . $key . '_' . $field;
                }
            }
            if ($this->balances->findBalance($conn, $options['pos_tenant'], $options['pos_branch'], $options['store_id'], (int) $item['id'])) {
                $counts['balances']++;
            } else {
                $blockers[] = 'recipe_pilot_fixture_missing_balance_' . $key;
            }
            if ($this->decimalPositive((string) ($item['qty_on_hand'] ?? '0'))) {
                $idempotencyKey = $this->openingMovementIdempotencyKey($options, $key);
                if ($this->movements->findByIdempotencyKey($conn, $options['pos_tenant'], $options['pos_branch'], $options['store_id'], $idempotencyKey)) {
                    $counts['opening_movements']++;
                } else {
                    $blockers[] = 'recipe_pilot_fixture_missing_opening_movement_' . $key;
                }
            }
        }

        if (!empty($modifier['group_exists']) && (int) ($modifier['group_id'] ?? 0) > 0) {
            $counts['modifier_groups']++;
        } else {
            $blockers[] = 'recipe_pilot_fixture_missing_modifier_group';
        }
        if (!empty($modifier['oat_option_exists']) && (int) ($modifier['oat_option_id'] ?? 0) > 0) {
            $counts['modifier_options']++;
        } else {
            $blockers[] = 'recipe_pilot_fixture_missing_modifier_option';
        }
        $latteItemId = (int) ($items['latte']['id'] ?? 0);
        $modifierGroupId = (int) ($modifier['group_id'] ?? 0);
        if ($latteItemId > 0 && $modifierGroupId > 0 && $this->countRows($conn, 'item_modifier_groups', 'item_id = ? AND group_id = ?', [$latteItemId, $modifierGroupId]) > 0) {
            $counts['item_modifier_links']++;
        } else {
            $blockers[] = 'recipe_pilot_fixture_missing_item_modifier_link';
        }

        foreach ($recipes as $key => $definition) {
            $recipe = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$definition['uuid']]);
            if (!$recipe) {
                $blockers[] = 'recipe_pilot_fixture_missing_recipe_' . $key;
                continue;
            }
            $counts['recipes']++;
            if ((string) ($recipe['status'] ?? '') !== 'active') {
                $blockers[] = 'recipe_pilot_fixture_recipe_not_active_' . $key;
            }
            $lineCount = $this->countRows($conn, 'recipe_lines', 'recipe_id = ?', [(int) $recipe['id']]);
            $counts['recipe_lines'] += $lineCount;
            if ($lineCount !== count($definition['lines'])) {
                $blockers[] = 'recipe_pilot_fixture_recipe_line_count_mismatch_' . $key;
            }
            if ($this->countRows($conn, 'recipe_cost_snapshots', 'snapshot_uuid = ?', [$definition['snapshot_uuid']]) > 0) {
                $counts['cost_snapshots']++;
            } else {
                $blockers[] = 'recipe_pilot_fixture_missing_cost_snapshot_' . $key;
            }
            foreach ($definition['cache'] as $cache) {
                $cacheCount = $this->countRows(
                    $conn,
                    'recipe_availability_cache',
                    'pos_tenant = ? AND pos_branch = ? AND store_id = ? AND sellable_item_id = ? AND order_type = ? AND channel = ?',
                    [
                        $options['pos_tenant'],
                        $options['pos_branch'],
                        $options['store_id'],
                        (int) $definition['sellable_item_id'],
                        $cache['order_type'],
                        $cache['channel'],
                    ]
                );
                if ($cacheCount > 0) {
                    $counts['availability_cache_rows']++;
                } else {
                    $blockers[] = 'recipe_pilot_fixture_missing_availability_cache_' . $key . '_' . $cache['order_type'] . '_' . $cache['channel'];
                }
            }
        }

        foreach ($this->fixtureDraftRecipes($options, $items, $modifier) as $key => $definition) {
            $recipe = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$definition['uuid']]);
            if (!$recipe) {
                $blockers[] = 'recipe_pilot_fixture_missing_draft_recipe_' . $key;
                continue;
            }
            $counts['draft_recipes']++;
            if ((string) ($recipe['status'] ?? '') !== 'draft') {
                $blockers[] = 'recipe_pilot_fixture_recipe_not_draft_' . $key;
            }
            $lineCount = $this->countRows($conn, 'recipe_lines', 'recipe_id = ?', [(int) $recipe['id']]);
            $counts['draft_recipe_lines'] += $lineCount;
            if ($lineCount !== count($definition['lines'])) {
                $blockers[] = 'recipe_pilot_fixture_draft_recipe_line_count_mismatch_' . $key;
            }
        }

        $draftBatch = $this->inspectDraftProductionBatch($conn, $options, $plan['fixture']['recipes'], $items);
        if (!empty($draftBatch['exists']) && (int) ($draftBatch['batch_id'] ?? 0) > 0) {
            $counts['draft_production_batches']++;
        } else {
            $blockers[] = 'recipe_pilot_fixture_missing_draft_production_batch';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'fixture_ready_for_operator_qa' => $blockers === [],
            'read_only' => true,
            'applied' => false,
            'dry_run' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => $plan['scope'],
            'fixture' => $plan['fixture'],
            'counts' => $counts,
            'expected_counts' => $expectedCounts,
            'pilot_env' => $plan['pilot_env'],
            'blockers' => $blockers,
        ];
    }

    private function normalizeOptions(array $options): array
    {
        $prefix = trim((string) ($options['prefix'] ?? 'Recipe QA'));
        if ($prefix === '') {
            $prefix = 'Recipe QA';
        }
        $barcodePrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($options['barcode_prefix'] ?? 'RQA')) ?: 'RQA');
        $barcodePrefix = substr($barcodePrefix, 0, 8);

        return [
            'apply' => !empty($options['apply']),
            'verify' => !empty($options['verify']),
            'prefix' => $prefix,
            'barcode_prefix' => $barcodePrefix,
            'pos_tenant' => max(0, (int) ($options['pos_tenant'] ?? 0)),
            'pos_branch' => max(0, (int) ($options['pos_branch'] ?? 0)),
            'store_id' => max(0, (int) ($options['store_id'] ?? 0)),
        ];
    }

    private function fixtureItems(array $options): array
    {
        $prefix = $options['prefix'];
        $barcode = $options['barcode_prefix'];

        return [
            'latte' => [
                'name' => $prefix . ' Latte',
                'barcode' => $barcode . '-LATTE',
                'item_type' => 'sellable',
                'track_stock' => 0,
                'cost_price' => '0.000000',
                'price1' => '55.000000',
                'qty_on_hand' => '0.000000',
                'moving_average_cost' => '0.000000',
                'group1' => 9101,
            ],
            'regular_milk' => [
                'name' => $prefix . ' Regular Milk',
                'barcode' => $barcode . '-MILK',
                'item_type' => 'ingredient',
                'track_stock' => 1,
                'cost_price' => '0.020000',
                'price1' => '0.000000',
                'qty_on_hand' => '20.000000',
                'moving_average_cost' => '0.020000',
                'group1' => 9102,
            ],
            'oat_milk' => [
                'name' => $prefix . ' Oat Milk',
                'barcode' => $barcode . '-OATMILK',
                'item_type' => 'ingredient',
                'track_stock' => 1,
                'cost_price' => '0.035000',
                'price1' => '0.000000',
                'qty_on_hand' => '8.000000',
                'moving_average_cost' => '0.035000',
                'group1' => 9102,
            ],
            'takeaway_cup' => [
                'name' => $prefix . ' Takeaway Cup',
                'barcode' => $barcode . '-CUP',
                'item_type' => 'packaging',
                'track_stock' => 1,
                'cost_price' => '0.150000',
                'price1' => '0.000000',
                'qty_on_hand' => '3.000000',
                'moving_average_cost' => '0.150000',
                'group1' => 9103,
            ],
            'prepared_sauce' => [
                'name' => $prefix . ' Prepared Sauce',
                'barcode' => $barcode . '-SAUCE',
                'item_type' => 'sellable',
                'track_stock' => 1,
                'cost_price' => '3.000000',
                'price1' => '30.000000',
                'qty_on_hand' => '0.000000',
                'moving_average_cost' => '3.000000',
                'group1' => 9104,
            ],
            'tomatoes' => [
                'name' => $prefix . ' Tomatoes',
                'barcode' => $barcode . '-TOMATO',
                'item_type' => 'ingredient',
                'track_stock' => 1,
                'cost_price' => '2.500000',
                'price1' => '0.000000',
                'qty_on_hand' => '20.000000',
                'moving_average_cost' => '2.500000',
                'group1' => 9102,
            ],
        ];
    }

    private function fixtureRecipes(array $options, array $items, array $modifier): array
    {
        return [
            'latte' => [
                'uuid' => $this->uuid($options['prefix'] . ':latte-recipe'),
                'snapshot_uuid' => $this->uuid($options['prefix'] . ':latte-cost-snapshot'),
                'sellable_item_id' => (int) ($items['latte']['id'] ?? 0),
                'recipe_name' => $options['prefix'] . ' Latte Recipe',
                'recipe_type' => 'make_to_order',
                'yield_qty' => '1.000000',
                'cost_per_yield' => '0.550000',
                'cost_per_sell_unit' => '0.550000',
                'cache' => [
                    ['order_type' => 'takeaway', 'channel' => 'pos', 'qty' => '3.000000', 'available' => 1, 'reason' => null],
                    ['order_type' => 'delivery', 'channel' => 'moova', 'qty' => '3.000000', 'available' => 1, 'reason' => null],
                    ['order_type' => 'delivery', 'channel' => 'cofe', 'qty' => '3.000000', 'available' => 1, 'reason' => null],
                ],
                'lines' => [
                    [
                        'key' => 'regular-milk',
                        'ingredient_key' => 'regular_milk',
                        'line_type' => 'ingredient',
                        'qty_per_yield' => '0.200000',
                        'modifier_behavior' => 'additive',
                        'substitution_group' => 'milk',
                        'order_type' => 'any',
                        'channel' => 'any',
                    ],
                    [
                        'key' => 'oat-remove-regular',
                        'ingredient_key' => 'regular_milk',
                        'line_type' => 'modifier_ingredient',
                        'qty_per_yield' => '0.200000',
                        'modifier_group_id' => (int) ($modifier['group_id'] ?? 0),
                        'modifier_option_id' => (int) ($modifier['oat_option_id'] ?? 0),
                        'modifier_behavior' => 'substitution_remove',
                        'substitution_group' => 'milk',
                        'order_type' => 'any',
                        'channel' => 'any',
                    ],
                    [
                        'key' => 'oat-add',
                        'ingredient_key' => 'oat_milk',
                        'line_type' => 'modifier_ingredient',
                        'qty_per_yield' => '0.200000',
                        'modifier_group_id' => (int) ($modifier['group_id'] ?? 0),
                        'modifier_option_id' => (int) ($modifier['oat_option_id'] ?? 0),
                        'modifier_behavior' => 'substitution_add',
                        'substitution_group' => 'milk',
                        'order_type' => 'any',
                        'channel' => 'any',
                    ],
                    [
                        'key' => 'takeaway-cup',
                        'ingredient_key' => 'takeaway_cup',
                        'line_type' => 'packaging',
                        'qty_per_yield' => '1.000000',
                        'modifier_behavior' => 'additive',
                        'substitution_group' => null,
                        'order_type' => 'takeaway',
                        'channel' => 'any',
                    ],
                    [
                        'key' => 'delivery-cup',
                        'ingredient_key' => 'takeaway_cup',
                        'line_type' => 'packaging',
                        'qty_per_yield' => '1.000000',
                        'modifier_behavior' => 'additive',
                        'substitution_group' => null,
                        'order_type' => 'delivery',
                        'channel' => 'any',
                    ],
                ],
            ],
            'prepared_sauce' => [
                'uuid' => $this->uuid($options['prefix'] . ':prepared-sauce-recipe'),
                'snapshot_uuid' => $this->uuid($options['prefix'] . ':prepared-sauce-cost-snapshot'),
                'sellable_item_id' => (int) ($items['prepared_sauce']['id'] ?? 0),
                'recipe_name' => $options['prefix'] . ' Prepared Sauce Recipe',
                'recipe_type' => 'batch_prepared',
                'yield_qty' => '10.000000',
                'cost_per_yield' => '30.000000',
                'cost_per_sell_unit' => '3.000000',
                'cache' => [
                    ['order_type' => 'takeaway', 'channel' => 'pos', 'qty' => '0.000000', 'available' => 0, 'reason' => 'Prepared item stock is 0.'],
                ],
                'lines' => [
                    [
                        'key' => 'tomatoes',
                        'ingredient_key' => 'tomatoes',
                        'line_type' => 'ingredient',
                        'qty_per_yield' => '12.000000',
                        'modifier_behavior' => 'additive',
                        'substitution_group' => null,
                        'order_type' => 'any',
                        'channel' => 'any',
                    ],
                ],
            ],
        ];
    }

    private function fixtureDraftRecipes(array $options, array $items, array $modifier): array
    {
        $active = $this->fixtureRecipes($options, $items, $modifier);
        $latte = $active['latte'];
        $latte['uuid'] = $this->uuid($options['prefix'] . ':latte-draft-recipe');
        $latte['recipe_name'] = $options['prefix'] . ' Latte Draft Recipe';
        $latte['status'] = 'draft';
        $latte['version_number'] = 2;
        $latte['snapshot_uuid'] = null;
        $latte['cache'] = [];

        return [
            'latte_draft' => $latte,
        ];
    }

    private function ensureItems(mysqli $conn, array $options, array &$created, array &$reused): array
    {
        $rows = [];
        foreach ($this->fixtureItems($options) as $key => $item) {
            $existing = $this->findItem($conn, $item['name'], $item['barcode']);
            if ($existing) {
                $rows[$key] = array_merge($item, ['id' => (int) $existing['id']]);
                $reused[] = 'item:' . $key;
                continue;
            }

            $stmt = $conn->prepare("
INSERT INTO myitems
  (iname, barcode, itmqty, cost_price, price1, price2, price3, group1, isdeleted, tenant, branch, item_type, track_stock, preferred_unit_id)
VALUES (?, ?, 0, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, NULL)");
            $stmt->bind_param(
                'ssssiiisi',
                $item['name'],
                $item['barcode'],
                $item['cost_price'],
                $item['price1'],
                $item['group1'],
                $options['pos_tenant'],
                $options['pos_branch'],
                $item['item_type'],
                $item['track_stock']
            );
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();
            $rows[$key] = array_merge($item, ['id' => $id]);
            $created[] = 'item:' . $key;
        }

        return $rows;
    }

    private function ensureModifier(mysqli $conn, array $options, int $latteItemId, array &$created, array &$reused): array
    {
        $groupName = $options['prefix'] . ' Milk Choice';
        $optionName = $options['prefix'] . ' Oat Milk Option';
        $group = $this->fetchOne(
            $conn,
            'SELECT * FROM modifier_groups WHERE name_ar = ? AND tenant = ? AND branch = ? LIMIT 1',
            [$groupName, $options['pos_tenant'], $options['pos_branch']]
        );
        if ($group) {
            $groupId = (int) $group['id'];
            $reused[] = 'modifier_group:milk_choice';
        } else {
            $stmt = $conn->prepare("
INSERT INTO modifier_groups
  (name_ar, name_en, selection_min, selection_max, is_required, is_active, tenant, branch, sort_order)
VALUES (?, ?, 0, 1, 0, 1, ?, ?, 10)");
            $stmt->bind_param('ssii', $groupName, $groupName, $options['pos_tenant'], $options['pos_branch']);
            $stmt->execute();
            $groupId = (int) $conn->insert_id;
            $stmt->close();
            $created[] = 'modifier_group:milk_choice';
        }

        $option = $this->fetchOne(
            $conn,
            'SELECT * FROM modifier_options WHERE group_id = ? AND name_ar = ? LIMIT 1',
            [$groupId, $optionName]
        );
        if ($option) {
            $optionId = (int) $option['id'];
            $reused[] = 'modifier_option:oat_milk';
        } else {
            $stmt = $conn->prepare("
INSERT INTO modifier_options
  (group_id, name_ar, name_en, price_delta, is_active, sort_order)
VALUES (?, ?, ?, 4.000, 1, 10)");
            $stmt->bind_param('iss', $groupId, $optionName, $optionName);
            $stmt->execute();
            $optionId = (int) $conn->insert_id;
            $stmt->close();
            $created[] = 'modifier_option:oat_milk';
        }

        $stmt = $conn->prepare('INSERT IGNORE INTO item_modifier_groups (item_id, group_id, sort_order) VALUES (?, ?, 10)');
        $stmt->bind_param('ii', $latteItemId, $groupId);
        $stmt->execute();
        $stmt->affected_rows > 0 ? $created[] = 'item_modifier_group:latte_milk_choice' : $reused[] = 'item_modifier_group:latte_milk_choice';
        $stmt->close();

        return [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'oat_option_id' => $optionId,
            'oat_option_name' => $optionName,
        ];
    }

    private function ensureRecipes(mysqli $conn, array $options, array $items, array $modifier, array &$created, array &$reused): array
    {
        $recipes = [];
        foreach ($this->fixtureRecipes($options, $items, $modifier) as $recipeKey => $definition) {
            $recipe = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$definition['uuid']]);
            if ($recipe) {
                $recipeId = (int) $recipe['id'];
                $reused[] = 'recipe:' . $recipeKey;
            } else {
                $stmt = $conn->prepare("
INSERT INTO recipe_headers
  (recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number,
   yield_qty, costing_method, requires_recipe_for_sale, allow_sale_without_stock, approved_at)
VALUES (?, ?, ?, ?, ?, ?, 'active', 1, ?, 'item_cost_price', 1, 0, CURRENT_TIMESTAMP)");
                $stmt->bind_param(
                    'siiisss',
                    $definition['uuid'],
                    $options['pos_tenant'],
                    $options['pos_branch'],
                    $definition['sellable_item_id'],
                    $definition['recipe_name'],
                    $definition['recipe_type'],
                    $definition['yield_qty']
                );
                $stmt->execute();
                $recipeId = (int) $conn->insert_id;
                $stmt->close();
                $created[] = 'recipe:' . $recipeKey;
            }

            foreach ($definition['lines'] as $line) {
                $lineUuid = $this->uuid($options['prefix'] . ':' . $recipeKey . ':' . $line['key']);
                $existingLine = $this->fetchOne($conn, 'SELECT id FROM recipe_lines WHERE line_uuid = ? LIMIT 1', [$lineUuid]);
                if ($existingLine) {
                    $reused[] = 'recipe_line:' . $recipeKey . ':' . $line['key'];
                    continue;
                }

                $ingredientId = (int) $items[$line['ingredient_key']]['id'];
                $modifierGroupId = $line['modifier_group_id'] ?? null;
                $modifierOptionId = $line['modifier_option_id'] ?? null;
                $substitutionGroup = $line['substitution_group'] ?? null;
                $sort = count($created) + 1;
                $stmt = $conn->prepare("
INSERT INTO recipe_lines
  (recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base,
   wastage_percent, is_required, modifier_group_id, modifier_option_id, modifier_behavior,
   substitution_group, order_type, channel, sort_order)
VALUES (?, ?, ?, ?, ?, '1.00000000', '0.0000', 1, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    'isissiissssi',
                    $recipeId,
                    $lineUuid,
                    $ingredientId,
                    $line['line_type'],
                    $line['qty_per_yield'],
                    $modifierGroupId,
                    $modifierOptionId,
                    $line['modifier_behavior'],
                    $substitutionGroup,
                    $line['order_type'],
                    $line['channel'],
                    $sort
                );
                $stmt->execute();
                $stmt->close();
                $created[] = 'recipe_line:' . $recipeKey . ':' . $line['key'];
            }

            $recipes[$recipeKey] = array_merge($definition, ['id' => $recipeId]);
        }

        return $recipes;
    }

    private function ensureDraftRecipes(mysqli $conn, array $options, array $items, array $modifier, array &$created, array &$reused): array
    {
        $recipes = [];
        foreach ($this->fixtureDraftRecipes($options, $items, $modifier) as $recipeKey => $definition) {
            $recipe = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$definition['uuid']]);
            if ($recipe) {
                $recipeId = (int) $recipe['id'];
                $reused[] = 'draft_recipe:' . $recipeKey;
            } else {
                $stmt = $conn->prepare("
INSERT INTO recipe_headers
  (recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number,
   yield_qty, costing_method, requires_recipe_for_sale, allow_sale_without_stock, approved_at)
VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, 'item_cost_price', 1, 0, NULL)");
                $stmt->bind_param(
                    'siiissis',
                    $definition['uuid'],
                    $options['pos_tenant'],
                    $options['pos_branch'],
                    $definition['sellable_item_id'],
                    $definition['recipe_name'],
                    $definition['recipe_type'],
                    $definition['version_number'],
                    $definition['yield_qty']
                );
                $stmt->execute();
                $recipeId = (int) $conn->insert_id;
                $stmt->close();
                $created[] = 'draft_recipe:' . $recipeKey;
            }

            foreach ($definition['lines'] as $line) {
                $lineUuid = $this->uuid($options['prefix'] . ':' . $recipeKey . ':' . $line['key']);
                $existingLine = $this->fetchOne($conn, 'SELECT id FROM recipe_lines WHERE line_uuid = ? LIMIT 1', [$lineUuid]);
                if ($existingLine) {
                    $reused[] = 'draft_recipe_line:' . $recipeKey . ':' . $line['key'];
                    continue;
                }

                $ingredientId = (int) $items[$line['ingredient_key']]['id'];
                $modifierGroupId = $line['modifier_group_id'] ?? null;
                $modifierOptionId = $line['modifier_option_id'] ?? null;
                $substitutionGroup = $line['substitution_group'] ?? null;
                $sort = count($created) + 1;
                $stmt = $conn->prepare("
INSERT INTO recipe_lines
  (recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base,
   wastage_percent, is_required, modifier_group_id, modifier_option_id, modifier_behavior,
   substitution_group, order_type, channel, sort_order)
VALUES (?, ?, ?, ?, ?, '1.00000000', '0.0000', 1, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    'isissiissssi',
                    $recipeId,
                    $lineUuid,
                    $ingredientId,
                    $line['line_type'],
                    $line['qty_per_yield'],
                    $modifierGroupId,
                    $modifierOptionId,
                    $line['modifier_behavior'],
                    $substitutionGroup,
                    $line['order_type'],
                    $line['channel'],
                    $sort
                );
                $stmt->execute();
                $stmt->close();
                $created[] = 'draft_recipe_line:' . $recipeKey . ':' . $line['key'];
            }

            $recipes[$recipeKey] = array_merge($definition, ['id' => $recipeId]);
        }

        return $recipes;
    }

    private function ensureBalancesAndOpeningMovements(mysqli $conn, array $options, array $items, array &$created, array &$reused): void
    {
        foreach ($items as $key => $item) {
            $qty = (string) $item['qty_on_hand'];
            $this->balances->putBalance($conn, [
                'pos_tenant' => $options['pos_tenant'],
                'pos_branch' => $options['pos_branch'],
                'store_id' => $options['store_id'],
                'item_id' => (int) $item['id'],
                'qty_on_hand' => $qty,
                'qty_reserved' => '0.000000',
                'qty_available' => $qty,
                'moving_average_cost' => (string) $item['moving_average_cost'],
            ]);
            $created[] = 'inventory_balance:' . $key;

            if (!$this->decimalPositive($qty)) {
                continue;
            }
            $idempotencyKey = $this->openingMovementIdempotencyKey($options, $key);
            $existing = $this->movements->findByIdempotencyKey(
                $conn,
                $options['pos_tenant'],
                $options['pos_branch'],
                $options['store_id'],
                $idempotencyKey
            );
            if ($existing) {
                $reused[] = 'opening_movement:' . $key;
                continue;
            }

            $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid($idempotencyKey),
                'movement_type' => 'opening_balance',
                'source_type' => 'manual',
                'source_uuid' => 'recipe-pilot-fixture',
                'pos_tenant' => $options['pos_tenant'],
                'pos_branch' => $options['pos_branch'],
                'store_id' => $options['store_id'],
                'item_id' => (int) $item['id'],
                'qty_in' => $qty,
                'unit_cost' => (string) $item['moving_average_cost'],
                'total_cost' => $this->totalCost($qty, (string) $item['moving_average_cost']),
                'idempotency_key' => $idempotencyKey,
            ]);
            $created[] = 'opening_movement:' . $key;
        }
    }

    private function ensureCostSnapshots(mysqli $conn, array $options, array $recipes, array $items, array &$created, array &$reused): void
    {
        foreach ($recipes as $key => $recipe) {
            $existing = $this->fetchOne($conn, 'SELECT id FROM recipe_cost_snapshots WHERE snapshot_uuid = ? LIMIT 1', [$recipe['snapshot_uuid']]);
            if ($existing) {
                $reused[] = 'cost_snapshot:' . $key;
                continue;
            }
            $ingredients = json_encode(['fixture' => true, 'key' => $key], JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare("
INSERT INTO recipe_cost_snapshots
  (snapshot_uuid, pos_tenant, pos_branch, recipe_id, sellable_item_id, version_number,
   cost_per_yield, cost_per_sell_unit, ingredient_cost_json, calculated_at)
VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->bind_param(
                'siiiisss',
                $recipe['snapshot_uuid'],
                $options['pos_tenant'],
                $options['pos_branch'],
                $recipe['id'],
                $recipe['sellable_item_id'],
                $recipe['cost_per_yield'],
                $recipe['cost_per_sell_unit'],
                $ingredients
            );
            $stmt->execute();
            $stmt->close();
            $created[] = 'cost_snapshot:' . $key;
        }
    }

    private function ensureAvailabilityCache(mysqli $conn, array $options, array $recipes, array $items, array &$created, array &$reused): void
    {
        foreach ($recipes as $key => $recipe) {
            foreach ($recipe['cache'] as $cache) {
                $this->availabilityCache->putAvailability($conn, [
                    'pos_tenant' => $options['pos_tenant'],
                    'pos_branch' => $options['pos_branch'],
                    'store_id' => $options['store_id'],
                    'sellable_item_id' => (int) $recipe['sellable_item_id'],
                    'recipe_id' => (int) $recipe['id'],
                    'order_type' => $cache['order_type'],
                    'channel' => $cache['channel'],
                    'computed_available_qty' => $cache['qty'],
                    'effective_available_qty' => $cache['qty'],
                    'effective_is_available' => $cache['available'],
                    'unavailable_reason' => $cache['reason'],
                    'availability_revision' => 1,
                    'calculated_at' => date('Y-m-d H:i:s'),
                ]);
                $created[] = 'availability_cache:' . $key . ':' . $cache['order_type'] . ':' . $cache['channel'];
            }
        }
    }

    private function ensureDraftProductionBatch(mysqli $conn, array $options, array $recipes, array $items, array &$created, array &$reused): void
    {
        $batchUuid = $this->uuid($options['prefix'] . ':draft-production-batch');
        $existing = $this->fetchOne($conn, 'SELECT * FROM production_batches WHERE batch_uuid = ? LIMIT 1', [$batchUuid]);
        if ($existing) {
            $reused[] = 'production_batch:draft';
            return;
        }

        $this->productionBatches->createBatch($conn, [
            'batch_uuid' => $batchUuid,
            'pos_tenant' => $options['pos_tenant'],
            'pos_branch' => $options['pos_branch'],
            'store_id' => $options['store_id'],
            'recipe_id' => (int) ($recipes['prepared_sauce']['id'] ?? 0),
            'output_item_id' => (int) ($items['prepared_sauce']['id'] ?? 0),
            'planned_output_qty' => '10.000000',
            'status' => 'draft',
            'created_by' => 1,
            'notes' => 'Recipe QA draft production batch for read-only selected-batch surface smoke.',
        ]);
        $created[] = 'production_batch:draft';
    }

    private function schemaBlockers(mysqli $conn): array
    {
        $blockers = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($conn, $table)) {
                $blockers[] = 'recipe_pilot_fixture_missing_table_' . $table;
            }
        }

        foreach ($this->requiredColumns() as $table => $columns) {
            if (!$this->tableExists($conn, $table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!$this->columnExists($conn, $table, $column)) {
                    $blockers[] = 'recipe_pilot_fixture_missing_column_' . $table . '_' . $column;
                }
            }
        }

        return $blockers;
    }

    private function requiredColumns(): array
    {
        return [
            'myitems' => ['id', 'iname', 'barcode', 'itmqty', 'cost_price', 'price1', 'price2', 'price3', 'group1', 'isdeleted', 'tenant', 'branch', 'item_type', 'track_stock', 'preferred_unit_id'],
            'modifier_groups' => ['id', 'name_ar', 'name_en', 'tenant', 'branch'],
            'modifier_options' => ['id', 'group_id', 'name_ar', 'name_en'],
            'item_modifier_groups' => ['item_id', 'group_id'],
            'recipe_headers' => ['id', 'recipe_uuid', 'pos_tenant', 'pos_branch', 'sellable_item_id', 'recipe_name', 'recipe_type', 'status', 'yield_qty'],
            'recipe_lines' => ['recipe_id', 'line_uuid', 'ingredient_item_id', 'line_type', 'qty_per_yield', 'modifier_behavior', 'substitution_group', 'order_type', 'channel'],
            'recipe_cost_snapshots' => ['snapshot_uuid', 'recipe_id', 'cost_per_yield', 'cost_per_sell_unit'],
            'inventory_movements' => ['movement_uuid', 'movement_type', 'source_type', 'item_id', 'qty_in', 'qty_out', 'idempotency_key'],
            'inventory_item_balances' => ['pos_tenant', 'pos_branch', 'store_id', 'item_id', 'qty_on_hand', 'qty_reserved', 'qty_available'],
            'recipe_availability_cache' => ['pos_tenant', 'pos_branch', 'store_id', 'sellable_item_id', 'recipe_id', 'order_type', 'channel', 'effective_is_available'],
            'production_batches' => ['id', 'batch_uuid', 'pos_tenant', 'pos_branch', 'store_id', 'recipe_id', 'output_item_id', 'planned_output_qty', 'status'],
        ];
    }

    private function fixtureConflictBlockers(mysqli $conn, array $options): array
    {
        $blockers = [];
        foreach ($this->fixtureItems($options) as $key => $item) {
            $matches = $this->fetchAll(
                $conn,
                'SELECT id, iname, barcode, isdeleted FROM myitems WHERE iname = ? OR barcode = ?',
                [$item['name'], $item['barcode']]
            );
            $ids = array_values(array_unique(array_map(static fn($row) => (int) $row['id'], $matches)));
            if (count($ids) > 1) {
                $blockers[] = 'recipe_pilot_fixture_item_identity_conflict_' . $key;
            }
            foreach ($matches as $row) {
                if ((string) $row['iname'] !== $item['name'] || (string) $row['barcode'] !== $item['barcode'] || (int) $row['isdeleted'] === 1) {
                    $blockers[] = 'recipe_pilot_fixture_item_row_conflict_' . $key;
                }
            }
        }

        foreach ($this->inspectFixtureItems($conn, $options) as $key => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId < 1 || !in_array($key, ['latte', 'prepared_sauce'], true)) {
                continue;
            }
            $expectedUuid = $key === 'latte'
                ? $this->uuid($options['prefix'] . ':latte-recipe')
                : $this->uuid($options['prefix'] . ':prepared-sauce-recipe');
            $rows = $this->fetchAll(
                $conn,
                "SELECT recipe_uuid FROM recipe_headers WHERE pos_tenant = ? AND pos_branch = ? AND sellable_item_id = ? AND status = 'active'",
                [$options['pos_tenant'], $options['pos_branch'], $itemId]
            );
            foreach ($rows as $row) {
                if ((string) $row['recipe_uuid'] !== $expectedUuid) {
                    $blockers[] = 'recipe_pilot_fixture_active_recipe_conflict_' . $key;
                }
            }
        }

        return $blockers;
    }

    private function inspectFixtureItems(mysqli $conn, array $options): array
    {
        $items = [];
        foreach ($this->fixtureItems($options) as $key => $item) {
            $existing = $this->findItem($conn, $item['name'], $item['barcode']);
            $items[$key] = array_merge($item, [
                'id' => $existing ? (int) $existing['id'] : null,
                'exists' => $existing !== null,
            ]);
        }

        return $items;
    }

    private function inspectModifier(mysqli $conn, array $options): array
    {
        $groupName = $options['prefix'] . ' Milk Choice';
        $group = $this->fetchOne(
            $conn,
            'SELECT * FROM modifier_groups WHERE name_ar = ? AND tenant = ? AND branch = ? LIMIT 1',
            [$groupName, $options['pos_tenant'], $options['pos_branch']]
        );
        $option = $group ? $this->fetchOne(
            $conn,
            'SELECT * FROM modifier_options WHERE group_id = ? AND name_ar = ? LIMIT 1',
            [(int) $group['id'], $options['prefix'] . ' Oat Milk Option']
        ) : null;

        return [
            'group_name' => $groupName,
            'group_id' => $group ? (int) $group['id'] : null,
            'group_exists' => $group !== null,
            'oat_option_name' => $options['prefix'] . ' Oat Milk Option',
            'oat_option_id' => $option ? (int) $option['id'] : null,
            'oat_option_exists' => $option !== null,
        ];
    }

    private function inspectRecipes(mysqli $conn, array $options, array $items): array
    {
        $modifier = $this->inspectModifier($conn, $options);
        $recipes = [];
        foreach ($this->fixtureRecipes($options, $items, $modifier) as $key => $recipe) {
            $existing = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$recipe['uuid']]);
            $recipes[$key] = [
                'recipe_uuid' => $recipe['uuid'],
                'recipe_id' => $existing ? (int) $existing['id'] : null,
                'recipe_name' => $recipe['recipe_name'],
                'recipe_type' => $recipe['recipe_type'],
                'sellable_item_id' => $recipe['sellable_item_id'] > 0 ? $recipe['sellable_item_id'] : null,
                'exists' => $existing !== null,
                'line_count' => $existing ? $this->countRows($conn, 'recipe_lines', 'recipe_id = ?', [(int) $existing['id']]) : 0,
            ];
        }

        return $recipes;
    }

    private function inspectDraftRecipes(mysqli $conn, array $options, array $items): array
    {
        $modifier = $this->inspectModifier($conn, $options);
        $recipes = [];
        foreach ($this->fixtureDraftRecipes($options, $items, $modifier) as $key => $recipe) {
            $existing = $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1', [$recipe['uuid']]);
            $recipes[$key] = [
                'recipe_uuid' => $recipe['uuid'],
                'recipe_id' => $existing ? (int) $existing['id'] : null,
                'recipe_name' => $recipe['recipe_name'],
                'recipe_type' => $recipe['recipe_type'],
                'status' => 'draft',
                'sellable_item_id' => $recipe['sellable_item_id'] > 0 ? $recipe['sellable_item_id'] : null,
                'exists' => $existing !== null,
                'line_count' => $existing ? $this->countRows($conn, 'recipe_lines', 'recipe_id = ?', [(int) $existing['id']]) : 0,
            ];
        }

        return $recipes;
    }

    private function inspectDraftProductionBatch(mysqli $conn, array $options, array $recipes, array $items): array
    {
        $batchUuid = $this->uuid($options['prefix'] . ':draft-production-batch');
        $existing = $this->fetchOne($conn, 'SELECT * FROM production_batches WHERE batch_uuid = ? LIMIT 1', [$batchUuid]);

        return [
            'batch_uuid' => $batchUuid,
            'batch_id' => $existing ? (int) $existing['id'] : null,
            'recipe_id' => (int) (($recipes['prepared_sauce']['recipe_id'] ?? null) ?: ($recipes['prepared_sauce']['id'] ?? 0)),
            'output_item_id' => (int) ($items['prepared_sauce']['id'] ?? 0),
            'planned_output_qty' => '10.000000',
            'status' => 'draft',
            'exists' => $existing !== null,
        ];
    }

    private function pilotEnvSuggestions(array $items, array $options): array
    {
        $ids = [];
        foreach (['latte', 'prepared_sauce'] as $key) {
            if (!empty($items[$key]['id'])) {
                $ids[] = (int) $items[$key]['id'];
            }
        }

        return [
            'POSMAIN_RECIPE_PILOT_POS_BRANCH' => (string) $options['pos_branch'],
            'POSMAIN_RECIPE_PILOT_ITEM_IDS' => $ids ? implode(',', $ids) : '<available after --apply>',
        ];
    }

    private function findItem(mysqli $conn, string $name, string $barcode): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM myitems WHERE iname = ? AND barcode = ? LIMIT 1', [$name, $barcode]);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne(
            $conn,
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $row = $this->fetchOne(
            $conn,
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function countRows(mysqli $conn, string $table, string $where, array $params = []): int
    {
        $row = $this->fetchOne($conn, 'SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = '';
            foreach ($params as $value) {
                $types .= is_int($value) ? 'i' : 's';
            }
            $refs = [];
            foreach ($params as $index => $value) {
                $refs[$index] = $value;
            }
            $bind = [$types];
            foreach ($refs as $index => $_) {
                $bind[] = &$refs[$index];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function uuid(string $seed): string
    {
        $hex = md5($seed);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function decimalPositive(string $value): bool
    {
        return RecipeDecimal::compare($value, '0') > 0;
    }

    private function totalCost(string $qty, string $unitCost): string
    {
        return RecipeDecimal::multiply($qty, $unitCost);
    }

    private function openingMovementIdempotencyKey(array $options, string $key): string
    {
        return 'recipe-pilot-fixture:opening:' . $options['prefix'] . ':' . $key . ':tenant:' . $options['pos_tenant'] . ':branch:' . $options['pos_branch'] . ':store:' . $options['store_id'];
    }
}
