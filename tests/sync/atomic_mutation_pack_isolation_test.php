<?php

$root = dirname(__DIR__, 2);
$pack = (string) file_get_contents($root . '/scripts/run_atomic_mutation_pack.sh');

atomicPackAssert(strpos($pack, 'ATOMIC_PACK_LOCAL_DATABASE_REQUIRED') !== false, 'pack must refuse non-local DB hosts');
atomicPackAssert(strpos($pack, 'posmain_atomic_pack_forbidden_default') !== false, 'pack must poison default DB fallback');
atomicPackAssert(strpos($pack, 'ATOMIC_PACK_TEST_COVERAGE_SKIPPED') !== false, 'pack must fail on skipped coverage');
atomicPackAssert(strpos($pack, 'ATOMIC_PACK_TEST_MISSING') !== false, 'pack must fail on missing tests');

preg_match_all('/tests\\/sync\\/[A-Za-z0-9_]+_test\\.php/', $pack, $matches);
$testFiles = array_values(array_unique($matches[0] ?? []));
atomicPackAssert(count($testFiles) === 12, 'atomic pack must retain the exact certified test set');

$databaseTests = 0;
foreach ($testFiles as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    if (strpos($source, 'CREATE DATABASE') === false) {
        continue;
    }
    $databaseTests++;
    atomicPackAssert(strpos($source, 'getmypid()') !== false, $relativePath . ' needs a process-unique fixture');
    atomicPackAssert(strpos($source, "\$db = 'posmain_") !== false, $relativePath . ' needs an explicit fixture prefix');
    atomicPackAssert(strpos($source, 'DROP DATABASE IF EXISTS') !== false, $relativePath . ' must drop its fixture');
}

atomicPackAssert($databaseTests >= 10, 'expected all atomic runtime fixtures to be mapped');

$requestKeyTest = (string) file_get_contents($root . '/tests/sync/pos_request_keys_idempotency_service_test.php');
atomicPackAssert(
    strpos($requestKeyTest, "getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2'") === false,
    'request-key proof must never default to kody2'
);

echo 'atomic-mutation-pack-isolation-ok tests=' . count($testFiles)
    . ' database_tests=' . $databaseTests . "\n";

function atomicPackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
