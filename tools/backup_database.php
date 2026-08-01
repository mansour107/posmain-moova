<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Updates/DatabaseBackupManager.php';

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
$manager = new PosmainDatabaseBackupManager($db);

if (isset($options['print-command']) || isset($options['dry-run'])) {
    echo $manager->printableDumpCommand($output) . PHP_EOL;
    exit(0);
}

try {
    $result = $manager->create($output);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function posmainBackupUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/backup_database.php --output=/absolute/path/to/backup.sql [--dry-run|--print-command]\n");
}
