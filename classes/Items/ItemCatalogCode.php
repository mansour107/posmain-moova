<?php

final class ItemCatalogCode
{
    public static function nextValue(mysqli $conn): int
    {
        $row = $conn->query('SELECT MAX(code) AS max_code FROM myitems')->fetch_assoc();
        $maxCode = $row['max_code'] ?? null;
        if ($maxCode === null) {
            return 1;
        }

        return (int) $maxCode + 1;
    }

    public static function resolveForInsert(mysqli $conn, ?int $submittedCode): int
    {
        if ($submittedCode !== null && $submittedCode > 0) {
            return $submittedCode;
        }

        return self::nextValue($conn);
    }
}
