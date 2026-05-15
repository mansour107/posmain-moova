<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ModifierLineNoteService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_modifiers_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $ids = phase4ModifierSeed($conn);
    $service = new ModifierLineNoteService();
    $enabled = phase4ModifierEnvEnabled();

    if (!$enabled) {
        $result = $service->saveLineCustomizations(
            $conn,
            900,
            901,
            $ids['item_id'],
            [$ids['oat_option_id'], $ids['caramel_option_id']],
            [['note_type' => 'kitchen', 'note_text' => 'بدون سكر']],
            ['user_id' => 77]
        );
        phase4ModifierAssert($result['code'] === 'MODIFIERS_DISABLED', 'disabled mode should return MODIFIERS_DISABLED');
        phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_modifiers') === 0, 'disabled mode should not write modifiers');
        phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_notes') === 0, 'disabled mode should not write notes');
        echo "phase4-modifier-line-note-service-ok disabled db={$db}\n";
        return;
    }

    $saved = $service->saveLineCustomizations(
        $conn,
        900,
        901,
        $ids['item_id'],
        [
            ['option_id' => $ids['oat_option_id'], 'qty' => 1],
            ['option_id' => $ids['caramel_option_id'], 'qty' => 1.5],
        ],
        [
            ['note_type' => 'kitchen', 'note_text' => 'بدون سكر'],
            ['note_type' => 'cashier', 'note_text' => 'حساسية مكسرات', 'created_by' => 88],
        ],
        ['user_id' => 77]
    );
    phase4ModifierAssert($saved['success'] === true, 'enabled mode should save');
    phase4ModifierAssert($saved['modifier_total'] === '8.875', 'modifier total should include decimal qty deltas');
    phase4ModifierAssert(count($saved['modifiers']) === 2, 'two modifier rows expected');
    phase4ModifierAssert(count($saved['notes']) === 2, 'two line notes expected');
    phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_modifiers') === 2, 'persisted modifier row count mismatch');
    phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_notes') === 2, 'persisted note row count mismatch');

    $fetched = $service->fetchLineCustomizations($conn, 900, 901);
    phase4ModifierAssert(count($fetched['modifiers']) === 2, 'fetch should return saved modifiers');
    phase4ModifierAssert($fetched['modifiers'][1]['line_delta'] === '3.375', 'fetch should preserve decimal line delta');
    phase4ModifierAssert($fetched['notes'][0]['note_text'] === 'بدون سكر', 'fetch should return kitchen note');
    phase4ModifierAssert($fetched['notes'][1]['created_by'] === 88, 'fetch should preserve note created_by override');

    $service->saveLineCustomizations(
        $conn,
        900,
        901,
        $ids['item_id'],
        [$ids['whole_option_id']],
        [['note_type' => 'customer', 'note_text' => 'على جنب']],
        ['user_id' => 77]
    );
    phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_modifiers') === 1, 'replace should remove old modifier rows');
    phase4ModifierAssert(phase4ModifierCount($conn, 'order_line_notes') === 1, 'replace should remove old notes');

    phase4ModifierExpectException(function () use ($service, $conn, $ids) {
        $service->saveLineCustomizations($conn, 1, 2, $ids['item_id'], [], [], ['modifiers_enabled' => true]);
    }, 'MODIFIER_SELECTION_MIN');

    phase4ModifierExpectException(function () use ($service, $conn, $ids) {
        $service->saveLineCustomizations(
            $conn,
            1,
            2,
            $ids['item_id'],
            [
                $ids['whole_option_id'],
                ['option_id' => $ids['caramel_option_id'], 'qty' => 1.5],
                ['option_id' => $ids['vanilla_option_id'], 'qty' => 1],
            ],
            [],
            ['modifiers_enabled' => true]
        );
    }, 'MODIFIER_SELECTION_MAX');

    phase4ModifierExpectException(function () use ($service, $conn, $ids) {
        $service->saveLineCustomizations(
            $conn,
            1,
            2,
            $ids['item_id'],
            [$ids['whole_option_id'], $ids['inactive_option_id']],
            [],
            ['modifiers_enabled' => true]
        );
    }, 'MODIFIER_OPTION_INVALID');

    phase4ModifierExpectException(function () use ($service, $conn, $ids) {
        $service->saveLineCustomizations(
            $conn,
            1,
            2,
            $ids['item_id'],
            [$ids['whole_option_id']],
            [['note_type' => 'expo', 'note_text' => 'bad']],
            ['modifiers_enabled' => true]
        );
    }, 'NOTE_TYPE_INVALID');

    echo "phase4-modifier-line-note-service-ok enabled db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4ModifierSeed(mysqli $conn): array
{
    $itemId = 200;
    $milkGroupId = phase4ModifierInsertGroup($conn, 'اختيار الحليب', 1, 1, 1, 1);
    $extrasGroupId = phase4ModifierInsertGroup($conn, 'إضافات', 0, 2, 0, 2);
    $wholeOptionId = phase4ModifierInsertOption($conn, $milkGroupId, 'حليب عادي', '0.000', 1, 1);
    $oatOptionId = phase4ModifierInsertOption($conn, $milkGroupId, 'حليب شوفان', '5.500', 1, 2);
    $caramelOptionId = phase4ModifierInsertOption($conn, $extrasGroupId, 'كراميل', '2.250', 1, 1);
    $vanillaOptionId = phase4ModifierInsertOption($conn, $extrasGroupId, 'فانيليا', '1.750', 1, 2);
    $inactiveOptionId = phase4ModifierInsertOption($conn, $extrasGroupId, 'غير نشط', '9.000', 0, 3);

    phase4ModifierLinkItemGroup($conn, $itemId, $milkGroupId, 1);
    phase4ModifierLinkItemGroup($conn, $itemId, $extrasGroupId, 2);

    return [
        'item_id' => $itemId,
        'milk_group_id' => $milkGroupId,
        'extras_group_id' => $extrasGroupId,
        'whole_option_id' => $wholeOptionId,
        'oat_option_id' => $oatOptionId,
        'caramel_option_id' => $caramelOptionId,
        'vanilla_option_id' => $vanillaOptionId,
        'inactive_option_id' => $inactiveOptionId,
    ];
}

function phase4ModifierInsertGroup(mysqli $conn, string $name, int $min, int $max, int $required, int $sort): int
{
    $stmt = $conn->prepare("
        INSERT INTO modifier_groups (name_ar, selection_min, selection_max, is_required, tenant, branch, sort_order)
        VALUES (?, ?, ?, ?, 0, 0, ?)
    ");
    $stmt->bind_param('siiii', $name, $min, $max, $required, $sort);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function phase4ModifierInsertOption(mysqli $conn, int $groupId, string $name, string $priceDelta, int $active, int $sort): int
{
    $stmt = $conn->prepare("
        INSERT INTO modifier_options (group_id, name_ar, price_delta, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issii', $groupId, $name, $priceDelta, $active, $sort);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function phase4ModifierLinkItemGroup(mysqli $conn, int $itemId, int $groupId, int $sort): void
{
    $stmt = $conn->prepare("
        INSERT INTO item_modifier_groups (item_id, group_id, sort_order)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param('iii', $itemId, $groupId, $sort);
    $stmt->execute();
    $stmt->close();
}

function phase4ModifierCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM {$table}")->fetch_assoc()['c'];
}

function phase4ModifierEnvEnabled(): bool
{
    $config = posmain_app_config();
    return !empty($config['features']['modifiers']);
}

function phase4ModifierExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4ModifierAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4ModifierAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
