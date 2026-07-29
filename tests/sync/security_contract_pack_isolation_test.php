<?php

$root = dirname(__DIR__, 2);
$pack = (string) file_get_contents($root . '/scripts/run_security_contract_pack.sh');
$fixture = (string) file_get_contents($root . '/tests/sync/security_test_database.php');
$databaseTests = [
    'user_override_deny_matrix_test.php',
    'user_lifecycle_drawer_guard_test.php',
    'role_capabilities_backfill_contract_test.php',
    'pos_item_void_override_runtime_test.php',
];

securityIsolationAssert(
    strpos($pack, 'POSMAIN_SECURITY_TEST_DISPOSABLE=1') !== false,
    'security pack must explicitly authorize only the disposable runtime test'
);
foreach ($databaseTests as $databaseTest) {
    $runtime = (string) file_get_contents($root . '/tests/sync/' . $databaseTest);
    securityIsolationAssert(
        strpos($runtime, "require_once __DIR__ . '/security_test_database.php'") !== false,
        $databaseTest . ' must use the disposable fixture'
    );
    securityIsolationAssert(
        strpos($runtime, 'SecurityTestDatabase::create()') !== false,
        $databaseTest . ' must create its own disposable database'
    );
    securityIsolationAssert(
        strpos($pack, '"tests/sync/' . $databaseTest . '"') !== false
            || strpos($pack, 'tests/sync/' . $databaseTest) !== false,
        $databaseTest . ' must be explicitly isolated by the pack'
    );
}
securityIsolationAssert(
    strpos($fixture, 'SECURITY_TEST_DISPOSABLE_MARKER_REQUIRED') !== false,
    'fixture must fail closed without the explicit marker'
);
securityIsolationAssert(
    strpos($fixture, '^posmain_security_test_') !== false
        && strpos($fixture, 'DROP DATABASE IF EXISTS') !== false,
    'fixture must enforce a disposable name and drop the whole database'
);
securityIsolationAssert(
    strpos($fixture, "'127.0.0.1', 'localhost', 'mysql'") !== false,
    'fixture must reject non-local database hosts'
);

echo "security-contract-pack-isolation-ok\n";

function securityIsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
