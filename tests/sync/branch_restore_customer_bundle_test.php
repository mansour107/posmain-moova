<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/CloudOperationalMirrorService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$sourceDb = 'posmain_restore_customer_source_' . getmypid();
$targetDb = 'posmain_restore_customer_target_' . getmypid();
$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_error) {
    echo "branch-restore-customer-bundle-skipped mysql-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$source = null;
$target = null;
try {
    $admin->query("CREATE DATABASE `{$sourceDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $admin->query("CREATE DATABASE `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $source = new mysqli($host, $user, $pass, $sourceDb, $port);
    $target = new mysqli($host, $user, $pass, $targetDb, $port);
    (new SyncSchemaManager())->apply($source);
    (new SyncSchemaManager())->apply($target);

    $config = branchRestoreCustomerConfig();
    $service = new PosCustomerService();
    $profile = $service->saveCustomer($source, [
        'display_name' => 'Restore Customer',
        'notes' => 'PII required for business recovery',
        'phones' => [
            ['phone' => '01006660001', 'is_primary' => true],
            ['phone' => '01006660002', 'label' => 'secondary'],
        ],
        'addresses' => [
            ['address_text' => 'Restore Address A', 'is_default' => true],
            ['address_text' => 'Restore Address B'],
        ],
    ], ['config' => $config]);
    $customerId = (int) $profile['id'];
    $secondaryPhoneId = (int) $source->query(
        "SELECT id FROM pos_customer_phones WHERE customer_id = {$customerId} ORDER BY id DESC LIMIT 1"
    )->fetch_assoc()['id'];
    $secondaryAddressId = (int) $source->query(
        "SELECT id FROM pos_customer_addresses WHERE customer_id = {$customerId} ORDER BY id DESC LIMIT 1"
    )->fetch_assoc()['id'];

    $source->begin_transaction();
    $source->query("UPDATE pos_customer_phones SET isdeleted = 1 WHERE id = {$secondaryPhoneId}");
    $source->query("UPDATE pos_customer_addresses SET isdeleted = 1 WHERE id = {$secondaryAddressId}");
    $service->recordSyncSnapshot($source, $customerId, [
        'config' => $config,
        'event_type' => 'customer.fixture_tombstones',
        'source_system' => 'restore_test',
    ]);
    $source->commit();

    $event = branchRestoreCustomerLatestEvent($source, $customerId);
    branchRestoreCustomerAssert(
        RestoreEventPhase::classify($event) === RestoreEventPhase::OPERATIONAL,
        'customer bundle must be exported in the guarded operational restore phase'
    );

    $mirror = new CloudOperationalMirrorService();
    $result = $mirror->applyFromBranchEvent($target, $config['branch']['uuid'], $event);
    branchRestoreCustomerAssert((int) ($result['customer_id'] ?? 0) === $customerId, 'customer bundle should restore');
    branchRestoreCustomerAssert(branchRestoreCustomerCount($target, 'pos_customers') === 1, 'one customer parent should restore');
    branchRestoreCustomerAssert(branchRestoreCustomerCount($target, 'pos_customer_phones') === 2, 'active and tombstoned phones should restore');
    branchRestoreCustomerAssert(branchRestoreCustomerCount($target, 'pos_customer_addresses') === 2, 'active and tombstoned addresses should restore');
    branchRestoreCustomerAssert(
        (int) $target->query("SELECT isdeleted FROM pos_customer_phones WHERE id = {$secondaryPhoneId}")->fetch_assoc()['isdeleted'] === 1,
        'phone tombstone should restore exactly'
    );
    branchRestoreCustomerAssert(
        (int) $target->query("SELECT isdeleted FROM pos_customer_addresses WHERE id = {$secondaryAddressId}")->fetch_assoc()['isdeleted'] === 1,
        'address tombstone should restore exactly'
    );

    $mirror->applyFromBranchEvent($target, $config['branch']['uuid'], $event);
    branchRestoreCustomerAssert(branchRestoreCustomerCount($target, 'pos_customer_phones') === 2, 'exact replay must not duplicate phones');
    branchRestoreCustomerAssert(branchRestoreCustomerCount($target, 'pos_customer_addresses') === 2, 'exact replay must not duplicate addresses');

    $crossCustomer = $event;
    $crossCustomer['payload']['phones'][0]['customer_id'] = $customerId + 1;
    branchRestoreCustomerRehash($crossCustomer);
    branchRestoreCustomerExpectFailure(
        static fn () => $mirror->applyFromBranchEvent($target, $config['branch']['uuid'], $crossCustomer),
        'CUSTOMER_SYNC_PHONE_SCOPE_INVALID'
    );

    $identityConflict = $event;
    $identityConflict['payload']['phones'][0]['id'] = 990001;
    $identityConflict['payload']['customer']['primary_phone_id'] = 990001;
    branchRestoreCustomerRehash($identityConflict);
    branchRestoreCustomerExpectFailure(
        static fn () => $mirror->applyFromBranchEvent($target, $config['branch']['uuid'], $identityConflict),
        'CUSTOMER_SYNC_PHONE_IDENTITY_CONFLICT'
    );

    $wrongBranch = '72727272-7272-4272-8272-727272727272';
    branchRestoreCustomerExpectFailure(
        static fn () => $mirror->applyFromBranchEvent($target, $wrongBranch, $event),
        'CUSTOMER_SYNC_IDENTITY_INVALID'
    );

    echo "branch-restore-customer-bundle-ok source={$sourceDb} target={$targetDb}\n";
} finally {
    if ($source instanceof mysqli) {
        $source->close();
    }
    if ($target instanceof mysqli) {
        $target->close();
    }
    $admin->query("DROP DATABASE IF EXISTS `{$sourceDb}`");
    $admin->query("DROP DATABASE IF EXISTS `{$targetDb}`");
    $admin->close();
}

function branchRestoreCustomerConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '72717171-7171-4171-8171-717171717171',
            'name' => 'Customer Restore',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
}

function branchRestoreCustomerLatestEvent(mysqli $conn, int $customerId): array
{
    $row = $conn->query(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'pos_customer' AND aggregate_local_id = {$customerId} ORDER BY event_version DESC LIMIT 1"
    )->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('customer restore event fixture missing');
    }

    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'payload_hash' => (string) $row['payload_hash'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function branchRestoreCustomerRehash(array &$event): void
{
    unset($event['payload']['payload_hash']);
    $event['payload']['payload_hash'] = hash(
        'sha256',
        json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    $event['payload_hash'] = hash(
        'sha256',
        json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}

function branchRestoreCustomerExpectFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        branchRestoreCustomerAssert($exception->getMessage() === $message, "expected {$message}, got {$exception->getMessage()}");
        return;
    }

    throw new RuntimeException("expected {$message}");
}

function branchRestoreCustomerCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc()['c'];
}

function branchRestoreCustomerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
