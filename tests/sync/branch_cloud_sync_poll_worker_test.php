<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/CloudLegacyPosMirrorService.php';
require_once __DIR__ . '/../../classes/Sync/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../../classes/Sync/BranchCloudSyncPollWorker.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class BranchCloudSyncPollWorkerTest extends TestCase
{
    private const BRANCH_UUID = 'dadadada-3333-4333-8333-dadadadadada';
    private const SECRET = 'phpunit-branch-cloud-sync-secret';
    private const ITEM_ID = 987654;
    private const ORDER_ID = 987655;
    private const LINE_ID = 987656;
    private const TABLE_ID = 987657;

    private static $conn;
    private $originalIdentity;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
        $db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

        self::$conn = @new mysqli($host, $user, $pass, $db, $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        self::$conn->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->originalIdentity = (new SyncBranchIdentity())->find(self::$conn);
        $this->cleanup();
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
        $this->registerCloudBranch();
    }

    protected function tearDown(): void
    {
        if (!self::$conn) {
            return;
        }

        $this->cleanup();
        if ($this->originalIdentity) {
            $stmt = self::$conn->prepare("
                INSERT INTO sync_branch_identity (
                    id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version
                ) VALUES (1, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE branch_uuid = VALUES(branch_uuid),
                                        branch_name = VALUES(branch_name),
                                        pos_tenant = VALUES(pos_tenant),
                                        pos_branch = VALUES(pos_branch),
                                        cloud_base_url = VALUES(cloud_base_url),
                                        current_menu_version = VALUES(current_menu_version)
            ");
            $branchUuid = (string) $this->originalIdentity['branch_uuid'];
            $branchName = $this->originalIdentity['branch_name'];
            $posTenant = $this->nullableInt($this->originalIdentity['pos_tenant']);
            $posBranch = $this->nullableInt($this->originalIdentity['pos_branch']);
            $cloudBaseUrl = $this->originalIdentity['cloud_base_url'];
            $menuVersion = (int) $this->originalIdentity['current_menu_version'];
            $stmt->bind_param('ssiisi', $branchUuid, $branchName, $posTenant, $posBranch, $cloudBaseUrl, $menuVersion);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function testPollerAppliesHostedMenuEditWhenCloudEventIsNewer(): void
    {
        $this->insertLocalItem('Local Old Name', '10.00', '2026-01-01 10:00:00');
        $cursor = $this->insertCloudMenuEvent('Cloud New Name', '14.50', '2026-01-01T10:10:00Z');

        $metrics = (new BranchCloudSyncPollWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
            'http_post' => $this->cloudHttpPost(),
        ]);

        $item = $this->fetchItem();
        $this->assertSame(1, $metrics['fetched']);
        $this->assertSame(1, $metrics['applied']);
        $this->assertSame(0, $metrics['stale']);
        $this->assertSame(1, $metrics['acked']);
        $this->assertSame($cursor, $metrics['checkpoint']);
        $this->assertSame('Cloud New Name', $item['iname']);
        $this->assertSame('14.500', number_format((float) $item['price1'], 3, '.', ''));
        $this->assertSame('ack_applied', $this->fetchCloudEvent($cursor)['status']);
        $this->assertSame((string) $cursor, $this->fetchCheckpoint());
        $this->assertSame('processed', $this->fetchInbox()['status']);
    }

    public function testPollerDeclinesHostedMenuEditWhenLocalValueIsNewer(): void
    {
        $this->insertLocalItem('Local Newer Name', '20.00', '2026-01-01 10:30:00');
        $cursor = $this->insertCloudMenuEvent('Cloud Older Name', '12.00', '2026-01-01T10:10:00Z');

        $metrics = (new BranchCloudSyncPollWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
            'http_post' => $this->cloudHttpPost(),
        ]);

        $item = $this->fetchItem();
        $this->assertSame(1, $metrics['fetched']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(1, $metrics['stale']);
        $this->assertSame(1, $metrics['acked']);
        $this->assertSame('Local Newer Name', $item['iname']);
        $this->assertSame('20.000', number_format((float) $item['price1'], 3, '.', ''));
        $event = $this->fetchCloudEvent($cursor);
        $this->assertSame('ack_declined', $event['status']);
        $this->assertStringContainsString('local value is newer', (string) $event['last_error']);
    }

    public function testPollerDeniesHostedOperationalOrderAndTableEvents(): void
    {
        $orderCursor = $this->insertCloudOrderEvent('2026-01-01T11:00:00Z');
        $tableCursor = $this->insertCloudTableEvent('2026-01-01T11:00:00Z');

        $metrics = (new BranchCloudSyncPollWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
            'http_post' => $this->cloudHttpPost(),
        ]);

        $this->assertSame(2, $metrics['fetched']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(2, $metrics['denied']);
        $this->assertSame(2, $metrics['acked']);
        $this->assertSame($tableCursor, $metrics['checkpoint']);
        $this->assertSame('ack_declined', $this->fetchCloudEvent($orderCursor)['status']);
        $this->assertSame('ack_declined', $this->fetchCloudEvent($tableCursor)['status']);
        $this->assertNull(self::$conn->query('SELECT id FROM ot_head WHERE id = ' . self::ORDER_ID)->fetch_assoc());
        $this->assertNull(self::$conn->query('SELECT id FROM fat_details WHERE id = ' . self::LINE_ID)->fetch_assoc());
        $this->assertNull(self::$conn->query('SELECT id FROM tables WHERE id = ' . self::TABLE_ID)->fetch_assoc());
    }

    public function testPollerDeniesHostedTableReleaseWithoutChangingLocalOrder(): void
    {
        $this->insertLocalActiveTableOrder('2026-01-01 11:00:00');
        $cursor = $this->insertCloudFreedTableEvent('2026-01-01T11:10:00Z');

        $metrics = (new BranchCloudSyncPollWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
            'http_post' => $this->cloudHttpPost(),
        ]);

        $order = self::$conn->query('SELECT * FROM ot_head WHERE id = ' . self::ORDER_ID)->fetch_assoc();
        $table = self::$conn->query('SELECT * FROM tables WHERE id = ' . self::TABLE_ID)->fetch_assoc();

        $this->assertSame(1, $metrics['fetched']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(1, $metrics['denied']);
        $this->assertSame(1, $metrics['acked']);
        $this->assertSame($cursor, $metrics['checkpoint']);
        $this->assertSame('ack_declined', $this->fetchCloudEvent($cursor)['status']);
        $this->assertSame('1', (string) $table['table_case']);
        $this->assertSame('active', (string) $order['order_status']);
        $this->assertSame('draft', (string) $order['invoice_status']);
        $this->assertSame('unpaid', (string) $order['payment_status']);
        $this->assertSame('0', (string) $order['isdeleted']);
    }

    private function insertLocalItem(string $name, string $price, string $mdtime): void
    {
        $id = self::ITEM_ID;
        $barcode = 'phpunit-cloud-sync';
        $stmt = self::$conn->prepare("
            INSERT INTO myitems (
                id, iname, barcode, cost_price, price1, price2, price3, group1, group2, isdeleted, user, tenant, branch, crtime, mdtime
            ) VALUES (?, ?, ?, 1.000, ?, 0.000, 0.000, 0, 0, 0, 1, 0, 0, ?, ?)
            ON DUPLICATE KEY UPDATE
                iname = VALUES(iname),
                barcode = VALUES(barcode),
                price1 = VALUES(price1),
                isdeleted = 0,
                crtime = VALUES(crtime),
                mdtime = VALUES(mdtime)
        ");
        $stmt->bind_param('isssss', $id, $name, $barcode, $price, $mdtime, $mdtime);
        $stmt->execute();
        $stmt->close();
    }

    private function insertCloudMenuEvent(string $name, string $price, string $capturedAtUtc): int
    {
        $itemUuid = PosOrderSnapshotBuilder::deterministicUuid(self::BRANCH_UUID, 'myitems:' . self::ITEM_ID);
        $nameRevisionUuid = PosOrderSnapshotBuilder::deterministicUuid(
            self::BRANCH_UUID,
            'phpunit:menu:name:' . $name . ':' . $capturedAtUtc
        );
        $priceRevisionUuid = PosOrderSnapshotBuilder::deterministicUuid(
            self::BRANCH_UUID,
            'phpunit:menu:price:' . $price . ':' . $capturedAtUtc
        );
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_menu_item',
            'source_system' => 'cloud_pos',
            'branch_uuid' => self::BRANCH_UUID,
            'source_node_id' => 'cloud-admin-node',
            'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'actor' => [
                'user_id' => 44,
                'permissions' => ['menu.edit'],
            ],
            'master_data' => [
                'schema_version' => 1,
                'aggregate_type' => 'menu_item',
                'aggregate_uuid' => $itemUuid,
                'source_node_id' => 'cloud-admin-node',
                'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'actor' => [
                    'user_id' => 44,
                    'permissions' => ['menu.edit'],
                ],
                'fields' => [
                    'item_name' => [
                        'value' => $name,
                        'changed_at_utc' => $capturedAtUtc,
                        'revision_uuid' => $nameRevisionUuid,
                    ],
                    'price' => [
                        'value' => $price,
                        'changed_at_utc' => $capturedAtUtc,
                        'revision_uuid' => $priceRevisionUuid,
                    ],
                ],
            ],
            'captured_at_utc' => $capturedAtUtc,
            'item_uuid' => $itemUuid,
            'local_item_id' => self::ITEM_ID,
            'menu_item' => [
                'item_uuid' => $itemUuid,
                'local_item_id' => self::ITEM_ID,
                'item_id' => self::ITEM_ID,
                'item_name' => $name,
                'price' => $price,
                'price1' => $price,
                'cost' => '1.00',
                'cost_price' => '1.00',
                'category_id' => 0,
                'isdeleted' => 0,
                'menu_version' => strtotime($capturedAtUtc),
            ],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $idempotencyKey = 'phpunit:branch-cloud-sync:' . bin2hex(random_bytes(8));
        $aggregateId = 'myitems:' . self::ITEM_ID;
        $sourceSystem = 'cloud_pos';
        $entityType = 'menu_item';
        $eventType = 'menu.item_saved';
        $eventVersion = (int) $payload['menu_item']['menu_version'];
        $localId = self::ITEM_ID;
        $branchUuid = self::BRANCH_UUID;

        $stmt = self::$conn->prepare("
            INSERT INTO cloud_sync_branch_events (
                event_uuid,
                branch_uuid,
                event_type,
                event_version,
                source_system,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param(
            'sssisssisssisss',
            $eventUuid,
            $branchUuid,
            $eventType,
            $eventVersion,
            $sourceSystem,
            $entityType,
            $itemUuid,
            $localId,
            $aggregateId,
            $entityType,
            $itemUuid,
            $localId,
            $idempotencyKey,
            $hash,
            $payloadJson
        );
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertCloudOrderEvent(string $capturedAtUtc): int
    {
        $orderUuid = '66666666-6666-4666-8666-666666666666';
        $lineUuid = '77777777-7777-4777-8777-777777777777';
        $tableUuid = '88888888-8888-4888-8888-888888888888';
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_order',
            'source_system' => 'pos_cashier',
            'branch_uuid' => self::BRANCH_UUID,
            'captured_at_utc' => $capturedAtUtc,
            'order_uuid' => $orderUuid,
            'local_order_id' => self::ORDER_ID,
            'order' => [
                'order_uuid' => $orderUuid,
                'local_order_id' => self::ORDER_ID,
                'pro_id' => '271',
                'pro_tybe' => 9,
                'order_type' => 'table',
                'cashier_user_id' => 1,
                'waiter_id' => 131,
                'table_uuid' => $tableUuid,
                'table_id' => self::TABLE_ID,
                'table_name' => 'PHPUnit Table',
                'pro_date' => '2026-01-01',
                'created_at' => '2026-01-01 11:00:00',
                'pro_value' => '18.00',
                'fat_total' => '18.00',
                'fat_net' => '18.00',
                'fat_disc' => '0.00',
                'paid_amount' => '0.00',
                'remaining_amount' => '18.00',
                'payment_status' => 'unpaid',
                'invoice_status' => 'draft',
                'order_status' => 'active',
                'isdeleted' => 0,
                'closed' => 0,
                'sync_revision' => strtotime($capturedAtUtc),
                'lines' => [[
                    'line_uuid' => $lineUuid,
                    'local_line_id' => self::LINE_ID,
                    'item_id' => self::ITEM_ID,
                    'item_name' => 'PHPUnit Tea',
                    'qty_out' => '1.00',
                    'price' => '18.00',
                    'cost_price' => '4.00',
                    'discount' => '0.00',
                    'det_value' => '18.00',
                    'profit' => '14.00',
                    'isdeleted' => 0,
                ]],
            ],
        ];

        return $this->insertCloudSyncEvent('order.saved', 'order', $orderUuid, self::ORDER_ID, 'ot_head:' . self::ORDER_ID, $payload);
    }

    private function insertCloudTableEvent(string $capturedAtUtc): int
    {
        $tableUuid = '88888888-8888-4888-8888-888888888888';
        $orderUuid = '66666666-6666-4666-8666-666666666666';
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_table',
            'source_system' => 'pos_cashier',
            'branch_uuid' => self::BRANCH_UUID,
            'captured_at_utc' => $capturedAtUtc,
            'table_uuid' => $tableUuid,
            'local_table_id' => self::TABLE_ID,
            'table' => [
                'table_uuid' => $tableUuid,
                'local_table_id' => self::TABLE_ID,
                'table_id' => self::TABLE_ID,
                'tname' => 'PHPUnit Table',
                'table_name' => 'PHPUnit Table',
                'table_case' => 1,
                'isdeleted' => 0,
                'active_order_uuid' => $orderUuid,
                'active_order_local_id' => self::ORDER_ID,
                'sync_revision' => strtotime($capturedAtUtc),
            ],
        ];

        return $this->insertCloudSyncEvent('table.updated', 'table', $tableUuid, self::TABLE_ID, 'tables:' . self::TABLE_ID, $payload);
    }

    private function insertCloudFreedTableEvent(string $capturedAtUtc): int
    {
        $tableUuid = '88888888-8888-4888-8888-888888888888';
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_table',
            'source_system' => 'cloud_pos',
            'branch_uuid' => self::BRANCH_UUID,
            'captured_at_utc' => $capturedAtUtc,
            'table_uuid' => $tableUuid,
            'local_table_id' => self::TABLE_ID,
            'table' => [
                'table_uuid' => $tableUuid,
                'local_table_id' => self::TABLE_ID,
                'table_id' => self::TABLE_ID,
                'tname' => 'PHPUnit Table',
                'table_name' => 'PHPUnit Table',
                'table_case' => 0,
                'isdeleted' => 0,
                'active_order_uuid' => null,
                'active_order_local_id' => null,
                'sync_revision' => strtotime($capturedAtUtc),
            ],
        ];

        return $this->insertCloudSyncEvent('table.updated', 'table', $tableUuid, self::TABLE_ID, 'tables:' . self::TABLE_ID, $payload);
    }

    private function insertLocalActiveTableOrder(string $mdtime): void
    {
        self::$conn->query("
            INSERT INTO tables (id, uuid, tname, table_case, isdeleted, mdtime)
            VALUES (" . self::TABLE_ID . ", '88888888-8888-4888-8888-888888888888', 'PHPUnit Table', 1, 0, '" . self::$conn->real_escape_string($mdtime) . "')
            ON DUPLICATE KEY UPDATE table_case = 1,
                                    isdeleted = 0,
                                    mdtime = VALUES(mdtime)
        ");
        self::$conn->query("
            INSERT INTO ot_head (
                id, pro_id, pro_tybe, order_type, table_id, pro_date,
                fat_total, fat_net, paid_amount, remaining_amount,
                payment_status, invoice_status, order_status, isdeleted, user, mdtime
            ) VALUES (
                " . self::ORDER_ID . ", 271, 9, 'table', " . self::TABLE_ID . ", '2026-01-01',
                18.000, 18.000, 0.000, 18.000,
                'unpaid', 'draft', 'active', 0, 1, '" . self::$conn->real_escape_string($mdtime) . "'
            )
            ON DUPLICATE KEY UPDATE table_id = VALUES(table_id),
                                    payment_status = 'unpaid',
                                    invoice_status = 'draft',
                                    order_status = 'active',
                                    isdeleted = 0,
                                    mdtime = VALUES(mdtime)
        ");
    }

    private function insertCloudSyncEvent(string $eventType, string $entityType, string $entityUuid, int $localId, string $aggregateId, array $payload): int
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $idempotencyKey = 'phpunit:branch-cloud-sync:' . bin2hex(random_bytes(8));
        $sourceSystem = (string) ($payload['source_system'] ?? 'cloud_pos');
        $eventVersion = (int) ($payload['order']['sync_revision'] ?? $payload['table']['sync_revision'] ?? 1);
        $branchUuid = self::BRANCH_UUID;

        $stmt = self::$conn->prepare("
            INSERT INTO cloud_sync_branch_events (
                event_uuid,
                branch_uuid,
                event_type,
                event_version,
                source_system,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param(
            'sssisssisssisss',
            $eventUuid,
            $branchUuid,
            $eventType,
            $eventVersion,
            $sourceSystem,
            $entityType,
            $entityUuid,
            $localId,
            $aggregateId,
            $entityType,
            $entityUuid,
            $localId,
            $idempotencyKey,
            $hash,
            $payloadJson
        );
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function cloudHttpGet(): callable
    {
        return function (string $url, array $headers): array {
            $this->assertStringContainsString('/api/sync/branch_events.php', $url);
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $result = (new CloudBranchSyncEventService())->handleBranchEvents(
                self::$conn,
                $this->headerLinesToMap($headers),
                $query,
                $this->cloudConfig()
            );

            return [
                'ok' => $result['status_code'] >= 200 && $result['status_code'] < 300,
                'status' => $result['status_code'],
                'body' => json_encode($result['body'], JSON_UNESCAPED_SLASHES),
                'json' => $result['body'],
                'error' => '',
            ];
        };
    }

    private function cloudHttpPost(): callable
    {
        return function (string $url, string $body, array $headers): array {
            $this->assertStringContainsString('/api/sync/ack_branch_events.php', $url);
            $result = (new CloudBranchSyncEventService())->handleAck(
                self::$conn,
                $this->headerLinesToMap($headers),
                $body,
                $this->cloudConfig()
            );

            return [
                'ok' => $result['status_code'] >= 200 && $result['status_code'] < 300,
                'status' => $result['status_code'],
                'body' => json_encode($result['body'], JSON_UNESCAPED_SLASHES),
                'json' => $result['body'],
                'error' => '',
            ];
        };
    }

    private function headerLinesToMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $parts = explode(':', (string) $header, 2);
            if (count($parts) === 2) {
                $map[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $map;
    }

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Cloud Sync Branch',
                'pos_tenant' => 11,
                'pos_branch' => 22,
                'cloud_base_url' => 'http://fake-cloud.local',
            ],
            'sync' => [
                'branch_secret' => self::SECRET,
                'branch_sync_enabled' => true,
                'worker_enabled' => true,
                'cloud_pull_enabled' => true,
            ],
        ]);
    }

    private function cloudConfig(): array
    {
        return posmain_app_config([
            'role' => 'cloud',
            'sync' => [
                'cloud_branch_secrets' => [self::BRANCH_UUID => self::SECRET],
            ],
        ]);
    }

    private function registerCloudBranch(): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Cloud Sync Branch';
        $stmt = self::$conn->prepare("
            INSERT INTO cloud_branches (branch_uuid, branch_name, status, sync_secret_hash)
            VALUES (?, ?, 'active', ?)
            ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name),
                                    status = 'active',
                                    sync_secret_hash = VALUES(sync_secret_hash)
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('sss', $branchUuid, $name, $hash);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchItem(): array
    {
        $row = self::$conn->query('SELECT * FROM myitems WHERE id = ' . self::ITEM_ID)->fetch_assoc();
        return $row ?: [];
    }

    private function fetchCloudEvent(int $id): array
    {
        $row = self::$conn->query('SELECT * FROM cloud_sync_branch_events WHERE id = ' . $id)->fetch_assoc();
        return $row ?: [];
    }

    private function fetchCheckpoint(): ?string
    {
        $stmt = self::$conn->prepare("
            SELECT last_cursor
            FROM sync_checkpoints
            WHERE branch_uuid = ?
              AND stream_name = 'cloud_sync'
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['last_cursor'] ?? null;
    }

    private function fetchInbox(): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM sync_inbox
            WHERE branch_uuid = ?
              AND direction = 'cloud_to_branch'
              AND idempotency_key LIKE 'phpunit:branch-cloud-sync:%'
            ORDER BY id DESC
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_sync_branch_events WHERE idempotency_key LIKE 'phpunit:branch-cloud-sync:%'");
        self::$conn->query("DELETE FROM sync_inbox WHERE branch_uuid = '" . self::BRANCH_UUID . "' AND idempotency_key LIKE 'phpunit:branch-cloud-sync:%'");
        self::$conn->query("DELETE FROM sync_checkpoints WHERE branch_uuid = '" . self::BRANCH_UUID . "' AND stream_name = 'cloud_sync'");
        self::$conn->query("DELETE FROM sync_master_field_history WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
        self::$conn->query("DELETE FROM sync_master_field_state WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
        self::$conn->query('DELETE FROM fat_details WHERE id = ' . self::LINE_ID . ' OR fatid = ' . self::ORDER_ID);
        self::$conn->query('DELETE FROM ot_head WHERE id = ' . self::ORDER_ID);
        self::$conn->query('DELETE FROM tables WHERE id = ' . self::TABLE_ID);
        self::$conn->query('DELETE FROM myitems WHERE id = ' . self::ITEM_ID);
        self::$conn->query("DELETE FROM sync_branch_identity WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}

class branch_cloud_sync_poll_worker_test extends BranchCloudSyncPollWorkerTest
{
}
