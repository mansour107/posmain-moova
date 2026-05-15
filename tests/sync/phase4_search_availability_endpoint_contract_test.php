<?php

$searchItems = file_get_contents(__DIR__ . '/../../ajax/search_items.php');
phase4SearchAvailabilityAssert(is_string($searchItems), 'unable to read ajax/search_items.php');
phase4SearchAvailabilityEndpointAssert($searchItems, 'search_items');
phase4SearchAvailabilityAssert(strpos($searchItems, "\$stmt->bind_param('sss'") !== false, 'search_items should keep prepared LIKE parameters');
phase4SearchAvailabilityAssert(strpos($searchItems, "'count' => count(\$items)") !== false, 'search_items should preserve count response contract');
phase4SearchAvailabilityAssert(strpos($searchItems, 'LIMIT 50') !== false, 'search_items should preserve fixed result limit');

$lazyItems = file_get_contents(__DIR__ . '/../../ajax/load_items_lazy.php');
phase4SearchAvailabilityAssert(is_string($lazyItems), 'unable to read ajax/load_items_lazy.php');
phase4SearchAvailabilityEndpointAssert($lazyItems, 'load_items_lazy');
phase4SearchAvailabilityAssert(strpos($lazyItems, 'bind_param($types, ...$params)') !== false, 'load_items_lazy should keep dynamic prepared parameters');
phase4SearchAvailabilityAssert(strpos($lazyItems, "'total' => \$total") !== false, 'load_items_lazy should preserve total response contract');
phase4SearchAvailabilityAssert(strpos($lazyItems, "'has_more' => (\$offset + \$limit) < \$total") !== false, 'load_items_lazy should preserve pagination contract');

echo "phase4-search-availability-endpoint-contract-ok\n";

function phase4SearchAvailabilityEndpointAssert(string $source, string $name): void
{
    phase4SearchAvailabilityAssert(
        strpos($source, 'ItemAvailabilityService.php') !== false,
        "{$name} should load ItemAvailabilityService"
    );
    phase4SearchAvailabilityAssert(
        strpos($source, 'decorateItems($conn, $items, $availabilityScope)') !== false,
        "{$name} should decorate the response items after fetching"
    );
    phase4SearchAvailabilityAssert(
        strpos($source, "'channel' => 'pos'") !== false,
        "{$name} should use a non-breaking POS availability channel scope"
    );
    phase4SearchAvailabilityAssert(
        stripos($source, 'ITEM_UNAVAILABLE') === false,
        "{$name} should not block sale behavior"
    );
    phase4SearchAvailabilityAssert(
        stripos($source, 'is_available = 1') === false,
        "{$name} should not filter unavailable items"
    );
}

function phase4SearchAvailabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
