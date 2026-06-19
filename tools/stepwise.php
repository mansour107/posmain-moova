<?php

require_once __DIR__ . '/../classes/Stepwise.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'steps:',
    'bootstrap:',
    'ledger-table:',
    'pattern:',
    'recursive',
    'dry-run',
    'apply',
    'allow-destructive',
    'host:',
    'port:',
    'user:',
    'pass:',
    'database:',
    'charset:',
    'help',
]);

if (isset($options['help'])) {
    stepwiseUsage();
    exit(0);
}

$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if ($dryRun === $apply) {
    stepwiseUsage(STDERR);
    exit(1);
}

$stepsPath = isset($options['steps']) ? trim((string) $options['steps']) : '';
if ($stepsPath === '') {
    fwrite(STDERR, "--steps=/absolute/path/to/migration/files is required.\n");
    exit(1);
}

$runnerOptions = [
    'ledger_table' => isset($options['ledger-table']) ? trim((string) $options['ledger-table']) : 'stepwise_ledger',
    'pattern' => isset($options['pattern']) ? trim((string) $options['pattern']) : '*.sql',
    'recursive' => isset($options['recursive']),
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = stepwiseConnect($options);
    $runner = new Stepwise($conn, $stepsPath, $runnerOptions);

    if ($dryRun) {
        $plan = $runner->plan();
        echo json_encode([
            'ok' => true,
            'mode' => 'dry-run',
            'steps_path' => $runner->stepsPath(),
            'ledger_table' => $runner->ledgerTable(),
            'discovered' => $plan['discovered'],
            'pending_count' => count($plan['pending']),
            'pending' => array_map(static function (array $step): array {
                return [
                    'step_key' => $step['step_key'],
                    'source_file' => $step['source_file'],
                    'checksum' => $step['checksum'],
                ];
            }, $plan['pending']),
            'drift' => $plan['drift'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $result = $runner->apply(null, isset($options['allow-destructive']));
    echo json_encode([
        'ok' => true,
        'mode' => 'apply',
        'steps_path' => $runner->stepsPath(),
        'ledger_table' => $runner->ledgerTable(),
        'applied_count' => count($result['applied']),
        'applied' => $result['applied'],
        'skipped' => $result['skipped'],
        'drift' => $result['drift'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param array<string, mixed> $options
 */
function stepwiseConnect(array $options): mysqli
{
    if (isset($options['bootstrap'])) {
        $bootstrap = trim((string) $options['bootstrap']);
        if ($bootstrap === '' || !is_file($bootstrap)) {
            throw new RuntimeException('Bootstrap file not found: ' . $bootstrap);
        }

        require_once $bootstrap;

        if (function_exists('stepwise_connect')) {
            $conn = stepwise_connect();
            if (!$conn instanceof mysqli) {
                throw new RuntimeException('stepwise_connect() must return a mysqli instance.');
            }

            return $conn;
        }

        throw new RuntimeException('Bootstrap file must define stepwise_connect(): mysqli');
    }

    $host = isset($options['host']) ? trim((string) $options['host']) : '';
    $user = isset($options['user']) ? trim((string) $options['user']) : '';
    $database = isset($options['database']) ? trim((string) $options['database']) : '';

    if ($host === '' || $user === '' || $database === '') {
        throw new RuntimeException('Provide --bootstrap=/path/to/connect.php or --host --user --database connection flags.');
    }

    $port = isset($options['port']) ? (int) $options['port'] : 3306;
    $password = isset($options['pass']) ? (string) $options['pass'] : '';
    $charset = isset($options['charset']) ? trim((string) $options['charset']) : 'utf8mb4';

    $conn = new mysqli($host, $user, $password, $database, $port);
    $conn->set_charset($charset);

    return $conn;
}

function stepwiseUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php tools/stepwise.php --steps=/absolute/path/to/files --dry-run\n");
    fwrite($stream, "  php tools/stepwise.php --steps=/absolute/path/to/files --apply [--allow-destructive]\n");
    fwrite($stream, "\n");
    fwrite($stream, "Connection:\n");
    fwrite($stream, "  --bootstrap=/path/to/connect.php   file must define stepwise_connect(): mysqli\n");
    fwrite($stream, "  --host= --port= --user= --pass= --database= --charset=utf8mb4\n");
    fwrite($stream, "\n");
    fwrite($stream, "Options:\n");
    fwrite($stream, "  --ledger-table=stepwise_ledger\n");
    fwrite($stream, "  --pattern=*.sql\n");
    fwrite($stream, "  --recursive\n");
}
