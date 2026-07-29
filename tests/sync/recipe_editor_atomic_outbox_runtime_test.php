<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorMutationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = trim((string) getenv('POSMAIN_TEST_MYSQL_DB'));
if ($db === '' || !preg_match('/^posmain_master_sync_[a-z0-9_]+$/', $db)) {
    fwrite(STDERR, "recipe-editor-atomic-outbox-runtime-refused-unsafe-database\n");
    exit(1);
}

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');
(new SyncSchemaManager())->apply($conn);

const RECIPE_ATOMIC_ITEM_OK = 984961;
const RECIPE_ATOMIC_ITEM_FAIL = 984962;

function recipeEditorAtomicRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recipeEditorAtomicRuntimeCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function recipeEditorAtomicRuntimeInsertItem(mysqli $conn, int $itemId, string $name): void
{
    $stmt = $conn->prepare("
        INSERT INTO myitems (
            id, iname, barcode, cost_price, price1, price2, price3, group1,
            isdeleted, user, tenant, branch, is_active, crtime, mdtime
        ) VALUES (?, ?, ?, 0.000000, 10.000000, 0.000000, 0.000000, 0, 0, 71, 0, 0, 1, NOW(), NOW())
    ");
    $barcode = 'recipe-atomic-' . $itemId;
    $stmt->bind_param('iss', $itemId, $name, $barcode);
    $stmt->execute();
    $stmt->close();
}

function recipeEditorAtomicRuntimeCleanup(mysqli $conn): void
{
    foreach ([RECIPE_ATOMIC_ITEM_OK, RECIPE_ATOMIC_ITEM_FAIL] as $itemId) {
        $recipeIds = [];
        $result = $conn->query('SELECT id FROM recipe_headers WHERE sellable_item_id = ' . $itemId);
        while ($row = $result->fetch_assoc()) {
            $recipeIds[] = (int) $row['id'];
        }
        if ($recipeIds !== []) {
            $ids = implode(',', $recipeIds);
            $conn->query("DELETE FROM sync_outbox WHERE aggregate_type = 'recipe' AND aggregate_local_id IN ({$ids})");
            $conn->query("DELETE FROM recipe_audit_log WHERE recipe_id IN ({$ids})");
            $conn->query("DELETE FROM recipe_cost_snapshots WHERE recipe_id IN ({$ids})");
            $conn->query("DELETE FROM recipe_variant_lines WHERE recipe_id IN ({$ids})");
            $conn->query("DELETE FROM recipe_lines WHERE recipe_id IN ({$ids})");
            $conn->query("DELETE FROM recipe_headers WHERE id IN ({$ids})");
        }
        $conn->query('DELETE FROM myitems WHERE id = ' . $itemId);
    }
}

$renamedOutbox = false;
try {
    recipeEditorAtomicRuntimeCleanup($conn);
    recipeEditorAtomicRuntimeInsertItem($conn, RECIPE_ATOMIC_ITEM_OK, 'Atomic Recipe Success');
    recipeEditorAtomicRuntimeInsertItem($conn, RECIPE_ATOMIC_ITEM_FAIL, 'Atomic Recipe Rollback');

    $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'shadow',
        ],
    ]));
    $service = new RecipeEditorMutationService($definition);
    $actor = new RecipeActorContext(71, 0, 0, null, ['recipe.manage'], '127.0.0.1', 'runtime');

    $created = $service->handle($conn, 'create_draft', [
        'sellable_item_id' => RECIPE_ATOMIC_ITEM_OK,
        'recipe_type' => 'make_to_order',
        'yield_qty' => '1.000000',
        'default_wastage_percent' => '0.0000',
    ], $actor);
    $recipeId = (int) $created['recipe_id'];
    recipeEditorAtomicRuntimeAssert($recipeId > 0, 'successful recipe mutation must return its durable recipe id');

    $stmt = $conn->prepare("
        SELECT payload_json
        FROM sync_outbox
        WHERE aggregate_type = 'recipe'
          AND aggregate_local_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $recipeId);
    $stmt->execute();
    $outbox = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    recipeEditorAtomicRuntimeAssert($outbox !== null, 'successful recipe mutation must commit an outbox event');
    $payload = json_decode((string) $outbox['payload_json'], true);
    recipeEditorAtomicRuntimeAssert(
        (int) ($payload['master_data']['actor']['user_id'] ?? 0) === 71,
        'recipe master event must retain the authenticated mutation actor'
    );
    recipeEditorAtomicRuntimeAssert(
        (string) ($payload['snapshot_type'] ?? '') === 'recipe_bundle',
        'recipe outbox event must use the recipe master bundle contract'
    );

    $recipeCountBefore = recipeEditorAtomicRuntimeCount($conn, 'recipe_headers');
    $auditCountBefore = recipeEditorAtomicRuntimeCount($conn, 'recipe_audit_log');
    $historyCountBefore = recipeEditorAtomicRuntimeCount($conn, 'sync_master_field_history');

    $conn->query('RENAME TABLE sync_outbox TO sync_outbox_atomic_test_unavailable');
    $renamedOutbox = true;
    try {
        $service->handle($conn, 'create_draft', [
            'sellable_item_id' => RECIPE_ATOMIC_ITEM_FAIL,
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $actor);
        throw new RuntimeException('recipe mutation unexpectedly committed without its outbox');
    } catch (Throwable $exception) {
        recipeEditorAtomicRuntimeAssert(
            str_contains($exception->getMessage(), 'sync_outbox table is missing'),
            'missing outbox must surface the actionable migration error'
        );
    } finally {
        $conn->query('RENAME TABLE sync_outbox_atomic_test_unavailable TO sync_outbox');
        $renamedOutbox = false;
    }

    recipeEditorAtomicRuntimeAssert(
        recipeEditorAtomicRuntimeCount($conn, 'recipe_headers') === $recipeCountBefore,
        'failed outbox write must roll back the recipe header'
    );
    recipeEditorAtomicRuntimeAssert(
        recipeEditorAtomicRuntimeCount($conn, 'recipe_audit_log') === $auditCountBefore,
        'failed outbox write must roll back recipe audit rows'
    );
    recipeEditorAtomicRuntimeAssert(
        recipeEditorAtomicRuntimeCount($conn, 'sync_master_field_history') === $historyCountBefore,
        'failed outbox write must roll back master revision history'
    );

    echo "recipe-editor-atomic-outbox-runtime-ok recipe_id={$recipeId}\n";
} finally {
    if ($renamedOutbox) {
        $conn->query('RENAME TABLE sync_outbox_atomic_test_unavailable TO sync_outbox');
    }
    recipeEditorAtomicRuntimeCleanup($conn);
    $conn->close();
}
