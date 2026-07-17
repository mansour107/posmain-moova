<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryAccountingService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAccountingService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_inventory_accounting_source_' . getmypid();
$targetDb = 'posmain_restore_inventory_accounting_target_' . getmypid();
$conflictDb = 'posmain_restore_inventory_accounting_conflict_' . getmypid();
$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_error) {
    echo "branch-restore-inventory-accounting-skipped mysql-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$source = null;
$target = null;
$conflict = null;
try {
    foreach ([$sourceDb, $targetDb, $conflictDb] as $db) {
        $admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
    $source = new mysqli($host, $user, $pass, $sourceDb, $port);
    $target = new mysqli($host, $user, $pass, $targetDb, $port);
    $conflict = new mysqli($host, $user, $pass, $conflictDb, $port);
    foreach ([$source, $target, $conflict] as $conn) {
        (new SyncSchemaManager())->apply($conn);
        branchRestoreInventoryAccountingCreateAccountingSchema($conn);
    }

    $config = branchRestoreInventoryAccountingConfig();
    (new SyncBranchIdentity())->ensure($source, $config);
    branchRestoreInventoryAccountingSeedAccounts($source);
    $movementUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
    $movementId = branchRestoreInventoryAccountingSeedMovement($source, $movementUuid);
    $service = new InventoryAccountingService(new InventoryFeatureFlags($config));
    $source->begin_transaction();
    $posted = $service->postWaste($source, branchRestoreInventoryAccountingContext($config), [$movementId]);
    $source->commit();
    $journalHeadId = (int) $posted['journal_head_id'];
    $event = branchRestoreInventoryAccountingEvent($source, $journalHeadId);

    branchRestoreInventoryAccountingAssert(
        RestoreEventPhase::classify($event) === RestoreEventPhase::OPERATIONAL,
        'inventory accounting must be restored only in the guarded operational phase'
    );

    branchRestoreInventoryAccountingSeedMovement($target, $movementUuid, $movementId);
    $inbox = new SyncInboxService();
    $applied = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryAccountingAssert($applied['status'] === 'processed', 'typed inventory journal event should apply');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'acc_head') === 3, 'referenced accounts and their ancestor should restore');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'journal_heads') === 1, 'one immutable journal head should restore');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'journal_entries') === 2, 'both balanced entries should restore');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingMovementJournal($target, $movementId) === $journalHeadId, 'existing movement should receive the exact journal link');
    $totals = $target->query('SELECT SUM(debit) AS debit, SUM(credit) AS credit FROM journal_entries')->fetch_assoc();
    branchRestoreInventoryAccountingAssert(bccomp((string) $totals['debit'], (string) $totals['credit'], 6) === 0, 'restored journal must remain balanced');

    $duplicate = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $event,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryAccountingAssert($duplicate['status'] === 'duplicate', 'exact replay should be idempotent');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'journal_heads') === 1, 'exact replay must not duplicate the journal');

    $sameVersionConflict = $event;
    $sameVersionConflict['payload']['journal_head']['details'] = 'Conflicting immutable details';
    branchRestoreInventoryAccountingRehash($sameVersionConflict);
    $sameVersionConflict['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $sameVersionConflict['idempotency_key'] = 'inventory-journal-conflict:' . $sameVersionConflict['event_uuid'];
    $conflictResult = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $sameVersionConflict,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryAccountingAssert($conflictResult['status'] === 'conflict', 'same version with different content must fail closed');
    $storedDetails = (string) $target->query('SELECT details FROM journal_heads WHERE id = ' . $journalHeadId)->fetch_assoc()['details'];
    branchRestoreInventoryAccountingAssert($storedDetails !== 'Conflicting immutable details', 'projection conflict must not overwrite the stored journal');

    branchRestoreInventoryAccountingSeedMovement(
        $conflict,
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        $movementId
    );
    branchRestoreInventoryAccountingExpectCode(
        static fn() => (new SyncInboxService())->receiveBranchEvent(
            $conflict,
            $config['branch']['uuid'],
            $event,
            SyncApplyMode::LIVE_APPLY
        ),
        'INVENTORY_JOURNAL_SYNC_MOVEMENT_IDENTITY_CONFLICT'
    );
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($conflict, 'acc_head') === 0, 'movement conflict must roll back inserted accounts');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($conflict, 'journal_heads') === 0, 'movement conflict must roll back the journal head');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($conflict, 'journal_entries') === 0, 'movement conflict must roll back journal entries');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingMovementJournal($conflict, $movementId) === 0, 'movement conflict must leave its journal link empty');

    $recipeInputUuid = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc1';
    $recipeOutputUuid = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc2';
    $recipeInputId = branchRestoreRecipeAccountingSeedMovement(
        $source,
        $recipeInputUuid,
        'production_input',
        '12.000000'
    );
    $recipeOutputId = branchRestoreRecipeAccountingSeedMovement(
        $source,
        $recipeOutputUuid,
        'production_output',
        '10.000000'
    );
    $recipeService = new RecipeAccountingService(new RecipeFeatureFlags($config));
    $recipePosted = $recipeService->postProductionBatch(
        $source,
        branchRestoreRecipeAccountingContext($config),
        [$recipeInputId],
        [$recipeOutputId]
    );
    $recipeJournalHeadId = (int) $recipePosted['journal_head_id'];
    $recipeEvent = branchRestoreInventoryAccountingEvent($source, $recipeJournalHeadId);
    branchRestoreInventoryAccountingAssert(
        RestoreEventPhase::classify($recipeEvent) === RestoreEventPhase::OPERATIONAL,
        'recipe accounting must use the same guarded operational restore phase'
    );
    branchRestoreInventoryAccountingAssert(
        (string) $recipeEvent['event_type'] === 'recipe.accounting_journal_saved',
        'recipe accounting should retain its explicit event type'
    );

    branchRestoreRecipeAccountingSeedMovement(
        $target,
        $recipeInputUuid,
        'production_input',
        '12.000000',
        $recipeInputId
    );
    branchRestoreRecipeAccountingSeedMovement(
        $target,
        $recipeOutputUuid,
        'production_output',
        '10.000000',
        $recipeOutputId
    );
    $recipeApplied = $inbox->receiveBranchEvent(
        $target,
        $config['branch']['uuid'],
        $recipeEvent,
        SyncApplyMode::LIVE_APPLY
    );
    branchRestoreInventoryAccountingAssert($recipeApplied['status'] === 'processed', 'typed recipe journal event should apply');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'acc_head') === 6, 'recipe accounts should restore without duplicating their ancestor');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'journal_heads') === 2, 'recipe restore should add exactly one journal head');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingCount($target, 'journal_entries') === 5, 'recipe restore should add its three variance entries');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingMovementJournal($target, $recipeInputId) === $recipeJournalHeadId, 'recipe input movement should receive the exact journal link');
    branchRestoreInventoryAccountingAssert(branchRestoreInventoryAccountingMovementJournal($target, $recipeOutputId) === $recipeJournalHeadId, 'recipe output movement should receive the exact journal link');
    $restoredRecipeHead = $target->query('SELECT source_type, posting_kind FROM journal_heads WHERE id = ' . $recipeJournalHeadId)->fetch_assoc();
    branchRestoreInventoryAccountingAssert((string) $restoredRecipeHead['source_type'] === 'recipe_movement', 'restored recipe journal should preserve source provenance');
    branchRestoreInventoryAccountingAssert((string) $restoredRecipeHead['posting_kind'] === 'recipe_accounting', 'restored recipe journal should preserve posting provenance');

    echo "branch-restore-inventory-accounting-ok source={$sourceDb} target={$targetDb}\n";
} finally {
    foreach ([$source, $target, $conflict] as $conn) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
    foreach ([$sourceDb, $targetDb, $conflictDb] as $db) {
        $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    }
    $admin->close();
}

function branchRestoreInventoryAccountingConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '82828282-8282-4282-8282-828282828282',
            'name' => 'Inventory Accounting Restore',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
        'inventory' => [
            'ledger_mode' => 'bridge',
            'accounting' => true,
        ],
        'recipe' => [
            'enabled' => true,
            'mode' => 'full',
            'accounting' => true,
        ],
    ];
}

function branchRestoreInventoryAccountingContext(array $config): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'operation_id' => 88,
        'user_id' => 9,
        'inventory_asset_account_id' => 10,
        'waste_expense_account_id' => 11,
        'sync_config' => $config,
    ];
}

function branchRestoreInventoryAccountingCreateAccountingSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL, deletable INT DEFAULT 1,
        editable INT DEFAULT 1, aname VARCHAR(100) NOT NULL, constant INT DEFAULT 0, is_stock INT DEFAULT 0,
        is_fund INT DEFAULT 0, rentable INT NULL, parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0, nature INT NULL,
        kind INT NULL, is_basic INT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0,
        tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        total DECIMAL(24,6) NOT NULL, jdate DATE NOT NULL, op_id BIGINT NULL, pro_tybe INT NULL,
        details VARCHAR(250) NULL, op2 BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, user BIGINT NULL,
        tenant INT DEFAULT 0, branch INT DEFAULT 0, source_type VARCHAR(64) NULL, source_id BIGINT NULL,
        posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL, reversal_of_journal_id BIGINT NULL,
        UNIQUE KEY uq_inventory_restore_idempotency (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL, debit DECIMAL(24,6) NOT NULL DEFAULT 0,
        credit DECIMAL(24,6) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 BIGINT DEFAULT 0,
        op_id BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
}

