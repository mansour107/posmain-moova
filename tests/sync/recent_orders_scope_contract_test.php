<?php

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/ajax/get_recent_orders.php');
if ($source === false) {
    fwrite(STDERR, "recent-orders-scope-contract-FAIL: source unavailable\n");
    exit(1);
}

function recentOrdersScopeContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    recentOrdersScopeContractAssert(
        str_contains($source, "\$_SESSION['pos_tenant']"),
        'recent orders must derive tenant from the authenticated session'
    );
    recentOrdersScopeContractAssert(
        str_contains($source, "\$_SESSION['pos_branch']"),
        'recent orders must derive branch from the authenticated session'
    );
    recentOrdersScopeContractAssert(
        str_contains($source, 'AND {$scopePredicate}'),
        'the order list query must apply the operational scope predicate'
    );
    recentOrdersScopeContractAssert(
        str_contains($source, "recentOrdersColumnExists(\$conn, 'ot_head', 'tenant')"),
        'tenant filtering must preserve compatibility with pre-scope schemas'
    );
    recentOrdersScopeContractAssert(
        str_contains($source, "recentOrdersColumnExists(\$conn, 'ot_head', 'branch')"),
        'branch filtering must preserve compatibility with pre-scope schemas'
    );
    recentOrdersScopeContractAssert(
        str_contains($source, "'1 = 1'"),
        'pre-scope single-shop schemas must retain an explicit compatibility fallback'
    );

    echo "recent-orders-scope-contract-ok\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'recent-orders-scope-contract-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
