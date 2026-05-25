<?php

if (!function_exists('posmain_csv_safe_cell')) {
    function posmain_csv_safe_cell($value)
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = is_string($encoded) ? $encoded : '';
        }

        $text = str_replace("\0", '', (string) $value);
        if ($text === '') {
            return '';
        }

        $trimmed = ltrim($text, " \t\r\n");
        if ($trimmed === '') {
            return $text;
        }

        $first = $trimmed[0];
        if (in_array($first, ['=', '+', '@'], true)) {
            return "'" . $text;
        }
        if ($first === '-' && !is_numeric($trimmed)) {
            return "'" . $text;
        }
        if (in_array($text[0], ["\t", "\r", "\n"], true)) {
            return "'" . $text;
        }

        return $text;
    }
}

if (!function_exists('posmain_csv_safe_row')) {
    function posmain_csv_safe_row(array $values): array
    {
        return array_map('posmain_csv_safe_cell', $values);
    }
}

if (!function_exists('posmain_csv_write_row')) {
    function posmain_csv_write_row($output, array $values): void
    {
        fputcsv($output, $values, ',', '"', '');
    }
}
