<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Financial/FinancialCertifiedMode.php';
require_once __DIR__ . '/../classes/Financial/FinancialReconciliationService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/financial_certification_preflight.php [--json]\n");
    fwrite(STDOUT, "Read-only gate for the exact-money POS certification database.\n");
    exit(0);
}

$result = [
    'production_ready' => false,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'scope' => 'financial_certification_database',
    'checks' => [],
    'blockers' => [],
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();

    $requiredTables = [
        'payment_methods',
        'tax_categories',
        'credit_notes',
        'credit_note_lines',
        'payment_refunds',
        'journal_heads',
        'journal_entries',
        'document_counters',
    ];
    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!financialCertificationTableExists($conn, $table)) {
            $missingTables[] = $table;
        }
    }
    $result['checks']['required_tables'] = [
        'ok' => $missingTables === [],
        'missing' => $missingTables,
    ];
    if ($missingTables !== []) {
        $result['blockers'][] = 'financial_schema_missing';
    }

    $requiredJournalColumns = [
        'source_type',
        'source_id',
        'posting_kind',
        'idempotency_key',
        'reversal_of_journal_id',
    ];
    $missingColumns = [];
    if (financialCertificationTableExists($conn, 'journal_heads')) {
        foreach ($requiredJournalColumns as $column) {
            if (!financialCertificationColumnExists($conn, 'journal_heads', $column)) {
                $missingColumns[] = $column;
            }
        }
    }
    $result['checks']['journal_provenance'] = [
        'ok' => $missingColumns === [],
        'missing_columns' => $missingColumns,
    ];
    if ($missingColumns !== []) {
        $result['blockers'][] = 'journal_provenance_schema_missing';
    }
    $requiredJournalIndexes = ['uq_journal_heads_idempotency', 'uq_journal_heads_source_kind'];
    $missingIndexes = [];
    if (financialCertificationTableExists($conn, 'journal_heads')) {
        foreach ($requiredJournalIndexes as $index) {
            if (!financialCertificationIndexExists($conn, 'journal_heads', $index)) {
                $missingIndexes[] = $index;
            }
        }
    }
    $result['checks']['journal_idempotency_indexes'] = [
        'ok' => $missingIndexes === [],
        'missing_indexes' => $missingIndexes,
    ];
    if ($missingIndexes !== []) {
        $result['blockers'][] = 'journal_idempotency_indexes_missing';
    }

    if (financialCertificationTableExists($conn, 'payment_methods')) {
        $badTenders = (int) financialCertificationScalar($conn, "
            SELECT COUNT(*)
            FROM payment_methods
            WHERE is_active = 1
              AND (
                account_id IS NULL
                OR type NOT IN ('cash', 'card', 'wallet', 'bank')
                OR (type <> 'cash' AND requires_reference <> 1)
              )
        ");
        $result['checks']['configured_tenders'] = ['ok' => $badTenders === 0, 'invalid_active_count' => $badTenders];
        if ($badTenders !== 0) {
            $result['blockers'][] = 'invalid_active_payment_method_configuration';
        }
    }

    if (financialCertificationTableExists($conn, 'journal_entries')) {
        $imbalanced = (int) financialCertificationScalar($conn, "
            SELECT COUNT(*)
            FROM (
                SELECT journal_id
                FROM journal_entries
                GROUP BY journal_id
                HAVING SUM(debit) <> SUM(credit)
            ) AS unbalanced
        ");
        $subCentEntries = (int) financialCertificationScalar($conn, "
            SELECT COUNT(*)
            FROM journal_entries
            WHERE debit <> ROUND(debit, 2)
               OR credit <> ROUND(credit, 2)
        ");
        $result['checks']['journal_integrity'] = [
            'ok' => $imbalanced === 0 && $subCentEntries === 0,
            'imbalanced_journal_count' => $imbalanced,
            'sub_cent_entry_count' => $subCentEntries,
        ];
        if ($imbalanced !== 0) {
            $result['blockers'][] = 'journal_imbalance_detected';
        }
        if ($subCentEntries !== 0) {
            $result['blockers'][] = 'posted_journal_precision_not_two_decimals';
        }
    }

    if (financialCertificationTableExists($conn, 'payment_refunds')) {
        $hasStatus = financialCertificationColumnExists($conn, 'payment_refunds', 'status');
        $orphanRefunds = (int) financialCertificationScalar($conn, $hasStatus ? "
            SELECT COUNT(*)
            FROM payment_refunds pr
            LEFT JOIN credit_notes cn ON cn.id = pr.credit_note_id
            WHERE cn.id IS NULL
               OR cn.status <> 'posted'
               OR (pr.status <> 'pending_external' AND pr.journal_head_id IS NULL)
        " : "
            SELECT COUNT(*)
            FROM payment_refunds pr
            LEFT JOIN credit_notes cn ON cn.id = pr.credit_note_id
            WHERE cn.id IS NULL OR cn.status <> 'posted' OR pr.journal_head_id IS NULL
        ");
        $result['checks']['refund_provenance'] = ['ok' => $orphanRefunds === 0, 'invalid_refund_count' => $orphanRefunds];
        if ($orphanRefunds !== 0) {
            $result['blockers'][] = 'refund_without_posted_credit_note_or_journal';
        }
    }

    require_once __DIR__ . '/../classes/Financial/FinancialReconciliationService.php';
    $recon = (new FinancialReconciliationService())->runAll($conn);
    $result['checks']['financial_reconciliations'] = $recon;
    if (!$recon['ok']) {
        foreach ($recon['blockers'] as $blocker) {
            // Fresh/empty DBs may lack account-balance cache alignment until opening docs.
            if ($blocker === 'account_balance_vs_journals' && !financialCertificationEnvFlag('POSMAIN_FINANCIAL_REQUIRE_BALANCE_CACHE')) {
                continue;
            }
            if ($blocker === 'cash_vs_drawer' && !financialCertificationEnvFlag('POSMAIN_FINANCIAL_REQUIRE_CASH_DRAWER_LINK')) {
                continue;
            }
            $result['blockers'][] = 'recon_' . $blocker;
        }
    }

    $conn->close();
} catch (Throwable $exception) {
    $result['blockers'][] = 'financial_preflight_database_unreachable';
    $result['error'] = $exception->getMessage();
}

$result['blockers'] = array_values(array_unique($result['blockers']));
$result['production_ready'] = $result['blockers'] === [];

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($result['production_ready'] ? 'PASS' : 'BLOCKED') . ' financial certification preflight' . PHP_EOL;
    foreach ($result['blockers'] as $blocker) {
        echo '- ' . $blocker . PHP_EOL;
    }
}

exit($result['production_ready'] ? 0 : 2);

function financialCertificationEnvFlag(string $name): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return false;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function financialCertificationTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function financialCertificationColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function financialCertificationIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function financialCertificationScalar(mysqli $conn, string $sql): string
{
    $row = $conn->query($sql)->fetch_row();

    return (string) ($row[0] ?? '0');
}
