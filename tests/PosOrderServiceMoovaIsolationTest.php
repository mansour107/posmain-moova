<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/PosOrderService.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';

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
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
    }

    public function test_same_table_moova_orders_share_receipt_but_cancel_only_owned_lines(): void
    {
        $table = self::$conn->query("
            SELECT id
            FROM tables
            WHERE isdeleted = 0
              AND (branch IS NULL OR branch = '' OR branch = '0')
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();
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
        $table = self::$conn->query("
            SELECT id
            FROM tables
            WHERE isdeleted = 0
              AND (branch IS NULL OR branch = '' OR branch = '0')
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc();
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
        } finally {
            self::$conn->rollback();
        }
    }
}
