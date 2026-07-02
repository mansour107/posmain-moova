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
