<?php

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/save_start_balance.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    fwrite(STDERR, "Unable to read save_start_balance.php\n");
    exit(1);
}
$pagePath = $root . '/items_start_balance.php';
$page = file_get_contents($pagePath);
if ($page === false) {
    fwrite(STDERR, "Unable to read items_start_balance.php\n");
    exit(1);
}

itemsStartBalanceAssert(
    strpos($source, 'POSMAIN_OPENING_BALANCE_PRO_TYPE = 14') !== false,
    'opening balance save should use the legacy opening-balance operation type'
);
itemsStartBalanceAssert(
    strpos($source, 'INSERT INTO fat_details') !== false,
    'opening balance save should create stock movement rows'
);
itemsStartBalanceAssert(
    strpos($source, 'qty_in') !== false && strpos($source, 'qty_out') !== false,
    'opening balance save should express stock through qty_in/qty_out'
);
itemsStartBalanceAssert(
    strpos($source, 'posmain_start_balance_non_opening_qty') !== false,
    'opening balance save should calculate the opening adjustment around non-opening movements'
);
itemsStartBalanceAssert(
    strpos($source, '$stmt = $conn->prepare("UPDATE myitems SET itmqty = ?, cost_price = ? WHERE id = ?")') === false,
    'opening balance save must not use the old direct myitems.itmqty assignment as the source of truth'
);
itemsStartBalanceAssert(
    strpos($source, 'posmain_start_balance_update_item_summary') !== false,
    'opening balance save may refresh the myitems summary only after writing the movement ledger'
);
itemsStartBalanceAssert(
    strpos($source, 'inventory_movements') !== false
        && strpos($source, 'inventory_item_balances') !== false,
    'opening balance save should seed the recipe inventory ledger used by ingredient consumption'
);
itemsStartBalanceAssert(
    strpos($source, "'opening_balance'") !== false
        && strpos($source, 'items-start-balance:tenant:') !== false,
    'recipe opening balance movements should be idempotent opening_balance rows'
);
itemsStartBalanceAssert(
    strpos($source, 'posmain_start_balance_recipe_movement_integrity') !== false
        && strpos($source, 'payload_hash') !== false
        && strpos($source, 'metadata_json') !== false,
    'recipe opening balance movements should store payload hash and metadata when the migrated columns exist'
);
itemsStartBalanceAssert(
    strpos($source, 'posmain_start_balance_column_exists') !== false,
    'opening balance save should keep compatibility with older recipe ledger schemas while writing new metadata columns when available'
);
itemsStartBalanceAssert(
    strpos($page, 'items_start_balance_recipe_balance') !== false
        && strpos($page, 'inventory_item_balances') !== false
        && strpos($page, "['ingredient', 'packaging']") !== false,
    'opening balance screen should display recipe ledger balances for ingredient and packaging rows'
);

echo "items-start-balance-contract-ok\n";

function itemsStartBalanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
