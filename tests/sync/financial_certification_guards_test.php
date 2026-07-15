<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Financial/FinancialCertificationBaselineService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialReconciliationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_financial_guards_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "financial-certification-guards-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    $result = (new FinancialReconciliationService())->runAll($conn);
    financialGuardAssert($result['ok'] === false, 'missing reconciliation schema must block certification');
    financialGuardAssert(in_array('reconciliation_check_failed', $result['blockers'], true), 'schema failure blocker must be explicit');
    financialGuardAssert(count($result['errors']) >= 10, 'each unavailable reconciliation must expose an error');
    foreach ($result['differences'] as $difference) {
        financialGuardAssert(is_int($difference), 'difference compatibility fields must remain integers');
    }

    $conn->query("
        CREATE TABLE financial_certification_baselines (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          cutoff_time DATETIME NOT NULL,
          manifest_hash CHAR(64) NOT NULL,
          approver VARCHAR(191) NOT NULL,
          historical_exceptions_json JSON NOT NULL,
          approved_at DATETIME NOT NULL,
          invalidated_at DATETIME NULL,
          invalidation_reason VARCHAR(500) NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_financial_certification_baseline_manifest (manifest_hash),
          KEY idx_financial_certification_baseline_active (invalidated_at, approved_at)
        ) ENGINE=InnoDB
    ");
    $baseline = new FinancialCertificationBaselineService();
    $first = $baseline->create($conn, '2026-07-01 00:00:00', ['legacy_cash_payments' => 29], 'release-owner');
    financialGuardAssert(($baseline->active($conn)['manifest_hash'] ?? '') === $first['manifest_hash'], 'created baseline must verify');
    $firstReplay = $baseline->create($conn, '2026-07-01 00:00:00', ['legacy_cash_payments' => 29], 'release-owner');
    financialGuardAssert(($firstReplay['replayed'] ?? false) === true && $firstReplay['id'] === $first['id'], 'repeating the active reviewed baseline must be idempotent');

    $second = $baseline->create($conn, '2026-07-02 00:00:00', ['legacy_cash_payments' => 29], 'release-owner');
    financialGuardAssert($second['manifest_hash'] !== $first['manifest_hash'], 'new baseline must have its reviewed manifest');
    $invalidated = (int) $conn->query("SELECT COUNT(*) AS c FROM financial_certification_baselines WHERE invalidated_at IS NOT NULL")->fetch_assoc()['c'];
    financialGuardAssert($invalidated === 1, 'new certification baseline must invalidate the prior baseline');

    $conn->query("UPDATE financial_certification_baselines SET historical_exceptions_json = JSON_OBJECT('legacy_cash_payments', 30) WHERE id = " . (int) $second['id']);
    try {
        $baseline->active($conn);
        throw new RuntimeException('tampered baseline must not verify');
    } catch (RuntimeException $exception) {
        financialGuardAssert($exception->getMessage() === 'FINANCIAL_BASELINE_MANIFEST_TAMPERED', 'tamper must invalidate certification');
    }

    echo "financial-certification-guards-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function financialGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
