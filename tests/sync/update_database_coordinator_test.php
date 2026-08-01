<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Updates/UpdateDatabaseCoordinator.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = getmypid() . '_' . bin2hex(random_bytes(2));
$shopDatabase = 'posmain_update_shop_' . $suffix;
$routerDatabase = 'posmain_update_router_' . $suffix;
$root = sys_get_temp_dir() . '/posmain-update-coordinator-' . $suffix;
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "update-database-coordinator-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mkdir($root . '/update', 0700, true);
mkdir($root . '/backup/updates', 0700, true);

$targets = [
    [
        'kind' => 'router',
        'label' => 'router',
        'database' => compactDatabase($host, $port, $routerDatabase, $user, $pass),
    ],
    [
        'kind' => 'shop',
        'label' => 'shop:test',
        'database' => compactDatabase($host, $port, $shopDatabase, $user, $pass),
    ],
];
$managerFactory = static function (array $database): object {
    return new class($database) {
        private array $database;

        public function __construct(array $database)
        {
            $this->database = $database;
        }

        public function create(string $file): array
        {
            $contents = str_repeat('-- verified coordinator fixture ' . $this->database['name'] . "\n", 20);
            file_put_contents($file, $contents);
            return [
                'ok' => true,
                'file' => $file,
                'database' => $this->database['name'],
                'bytes' => filesize($file),
                'sha256' => hash_file('sha256', $file),
            ];
        }

        public function verify(string $file): array
        {
            if (!is_file($file) || filesize($file) < 1) {
                throw new RuntimeException('fixture backup unavailable');
            }
            return ['ok' => true, 'file' => $file];
        }

        public function restore(string $file): array
        {
            $this->verify($file);
            return [
                'ok' => true,
                'verification' => [
                    'ok' => true,
                    'database' => $this->database['name'],
                    'table_count' => 1,
                ],
            ];
        }
    };
};
$targetExecutor = static function (
    string $mode,
    array $target,
    string $backupFile,
    string $projectRoot
): array {
    $conn = PosmainShopRouter::connectDatabase($target['database']);
    try {
        if ($target['kind'] === 'router') {
            $applied = $mode === 'apply' ? (new PosmainShopRouter())->install($conn) : [];
            $missing = [];
            foreach (['app_sessions', 'security_audit_log', 'failed_login_attempts', 'router_shops', 'router_login_aliases', 'router_branch_routes'] as $table) {
                $escaped = $conn->real_escape_string($table);
                if ($conn->query("SHOW TABLES LIKE '{$escaped}'")->num_rows === 0) {
                    $missing[] = $table;
                }
            }
            $upgrades = (new PosmainShopRouter())->upgradeStatements($conn);
            $verification = ['ok' => $missing === [] && $upgrades === []];
            return [
                'ok' => $verification['ok'],
                'target' => ['kind' => 'router'],
                'router_applied' => $applied,
                'verification' => $verification,
            ];
        }

        $stepwise = new Stepwise($conn, $projectRoot . '/update', ['ledger_table' => 'stepwise_ledger']);
        $stepwiseResult = ['applied' => [], 'skipped' => []];
        $schemaApplied = [];
        if ($mode === 'apply') {
            $stepwiseResult = $stepwise->apply('update_worker', true);
            $schemaApplied = (new PosmainSchemaMigrationRunner())->apply($conn, $backupFile);
        }
        $stepwisePlan = $stepwise->plan(false);
        $schemaPending = (new PosmainSchemaMigrationRunner())->pending($conn);
        $verification = [
            'ok' => $stepwisePlan['pending'] === [] && $stepwisePlan['drift'] === [] && $schemaPending === [],
        ];
        return [
            'ok' => $verification['ok'],
            'target' => ['kind' => $target['kind']],
            'stepwise_applied' => $stepwiseResult['applied'],
            'schema_applied' => $schemaApplied,
            'verification' => $verification,
        ];
    } finally {
        $conn->close();
    }
};

try {
    $conn->query("CREATE DATABASE `{$shopDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->query("CREATE DATABASE `{$routerDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    $coordinator = new PosmainUpdateDatabaseCoordinator(
        $root,
        ['database' => $targets[1]['database'], 'router' => ['enabled' => false]],
        static fn(): array => $targets,
        $managerFactory,
        $targetExecutor
    );
    $publicTargets = $coordinator->publicTargets();
    coordinatorAssert(count($publicTargets) === 2, 'all configured targets must be included');
    coordinatorAssert(!isset($publicTargets[0]['pass']), 'public targets must not expose passwords');

    $before = $coordinator->plan();
    coordinatorAssert($before['target_count'] === 2, 'plan must cover router and shop');
    coordinatorAssert($before['pending_count'] > 0, 'empty databases must report pending work');
    $shopPlan = array_values(array_filter(
        $before['targets'],
        static fn(array $row): bool => $row['target']['kind'] === 'shop'
    ))[0];
    coordinatorAssert(count($shopPlan['schema_pending']) > 0, 'authoritative schema stream must be planned');

    $jobId = 'upd_20260730_120000_' . substr(hash('sha256', $suffix), 0, 6);
    $backupSet = $coordinator->backupAll($jobId);
    coordinatorAssert(count($backupSet['artifacts']) === 2, 'every target must have a backup');
    $manifest = (string) file_get_contents($backupSet['manifest_file']);
    coordinatorAssert(strpos($manifest, $pass) === false || $pass === '', 'manifest must not contain database password');

    $applied = $coordinator->applyAll($backupSet);
    coordinatorAssert($applied['ok'] === true, 'all target migrations must apply');
    coordinatorAssert($applied['verification']['ok'] === true, 'apply must include zero-pending verification');
    $after = $coordinator->plan();
    coordinatorAssert($after['pending_count'] === 0, 'both migration streams must be empty after apply');

    $restored = $coordinator->restoreAll($backupSet);
    coordinatorAssert($restored['ok'] === true, 'restore must attempt every target');
    coordinatorAssert(count($restored['targets']) === 2, 'restore result must cover every target');
    coordinatorAssert($coordinator->deleteBackupSet($backupSet), 'verified backup set must be safely deletable');

    echo "update-database-coordinator-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$shopDatabase}`");
    $conn->query("DROP DATABASE IF EXISTS `{$routerDatabase}`");
    $conn->close();
    removeCoordinatorTree($root);
}

function compactDatabase(string $host, int $port, string $name, string $user, string $pass): array
{
    return compact('host', 'port', 'name', 'user', 'pass') + ['charset' => 'utf8mb4'];
}

function coordinatorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeCoordinatorTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        is_dir($child) ? removeCoordinatorTree($child) : @unlink($child);
    }
    @rmdir($path);
}
