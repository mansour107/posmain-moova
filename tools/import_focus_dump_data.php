<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_bootstrap.php';

$options = getopt('', ['file:', 'database::', 'json', 'help']);
if (isset($options['help']) || empty($options['file'])) {
    fwrite(STDOUT, "Usage: php tools/import_focus_dump_data.php --file=/absolute/path/to/dump.sql [--database=focushouse]\n");
    exit(isset($options['help']) ? 0 : 1);
}

$dumpPath = (string) $options['file'];
if (!is_file($dumpPath)) {
    fwrite(STDERR, "Dump file not found: {$dumpPath}\n");
    exit(1);
}

$config = posmain_app_config();
$dbName = trim((string) ($options['database'] ?? $config['database']['name'] ?? ''));
if ($dbName === '') {
    fwrite(STDERR, "Database name is required.\n");
    exit(1);
}

$host = (string) ($config['database']['host'] ?? '127.0.0.1');
$port = (int) ($config['database']['port'] ?? 3306);
$user = (string) ($config['database']['user'] ?? 'root');
$pass = (string) ($config['database']['pass'] ?? '');

if ($host === 'mysql' || $host === 'posmain-mysql') {
  // CLI on host talking to published port.
    $host = getenv('POSMAIN_DB_HOST') ?: '127.0.0.1';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $dbName, $port);
$conn->set_charset('utf8mb4');

$content = file_get_contents($dumpPath);
if ($content === false) {
    throw new RuntimeException('Failed to read dump file.');
}

$result = [
    'ok' => true,
    'database' => $dbName,
    'imported_tables' => [],
    'customer_visits_rows' => 0,
    'skipped_tables' => [],
    'errors' => [],
];

try {
    ensureCustomerVisitsTable($conn);
    $conn->query('SET FOREIGN_KEY_CHECKS=0');

    $statements = extractInsertStatements($content);
    foreach ($statements as $table => $sqlStatements) {
        if ($table === 'visits') {
            $conn->query('TRUNCATE TABLE customer_visits');
            $result['customer_visits_rows'] = importVisitsAsCustomerVisits($conn, $sqlStatements);
            $result['imported_tables']['customer_visits'] = $result['customer_visits_rows'];
            continue;
        }

        truncateTableIfExists($conn, $table);

        $imported = 0;
        foreach ($sqlStatements as $sql) {
            if (!insertMatchesLiveSchema($conn, $sql)) {
                $result['skipped_tables'][$table] = 'column mismatch with live schema';
                continue 2;
            }
            $conn->query($sql);
            $imported += max(0, (int) $conn->affected_rows);
        }
        $result['imported_tables'][$table] = $imported;
    }

    ensureSettingsCustomerVisitFlag($conn);
    $conn->query('SET FOREIGN_KEY_CHECKS=1');
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['errors'][] = $e->getMessage();
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo $result['ok'] ? "Import OK into {$dbName}\n" : "Import failed\n";
    foreach ($result['imported_tables'] as $table => $count) {
        echo "  {$table}: {$count}\n";
    }
    if ($result['customer_visits_rows'] > 0) {
        echo "  customer_visits mapped from visits: {$result['customer_visits_rows']}\n";
    }
    foreach ($result['skipped_tables'] as $table => $reason) {
        echo "  skipped {$table}: {$reason}\n";
    }
    foreach ($result['errors'] as $error) {
        echo "  error: {$error}\n";
    }
}

exit($result['ok'] ? 0 : 1);

function ensureCustomerVisitsTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS customer_visits (
            id INT(11) NOT NULL AUTO_INCREMENT,
            gender ENUM('male','female') NOT NULL,
            age_group ENUM('under18','18_25','25_40','over40') NOT NULL,
            mode ENUM('solo','group') NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NULL DEFAULT NULL,
            order_value ENUM('under60','over60') NOT NULL,
            visit_type ENUM('new','returning','regular') NOT NULL,
            created_by INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_isdeleted (isdeleted),
            KEY idx_visit_type (visit_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureSettingsCustomerVisitFlag(mysqli $conn): void
{
    $res = $conn->query("SHOW COLUMNS FROM settings LIKE 'show_customer_visits'");
    if ($res && $res->num_rows === 0) {
        $conn->query('ALTER TABLE settings ADD COLUMN show_customer_visits INT DEFAULT 1');
    }
}

function truncateTableIfExists(mysqli $conn, string $table): void
{
    $safe = str_replace('`', '``', $table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    if ($res && $res->num_rows > 0) {
        $conn->query("TRUNCATE TABLE `{$safe}`");
    }
}

function extractInsertStatements(string $content): array
{
    $pattern = '/INSERT INTO `([^`]+)`[^;]+;/s';
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
    $grouped = [];
    foreach ($matches as $match) {
        $grouped[$match[1]][] = $match[0];
    }

    return $grouped;
}

function insertMatchesLiveSchema(mysqli $conn, string $sql): bool
{
    if (!preg_match('/INSERT INTO `([^`]+)` \(([^)]+)\)/', $sql, $m)) {
        return false;
    }
    $table = $m[1];
    $insertCols = array_map(static fn ($c) => trim($c, " `"), explode(',', $m[2]));
    $res = $conn->query("
        SELECT COLUMN_NAME
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '" . $conn->real_escape_string($table) . "'
    ");
    $liveCols = [];
    while ($row = $res->fetch_assoc()) {
        $liveCols[] = $row['COLUMN_NAME'];
    }
    $liveMap = array_fill_keys($liveCols, true);
    foreach ($insertCols as $col) {
        if (!isset($liveMap[$col])) {
            return false;
        }
    }

    return true;
}

function importVisitsAsCustomerVisits(mysqli $conn, array $sqlStatements): int
{
    $inserted = 0;
    $stmt = $conn->prepare('
        INSERT INTO customer_visits
            (id, gender, age_group, mode, start_time, order_value, visit_type, created_by, created_at, isdeleted)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($sqlStatements as $sql) {
        if (!preg_match('/VALUES\s*(.*);\s*$/s', $sql, $m)) {
            continue;
        }
        $rows = splitSqlRows($m[1]);
        foreach ($rows as $rowSql) {
            $values = parseSqlTuple($rowSql);
            if (count($values) < 18) {
                continue;
            }
            $id = (int) sqlValueToPhp($values[0]);
            $gender = (string) sqlValueToPhp($values[1]);
            $ageGroup = (string) sqlValueToPhp($values[2]);
            $mode = (string) sqlValueToPhp($values[3]);
            $startTime = (string) sqlValueToPhp($values[4]);
            $orderValue = (string) sqlValueToPhp($values[5]);
            $type = (string) sqlValueToPhp($values[6]);
            $createdBy = (int) sqlValueToPhp($values[7]);
            $createdAt = (string) sqlValueToPhp($values[8]);
            $isdeleted = (int) sqlValueToPhp($values[17]);
            $stmt->bind_param(
                'issssssisi',
                $id,
                $gender,
                $ageGroup,
                $mode,
                $startTime,
                $orderValue,
                $type,
                $createdBy,
                $createdAt,
                $isdeleted
            );
            $stmt->execute();
            $inserted++;
        }
    }
    $stmt->close();

    return $inserted;
}

function splitSqlRows(string $valuesSql): array
{
    $rows = [];
    $depth = 0;
    $current = '';
    $len = strlen($valuesSql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $valuesSql[$i];
        if ($ch === "'" && ($i === 0 || $valuesSql[$i - 1] !== '\\')) {
            $current .= $ch;
            $i++;
            while ($i < $len) {
                $current .= $valuesSql[$i];
                if ($valuesSql[$i] === "'" && $valuesSql[$i - 1] !== '\\') {
                    if ($i + 1 < $len && $valuesSql[$i + 1] === "'") {
                        $current .= $valuesSql[++$i];
                        continue;
                    }
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($ch === '(') {
            $depth++;
            if ($depth === 1) {
                $current = '';
                continue;
            }
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                $rows[] = $current;
                $current = '';
                continue;
            }
        }
        if ($depth > 0) {
            $current .= $ch;
        }
    }

    return $rows;
}

function parseSqlTuple(string $rowSql): array
{
    $values = [];
    $current = '';
    $inString = false;
    $len = strlen($rowSql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $rowSql[$i];
        if ($ch === "'" && !$inString) {
            $inString = true;
            continue;
        }
        if ($ch === "'" && $inString) {
            if ($i + 1 < $len && $rowSql[$i + 1] === "'") {
                $current .= "'";
                $i++;
                continue;
            }
            $inString = false;
            continue;
        }
        if ($ch === ',' && !$inString) {
            $values[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if ($current !== '') {
        $values[] = trim($current);
    }

    return $values;
}

function sqlValueToPhp(string $raw)
{
    $raw = trim($raw);
    if (strcasecmp($raw, 'NULL') === 0) {
        return null;
    }
    if (is_numeric($raw)) {
        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    return $raw;
}