function branchRestoreInventoryAccountingSeedAccounts(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, tenant, branch) VALUES
        (1, '1', 'Inventory accounting root', 0, 1, 1, 1),
        (10, '110', 'Inventory asset', 1, 0, 1, 1),
        (11, '510', 'Inventory waste', 1, 0, 1, 1),
        (120, '120', 'Raw inventory', 1, 0, 1, 1),
        (130, '130', 'Prepared inventory', 1, 0, 1, 1),
        (530, '530', 'Production variance', 1, 0, 1, 1)");
}

function branchRestoreRecipeAccountingContext(array $config): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => $config['branch']['uuid'],
        'store_id' => 1,
        'batch_id' => 99,
        'batch_uuid' => '82828282-8282-4282-8282-828282828299',
        'output_item_id' => 9902,
        'user_id' => 9,
        'raw_inventory_account_id' => 120,
        'prepared_inventory_account_id' => 130,
        'production_variance_account_id' => 530,
        'sync_config' => $config,
    ];
}

function branchRestoreRecipeAccountingSeedMovement(
    mysqli $conn,
    string $uuid,
    string $movementType,
    string $totalCost,
    ?int $id = null
): int {
    $columns = $id === null ? '' : 'id, ';
    $values = $id === null ? '' : ((int) $id) . ', ';
    $qtyIn = $movementType === 'production_output' ? '1.000000' : '0.000000';
    $qtyOut = $movementType === 'production_input' ? '1.000000' : '0.000000';
    $branchUuid = branchRestoreInventoryAccountingConfig()['branch']['uuid'];
    $itemId = $movementType === 'production_input' ? 9901 : 9902;
    $key = 'recipe-accounting-restore:' . $movementType;
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        {$columns}movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type,
        source_type, source_id, production_batch_id, qty_in, qty_out, unit_cost, total_cost, idempotency_key
    ) VALUES ({$values}?, 1, 1, ?, 1, ?, ?, 'production_batch', 99, 99, ?, ?, ?, ?, ?)");
    $unitCost = $totalCost;
    $stmt->bind_param(
        'ssissssss',
        $uuid,
        $branchUuid,
        $itemId,
        $movementType,
        $qtyIn,
        $qtyOut,
        $unitCost,
        $totalCost,
        $key
    );
    $stmt->execute();
    $movementId = $id ?? (int) $conn->insert_id;
    $stmt->close();

    return $movementId;
}

function branchRestoreInventoryAccountingSeedMovement(mysqli $conn, string $uuid, ?int $id = null): int
{
    $columns = $id === null ? '' : 'id, ';
    $values = $id === null ? '' : ((int) $id) . ', ';
    $branchUuid = branchRestoreInventoryAccountingConfig()['branch']['uuid'];
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        {$columns}movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type,
        source_type, source_id, qty_out, unit_cost, total_cost, idempotency_key
    ) VALUES ({$values}?, 1, 1, ?, 1, 9901, 'waste', 'adjustment', 1, '2.000000', '3.000000', '6.000000', 'inventory-accounting-restore:1')");
    $stmt->bind_param('ss', $uuid, $branchUuid);
    $stmt->execute();
    $movementId = $id ?? (int) $conn->insert_id;
    $stmt->close();

    return $movementId;
}

function branchRestoreInventoryAccountingEvent(mysqli $conn, int $journalHeadId): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'inventory_journal' AND aggregate_local_id = {$journalHeadId} LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('inventory accounting restore event missing');
    }

    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'payload_hash' => (string) $row['payload_hash'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function branchRestoreInventoryAccountingRehash(array &$event): void
{
    unset($event['payload']['payload_hash']);
    $event['payload']['payload_hash'] = hash('sha256', branchRestoreInventoryAccountingJson($event['payload']));
    $event['payload_hash'] = hash('sha256', branchRestoreInventoryAccountingJson($event['payload']));
}

function branchRestoreInventoryAccountingJson(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($json)) {
        throw new RuntimeException('inventory accounting restore JSON encode failed');
    }
    return $json;
}

function branchRestoreInventoryAccountingMovementJournal(mysqli $conn, int $movementId): int
{
    return (int) $conn->query('SELECT COALESCE(accounting_journal_id, 0) AS id FROM inventory_movements WHERE id = ' . $movementId)->fetch_assoc()['id'];
}

function branchRestoreInventoryAccountingCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function branchRestoreInventoryAccountingExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        branchRestoreInventoryAccountingAssert($exception->getMessage() === $code, 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected failure: ' . $code);
}

function branchRestoreInventoryAccountingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
