<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Items/ItemRecipeCatalogService.php';

class ItemRecipeCatalogServiceTest extends TestCase
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
        self::$dbName = 'posmain_item_recipe_catalog_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');
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

        self::$conn->query('DROP TABLE IF EXISTS myitems');
    }

    public function testSavesRecipeCatalogMetadataWhenColumnsExist(): void
    {
        self::$conn->query("
            CREATE TABLE myitems (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                iname VARCHAR(255) NULL,
                item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                preferred_unit_id BIGINT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query("INSERT INTO myitems (iname) VALUES ('Bun')");

        (new ItemRecipeCatalogService())->saveMetadata(self::$conn, 1, [
            'item_type' => 'packaging',
            'track_stock' => 1,
            'preferred_unit_id' => 7,
        ]);
        $row = self::$conn->query('SELECT * FROM myitems WHERE id = 1')->fetch_assoc();

        $this->assertSame('packaging', $row['item_type']);
        $this->assertSame(1, (int) $row['track_stock']);
        $this->assertSame(7, (int) $row['preferred_unit_id']);
    }

    public function testServiceItemIsForcedToNonStockAndInvalidTypeFallsBack(): void
    {
        self::$conn->query("
            CREATE TABLE myitems (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                preferred_unit_id BIGINT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query('INSERT INTO myitems () VALUES ()');

        (new ItemRecipeCatalogService())->saveMetadata(self::$conn, 1, [
            'item_type' => 'service',
            'track_stock' => 1,
        ]);
        $serviceRow = self::$conn->query('SELECT * FROM myitems WHERE id = 1')->fetch_assoc();

        (new ItemRecipeCatalogService())->saveMetadata(self::$conn, 1, [
            'item_type' => 'unknown',
            'track_stock' => 1,
        ]);
        $fallbackRow = self::$conn->query('SELECT * FROM myitems WHERE id = 1')->fetch_assoc();

        $this->assertSame('service', $serviceRow['item_type']);
        $this->assertSame(0, (int) $serviceRow['track_stock']);
        $this->assertSame('sellable', $fallbackRow['item_type']);
        $this->assertSame(1, (int) $fallbackRow['track_stock']);
    }

    public function testMissingRecipeMetadataColumnsAreBackwardCompatibleNoop(): void
    {
        self::$conn->query("
            CREATE TABLE myitems (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                iname VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query("INSERT INTO myitems (iname) VALUES ('Legacy')");

        (new ItemRecipeCatalogService())->saveMetadata(self::$conn, 1, [
            'item_type' => 'ingredient',
            'track_stock' => 1,
            'preferred_unit_id' => 3,
        ]);
        $row = self::$conn->query('SELECT * FROM myitems WHERE id = 1')->fetch_assoc();

        $this->assertSame('Legacy', $row['iname']);
    }
}
