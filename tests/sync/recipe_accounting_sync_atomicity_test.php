<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/InventoryAccountingSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAccountingService.php';

final class FailingRecipeAccountingSyncEventService extends OperationalSyncEventService
{
    public function recordInventoryAccountingSnapshot(
        mysqli $conn,
        int $journalHeadId,
        array $movementIds,
        array $options = []
    ): ?array {
        throw new RuntimeException('RECIPE_ACCOUNTING_SYNC_CAPTURE_FAILED');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_accounting_sync_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    recipeAccountingSyncCreateAccountingSchema($conn);

    $config = recipeAccountingSyncConfig();
    (new SyncBranchIdentity())->ensure($conn, $config);
    recipeAccountingSyncSeedAccounts($conn);
    $flags = new RecipeFeatureFlags($config);
    $service = new RecipeAccountingService($flags);
    $context = recipeAccountingSyncContext($config);

    $rollbackInput = recipeAccountingSyncSeedMovement(
        $conn,
        1,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
        'production_input',
        '12.000000'
    );
    $rollbackOutput = recipeAccountingSyncSeedMovement(
        $conn,
        2,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2',
        'production_output',
        '10.000000'
    );
    $conn->begin_transaction();
    $rolledBack = $service->postProductionBatch($conn, $context, [$rollbackInput], [$rollbackOutput]);
    recipeAccountingSyncAssert((int) $rolledBack['journal_head_id'] > 0, 'journal should be posted inside the caller transaction');
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 1, 'journal should be visible before caller commit');
    recipeAccountingSyncAssert(recipeAccountingSyncOutboxCount($conn) === 1, 'recipe journal event should exist before caller commit');
    $conn->rollback();
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 0, 'caller rollback must remove the recipe journal');
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_entries') === 0, 'caller rollback must remove the recipe entries');
    recipeAccountingSyncAssert(recipeAccountingSyncOutboxCount($conn) === 0, 'caller rollback must remove the recipe event');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $rollbackInput) === 0, 'caller rollback must remove the input link');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $rollbackOutput) === 0, 'caller rollback must remove the output link');

    $conn->begin_transaction();
    $posted = $service->postProductionBatch($conn, $context, [$rollbackInput], [$rollbackOutput]);
    $conn->commit();
    $journalHeadId = (int) $posted['journal_head_id'];
    recipeAccountingSyncAssert($journalHeadId > 0, 'successful production posting should return a journal head');
    recipeAccountingSyncAssert((int) $posted['entry_count'] === 3, 'production variance should post three balanced entries');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $rollbackInput) === $journalHeadId, 'input movement should link to the recipe journal');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $rollbackOutput) === $journalHeadId, 'output movement should link to the recipe journal');
    $firstEvent = recipeAccountingSyncEvent($conn, $journalHeadId);
    $firstPayload = json_decode((string) $firstEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    recipeAccountingSyncAssert((string) $firstEvent['event_type'] === 'recipe.accounting_journal_saved', 'recipe capture should use its explicit event type');
    recipeAccountingSyncAssert((string) $firstEvent['source_system'] === 'recipe_accounting', 'recipe capture should retain its source system');
    recipeAccountingSyncAssert((string) $firstPayload['journal_head']['source_type'] === 'recipe_movement', 'payload should identify recipe movement provenance');
    recipeAccountingSyncAssert((string) $firstPayload['journal_head']['posting_kind'] === 'recipe_accounting', 'payload should identify recipe accounting provenance');
    recipeAccountingSyncAssert(count($firstPayload['journal_entries']) === 3, 'payload should include the variance entry');
    recipeAccountingSyncAssert(count($firstPayload['movements']) === 2, 'payload should include exact input and output movements');

    $conn->query("DELETE FROM sync_outbox WHERE aggregate_type = 'inventory_journal'");
    $healed = $service->postProductionBatch($conn, $context, [$rollbackInput], [$rollbackOutput]);
    recipeAccountingSyncAssert(!empty($healed['existing']), 'replay should reuse the recipe journal');
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 1, 'replay must not duplicate the recipe journal');
    $healedEvent = recipeAccountingSyncEvent($conn, $journalHeadId);
    recipeAccountingSyncAssert((string) $healedEvent['payload_hash'] === (string) $firstEvent['payload_hash'], 'healed event must reproduce its payload hash');
    recipeAccountingSyncAssert((string) $healedEvent['payload_json'] === (string) $firstEvent['payload_json'], 'healed event must reproduce byte-equivalent content');

    $validator = new InventoryAccountingSyncPayloadService();
    $validator->assertValid($firstPayload, recipeAccountingSyncBranchUuid());
    $wrongPair = $firstPayload;
    $wrongPair['journal_head']['posting_kind'] = 'manual_voucher';
    recipeAccountingSyncRehash($wrongPair);
    recipeAccountingSyncExpectCode(
        static fn() => $validator->assertValid($wrongPair, recipeAccountingSyncBranchUuid()),
        'INVENTORY_JOURNAL_SYNC_HEAD_INVALID'
    );
    $crossedPair = $firstPayload;
    $crossedPair['journal_head']['source_type'] = 'inventory_movement';
    recipeAccountingSyncRehash($crossedPair);
    recipeAccountingSyncExpectCode(
        static fn() => $validator->assertValid($crossedPair, recipeAccountingSyncBranchUuid()),
        'INVENTORY_JOURNAL_SYNC_HEAD_INVALID'
    );

    $failingInput = recipeAccountingSyncSeedMovement(
        $conn,
        3,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3',
        'production_input',
        '9.000000'
    );
    $failingOutput = recipeAccountingSyncSeedMovement(
        $conn,
        4,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4',
        'production_output',
        '8.000000'
    );
    $failingService = new RecipeAccountingService(
        $flags,
        null,
        null,
        null,
        new FailingRecipeAccountingSyncEventService()
    );
    $conn->begin_transaction();
    recipeAccountingSyncExpectCode(
        static fn() => $failingService->postProductionBatch($conn, $context, [$failingInput], [$failingOutput]),
        'RECIPE_ACCOUNTING_SYNC_CAPTURE_FAILED'
    );
    recipeAccountingSyncAssert(
        (int) $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc()['active'] === 1,
        'capture failure should leave the caller transaction active'
    );
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 1, 'savepoint failure must leave only the prior journal');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $failingInput) === 0, 'savepoint failure must remove the input link');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $failingOutput) === 0, 'savepoint failure must remove the output link');
    $conn->rollback();

    $standaloneInput = recipeAccountingSyncSeedMovement(
        $conn,
        5,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa5',
        'production_input',
        '7.000000'
    );
    $standaloneOutput = recipeAccountingSyncSeedMovement(
        $conn,
        6,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa6',
        'production_output',
        '6.000000'
    );
    recipeAccountingSyncExpectCode(
        static fn() => $failingService->postProductionBatch($conn, $context, [$standaloneInput], [$standaloneOutput]),
        'RECIPE_ACCOUNTING_SYNC_CAPTURE_FAILED'
    );
    recipeAccountingSyncAssert(
        (int) $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc()['active'] === 0,
        'self-managed capture failure should close its transaction'
    );
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 1, 'self-managed failure must roll back the failed recipe journal');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $standaloneInput) === 0, 'self-managed failure must remove the input link');
    recipeAccountingSyncAssert(recipeAccountingSyncMovementJournal($conn, $standaloneOutput) === 0, 'self-managed failure must remove the output link');

    $hostedInput = recipeAccountingSyncSeedMovement(
        $conn,
        7,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa7',
        'production_input',
        '5.000000'
    );
    $hostedOutput = recipeAccountingSyncSeedMovement(
        $conn,
        8,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa8',
        'production_output',
        '4.000000'
    );
    $hostedConfig = $config;
    $hostedConfig['role'] = 'cloud';
    $hostedConfig['sync']['cloud_to_branch_publish_enabled'] = true;
    $hostedContext = recipeAccountingSyncContext($hostedConfig);
    $beforeHosted = recipeAccountingSyncOutboxCount($conn);
    $conn->begin_transaction();
    $service->postProductionBatch($conn, $hostedContext, [$hostedInput], [$hostedOutput]);
    recipeAccountingSyncAssert(recipeAccountingSyncOutboxCount($conn) === $beforeHosted, 'hosted recipe accounting must not create automatic reverse sync');
    $conn->rollback();

    $disabledConfig = $config;
    $disabledConfig['recipe']['enabled'] = false;
    $disabledConfig['recipe']['mode'] = 'off';
    $disabledConfig['recipe']['accounting'] = false;
    $disabledService = new RecipeAccountingService(
        new RecipeFeatureFlags($disabledConfig),
        null,
        null,
        null,
        new FailingRecipeAccountingSyncEventService()
    );
    $disabled = $disabledService->postProductionBatch(
        $conn,
        recipeAccountingSyncContext($disabledConfig),
        [$standaloneInput],
        [$standaloneOutput]
    );
    recipeAccountingSyncAssert(!empty($disabled['noop']), 'accounting-disabled behavior should remain a no-op');
    recipeAccountingSyncAssert(recipeAccountingSyncCount($conn, 'journal_heads') === 1, 'accounting-disabled no-op must not write a journal');

    echo "recipe-accounting-sync-atomicity-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeAccountingSyncBranchUuid(): string
{
    return '83838383-8383-4383-8383-838383838383';
}

function recipeAccountingSyncConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => recipeAccountingSyncBranchUuid(),
            'name' => 'Recipe Accounting Atomicity',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
            'cloud_to_branch_publish_enabled' => false,
        ],
        'recipe' => [
            'enabled' => true,
            'mode' => 'full',
            'accounting' => true,
        ],
    ];
}

function recipeAccountingSyncContext(array $config): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => recipeAccountingSyncBranchUuid(),
        'store_id' => 1,
        'batch_id' => 77,
        'batch_uuid' => '83838383-8383-4383-8383-838383838377',
        'output_item_id' => 9002,
        'user_id' => 9,
        'raw_inventory_account_id' => 120,
        'prepared_inventory_account_id' => 130,
        'production_variance_account_id' => 530,
        'sync_config' => $config,
    ];
}

function recipeAccountingSyncCreateAccountingSchema(mysqli $conn): void
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
        UNIQUE KEY uq_recipe_sync_idempotency (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL, debit DECIMAL(24,6) NOT NULL DEFAULT 0,
        credit DECIMAL(24,6) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 BIGINT DEFAULT 0,
        op_id BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
}

function recipeAccountingSyncSeedAccounts(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, tenant, branch) VALUES
        (1, '1', 'Recipe accounting root', 0, 1, 1, 1),
        (120, '120', 'Raw inventory', 1, 0, 1, 1),
        (130, '130', 'Prepared inventory', 1, 0, 1, 1),
        (530, '530', 'Production variance', 1, 0, 1, 1)");
}

