<?php

use PHPUnit\Framework\TestCase;

class AccReportBalanceQueryTest extends TestCase
{
    public function testBalanceRefreshTreatsAccountsWithoutJournalEntriesAsZero(): void
    {
        $source = file_get_contents(__DIR__ . '/../acc_report.php');

        $this->assertMatchesRegularExpression(
            '/COALESCE\s*\(\s*SUM\s*\(\s*journal_entries\.debit\s*\)\s*,\s*0\s*\)\s*-\s*COALESCE\s*\(\s*SUM\s*\(\s*journal_entries\.credit\s*\)\s*,\s*0\s*\)/i',
            $source
        );
    }
}
