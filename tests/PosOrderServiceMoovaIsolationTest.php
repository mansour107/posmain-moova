<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/PosOrderService.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';

class PosOrderServiceMoovaIsolationTest extends TestCase
{
    private static $conn;

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

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        MoovaPosIntegration::ensureSchema(self::$conn);
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
    }

    public function test_same_table_moova_orders_share_receipt_but_cancel_only_owned_lines(): void
    {
        $table = $this->loadFreeTable();
        $items = self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 2
        ")->fetch_all(MYSQLI_ASSOC);

        if (!$table || count($items) < 2) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $service = new PosOrderService();
        $scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
        $prefix = 'phpunit-isolation-' . bin2hex(random_bytes(6));

        self::$conn->begin_transaction();
        try {
            $first = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $prefix . '-A',
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    ['itemId' => (string) $items[0]['id'], 'qty' => 1],
                ],
            ]);
            $second = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $prefix . '-B',
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    ['itemId' => (string) $items[0]['id'], 'qty' => 2],
                ],
            ]);

            $this->assertSame((int) $first['order_id'], (int) $second['order_id']);
            $this->assertFalse((bool) $first['merged']);
            $this->assertTrue((bool) $second['merged']);

            $orderId = (int) $first['order_id'];
            $sameItemRowsAfterCreate = self::$conn->query('SELECT COUNT(*) AS c, SUM(qty_out) AS q FROM fat_details WHERE fatid = ' . $orderId . ' AND item_id = ' . (int) $items[0]['id'] . ' AND isdeleted = 0')->fetch_assoc();
            $this->assertSame(1, (int) $sameItemRowsAfterCreate['c']);
            $this->assertSame(3.0, (float) $sameItemRowsAfterCreate['q']);

            self::$conn->query("
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store, cost_price, profit,
                    tenant, branch
                ) VALUES (9, {$orderId}, " . (int) $items[0]['id'] . ", 1, 0, 4, 10, 0, 40, {$orderId}, 9, 1, 0, 40, 0, 0)
            ");

            $service->replaceMoovaTableOrder(self::$conn, $scope, $orderId, [
                'moovaOrderId' => $prefix . '-B',
                'expectedStateHash' => $second['state_hash'],
                'items' => [
                    ['itemId' => (string) $items[0]['id'], 'qty' => 3],
                ],
            ]);

            $firstRows = self::$conn->query("
                SELECT l.qty_out, fd.isdeleted
                FROM moova_pos_order_lines l
                INNER JOIN fat_details fd ON fd.id = l.fat_detail_id
                WHERE l.moova_order_id = '" . self::$conn->real_escape_string($prefix . '-A') . "'
                  AND l.status = 'active'
            ")->fetch_all(MYSQLI_ASSOC);
            $secondRows = self::$conn->query("
                SELECT l.qty_out, fd.isdeleted
                FROM moova_pos_order_lines l
                INNER JOIN fat_details fd ON fd.id = l.fat_detail_id
                WHERE l.moova_order_id = '" . self::$conn->real_escape_string($prefix . '-B') . "'
                  AND l.status = 'active'
            ")->fetch_all(MYSQLI_ASSOC);
            $this->assertSame(1, count($firstRows));
            $this->assertSame(1, count($secondRows));
            $this->assertSame(1.0, (float) $firstRows[0]['qty_out']);
            $this->assertSame(3.0, (float) $secondRows[0]['qty_out']);
            $usageAfterEdit = self::$conn->query('SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE order_id = ' . $orderId)->fetch_assoc();
            $this->assertSame(0, (int) $usageAfterEdit['c']);
            $sameItemRowsAfterEdit = self::$conn->query('SELECT COUNT(*) AS c, SUM(qty_out) AS q FROM fat_details WHERE fatid = ' . $orderId . ' AND item_id = ' . (int) $items[0]['id'] . ' AND isdeleted = 0')->fetch_assoc();
            $this->assertSame(2, (int) $sameItemRowsAfterEdit['c']);
            $this->assertSame(8.0, (float) $sameItemRowsAfterEdit['q']);

            $secondState = $service->getMoovaOrderLineStateSnapshot(self::$conn, 0, 0, $orderId, $prefix . '-B');
            $service->cancelMoovaTableOrder(self::$conn, $scope, $orderId, $prefix . '-B', $secondState['hash'] ?? '');

            $order = self::$conn->query('SELECT isdeleted, fat_net FROM ot_head WHERE id = ' . $orderId)->fetch_assoc();
            $tableState = self::$conn->query('SELECT table_case FROM tables WHERE id = ' . (int) $table['id'])->fetch_assoc();
            $activeFirstLines = self::$conn->query("
                SELECT COUNT(*) AS c
                FROM moova_pos_order_lines l
                INNER JOIN fat_details fd ON fd.id = l.fat_detail_id
                WHERE l.moova_order_id = '" . self::$conn->real_escape_string($prefix . '-A') . "'
                  AND l.status = 'active'
                  AND fd.isdeleted = 0
            ")->fetch_assoc();
            $activeSecondLines = self::$conn->query("
                SELECT COUNT(*) AS c
                FROM moova_pos_order_lines l
                INNER JOIN fat_details fd ON fd.id = l.fat_detail_id
                WHERE l.moova_order_id = '" . self::$conn->real_escape_string($prefix . '-B') . "'
                  AND l.status = 'active'
                  AND fd.isdeleted = 0
            ")->fetch_assoc();
            $unmappedActive = self::$conn->query("
                SELECT COUNT(*) AS c
                FROM fat_details fd
                LEFT JOIN moova_pos_order_lines l ON l.fat_detail_id = fd.id
                WHERE fd.fatid = {$orderId}
                  AND fd.isdeleted = 0
                  AND l.id IS NULL
            ")->fetch_assoc();

            $this->assertSame(0, (int) $order['isdeleted']);
            $this->assertSame(1, (int) $tableState['table_case']);
            $this->assertSame(1, (int) $activeFirstLines['c']);
            $this->assertSame(0, (int) $activeSecondLines['c']);
            $this->assertSame(1, (int) $unmappedActive['c']);
        } finally {
            self::$conn->rollback();
        }
    }

    public function test_last_moova_line_cancel_zeroes_receipt_without_freeing_table(): void
    {
        $table = $this->loadFreeTable();
        $item = self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();

        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $service = new PosOrderService();
        $scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
        $moovaOrderId = 'phpunit-last-cancel-' . bin2hex(random_bytes(6));

        self::$conn->begin_transaction();
        try {
            $created = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $moovaOrderId,
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    ['itemId' => (string) $item['id'], 'qty' => 1],
                ],
            ]);

            $service->cancelMoovaTableOrder(self::$conn, $scope, (int) $created['order_id'], $moovaOrderId, $created['state_hash']);

            $order = self::$conn->query('SELECT isdeleted, fat_net, invoice_status FROM ot_head WHERE id = ' . (int) $created['order_id'])->fetch_assoc();
            $tableState = self::$conn->query('SELECT table_case FROM tables WHERE id = ' . (int) $table['id'])->fetch_assoc();

            $this->assertSame(1, (int) $order['isdeleted']);
            $this->assertSame(0.0, (float) $order['fat_net']);
            $this->assertSame('cancelled', (string) $order['invoice_status']);
            $this->assertSame(1, (int) $tableState['table_case']);
            $usage = self::$conn->query('SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE order_id = ' . (int) $created['order_id'])->fetch_assoc();
            $this->assertSame(0, (int) $usage['c']);
        } finally {
            self::$conn->rollback();
        }
    }

    public function test_external_line_identity_records_modifier_lines_without_changing_pos_merge(): void
    {
        $table = $this->loadFreeTable();
        $item = self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();

        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $service = new PosOrderService();
        $scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1, 'branch_uuid' => '99999999-9999-4999-8999-999999999999'];
        $moovaOrderId = 'phpunit-external-lines-' . bin2hex(random_bytes(6));

        self::$conn->begin_transaction();
        try {
            $created = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $moovaOrderId,
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    [
                        'externalLineId' => 'provider-line-plain',
                        'itemId' => (string) $item['id'],
                        'qty' => 1,
                        'modifiers' => [
                            ['option_id' => 10, 'qty' => 1],
                        ],
                    ],
                    [
                        'externalLineId' => 'provider-line-extra',
                        'itemId' => (string) $item['id'],
                        'qty' => 1,
                        'modifiers' => [
                            ['option_id' => 11, 'qty' => 1],
                        ],
                    ],
                ],
            ]);

            $orderId = (int) $created['order_id'];
            $sameItemRows = self::$conn->query('SELECT COUNT(*) AS c, SUM(qty_out) AS q FROM fat_details WHERE fatid = ' . $orderId . ' AND item_id = ' . (int) $item['id'] . ' AND isdeleted = 0')->fetch_assoc();
            $this->assertSame(1, (int) $sameItemRows['c']);
            $this->assertSame(2.0, (float) $sameItemRows['q']);
            $usage = self::$conn->query('SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE order_id = ' . $orderId)->fetch_assoc();
            $this->assertSame(0, (int) $usage['c']);

            $escapedOrderId = self::$conn->real_escape_string($moovaOrderId);
            $maps = self::$conn->query("
                SELECT source_channel, external_line_id, order_id, fat_detail_id, item_id,
                       modifiers_hash, modifiers_json, line_status, branch_uuid
                FROM external_order_line_map
                WHERE external_order_id = '{$escapedOrderId}'
                  AND pos_tenant = 0
                  AND pos_branch = 0
                ORDER BY external_line_id ASC
            ")->fetch_all(MYSQLI_ASSOC);

            $this->assertSame(2, count($maps));
            $this->assertSame(['provider-line-extra', 'provider-line-plain'], array_column($maps, 'external_line_id'));
            foreach ($maps as $map) {
                $this->assertSame('moova', $map['source_channel']);
                $this->assertSame($orderId, (int) $map['order_id']);
                $this->assertSame((int) $item['id'], (int) $map['item_id']);
                $this->assertSame('merged', $map['line_status']);
                $this->assertGreaterThan(0, (int) $map['fat_detail_id']);
                $this->assertNotSame('', (string) $map['modifiers_hash']);
                $this->assertIsArray(json_decode((string) $map['modifiers_json'], true));
                $this->assertSame('99999999-9999-4999-8999-999999999999', $map['branch_uuid']);
            }
            $this->assertNotSame($maps[0]['modifiers_hash'], $maps[1]['modifiers_hash']);
        } finally {
            self::$conn->rollback();
        }
    }

    public function test_moova_recipe_contexts_preserve_modifier_specific_external_lines(): void
    {
        $table = $this->loadFreeTable();
        $item = self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();

        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $recipeSpy = new PosOrderServiceMoovaRecipeLifecycleSpy();
        $service = new PosOrderService($recipeSpy);
        $scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1, 'branch_uuid' => '99999999-9999-4999-8999-999999999999'];
        $moovaOrderId = 'phpunit-recipe-external-lines-' . bin2hex(random_bytes(6));

        self::$conn->begin_transaction();
        try {
            $created = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $moovaOrderId,
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    [
                        'externalLineId' => 'provider-line-plain',
                        'itemId' => (string) $item['id'],
                        'qty' => 1,
                        'modifiers' => [
                            ['option_id' => 10, 'qty' => 1],
                        ],
                    ],
                    [
                        'externalLineId' => 'provider-line-extra',
                        'itemId' => (string) $item['id'],
                        'qty' => 1,
                        'modifiers' => [
                            ['option_id' => 11, 'qty' => 1],
                        ],
                    ],
                ],
            ]);

            $this->assertSame(2, count($recipeSpy->added));
            $this->assertSame(['moova:provider-line-plain', 'moova:provider-line-extra'], array_column($recipeSpy->added, 'source_line_uuid'));
            $this->assertSame(['1.000000', '1.000000'], array_column($recipeSpy->added, 'quantity'));
            $this->assertSame((int) $item['id'], (int) $recipeSpy->added[0]['sellable_item_id']);
            $this->assertSame((int) $created['order_id'], (int) $recipeSpy->added[0]['order_id']);
            $this->assertSame((int) $recipeSpy->added[0]['fat_detail_id'], (int) $recipeSpy->added[1]['fat_detail_id']);
            $this->assertSame(10, (int) $recipeSpy->added[0]['modifiers'][0]['option_id']);
            $this->assertSame(11, (int) $recipeSpy->added[1]['modifiers'][0]['option_id']);
        } finally {
            self::$conn->rollback();
        }
    }

    public function test_moova_mapped_recipe_contexts_use_unit_value_decimal_quantity(): void
    {
        $table = $this->loadFreeTable();
        $item = self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();

        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $recipeSpy = new PosOrderServiceMoovaRecipeLifecycleSpy();
        $service = new PosOrderService($recipeSpy);
        $scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1, 'branch_uuid' => '99999999-9999-4999-8999-999999999999'];
        $moovaOrderId = 'phpunit-recipe-unit-value-' . bin2hex(random_bytes(6));

        self::$conn->begin_transaction();
        try {
            $created = $service->createOrMergeMoovaTableOrder(self::$conn, $scope, [
                'cofeOrderId' => $moovaOrderId,
                'branchId' => '',
                'tableNumber' => (string) $table['id'],
                'items' => [
                    [
                        'externalLineId' => 'provider-line-unit-value',
                        'itemId' => (string) $item['id'],
                        'qty' => 3,
                    ],
                ],
            ]);

            $orderId = (int) $created['order_id'];
            $mapping = self::$conn->query("
                SELECT id, fat_detail_id
                FROM moova_pos_order_lines
                WHERE moova_order_id = '" . self::$conn->real_escape_string($moovaOrderId) . "'
                  AND status = 'active'
                LIMIT 1
            ")->fetch_assoc();
            $this->assertIsArray($mapping);
            $detailId = (int) $mapping['fat_detail_id'];
            $mappingId = (int) $mapping['id'];

            self::$conn->query("
                UPDATE fat_details
                SET u_val = 2,
                    qty_out = 6,
                    price = 5,
                    det_value = 30,
                    profit = 30
                WHERE id = {$detailId}
            ");
            self::$conn->query("
                UPDATE moova_pos_order_lines
                SET qty_out = 6,
                    price = 5,
                    det_value = 30
                WHERE id = {$mappingId}
            ");

            $state = $service->getMoovaOrderLineStateSnapshot(self::$conn, 0, 0, $orderId, $moovaOrderId);
            $service->cancelMoovaTableOrder(self::$conn, $scope, $orderId, $moovaOrderId, $state['hash'] ?? '');

            $this->assertSame(['3.000000'], array_column($recipeSpy->cancelled, 'quantity'));
            $this->assertSame('moova:provider-line-unit-value', $recipeSpy->cancelled[0]['source_line_uuid']);
        } finally {
            self::$conn->rollback();
        }
    }

    private function loadFreeTable(): ?array
    {
        return self::$conn->query("
            SELECT t.id
            FROM tables t
            WHERE t.isdeleted = 0
              AND (t.branch IS NULL OR t.branch = '' OR t.branch = '0')
              AND NOT EXISTS (
                  SELECT 1
                  FROM ot_head h
                  WHERE h.tenant = 0
                    AND h.branch = 0
                    AND h.table_id = t.id
                    AND h.pro_tybe = 9
                    AND h.isdeleted = 0
                    AND COALESCE(h.order_status, 'active') = 'active'
                    AND COALESCE(h.payment_status, 'unpaid') IN ('unpaid', 'partial')
              )
            ORDER BY t.id ASC
            LIMIT 1
        ")->fetch_assoc() ?: null;
    }
}

class PosOrderServiceMoovaRecipeLifecycleSpy extends RecipeOrderLifecycleService
{
    public array $added = [];
    public array $cancelled = [];

    public function onOrderLineAdded($ctx): array
    {
        $this->added[] = (array) $ctx;

        return [
            'success' => true,
            'action' => 'order_line_added',
            'noop' => true,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }

    public function onOrderLineCancelled($ctx, string $reason): array
    {
        $line = (array) $ctx;
        $line['reason'] = $reason;
        $this->cancelled[] = $line;

        return [
            'success' => true,
            'action' => 'order_line_cancelled',
            'noop' => true,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }
}