function recipeAccountingSyncSeedMovement(
    mysqli $conn,
    int $sourceId,
    string $uuid,
    string $movementType,
    string $totalCost
): int {
    $qtyIn = $movementType === 'production_output' ? '1.000000' : '0.000000';
    $qtyOut = $movementType === 'production_input' ? '1.000000' : '0.000000';
    $stmt = $conn->prepare("INSERT INTO inventory_movements (
        movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type,
        source_type, source_id, production_batch_id, qty_in, qty_out, unit_cost, total_cost, idempotency_key
    ) VALUES (?, 1, 1, ?, 1, ?, ?, 'production_batch', ?, 77, ?, ?, ?, ?, ?)");
    $branchUuid = recipeAccountingSyncBranchUuid();
    $itemId = 9000 + $sourceId;
    $unitCost = $totalCost;
    $key = 'recipe-accounting-sync:' . $sourceId;
    $stmt->bind_param(
        'ssisisssss',
        $uuid,
        $branchUuid,
        $itemId,
        $movementType,
        $sourceId,
        $qtyIn,
        $qtyOut,
        $unitCost,
        $totalCost,
        $key
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function recipeAccountingSyncEvent(mysqli $conn, int $journalHeadId): array
{
    $row = $conn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'inventory_journal' AND aggregate_local_id = {$journalHeadId} LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('recipe accounting outbox event is missing');
    }

    return $row;
}

function recipeAccountingSyncMovementJournal(mysqli $conn, int $movementId): int
{
    return (int) $conn->query('SELECT COALESCE(accounting_journal_id, 0) AS id FROM inventory_movements WHERE id = ' . $movementId)->fetch_assoc()['id'];
}

function recipeAccountingSyncOutboxCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'inventory_journal'")->fetch_assoc()['c'];
}

function recipeAccountingSyncCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function recipeAccountingSyncRehash(array &$payload): void
{
    unset($payload['payload_hash']);
    $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

function recipeAccountingSyncExpectCode(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        recipeAccountingSyncAssert($exception->getMessage() === $code, 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected failure: ' . $code);
}

function recipeAccountingSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
