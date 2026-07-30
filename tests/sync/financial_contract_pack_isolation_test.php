<?php

$root = dirname(__DIR__, 2);
$packPath = $root . '/scripts/run_financial_pack.sh';
$pack = (string) file_get_contents($packPath);

financialPackAssert(
    strpos($pack, 'FINANCIAL_PACK_LOCAL_DATABASE_REQUIRED') !== false,
    'financial pack must refuse non-local database hosts'
);
financialPackAssert(
    strpos($pack, 'posmain_financial_pack_forbidden_default') !== false,
    'financial pack must poison the normal application database fallback'
);
financialPackAssert(
    strpos($pack, 'FINANCIAL_PACK_TEST_COVERAGE_SKIPPED') !== false
        && strpos($pack, '[Ss][Kk][Ii][Pp][Pp][Ee][Dd]') !== false,
    'financial pack must fail when a test reports skipped coverage'
);
financialPackAssert(
    strpos($pack, 'FINANCIAL_PACK_TEST_MISSING') !== false,
    'missing financial tests must fail the pack'
);

preg_match_all('/tests\\/sync\\/[A-Za-z0-9_]+_test\\.php/', $pack, $matches);
$testFiles = array_values(array_unique($matches[0] ?? []));
financialPackAssert(count($testFiles) >= 18, 'expected complete financial pack test list');

$databaseTestCount = 0;
foreach ($testFiles as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    if (strpos($source, 'CREATE DATABASE') === false) {
        continue;
    }
    $databaseTestCount++;
    financialPackAssert(
        strpos($source, 'getmypid()') !== false,
        $relativePath . ' must use a process-unique database name'
    );
    financialPackAssert(
        strpos($source, "\$db = 'posmain_") !== false,
        $relativePath . ' must use an explicit posmain_* fixture prefix'
    );
    financialPackAssert(
        strpos($source, 'DROP DATABASE IF EXISTS') !== false,
        $relativePath . ' must drop its fixture database'
    );
}

financialPackAssert($databaseTestCount >= 12, 'expected all financial integration fixtures to be mapped');

echo 'financial-contract-pack-isolation-ok tests=' . count($testFiles)
    . ' database_tests=' . $databaseTestCount . "\n";

function financialPackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
