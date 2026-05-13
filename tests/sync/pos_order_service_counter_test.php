<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class PosOrderServiceCounterTest extends TestCase
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
    }

    public function testPosOrderServiceSeedsCountersFromExistingDocumentsBeforeIncrementing(): void
    {
        $service = new PosOrderService();
        $ref = new ReflectionClass($service);
        $invoice = $ref->getMethod('getNextInvoiceNumber');
        $invoice->setAccessible(true);
        $journal = $ref->getMethod('getNextJournalId');
        $journal->setAccessible(true);

        $tenant = random_int(500000, 900000);
        $branch = random_int(500000, 900000);

        self::$conn->begin_transaction();
        try {
            self::$conn->query("
                INSERT INTO ot_head (pro_id, pro_tybe, tenant, branch, user)
                VALUES (1234, 9, {$tenant}, {$branch}, 1)
            ");
            self::$conn->query("
                INSERT INTO journal_heads (journal_id, total, jdate, tenant, branch)
                VALUES (88, 0, CURDATE(), {$tenant}, {$branch})
            ");

            $this->assertSame(1235, $invoice->invoke($service, self::$conn, 9, $tenant, $branch));
            $this->assertSame(1236, $invoice->invoke($service, self::$conn, 9, $tenant, $branch));
            $this->assertSame(89, $journal->invoke($service, self::$conn, $tenant, $branch));
            $this->assertSame(90, $journal->invoke($service, self::$conn, $tenant, $branch));

            $invoiceCounter = self::$conn->query("
                SELECT current_value
                FROM document_counters
                WHERE pos_tenant = {$tenant}
                  AND pos_branch = {$branch}
                  AND counter_type = 'pro_id'
                  AND counter_key = 'pro_tybe:9'
            ")->fetch_assoc();
            $journalCounter = self::$conn->query("
                SELECT current_value
                FROM document_counters
                WHERE pos_tenant = {$tenant}
                  AND pos_branch = {$branch}
                  AND counter_type = 'journal_id'
                  AND counter_key = 'journal:default'
            ")->fetch_assoc();

            $this->assertSame(1236, (int) $invoiceCounter['current_value']);
            $this->assertSame(90, (int) $journalCounter['current_value']);
        } finally {
            self::$conn->rollback();
        }
    }
}

class pos_order_service_counter_test extends PosOrderServiceCounterTest
{
}
