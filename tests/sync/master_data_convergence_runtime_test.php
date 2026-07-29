<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchCloudMasterApplyService.php';
require_once __DIR__ . '/../../classes/Sync/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderPricingService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = trim((string) getenv('POSMAIN_TEST_MYSQL_DB'));
if ($db === '' || !preg_match('/^posmain_master_sync_[a-z0-9_]+$/', $db)) {
    fwrite(STDERR, "master-data-convergence-runtime-refused-unsafe-database\n");
    exit(1);
}

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');
(new SyncSchemaManager())->apply($conn);

const MASTER_BRANCH_UUID = '94949494-9494-4494-8494-949494949494';
const MASTER_ITEM_ID = 984941;
const MASTER_ORDER_ID = 984942;
const MASTER_LINE_ID = 984943;
const MASTER_CATEGORY_ID = 984944;
const MASTER_INGREDIENT_ID = 984945;
const MASTER_UNIT_ID = 984946;
const MASTER_RECIPE_ID = 984947;
const MASTER_RECIPE_UUID = '95959595-9595-4595-8595-959595959595';

function masterRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function masterRuntimeEvent(array $fields, string $node = 'cloud-node-a', int $actorId = 41): array
{
    $itemUuid = PosOrderSnapshotBuilder::deterministicUuid(MASTER_BRANCH_UUID, 'myitems:' . MASTER_ITEM_ID);
    $fieldEnvelope = [];
    foreach ($fields as $name => $definition) {
        $fieldEnvelope[$name] = [
            'value' => $definition['value'],
            'changed_at_utc' => $definition['changed_at_utc'],
            'revision_uuid' => $definition['revision_uuid']
                ?? PosOrderSnapshotBuilder::deterministicUuid(
                    MASTER_BRANCH_UUID,
                    'runtime:' . $node . ':' . $name . ':' . $definition['changed_at_utc']
                        . ':' . json_encode($definition['value'])
                ),
        ];
    }
    $actor = [
        'user_id' => $actorId,
        'permissions' => $actorId > 0 ? ['menu.edit'] : [],
    ];
    $payload = [
        'schema_version' => 1,
        'snapshot_type' => 'pos_menu_item',
        'source_system' => 'cloud_pos',
        'branch_uuid' => MASTER_BRANCH_UUID,
        'source_node_id' => $node,
        'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'actor' => $actor,
        'local_item_id' => MASTER_ITEM_ID,
        'item_uuid' => $itemUuid,
        'menu_item' => [
            'local_item_id' => MASTER_ITEM_ID,
            'item_uuid' => $itemUuid,
        ],
        'master_data' => [
            'schema_version' => 1,
            'aggregate_type' => 'menu_item',
            'aggregate_uuid' => $itemUuid,
            'source_node_id' => $node,
            'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'actor' => $actor,
            'fields' => $fieldEnvelope,
        ],
    ];
    return [
        'event_uuid' => PosOrderSnapshotBuilder::deterministicUuid(
            MASTER_BRANCH_UUID,
            'event:' . $node . ':' . hash('sha256', json_encode($fieldEnvelope))
        ),
        'idempotency_key' => 'runtime:' . hash('sha256', json_encode($fieldEnvelope)),
        'source_system' => 'cloud_pos',
        'event_type' => 'menu.item_saved',
        'aggregate_type' => 'menu_item',
        'aggregate_uuid' => $itemUuid,
        'aggregate_local_id' => MASTER_ITEM_ID,
        'entity_type' => 'menu_item',
        'entity_uuid' => $itemUuid,
        'entity_local_id' => MASTER_ITEM_ID,
        'payload' => $payload,
    ];
}

