<?php

use PHPUnit\Framework\TestCase;

class LegacyCofeCounterTest extends TestCase
{
    public function testCofeEndpointUsesDocumentCounterServiceInsideTransaction(): void
    {
        $source = file_get_contents(__DIR__ . '/../../ajax/cofe_create_order.php');

        $this->assertStringContainsString("require_once('../classes/Sync/DocumentCounterService.php')", $source);
        $this->assertStringContainsString('$conn->begin_transaction();', $source);
        $this->assertStringContainsString('$counterService = new DocumentCounterService();', $source);
        $this->assertGreaterThan(
            strpos($source, '$conn->begin_transaction();'),
            strpos($source, '$counterService = new DocumentCounterService();')
        );
        $this->assertStringContainsString('nextCofeProId($conn, $counterService, $pro_tybe)', $source);
        $this->assertStringContainsString('nextCofeProId($conn, $counterService, 1)', $source);
        $this->assertStringContainsString('nextCofeJournalId($conn, $counterService)', $source);
        $this->assertStringContainsString('$counterService->ensureCounterRow', $source);
        $this->assertStringContainsString('$counterService->nextProId', $source);
        $this->assertStringContainsString('$counterService->nextJournalId', $source);
    }

    public function testCofeEndpointNoLongerDirectlyAllocatesMaxPlusOne(): void
    {
        $source = file_get_contents(__DIR__ . '/../../ajax/cofe_create_order.php');

        $this->assertStringNotContainsString("SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id", $source);
        $this->assertStringNotContainsString("SELECT MAX(journal_id) as max_id FROM journal_heads", $source);
        $this->assertStringNotContainsString('$row[\'max_id\'] + 1', $source);
    }
}

class legacy_cofe_counter_test extends LegacyCofeCounterTest
{
}
