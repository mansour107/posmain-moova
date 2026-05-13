<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/DocumentCounterService.php';

class DocumentCounterTests extends TestCase
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

        self::$conn->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        self::$conn->query("DELETE FROM document_counters WHERE counter_key LIKE 'phpunit:%'");
    }

    public function testNextValueUsesTwoStepLastInsertIdPattern(): void
    {
        $source = file_get_contents(__DIR__ . '/../../classes/Sync/DocumentCounterService.php');

        $this->assertStringContainsString('INSERT INTO document_counters', $source);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, VALUES(current_value))', $source);
        $this->assertStringContainsString('LAST_INSERT_ID(current_value + 1)', $source);
        $this->assertStringContainsString('SELECT LAST_INSERT_ID() AS next_value', $source);
        $this->assertGreaterThan(
            strpos($source, 'INSERT INTO document_counters'),
            strpos($source, 'LAST_INSERT_ID(current_value + 1)')
        );
    }

    public function testCounterIncrementsInsideCallerTransaction(): void
    {
        $service = new DocumentCounterService();
        $type = 'phpunit:invoice:' . bin2hex(random_bytes(4));

        self::$conn->begin_transaction();
        try {
            $this->assertSame(1, $service->nextCounter(self::$conn, 10, 20, 'phpunit', $type));
            $this->assertSame(2, $service->nextCounter(self::$conn, 10, 20, 'phpunit', $type));

            $row = self::$conn->query("
                SELECT current_value
                FROM document_counters
                WHERE pos_tenant = 10
                  AND pos_branch = 20
                  AND counter_type = 'phpunit'
                  AND counter_key = '" . self::$conn->real_escape_string($type) . "'
            ")->fetch_assoc();
            $this->assertSame(2, (int) $row['current_value']);
        } finally {
            self::$conn->rollback();
        }

        $count = self::$conn->query("
            SELECT COUNT(*) AS c
            FROM document_counters
            WHERE pos_tenant = 10
              AND pos_branch = 20
              AND counter_type = 'phpunit'
              AND counter_key = '" . self::$conn->real_escape_string($type) . "'
        ")->fetch_assoc();
        $this->assertSame(0, (int) $count['c']);
    }

    public function testConvenienceCountersUsePlanScopeAndKeys(): void
    {
        $source = file_get_contents(__DIR__ . '/../../classes/Sync/DocumentCounterService.php');

        $this->assertStringContainsString('function nextProId', $source);
        $this->assertStringContainsString("'pro_id'", $source);
        $this->assertStringContainsString("'pro_tybe:'", $source);
        $this->assertStringContainsString('function nextJournalId', $source);
        $this->assertStringContainsString("'journal_id'", $source);
        $this->assertStringContainsString("'journal:'", $source);
        $this->assertStringContainsString('pos_tenant', $source);
        $this->assertStringContainsString('pos_branch', $source);
        $this->assertStringContainsString('counter_type', $source);
        $this->assertStringContainsString('counter_key', $source);
    }

    public function testBackfillSeedDoesNotReplaceExistingHigherCounter(): void
    {
        $service = new DocumentCounterService();
        $type = 'phpunit:journal:' . bin2hex(random_bytes(4));

        $service->ensureCounterRow(self::$conn, 1, 2, 'phpunit', $type, 9);
        $service->ensureCounterRow(self::$conn, 1, 2, 'phpunit', $type, 3);

        $row = self::$conn->query("
            SELECT current_value
            FROM document_counters
            WHERE pos_tenant = 1
              AND pos_branch = 2
              AND counter_type = 'phpunit'
              AND counter_key = '" . self::$conn->real_escape_string($type) . "'
        ")->fetch_assoc();
        $this->assertSame(9, (int) $row['current_value']);
    }
}

class document_counter_tests extends DocumentCounterTests
{
}
