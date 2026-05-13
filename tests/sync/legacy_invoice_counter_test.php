<?php

use PHPUnit\Framework\TestCase;

class LegacyInvoiceCounterTest extends TestCase
{
    public function testInvoiceEndpointUsesDocumentCounterServiceInInsertBranches(): void
    {
        $source = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');

        $this->assertStringContainsString("require_once('../classes/Sync/DocumentCounterService.php')", $source);
        $this->assertStringContainsString('$counterService = new DocumentCounterService();', $source);
        $this->assertStringContainsString('$pro_id = null;', $source);
        $this->assertStringContainsString('$pro_id = $original_pro_id;', $source);
        $this->assertStringContainsString('$pro_id = nextLegacyInvoiceProId($conn, $counterService, (int) $pro_tybe);', $source);
        $this->assertStringContainsString('$journal_id = nextLegacyInvoiceJournalId($conn, $counterService);', $source);
        $this->assertStringContainsString('$cash_op_id = nextLegacyInvoiceProId($conn, $counterService, (int) $config[\'paid_type\']);', $source);
        $this->assertStringContainsString('$bank_op_id = nextLegacyInvoiceProId($conn, $counterService, (int) $config[\'paid_type\']);', $source);
        $this->assertStringContainsString('$counterService->ensureCounterRow', $source);
        $this->assertStringContainsString('$counterService->nextProId', $source);
        $this->assertStringContainsString('$counterService->nextJournalId', $source);
    }

    public function testInvoiceEndpointNoLongerHasPreTransactionAllocationsOrDirectMaxPlusOne(): void
    {
        $source = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');

        $this->assertStringNotContainsString('$pro_id = getNextInvoiceNumber($conn, $pro_tybe);', $source);
        $this->assertStringNotContainsString('$disc_op_id = getNextOperationNumber($conn, $config[\'disc_type\']);', $source);
        $this->assertStringNotContainsString('$paid_op_id = getNextOperationNumber($conn, $config[\'paid_type\']);', $source);
        $this->assertStringNotContainsString('SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id', $source);
        $this->assertStringNotContainsString('SELECT MAX(journal_id) as max_id FROM journal_heads', $source);
        $this->assertStringNotContainsString('$row[\'max_id\'] + 1', $source);
    }
}

class legacy_invoice_counter_test extends LegacyInvoiceCounterTest
{
}
