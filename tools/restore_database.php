<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Updates/DatabaseBackupManager.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This restore tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['input:', 'confirm-database:', 'dry-run', 'print-command', 'help']);
if (isset($options['help'])) {
    posmainRestoreUsage();
    exit(0);
}

$input = trim((string) ($options['input'] ?? ''));
$confirmDatabase = trim((string) ($options['confirm-database'] ?? ''));
if ($input === '') {
    posmainRestoreUsage(STDERR);
    exit(1);
}

$database = (array) (posmain_app_config()['database'] ?? []);
$manager = new PosmainDatabaseBackupManager($database);
if (isset($options['dry-run']) || isset($options['print-command'])) {
    try {
        $manager->verify($input);
        echo $manager->printableRestoreCommand($input) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$databaseName = (string) ($database['name'] ?? '');
if ($confirmDatabase === '' || !hash_equals($databaseName, $confirmDatabase)) {
    fwrite(STDERR, "--confirm-database must exactly match the configured database name ({$databaseName}).\n");
    exit(1);
}

try {
    $result = $manager->restore($input);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function posmainRestoreUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/restore_database.php --input=/absolute/path/to/backup.sql --confirm-database=DATABASE\n");
    fwrite($stream, "Validate and print the redacted command: add --dry-run or --print-command.\n");
}
