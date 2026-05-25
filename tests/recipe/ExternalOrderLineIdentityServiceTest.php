<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/ExternalOrderLineIdentityService.php';

class ExternalOrderLineIdentityServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

        self::$conn = @new mysqli($host, $user, $pass, '', $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$dbName = 'posmain_external_line_identity_' . getmypid();
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

        self::$conn->query('DELETE FROM external_order_line_map');
    }

    public function testSameItemDifferentModifiersGetDifferentSyntheticLineIdentities(): void
    {
        $service = new ExternalOrderLineIdentityService();
        $scope = new RecipeScope(0, 0, '11111111-1111-4111-8111-111111111111', 0, 'moova', 'delivery', 'moova');

        $plain = $service->registerLine(self::$conn, $scope, 'moova', 'moova-order-1', [
            'itemId' => 'pos-item-100',
            'qty' => 1,
            'modifiers' => [
                ['option_id' => 10, 'qty' => 1],
            ],
        ], 0, ['order_id' => 500, 'fat_detail_id' => 900]);
        $extra = $service->registerLine(self::$conn, $scope, 'moova', 'moova-order-1', [
            'itemId' => 'pos-item-100',
            'qty' => 1,
            'modifiers' => [
                ['option_id' => 11, 'qty' => 1],
            ],
        ], 1, ['order_id' => 500, 'fat_detail_id' => 901]);

        $this->assertNotSame($plain['external_line_id'], $extra['external_line_id']);
        $this->assertNotSame($plain['modifiers_hash'], $extra['modifiers_hash']);
        $this->assertSame(2, $this->mappingCount());
    }

    public function testReplayReturnsSameMappingAndCanAttachLocalLineIdentity(): void
    {
        $service = new ExternalOrderLineIdentityService();
        $scope = new RecipeScope(0, 0, null, 0, 'moova', 'delivery', 'moova');
        $line = [
            'lineId' => 'provider-line-7',
            'itemId' => 77,
            'modifiers' => [
                ['qty' => 1, 'option_id' => 3],
                ['option_id' => 2, 'qty' => 1],
            ],
        ];

        $first = $service->registerLine(self::$conn, $scope, 'moova', 'moova-order-2', $line, 0);
        $second = $service->registerLine(self::$conn, $scope, 'moova', 'moova-order-2', $line, 0, [
            'order_id' => 800,
            'fat_detail_id' => 801,
            'order_line_uuid' => '00000000-0000-4000-8000-000000000801',
        ]);

        $this->assertSame($first['mapping_id'], $second['mapping_id']);
        $this->assertSame('provider-line-7', $second['external_line_id']);
        $this->assertSame(1, $this->mappingCount());

        $row = $second['mapping'];
        $this->assertSame(800, (int) $row['order_id']);
        $this->assertSame(801, (int) $row['fat_detail_id']);
        $this->assertSame('00000000-0000-4000-8000-000000000801', $row['order_line_uuid']);
    }

    public function testModifierHashIsStableAcrossOptionOrder(): void
    {
        $service = new ExternalOrderLineIdentityService();
        $left = $service->modifiersHash([
            ['option_id' => 3, 'qty' => 1],
            ['qty' => 1, 'option_id' => 2],
        ]);
        $right = $service->modifiersHash([
            ['option_id' => 2, 'qty' => 1],
            ['qty' => 1, 'option_id' => 3],
        ]);

        $this->assertSame($left, $right);
    }

    public function testExternalLineUniquenessIsScopedByBranch(): void
    {
        $service = new ExternalOrderLineIdentityService();
        $line = ['lineId' => 'provider-line-1', 'itemId' => 44];

        $first = $service->registerLine(self::$conn, new RecipeScope(0, 0), 'moova', 'moova-order-3', $line, 0);
        $second = $service->registerLine(self::$conn, new RecipeScope(0, 1), 'moova', 'moova-order-3', $line, 0);

        $this->assertNotSame($first['mapping_id'], $second['mapping_id']);
        $this->assertSame(2, $this->mappingCount());
    }

    private function mappingCount(): int
    {
        $row = self::$conn->query('SELECT COUNT(*) AS c FROM external_order_line_map')->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }
}
