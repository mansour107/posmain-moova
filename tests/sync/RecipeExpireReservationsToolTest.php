<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class RecipeExpireReservationsToolTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $dbConfig;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        self::$dbConfig = [
            'host' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307),
            'user' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
            'pass' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        ];

        self::$conn = @new mysqli(
            self::$dbConfig['host'],
            self::$dbConfig['user'],
            self::$dbConfig['pass'],
            '',
            self::$dbConfig['port']
        );
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$dbName = 'posmain_recipe_expire_tool_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$conn && self::$dbName) {
            self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
            self::$conn->close();
        }
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
        self::$conn->query('DELETE FROM inventory_movements');
        self::$conn->query('DELETE FROM stock_reservations');
        self::$conn->query('DELETE FROM inventory_item_balances');
    }

    public function testDryRunDoesNotMutateAndApplyExpiresReservations(): void
    {
        $this->seedExpiredReservation();

        $dryRun = $this->runTool(['--json', '--now=2026-05-24 12:00:00']);

        $this->assertSame(0, $dryRun['code'], $dryRun['output']);
        $this->assertSame('dry_run', $dryRun['json']['mode']);
        $this->assertSame(1, $dryRun['json']['would_expire']);
        $this->assertSame('reserved', $this->reservationStatus());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame('2.000000', $this->balance()['qty_reserved']);

        $apply = $this->runTool(['--json', '--apply', '--now=2026-05-24 12:00:00']);

        $this->assertSame(0, $apply['code'], $apply['output']);
        $this->assertSame('apply', $apply['json']['mode']);
        $this->assertSame(1, $apply['json']['expired']);
        $this->assertSame('expired', $this->reservationStatus());
        $this->assertSame(1, $this->movementCount());
        $this->assertSame('0.000000', $this->balance()['qty_reserved']);
        $this->assertSame('5.000000', $this->balance()['qty_available']);
    }

    public function testApplyAndDryRunFlagsAreMutuallyExclusive(): void
    {
        $result = $this->runTool(['--apply', '--dry-run', '--json']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Use either --apply or --dry-run, not both.', $result['output']);
    }

    private function seedExpiredReservation(): void
    {
        self::$conn->query("
            INSERT INTO inventory_item_balances
                (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available)
            VALUES (0, 0, 0, 3001, 5.000000, 2.000000, 3.000000)
        ");
        self::$conn->query("
            INSERT INTO stock_reservations
                (reservation_uuid, pos_tenant, pos_branch, store_id, order_id, fat_detail_id, sellable_item_id,
                 recipe_id, ingredient_item_id, qty_reserved, status, expires_at, idempotency_key)
            VALUES
                ('00000000-0000-4000-8000-000000003001', 0, 0, 0, 7001, 7101, 2001,
                 1001, 3001, 2.000000, 'reserved', '2026-05-24 10:00:00', 'expire-tool-test')
        ");
    }

    private function runTool(array $args): array
    {
        $root = dirname(__DIR__, 2);
        $env = [
            'POSMAIN_ROUTER_ENABLED' => '0',
            'POSMAIN_DB_HOST' => self::$dbConfig['host'],
            'POSMAIN_DB_PORT' => (string) self::$dbConfig['port'],
            'POSMAIN_DB_USER' => self::$dbConfig['user'],
            'POSMAIN_DB_PASS' => self::$dbConfig['pass'],
            'POSMAIN_DB_NAME' => self::$dbName,
        ];
        $envParts = [];
        foreach ($env as $key => $value) {
            $envParts[] = $key . '=' . escapeshellarg($value);
        }

        $cmd = 'env ' . implode(' ', $envParts)
            . ' php ' . escapeshellarg($root . '/tools/recipe_expire_reservations.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>&1';
        exec($cmd, $lines, $code);
        $output = implode("\n", $lines);
        $json = null;
        if ($output !== '') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'code' => $code,
            'output' => $output,
            'json' => $json,
        ];
    }

    private function reservationStatus(): string
    {
        return (string) self::$conn->query('SELECT status FROM stock_reservations LIMIT 1')->fetch_assoc()['status'];
    }

    private function movementCount(): int
    {
        return (int) self::$conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'];
    }

    private function balance(): array
    {
        return self::$conn->query('SELECT qty_reserved, qty_available FROM inventory_item_balances WHERE item_id = 3001')->fetch_assoc();
    }
}
