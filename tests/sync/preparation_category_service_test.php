<?php

require_once __DIR__ . '/../../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_preparation_category_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "preparation-category-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query('CREATE TABLE myitems (id BIGINT NOT NULL PRIMARY KEY, group1 BIGINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE item_group (id BIGINT NOT NULL PRIMARY KEY, gname VARCHAR(120) NOT NULL, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE item_variants (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_item_id BIGINT NOT NULL, variant_item_id BIGINT NOT NULL, is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');

    $planned = (new SyncSchemaManager())->plannedStatements();
    $conn->query($planned['item_preparation_configs']);
    $conn->query($planned['item_group_preparation_configs']);
    $conn->query($planned['order_line_preparation_values']);

    $conn->query('INSERT INTO myitems (id, group1, isdeleted) VALUES (10, 7, 0), (11, 7, 0), (12, 8, 0), (13, 99, 0)');
    $conn->query("INSERT INTO item_group (id, gname, isdeleted) VALUES (7, 'Hot drinks', 0), (8, 'Cold drinks', 0), (9, 'Deleted', 1)");
    $conn->query('INSERT INTO item_variants (parent_item_id, variant_item_id, is_active) VALUES (10, 13, 1)');

    $service = new PreparationSelectionService();
    $enabled = ['preparation_fields_enabled' => true];

    $service->setCategorySugarAllowed($conn, 7, true, 4);
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 10, $enabled), 'category allowance must apply to its first item');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 11, $enabled), 'category allowance must apply to every item in the category');
    preparationCategoryAssert(!$service->itemAllowsSugarSpoons($conn, 12, $enabled), 'another category must remain unaffected');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 13, $enabled), 'variant item must inherit the parent category allowance');

    $validatedZero = $service->validateForItem($conn, 10, [['code' => 'sugar_spoons', 'value' => 0]], $enabled);
    preparationCategoryAssert(($validatedZero[0]['value'] ?? null) === 0, 'zero sugar must remain an explicit valid kitchen instruction');
    $validatedLarge = $service->validateForItem($conn, 10, [['code' => 'sugar_spoons', 'value' => 27]], $enabled);
    preparationCategoryAssert(($validatedLarge[0]['value'] ?? null) === 27, 'normal cashier quantities must not be limited to five');

    $requiredThrown = false;
    try {
        $service->validateForItem($conn, 10, [], $enabled);
    } catch (InvalidArgumentException $exception) {
        $requiredThrown = $exception->getMessage() === 'PREPARATION_VALUE_REQUIRED';
    }
    preparationCategoryAssert($requiredThrown, 'eligible drinks must require an explicit cashier selection');

    $service->setItemSugarAllowed($conn, 12, true, 4);
    $decorated = $service->decorateItems($conn, [
        ['id' => 10, 'group1' => 7],
        ['id' => 12, 'group1' => 8],
        ['id' => 13, 'group1' => 99],
    ], $enabled);
    preparationCategoryAssert(!empty($decorated[0]['allows_sugar_spoons']), 'category item must be decorated as eligible');
    preparationCategoryAssert(!empty($decorated[1]['allows_sugar_spoons']), 'direct item allowance must decorate as eligible');
    preparationCategoryAssert(!empty($decorated[2]['allows_sugar_spoons']), 'variant decoration must follow its parent');

    $service->setCategorySugarAllowed($conn, 7, false, 4);
    preparationCategoryAssert(!$service->itemAllowsSugarSpoons($conn, 10, $enabled), 'disabling a category must remove inherited allowance');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 12, $enabled), 'disabling a category must not remove direct item allowance');

    $conn->begin_transaction();
    $replacement = $service->replaceSugarAssignments($conn, [7], [12], 4);
    $conn->commit();
    preparationCategoryAssert($replacement['changed_category_ids'] === [7], 'bulk replacement must report the changed category');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 10, $enabled), 'bulk-selected category must enable all current category items');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 12, $enabled), 'bulk-selected individual item must remain enabled');

    $conn->begin_transaction();
    $replacement = $service->replaceSugarAssignments($conn, [8], [11], 4);
    $conn->commit();
    preparationCategoryAssert($replacement['changed_category_ids'] === [7, 8], 'bulk replacement must disable removed categories and enable selected categories');
    preparationCategoryAssert($replacement['changed_item_ids'] === [11, 12], 'bulk replacement must disable removed items and enable selected items');
    preparationCategoryAssert(!$service->itemAllowsSugarSpoons($conn, 10, $enabled), 'removed category must no longer enable its unselected item');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 11, $enabled), 'explicit item selection must work outside category inheritance');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 12, $enabled), 'new category selection must enable its items');

    $invalidOwnerThrown = false;
    try {
        $service->replaceSugarAssignments($conn, [9], [], 4);
    } catch (InvalidArgumentException $exception) {
        $invalidOwnerThrown = $exception->getMessage() === 'PREPARATION_ASSIGNMENT_OWNER_INVALID';
    }
    preparationCategoryAssert($invalidOwnerThrown, 'bulk replacement must reject deleted or unknown owners');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 11, $enabled), 'rejected bulk replacement must preserve existing item assignments');
    preparationCategoryAssert($service->itemAllowsSugarSpoons($conn, 12, $enabled), 'rejected bulk replacement must preserve existing category assignments');

    $conn->begin_transaction();
    $noChanges = $service->replaceSugarAssignments($conn, [8], [11], 4);
    $conn->commit();
    preparationCategoryAssert($noChanges['changed_category_ids'] === [], 'saving unchanged category assignments must be idempotent');
    preparationCategoryAssert($noChanges['changed_item_ids'] === [], 'saving unchanged item assignments must be idempotent');

    echo "preparation-category-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function preparationCategoryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
