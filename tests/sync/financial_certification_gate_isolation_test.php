<?php

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/scripts/run_financial_certification_gate.sh');
$seed = file_get_contents($root . '/tools/financial_certification_seed.php');

financialCertificationGateIsolationAssert(is_string($runner) && $runner !== '', 'financial certification gate runner must be readable');
financialCertificationGateIsolationAssert(is_string($seed) && $seed !== '', 'financial certification seed must be readable');

foreach ([
    '127.0.0.1|localhost|mysql',
    'FINANCIAL_CERTIFICATION_GATE_LOCAL_DATABASE_REQUIRED',
    'GATE_DB="posmain_financial_gate_$$_${RANDOM}"',
    'POSMAIN_FINANCIAL_GATE_DISPOSABLE=1',
    'trap cleanup_gate EXIT',
    'tools/financial_certification_seed.php --drop-disposable',
    'tools/financial_certification_preflight.php --json',
    'FINANCIAL_CERTIFICATION_GATE_NOT_GREEN',
    'FINANCIAL_CERTIFICATION_GATE_CHECK_NOT_GREEN',
] as $needle) {
    financialCertificationGateIsolationAssert(
        strpos($runner, $needle) !== false,
        'runner must contain isolation/fail-closed control: ' . $needle
    );
}

financialCertificationGateIsolationAssert(
    strpos($runner, 'POSMAIN_FINANCIAL_CERT_DB') === false,
    'runner must not accept an operator-selected or standing certification database name'
);
financialCertificationGateIsolationAssert(
    strpos($runner, 'POSMAIN_MYSQL_DATABASE="${') === false,
    'runner must always generate its disposable database name'
);

foreach ([
    'FINANCIAL_CERTIFICATION_DATABASE_NAME_INVALID',
    'FINANCIAL_CERTIFICATION_LOCAL_DATABASE_REQUIRED',
    'FINANCIAL_CERTIFICATION_DISPOSABLE_DATABASE_REQUIRED',
    'FINANCIAL_CERTIFICATION_DISPOSABLE_MARKER_REQUIRED',
    'FINANCIAL_CERTIFICATION_DISPOSABLE_DATABASE_ALREADY_EXISTS',
    "'/^posmain_financial_gate_[0-9]+_[0-9]+$/'",
] as $needle) {
    financialCertificationGateIsolationAssert(
        strpos($seed, $needle) !== false,
        'seed must contain disposable database guard: ' . $needle
    );
}

echo "financial-certification-gate-isolation-ok\n";

function financialCertificationGateIsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
