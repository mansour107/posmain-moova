<?php

$searchItems = file_get_contents(__DIR__ . '/../../ajax/search_items.php');
searchSqlInjectionAssert(is_string($searchItems), 'unable to read ajax/search_items.php');
searchSqlInjectionAssert(strpos($searchItems, '$conn->prepare($query)') !== false, 'search_items should prepare the query');
searchSqlInjectionAssert(strpos($searchItems, "\$stmt->bind_param('sss'") !== false, 'search_items should bind all LIKE parameters');
searchSqlInjectionAssert(strpos($searchItems, 'LIKE \'$search_param\'') === false, 'search_items should not interpolate LIKE search text');
searchSqlInjectionAssert(strpos($searchItems, 'real_escape_string($search)') === false, 'search_items should not rely on string escaping for SQL shape');
searchSqlInjectionAssert(strpos($searchItems, '$conn->query($query)') === false, 'search_items should not execute interpolated search SQL');

$searchItem = file_get_contents(__DIR__ . '/../../ajax/search_item.php');
searchSqlInjectionAssert(is_string($searchItem), 'unable to read ajax/search_item.php');
searchSqlInjectionAssert(strpos($searchItem, 'preg_match') !== false, 'search_item should validate numeric barcode before id lookup');
searchSqlInjectionAssert(strpos($searchItem, '$stmt->bind_param("sis"') !== false, 'search_item should bind barcode, id, and LIKE search');
searchSqlInjectionAssert(strpos($searchItem, 'id = ? OR id = ?') === false, 'search_item should not keep duplicate id lookup placeholders');

$lazy = file_get_contents(__DIR__ . '/../../ajax/load_items_lazy.php');
searchSqlInjectionAssert(is_string($lazy), 'unable to read ajax/load_items_lazy.php');
searchSqlInjectionAssert(strpos($lazy, 'max(1, intval($_GET[\'page\']))') !== false, 'load_items_lazy should clamp page');
searchSqlInjectionAssert(strpos($lazy, 'min(100, max(1, intval($_GET[\'limit\'])))') !== false, 'load_items_lazy should clamp limit');
searchSqlInjectionAssert(strpos($lazy, 'bind_param($types, ...$params)') !== false, 'load_items_lazy should keep prepared dynamic parameters');

echo "search-sql-injection-ok\n";

function searchSqlInjectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
