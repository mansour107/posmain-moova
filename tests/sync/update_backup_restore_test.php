<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Updates/DatabaseBackupManager.php';

$directory = sys_get_temp_dir() . '/posmain-update-backup-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('unable to create backup test directory');
}

$database = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'posmain_update_fixture',
    'user' => 'fixture_user',
    'pass' => 'fixture-secret',
    'charset' => 'utf8mb4',
];
$processCalls = [];
$runner = static function (array $command, array $environment, ?string $stdinFile) use (&$processCalls): array {
    $processCalls[] = [
        'command' => $command,
        'password' => (string) ($environment['MYSQL_PWD'] ?? ''),
        'environment' => $environment,
        'stdin' => $stdinFile,
    ];
    if (basename((string) ($command[0] ?? '')) === 'mysqldump') {
        $output = '';
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--result-file=')) {
                $output = substr($argument, strlen('--result-file='));
            }
        }
        if ($output === '') {
            return ['exit_code' => 2, 'stderr' => 'missing result file'];
        }
        file_put_contents($output, updateBackupFixture('posmain_update_fixture'));
    }

    return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
};
$manager = new PosmainDatabaseBackupManager(
    $database,
    $runner,
    static fn(array $config): array => [
        'ok' => $config['name'] === 'posmain_update_fixture',
        'database' => $config['name'],
        'table_count' => 1,
    ]
);

try {
    $backupFile = $directory . '/verified.sql';
    $created = $manager->create($backupFile);
    updateBackupAssert($created['ok'] === true, 'created backup must verify');
    updateBackupAssert($created['database'] === 'posmain_update_fixture', 'backup must bind to configured database');
    updateBackupAssert($created['bytes'] === filesize($backupFile), 'backup byte count must match');
    updateBackupAssert(strlen($created['sha256']) === 64, 'backup must have sha256');
    updateBackupAssert((fileperms($backupFile) & 0777) === 0600, 'backup permissions must be owner-only');
    updateBackupAssert(!file_exists($backupFile . '.partial'), 'backup must publish atomically');
    updateBackupAssert(in_array('--quick', $processCalls[0]['command'], true), 'dump must stream rows');
    updateBackupAssert(in_array('--hex-blob', $processCalls[0]['command'], true), 'dump must preserve binary values');
    updateBackupAssert(in_array('--add-drop-database', $processCalls[0]['command'], true), 'dump must support exact restore');
    updateBackupAssert($processCalls[0]['password'] === 'fixture-secret', 'password must be passed through environment');
    updateBackupAssert(strpos(implode(' ', $processCalls[0]['command']), 'fixture-secret') === false, 'password must not appear in command');
    updateBackupAssert(
        array_diff(array_keys($processCalls[0]['environment']), ['PATH', 'MYSQL_PWD', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL']) === [],
        'database clients must receive only the bounded process environment'
    );

    $restored = $manager->restore($backupFile);
    updateBackupAssert($restored['verification']['ok'] === true, 'restore must run post-restore verification');
    updateBackupAssert($processCalls[1]['stdin'] === $backupFile, 'restore must stream the verified backup to mysql');
    updateBackupAssert(basename($processCalls[1]['command'][0]) === 'mysql', 'restore must use mysql client');
    updateBackupAssert(!in_array('posmain_update_fixture', $processCalls[1]['command'], true), 'restore must let the dump select its bound database');

    $incomplete = $directory . '/incomplete.sql';
    file_put_contents($incomplete, str_repeat('-', 300));
    try {
        $manager->verify($incomplete);
        throw new RuntimeException('incomplete dump must be rejected');
    } catch (RuntimeException $exception) {
        updateBackupAssert(
            str_starts_with($exception->getMessage(), 'BACKUP_HEADER_INVALID:'),
            'invalid dump must fail before restore'
        );
    }

    $wrongDatabase = $directory . '/wrong-database.sql';
    file_put_contents($wrongDatabase, updateBackupFixture('another_database'));
    try {
        $manager->restore($wrongDatabase);
        throw new RuntimeException('wrong database dump must be rejected');
    } catch (RuntimeException $exception) {
        updateBackupAssert(
            $exception->getMessage() === 'BACKUP_DATABASE_MISMATCH:posmain_update_fixture',
            'restore must refuse a dump for another database'
        );
    }
    updateBackupAssert(count($processCalls) === 2, 'rejected backups must never invoke mysql');

    $failedOutput = $directory . '/failed.sql';
    $failedManager = new PosmainDatabaseBackupManager(
        $database,
        static function (array $command): array {
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, strlen('--result-file=')), 'partial');
                }
            }
            return ['exit_code' => 1, 'stderr' => 'simulated dump failure'];
        }
    );
    try {
        $failedManager->create($failedOutput);
        throw new RuntimeException('failed dump must throw');
    } catch (RuntimeException $exception) {
        updateBackupAssert(
            $exception->getMessage() === 'BACKUP_FAILED:simulated dump failure',
            'dump failure must be explicit'
        );
    }
    updateBackupAssert(!file_exists($failedOutput), 'failed dump must not publish an output');
    updateBackupAssert(glob($failedOutput . '.partial-*') === [], 'failed dump must remove partial files');

    putenv('POSMAIN_MYSQLDUMP_BIN=/definitely/missing/posmain-mysqldump');
    try {
        $missingClient = new PosmainDatabaseBackupManager($database);
        $missingClient->preflight();
        throw new RuntimeException('missing mysqldump must fail preflight');
    } catch (RuntimeException $exception) {
        updateBackupAssert(
            str_starts_with($exception->getMessage(), 'MYSQLDUMP_UNAVAILABLE:'),
            'missing database client must retain an operator-facing diagnostic'
        );
        updateBackupAssert(
            $exception->getMessage() !== 'DATABASE_PROCESS_START_FAILED',
            'missing database client must not collapse into a generic process error'
        );
    } finally {
        putenv('POSMAIN_MYSQLDUMP_BIN');
    }

    echo "update-backup-restore-ok\n";
} finally {
    putenv('POSMAIN_MYSQLDUMP_BIN');
    foreach (glob($directory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($directory);
}

function updateBackupFixture(string $database): string
{
    return implode("\n", [
        '-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)',
        '--',
        '-- Current Database: `' . $database . '`',
        '--',
        'DROP DATABASE IF EXISTS `' . $database . '`;',
        'CREATE DATABASE /*!32312 IF NOT EXISTS*/ `' . $database . '` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;',
        'USE `' . $database . '`;',
        'CREATE TABLE `fixture` (`id` int NOT NULL PRIMARY KEY);',
        'INSERT INTO `fixture` VALUES (1);',
        str_repeat('-', 256),
        '-- Dump completed on 2026-07-30 12:00:00',
        '',
    ]);
}

function updateBackupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
