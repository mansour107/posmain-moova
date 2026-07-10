<?php

/**
 * Exact-money financial core is always on.
 * Legacy dual-mode / env toggles are intentionally removed.
 */
final class FinancialCertifiedMode
{
    public static function isEnabled(): bool
    {
        return true;
    }

    public static function assertEnabled(string $code = 'FINANCIAL_CERTIFIED_MODE_REQUIRED'): void
    {
        // Always enabled; kept for call-site compatibility.
    }

    /** Reject direct journal SQL outside JournalPostingService. */
    public static function rejectDirectJournalWrite(): void
    {
        throw new RuntimeException('JOURNAL_DIRECT_WRITE_FORBIDDEN');
    }
}
