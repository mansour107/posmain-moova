<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';

$options = getopt('', ['json', 'include-cutover', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/shop_health_sweep.php [--include-cutover] [--json]\n");
    fwrite(STDOUT, "Checks public health and optional cutover readiness for default DB and router shops.\n");
    exit(0);
}

$shops = shopHealthSweepShops();
$results = [];
$healthy = true;

foreach ($shops as $shop) {
    $slug = (string) ($shop['slug'] ?? 'default');
    $dbName = (string) ($shop['db_name'] ?? '');
    $entry = [
        'slug' => $slug,
        'db_name' => $dbName,
        'health' => shopHealthSweepHealthCheck(),
    ];
    if (empty($entry['health']['ok'])) {
        $healthy = false;
    }
    if (isset($options['include-cutover']) && $dbName !== '') {
        $entry['cutover'] = shopHealthSweepCutoverCheck($dbName);
        if (empty($entry['cutover']['ready_for_cutover'])) {
            $healthy = false;
        }
    }
    $results[] = $entry;
}

$payload = [
    'ok' => $healthy,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'shop_count' => count($results),
    'shops' => $results,
];

if (isset($options['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, 'Shop health sweep: ' . ($healthy ? 'OK' : 'ISSUES') . PHP_EOL);
    foreach ($results as $entry) {
        fwrite(STDOUT, '- ' . $entry['slug'] . ' health=' . (!empty($entry['health']['ok']) ? 'ok' : 'fail'));
        if (isset($entry['cutover'])) {
            fwrite(STDOUT, ' cutover=' . (!empty($entry['cutover']['ready_for_cutover']) ? 'ready' : 'blocked');
        }
        fwrite(STDOUT, PHP_EOL);
    }
}

exit($healthy ? 0 : 2);

function shopHealthSweepShops(): array
{
    $unique = [];
    $defaultDb = (string) (posmain_app_config()['database']['name'] ?? 'kody2');
    $unique[$defaultDb] = ['slug' => 'default', 'db_name' => $defaultDb];

    $config = posmain_app_config();
    if (empty($config['router']['enabled'])) {
        return array_values($unique);
    }

    try {
        require_once __DIR__ . '/../classes/Router/ShopRouter.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $routerDb = (array) ($config['router']['database'] ?? []);
        $routerConn = new mysqli(
            (string) ($routerDb['host'] ?? ''),
            (string) ($routerDb['user'] ?? ''),
            (string) ($routerDb['pass'] ?? ''),
            (string) ($routerDb['name'] ?? ''),
            (int) ($routerDb['port'] ?? 3306)
        );
        foreach ((new ShopRouter())->listActiveShops($routerConn) as $row) {
            $dbName = trim((string) ($row['db_name'] ?? ''));
            if ($dbName === '') {
                continue;
            }
            $unique[$dbName] = [
                'slug' => (string) ($row['slug'] ?? $dbName),
                'db_name' => $dbName,
            ];
        }
        $routerConn->close();
    } catch (Throwable $exception) {
        return array_values($unique);
    }

    return array_values($unique);
}

function shopHealthSweepHealthCheck(): array
{
    $base = rtrim((string) (posmain_app_config()['public_base_url'] ?? ''), '/');
    if ($base === '') {
        return ['ok' => false, 'error' => 'public_base_url_missing'];
    }
    $url = $base . '/api/health.php';
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return ['ok' => false, 'url' => $url, 'error' => 'health_fetch_failed'];
    }
    $payload = json_decode($body, true);

    return [
        'ok' => is_array($payload) && !empty($payload['healthy']),
        'url' => $url,
        'http_ok' => is_array($payload) && !empty($payload['ok']),
    ];
}

function shopHealthSweepCutoverCheck(string $dbName): array
{
    require_once __DIR__ . '/../classes/Inventory/InventoryCutoverReadinessService.php';
    $previous = getenv('POSMAIN_DB_NAME');
    putenv('POSMAIN_DB_NAME=' . $dbName);
    $_ENV['POSMAIN_DB_NAME'] = $dbName;
    try {
        $conn = posmain_db_connect();
        $review = (new InventoryCutoverReadinessService())->review($conn, []);
        $conn->close();

        return [
            'ready_for_cutover' => !empty($review['ready_for_cutover']),
            'blockers' => $review['blockers'] ?? [],
        ];
    } catch (Throwable $exception) {
        return [
            'ready_for_cutover' => false,
            'blockers' => ['cutover_check_failed'],
            'error' => $exception->getMessage(),
        ];
    } finally {
        if ($previous === false) {
            putenv('POSMAIN_DB_NAME');
            unset($_ENV['POSMAIN_DB_NAME']);
        } else {
            putenv('POSMAIN_DB_NAME=' . $previous);
            $_ENV['POSMAIN_DB_NAME'] = $previous;
        }
    }
}