function masterRuntimeApply(mysqli $conn, array $event): array
{
    $conn->begin_transaction();
    try {
        $result = (new BranchCloudMasterApplyService())->apply($conn, MASTER_BRANCH_UUID, $event);
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function masterRuntimeReceiveOnCloud(mysqli $conn, array $event): array
{
    $event['source_system'] = 'pos';
    $event['payload']['source_system'] = 'pos';
    return (new SyncInboxService())->receiveBranchEvent(
        $conn,
        MASTER_BRANCH_UUID,
        $event,
        SyncApplyMode::LIVE_APPLY
    );
}

function masterRuntimeRecipeEvent(array $fields, string $node = 'cloud-recipe-node', int $actorId = 51): array
{
    $fieldEnvelope = [];
    foreach ($fields as $name => $definition) {
        $fieldEnvelope[$name] = [
            'value' => $definition['value'],
            'changed_at_utc' => $definition['changed_at_utc'],
            'revision_uuid' => PosOrderSnapshotBuilder::deterministicUuid(
                MASTER_BRANCH_UUID,
                'recipe-runtime:' . $node . ':' . $name . ':' . $definition['changed_at_utc']
                    . ':' . json_encode($definition['value'])
            ),
        ];
    }
    $actor = [
        'user_id' => $actorId,
        'permissions' => $actorId > 0 ? ['recipe.manage'] : [],
    ];
    $payload = [
        'schema_version' => 1,
        'snapshot_type' => 'recipe_bundle',
        'source_system' => 'cloud_pos',
        'branch_uuid' => MASTER_BRANCH_UUID,
        'source_node_id' => $node,
        'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'actor' => $actor,
        'recipe_id' => MASTER_RECIPE_ID,
        'recipe_uuid' => MASTER_RECIPE_UUID,
        'master_data' => [
            'schema_version' => 1,
            'aggregate_type' => 'recipe',
            'aggregate_uuid' => MASTER_RECIPE_UUID,
            'source_node_id' => $node,
            'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'actor' => $actor,
            'fields' => $fieldEnvelope,
        ],
    ];
    return [
        'event_uuid' => PosOrderSnapshotBuilder::deterministicUuid(
            MASTER_BRANCH_UUID,
            'recipe-event:' . $node . ':' . hash('sha256', json_encode($fieldEnvelope))
        ),
        'idempotency_key' => 'recipe-runtime:' . hash('sha256', json_encode($fieldEnvelope)),
        'source_system' => 'cloud_pos',
        'event_type' => 'recipe.saved',
        'aggregate_type' => 'recipe',
        'aggregate_uuid' => MASTER_RECIPE_UUID,
        'aggregate_local_id' => MASTER_RECIPE_ID,
        'entity_type' => 'recipe',
        'entity_uuid' => MASTER_RECIPE_UUID,
        'entity_local_id' => MASTER_RECIPE_ID,
        'payload' => $payload,
    ];
}

function masterRuntimeCleanup(mysqli $conn): void
{
    $branch = MASTER_BRANCH_UUID;
    $conn->query("DELETE FROM sync_outbox WHERE branch_uuid = '{$branch}'");
    $conn->query("DELETE FROM sync_master_field_history WHERE branch_uuid = '{$branch}'");
    $conn->query("DELETE FROM sync_master_field_state WHERE branch_uuid = '{$branch}'");
    $conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '{$branch}'");
    $conn->query("DELETE FROM sync_inbox WHERE branch_uuid = '{$branch}'");
    $conn->query("DELETE FROM cloud_menu_items WHERE branch_uuid = '{$branch}'");
    $conn->query('DELETE FROM fat_details WHERE id = ' . MASTER_LINE_ID . ' OR fatid = ' . MASTER_ORDER_ID);
    $conn->query('DELETE FROM ot_head WHERE id = ' . MASTER_ORDER_ID);
    $conn->query('DELETE FROM myitems WHERE id = ' . MASTER_ITEM_ID);
    $conn->query('DELETE FROM recipe_variant_lines WHERE recipe_id = ' . MASTER_RECIPE_ID);
    $conn->query('DELETE FROM recipe_lines WHERE recipe_id = ' . MASTER_RECIPE_ID);
    $conn->query('DELETE FROM recipe_headers WHERE id = ' . MASTER_RECIPE_ID);
    $conn->query('DELETE FROM myitems WHERE id = ' . MASTER_INGREDIENT_ID);
    $conn->query('DELETE FROM myunits WHERE id = ' . MASTER_UNIT_ID);
    $conn->query('DELETE FROM item_group WHERE id = ' . MASTER_CATEGORY_ID);
    $conn->query("DELETE FROM sync_branch_identity WHERE branch_uuid = '{$branch}'");
}

try {
    masterRuntimeCleanup($conn);
    $conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    (new SyncBranchIdentity())->ensure($conn, [
        'role' => 'branch',
        'branch' => [
            'uuid' => MASTER_BRANCH_UUID,
            'name' => 'master-convergence-runtime',
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ],
    ]);
    $conn->query(
        "INSERT INTO item_group (id, gname, isdeleted) VALUES ("
        . MASTER_CATEGORY_ID . ", 'Runtime Category', 0)"
    );
    $conn->query(
        "INSERT INTO myunits (id, uname, isdeleted) VALUES ("
        . MASTER_UNIT_ID . ", 'runtime-unit', 0)"
    );
    $conn->query(
        "INSERT INTO myitems (
            id, iname, barcode, cost_price, price1, price2, price3, group1,
            isdeleted, user, tenant, branch, item_type, track_stock, preferred_unit_id,
            is_active, crtime, mdtime
        ) VALUES (
            " . MASTER_INGREDIENT_ID . ", 'Runtime Ingredient', 'runtime-ingredient',
            1.000, 0.000, 0.000, 0.000, " . MASTER_CATEGORY_ID . ",
            0, 41, 0, 0, 'ingredient', 1, " . MASTER_UNIT_ID . ",
            1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'
        )"
    );
    $conn->query(
        "INSERT INTO recipe_headers (
            id, recipe_uuid, pos_tenant, pos_branch, branch_uuid, sellable_item_id,
            recipe_name, recipe_type, status, version_number, yield_qty, yield_unit_id,
            default_wastage_percent, costing_method, created_by, created_at, updated_at
        ) VALUES (
            " . MASTER_RECIPE_ID . ", '" . MASTER_RECIPE_UUID . "', 0, 0, '"
        . MASTER_BRANCH_UUID . "', " . MASTER_ITEM_ID . ",
            'Original Recipe', 'make_to_order', 'draft', 1, '1.000000', "
        . MASTER_UNIT_ID . ", '0.0000', 'moving_average', 51,
            '2026-01-01 00:00:00', '2026-01-01 00:00:00'
        )"
    );
    $conn->query(
        "INSERT INTO myitems (
            id, iname, barcode, cost_price, price1, price2, price3, group1,
            isdeleted, user, tenant, branch, is_active, crtime, mdtime
        ) VALUES (
            " . MASTER_ITEM_ID . ", 'Original', 'runtime-master', 2.000, 10.000, 0.000, 0.000, "
        . MASTER_CATEGORY_ID . ", 0, 41, 0, 0, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'
        )"
    );
    $conn->query(
        "INSERT INTO ot_head (
            id, pro_id, pro_tybe, order_type, pro_date, fat_total, fat_net,
            paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, user
        ) VALUES (
            " . MASTER_ORDER_ID . ", 1, 9, 'takeaway', CURRENT_DATE, 10.000, 10.000,
            0.000, 10.000, 'unpaid', 'draft', 'active', 0, 41
        )"
    );
    $conn->query(
        "INSERT INTO fat_details (
            id, fatid, pro_tybe, fat_tybe, item_id, qty_out, price, cost_price,
            det_value, profit, isdeleted
        ) VALUES (
            " . MASTER_LINE_ID . ", " . MASTER_ORDER_ID . ", 9, 9, " . MASTER_ITEM_ID . ",
            1.000000, 10.000000, 2.000000, 10.000000, 8.000000, 0
        )"
    );

    $t1 = '2026-07-29T10:00:00.000001Z';
    $nameResult = masterRuntimeApply($conn, masterRuntimeEvent([
        'item_name' => ['value' => 'Cloud Name', 'changed_at_utc' => $t1],
    ]));
    masterRuntimeAssert(empty($nameResult['denied']), 'authorized catalog edit must apply');

    $t2 = '2026-07-29T10:00:01.000001Z';
    masterRuntimeApply($conn, masterRuntimeEvent([
        'price' => ['value' => '12.500000', 'changed_at_utc' => $t2],
    ], 'cloud-node-b'));
    $item = $conn->query('SELECT iname, price1 FROM myitems WHERE id = ' . MASTER_ITEM_ID)->fetch_assoc();
    masterRuntimeAssert((string) $item['iname'] === 'Cloud Name', 'non-overlapping item-name revision must survive price edit');
    masterRuntimeAssert(number_format((float) $item['price1'], 6, '.', '') === '12.500000', 'winning exact price revision must apply');
    $captured = $conn->query('SELECT price FROM fat_details WHERE id = ' . MASTER_LINE_ID)->fetch_assoc();
    masterRuntimeAssert(number_format((float) $captured['price'], 6, '.', '') === '10.000000', 'existing cart line must retain captured price');
    $newCart = (new OrderPricingService())->resolveTableSaveRequest($conn, [
        'items' => [[
            'id' => MASTER_ITEM_ID,
            'qty' => '1.000000',
            'price' => '0.000000',
            'discount' => '0.000000',
        ]],
        'discount' => '0.000000',
    ]);
    masterRuntimeAssert(
        (string) ($newCart['items'][0]['price'] ?? '') === '12.500000',
        'new cart pricing must resolve the newly converged exact catalog price'
    );

    $branchEvent = masterRuntimeEvent([
        'item_name' => ['value' => 'Branch Converged Name', 'changed_at_utc' => '2026-07-29T10:00:01.500001Z'],
    ], 'branch:' . MASTER_BRANCH_UUID);
    $branchCloudResult = masterRuntimeReceiveOnCloud($conn, $branchEvent);
    masterRuntimeAssert(
        (string) ($branchCloudResult['status'] ?? '') === 'processed',
        'branch master edit must project to cloud'
    );
    $cloudItem = $conn->query(
        "SELECT item_name, price FROM cloud_menu_items
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "' LIMIT 1"
    )->fetch_assoc();
    masterRuntimeAssert(
        (string) ($cloudItem['item_name'] ?? '') === 'Branch Converged Name',
        'branch item name must reach cloud projection'
    );
    masterRuntimeAssert(
        (string) ($cloudItem['price'] ?? '') === '12.500000',
        'partial branch edit must retain independently converged exact price'
    );
    $historyBeforeReplay = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "'"
    )->fetch_assoc()['c'];
    $branchCloudReplay = masterRuntimeReceiveOnCloud($conn, $branchEvent);
    $historyAfterReplay = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "'"
    )->fetch_assoc()['c'];
    masterRuntimeAssert(
        (string) ($branchCloudReplay['status'] ?? '') === 'duplicate',
        'same branch event retry must replay from cloud inbox'
    );
    masterRuntimeAssert(
        $historyBeforeReplay === $historyAfterReplay,
        'branch event replay must not duplicate master revision history'
    );

    $tieTime = '2026-07-29T10:00:02.000001Z';
    masterRuntimeApply($conn, masterRuntimeEvent([
        'item_name' => ['value' => 'Tie Winner', 'changed_at_utc' => $tieTime],
    ], 'cloud-node-z'));
    masterRuntimeApply($conn, masterRuntimeEvent([
        'item_name' => ['value' => 'Tie Loser', 'changed_at_utc' => $tieTime],
    ], 'cloud-node-0'));
    $item = $conn->query('SELECT iname FROM myitems WHERE id = ' . MASTER_ITEM_ID)->fetch_assoc();
    masterRuntimeAssert((string) $item['iname'] === 'Tie Winner', 'equal timestamps must use stable node-id tie-break');
    $ignored = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "' AND outcome = 'ignored'"
    )->fetch_assoc()['c'];
    masterRuntimeAssert($ignored >= 1, 'losing field revision must remain in append-only history');

    $beforeCategory = (int) $conn->query(
        'SELECT group1 FROM myitems WHERE id = ' . MASTER_ITEM_ID
    )->fetch_assoc()['group1'];
    try {
        masterRuntimeApply($conn, masterRuntimeEvent([
            'category_id' => ['value' => 999999991, 'changed_at_utc' => '2026-07-29T10:00:03.000001Z'],
        ], 'cloud-node-c'));
        throw new RuntimeException('missing category dependency unexpectedly applied');
    } catch (RuntimeException $e) {
        masterRuntimeAssert(
            $e->getMessage() === 'MASTER_MENU_CATEGORY_DEPENDENCY_MISSING',
            'missing category must fail closed with the expected reason'
        );
    }
    $afterCategory = (int) $conn->query(
        'SELECT group1 FROM myitems WHERE id = ' . MASTER_ITEM_ID
    )->fetch_assoc()['group1'];
    masterRuntimeAssert($beforeCategory === $afterCategory, 'dependency failure must not partially modify item');

    $unauthorized = masterRuntimeApply($conn, masterRuntimeEvent([
        'item_name' => ['value' => 'Unauthorized', 'changed_at_utc' => '2026-07-29T10:00:04.000001Z'],
    ], 'cloud-node-d', 0));
    masterRuntimeAssert(
        !empty($unauthorized['denied']) && $unauthorized['reason'] === 'MASTER_EVENT_ADMIN_AUTH_REQUIRED',
        'unauthorized cloud catalog event must be denied'
    );
    $item = $conn->query('SELECT iname FROM myitems WHERE id = ' . MASTER_ITEM_ID)->fetch_assoc();
    masterRuntimeAssert((string) $item['iname'] === 'Tie Winner', 'unauthorized event must have zero item side effects');

    $delayed = masterRuntimeEvent([
        'name2' => ['value' => 'Delayed but valid', 'changed_at_utc' => '2026-07-29T10:00:04.500001Z'],
    ], 'cloud-node-delayed');
    $delayed['payload']['origin_clock_utc'] = '2026-01-01T00:00:00Z';
    $delayed['payload']['master_data']['origin_clock_utc'] = '2026-01-01T00:00:00Z';
    $delayedResult = masterRuntimeApply($conn, $delayed);
    masterRuntimeAssert(empty($delayedResult['denied']), 'offline delivery age must not be misclassified as clock drift');
    $item = $conn->query('SELECT name2 FROM myitems WHERE id = ' . MASTER_ITEM_ID)->fetch_assoc();
    masterRuntimeAssert((string) $item['name2'] === 'Delayed but valid', 'valid delayed master revision must apply');

    $operational = masterRuntimeEvent([]);
    $operational['event_type'] = 'payment.posted';
    $operational['aggregate_type'] = 'payment';
    $operational['entity_type'] = 'payment';
    $operational['payload']['snapshot_type'] = 'financial_refund_bundle';
    $denied = masterRuntimeApply($conn, $operational);
    masterRuntimeAssert(
        !empty($denied['denied']) && $denied['reason'] === 'CLOUD_OPERATIONAL_OR_UNKNOWN_EVENT_DENIED',
        'cloud-originated operational event must be denied'
    );

    masterRuntimeApply($conn, masterRuntimeRecipeEvent([
        'recipe_name' => ['value' => 'Cloud Draft Recipe', 'changed_at_utc' => '2026-07-29T11:00:00.000001Z'],
        'lines' => [
            'value' => [[
                'line_uuid' => '96969696-9696-4696-8696-969696969696',
                'ingredient_item_id' => MASTER_INGREDIENT_ID,
                'sub_recipe_id' => null,
                'line_type' => 'ingredient',
                'ingredient_item_type_snapshot' => 'ingredient',
                'qty_per_yield' => '2.000000',
                'unit_id' => MASTER_UNIT_ID,
                'unit_conversion_to_base' => '1.00000000',
                'wastage_percent' => '0.0000',
                'is_required' => 1,
                'modifier_group_id' => null,
                'modifier_option_id' => null,
                'modifier_behavior' => 'additive',
                'substitution_group' => null,
                'order_type' => 'any',
                'channel' => 'any',
                'sort_order' => 0,
                'notes' => null,
            ]],
            'changed_at_utc' => '2026-07-29T11:00:00.000001Z',
        ],
    ]));
    $recipe = $conn->query(
        'SELECT recipe_name, status FROM recipe_headers WHERE id = ' . MASTER_RECIPE_ID
    )->fetch_assoc();
    $line = $conn->query(
        'SELECT ingredient_item_id, qty_per_yield FROM recipe_lines WHERE recipe_id = ' . MASTER_RECIPE_ID
    )->fetch_assoc();
    masterRuntimeAssert((string) $recipe['recipe_name'] === 'Cloud Draft Recipe', 'authorized draft recipe name must converge');
    masterRuntimeAssert((string) $recipe['status'] === 'draft', 'cloud recipe convergence must not activate a recipe');
    masterRuntimeAssert((int) $line['ingredient_item_id'] === MASTER_INGREDIENT_ID, 'validated recipe ingredient must converge');
    masterRuntimeAssert((string) $line['qty_per_yield'] === '2.000000', 'recipe quantity must remain exact decimal');

    $lineCountBefore = (int) $conn->query(
        'SELECT COUNT(*) AS c FROM recipe_lines WHERE recipe_id = ' . MASTER_RECIPE_ID
    )->fetch_assoc()['c'];
    try {
        masterRuntimeApply($conn, masterRuntimeRecipeEvent([
            'lines' => [
                'value' => [[
                    'line_uuid' => '97979797-9797-4797-8797-979797979797',
                    'ingredient_item_id' => 999999992,
                    'line_type' => 'ingredient',
                    'qty_per_yield' => '1.000000',
                    'unit_id' => MASTER_UNIT_ID,
                    'unit_conversion_to_base' => '1.00000000',
                    'wastage_percent' => '0.0000',
                    'is_required' => 1,
                    'order_type' => 'any',
                    'channel' => 'any',
                ]],
                'changed_at_utc' => '2026-07-29T11:00:01.000001Z',
            ],
        ], 'cloud-recipe-node-b'));
        throw new RuntimeException('missing recipe dependency unexpectedly applied');
    } catch (RuntimeException $e) {
        masterRuntimeAssert(
            $e->getMessage() === 'MASTER_RECIPE_INGREDIENT_DEPENDENCY_MISSING',
            'missing recipe dependency must fail closed'
        );
    }
    $lineCountAfter = (int) $conn->query(
        'SELECT COUNT(*) AS c FROM recipe_lines WHERE recipe_id = ' . MASTER_RECIPE_ID
    )->fetch_assoc()['c'];
    masterRuntimeAssert($lineCountAfter === $lineCountBefore, 'invalid recipe patch must not partially replace lines');

    $branchRecipeEvent = masterRuntimeRecipeEvent([
        'recipe_name' => [
            'value' => 'Branch Converged Recipe',
            'changed_at_utc' => '2026-07-29T11:00:01.500001Z',
        ],
    ], 'branch:' . MASTER_BRANCH_UUID);
    $branchRecipeResult = masterRuntimeReceiveOnCloud($conn, $branchRecipeEvent);
    masterRuntimeAssert(
        (string) ($branchRecipeResult['status'] ?? '') === 'processed',
        'branch recipe master edit must reach the cloud convergence projector'
    );
    $recipe = $conn->query(
        'SELECT recipe_name FROM recipe_headers WHERE id = ' . MASTER_RECIPE_ID
    )->fetch_assoc();
    masterRuntimeAssert(
        (string) ($recipe['recipe_name'] ?? '') === 'Branch Converged Recipe',
        'branch recipe field revision must converge exactly'
    );
    $recipeHistoryBeforeReplay = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "' AND aggregate_type = 'recipe'"
    )->fetch_assoc()['c'];
    $branchRecipeReplay = masterRuntimeReceiveOnCloud($conn, $branchRecipeEvent);
    $recipeHistoryAfterReplay = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history
         WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "' AND aggregate_type = 'recipe'"
    )->fetch_assoc()['c'];
    masterRuntimeAssert(
        (string) ($branchRecipeReplay['status'] ?? '') === 'duplicate',
        'branch recipe retry must replay from the cloud inbox'
    );
    masterRuntimeAssert(
        $recipeHistoryBeforeReplay === $recipeHistoryAfterReplay,
        'branch recipe retry must not duplicate field history'
    );

    $conn->query("UPDATE recipe_headers SET status = 'active' WHERE id = " . MASTER_RECIPE_ID);
    try {
        masterRuntimeApply($conn, masterRuntimeRecipeEvent([
            'recipe_name' => ['value' => 'Unsafe Active Edit', 'changed_at_utc' => '2026-07-29T11:00:02.000001Z'],
        ], 'cloud-recipe-node-c'));
        throw new RuntimeException('active recipe unexpectedly mutated from cloud');
    } catch (RuntimeException $e) {
        masterRuntimeAssert(
            $e->getMessage() === 'MASTER_RECIPE_ACTIVE_OR_ARCHIVED_REQUIRES_BRANCH_VERSION_APPROVAL',
            'active recipe changes must require branch version approval'
        );
    }

    $conn->query(
        "UPDATE myitems
         SET name2 = 'Branch Recorder Exact', mdtime = CURRENT_TIMESTAMP
         WHERE id = " . MASTER_ITEM_ID
    );
    $conn->begin_transaction();
    try {
        $recorded = (new SyncOutboxEventService())->recordMenuItemSnapshot($conn, MASTER_ITEM_ID, [
            'event_type' => 'menu.item_saved',
            'source_system' => 'catalog_runtime',
            'source_transaction_id' => 'master-runtime-menu-producer-v1',
            'actor_user_id' => 41,
            'config' => [
                'role' => 'branch',
                'branch' => [
                    'uuid' => MASTER_BRANCH_UUID,
                    'name' => 'master-convergence-runtime',
                    'pos_tenant' => 0,
                    'pos_branch' => 0,
                ],
                'sync' => [
                    'outbox_enabled' => true,
                    'menu_sync_enabled' => true,
                    'branch_sync_enabled' => true,
                ],
            ],
        ]);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    masterRuntimeAssert((int) ($recorded['outbox_id'] ?? 0) > 0, 'branch menu write must create an outbox event');
    $outboxRow = $conn->query(
        'SELECT payload_json FROM sync_outbox WHERE id = ' . (int) $recorded['outbox_id']
    )->fetch_assoc();
    $recordedPayload = json_decode((string) ($outboxRow['payload_json'] ?? ''), true);
    masterRuntimeAssert(
        (int) ($recordedPayload['master_data']['actor']['user_id'] ?? 0) === 41
            && in_array('menu.edit', $recordedPayload['master_data']['actor']['permissions'] ?? [], true),
        'branch menu producer must preserve authenticated administrator evidence'
    );
    masterRuntimeAssert(
        (string) ($recordedPayload['master_data']['fields']['name2']['value'] ?? '') === 'Branch Recorder Exact',
        'branch menu producer must emit the changed field through the master revision envelope'
    );
    masterRuntimeAssert(
        (string) ($recordedPayload['menu_item']['price'] ?? '') === '12.500000',
        'branch menu producer must retain exact decimal catalog money'
    );

    $stateCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_state WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "'"
    )->fetch_assoc()['c'];
    $historyCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_master_field_history WHERE branch_uuid = '" . MASTER_BRANCH_UUID . "'"
    )->fetch_assoc()['c'];
    masterRuntimeAssert($stateCount >= 10, 'legacy adapter must seed field-level state');
    masterRuntimeAssert($historyCount > $stateCount, 'accepted and overwritten revisions must be auditable');

    echo "master-data-convergence-runtime-ok state={$stateCount} history={$historyCount}\n";
} finally {
    masterRuntimeCleanup($conn);
    $conn->close();
}
