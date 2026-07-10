<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../Items/ItemUnitConversionFeatureFlags.php';

final class JournalPostingGuard
{
    public static function assertBalancedEntries(array $entries, int $scale = 6): void
    {
        $debit = RecipeDecimal::zero($scale);
        $credit = RecipeDecimal::zero($scale);

        foreach ($entries as $entry) {
            if ((int) ($entry['account_id'] ?? 0) < 1) {
                throw new InvalidArgumentException('JOURNAL_ACCOUNT_REQUIRED');
            }
            $entryDebit = $entry['debit'] ?? '0';
            $entryCredit = $entry['credit'] ?? '0';
            if (RecipeDecimal::compare($entryDebit, '0', $scale) < 0 || RecipeDecimal::compare($entryCredit, '0', $scale) < 0) {
                throw new InvalidArgumentException('JOURNAL_AMOUNT_NEGATIVE');
            }
            if (
                RecipeDecimal::compare($entryDebit, '0', $scale) > 0
                && RecipeDecimal::compare($entryCredit, '0', $scale) > 0
            ) {
                throw new InvalidArgumentException('JOURNAL_ENTRY_MUST_BE_ONE_SIDED');
            }
            $debit = RecipeDecimal::add($debit, $entry['debit'] ?? '0', $scale);
            $credit = RecipeDecimal::add($credit, $entry['credit'] ?? '0', $scale);
        }

        if (RecipeDecimal::compare($debit, $credit, $scale) !== 0) {
            throw new InvalidArgumentException('JOURNAL_NOT_BALANCED');
        }
    }

    public static function rejectJournalMutationIfAppendOnly(): void
    {
        if (!ItemUnitConversionFeatureFlags::appendOnlyJournals()) {
            return;
        }

        throw new InvalidArgumentException('JOURNAL_APPEND_ONLY');
    }
}
