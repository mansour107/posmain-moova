<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This backup tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['output:', 'dry-run', 'print-command', 'help']);
if (isset($options['help'])) {
    posmainBackupUsage();
    exit(0);
}

$output = isset($options['output']) ? trim((string) $options['output']) : '';
if ($output === '') {
    posmainBackupUsage(STDERR);
    exit(1);
}

$config = posmain_app_config();
$db = $config['database'];
$command = posmainBackupCommand($db, $output, true);

if (isset($options['print-command']) || isset($options['dry-run'])) {
    echo posmainBackupCommand($db, $output, false) . PHP_EOL;
    exit(0);
}

$dir = dirname($output);
if (!is_dir($dir) || !is_writable($dir)) {
    fwrite(STDERR, "Backup directory is not writable: {$dir}\n");
    exit(1);
}

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$env = getenv();
if (!is_array($env)) {
    $env = $_ENV;
}
$env['MYSQL_PWD'] = (string) ($db['pass'] ?? '');
$process = proc_open($command, $descriptors, $pipes, null, $env);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start mysqldump.\n");
    exit(1);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);

if ($code !== 0) {
    fwrite(STDERR, trim($stderr ?: $stdout) . PHP_EOL);
    exit($code);
}

echo json_encode([
    'ok' => true,
    'output' => $output,
    'bytes' => is_file($output) ? filesize($output) : 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function posmainBackupUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/backup_database.php --output=/absolute/path/to/backup.sql [--dry-run|--print-command]\n");
}

function posmainBackupCommand(array $db, string $output, bool $withRedirect): string
{
    $parts = [
        'mysqldump',
        '--single-transaction',
        '--routines',
        '--triggers',
        '--default-character-set=' . escapeshellarg((string) ($db['charset'] ?? 'utf8mb4')),
        '--host=' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1')),
        '--port=' . escapeshellarg((string) ($db['port'] ?? 3306)),
        '--user=' . escapeshellarg((string) ($db['user'] ?? '')),
        escapeshellarg((string) ($db['name'] ?? '')),
    ];

    $cmd = implode(' ', $parts);
    if ($withRedirect) {
        $cmd .= ' > ' . escapeshellarg($output);
    } else {
        $cmd .= ' > ' . escapeshellarg($output);
        $cmd = 'MYSQL_PWD=******** ' . $cmd;
    }

    return $cmd;
}
