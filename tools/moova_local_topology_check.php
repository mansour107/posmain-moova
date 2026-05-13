<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This topology check must be run from the command line.\n");
    exit(2);
}

$options = moova_topology_parse_args($argv);
if (!empty($options['help'])) {
    echo "Usage: php tools/moova_local_topology_check.php [--fail-on-down]\n";
    echo "       [--pos-url=http://127.0.0.1:8010/index.php]\n";
    echo "       [--moova-url=http://127.0.0.1:3001]\n";
    exit(0);
}

$report = moova_topology_report($options);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($options['fail_on_down']) && empty($report['ok']) ? 1 : 0);

function moova_topology_report(array $options): array
{
    $posUrl = rtrim((string) ($options['pos_url'] ?? 'http://127.0.0.1:8010/index.php'), '/');
    $moovaUrl = rtrim((string) ($options['moova_url'] ?? 'http://127.0.0.1:3001'), '/');
    $checks = [
        'pos_tcp' => moova_topology_tcp_check($posUrl),
        'pos_http' => moova_topology_http_check($posUrl),
        'moova_tcp' => moova_topology_tcp_check($moovaUrl),
        'moova_readyz' => moova_topology_http_check($moovaUrl . '/readyz'),
        'moova_pos_widget' => moova_topology_http_check($moovaUrl . '/pos-widget'),
        'docker' => moova_topology_docker_hints(),
        'pos_db_moova_links' => moova_topology_pos_db_links($options),
    ];

    $diagnosis = moova_topology_diagnosis($checks, $moovaUrl);
    $ok = empty(array_filter($diagnosis, static fn ($item) => ($item['severity'] ?? '') === 'error'));

    return [
        'ok' => $ok,
        'checked_at' => gmdate('c'),
        'inputs' => [
            'pos_url' => $posUrl,
            'moova_url' => $moovaUrl,
        ],
        'checks' => $checks,
        'diagnosis' => $diagnosis,
    ];
}

function moova_topology_parse_args(array $argv): array
{
    $options = [
        'pos_url' => getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010/index.php',
        'moova_url' => getenv('POSMAIN_LOCAL_MOOVA_URL') ?: 'http://127.0.0.1:3001',
        'mysql_host' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'mysql_port' => (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307),
        'mysql_user' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'mysql_pass' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'mysql_db' => getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2',
        'fail_on_down' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--fail-on-down') {
            $options['fail_on_down'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--pos-url=')) {
            $options['pos_url'] = substr($arg, strlen('--pos-url='));
            continue;
        }
        if (str_starts_with($arg, '--moova-url=')) {
            $options['moova_url'] = substr($arg, strlen('--moova-url='));
            continue;
        }
        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

function moova_topology_tcp_check(string $url): array
{
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($host === '') {
        return ['ok' => false, 'host' => $host, 'port' => $port, 'error' => 'missing_host'];
    }

    $errorNo = 0;
    $error = '';
    $socket = @fsockopen($host, $port, $errorNo, $error, 2.0);
    if (is_resource($socket)) {
        fclose($socket);
        return ['ok' => true, 'host' => $host, 'port' => $port];
    }

    return [
        'ok' => false,
        'host' => $host,
        'port' => $port,
        'error' => $error ?: 'connection_failed',
        'error_no' => $errorNo,
    ];
}

function moova_topology_http_check(string $url): array
{
    $error = null;
    set_error_handler(static function ($severity, $message) use (&$error): bool {
        $error = $message;
        return true;
    });
    $raw = file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]));
    restore_error_handler();

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [
        'ok' => $raw !== false && $status >= 200 && $status < 400,
        'url' => $url,
        'http_status' => $status,
        'content_length' => $raw === false ? 0 : strlen($raw),
        'error' => $raw === false ? ($error ?: 'request_failed') : null,
    ];
}

function moova_topology_docker_hints(): array
{
    $raw = shell_exec("docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null");
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'containers' => [], 'error' => 'docker_unavailable_or_no_matching_output'];
    }

    $containers = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if ($line === '' || !preg_match('/posmain|cofe|moova|redis|postgres/i', $line)) {
            continue;
        }
        [$name, $status, $ports] = array_pad(explode("\t", $line, 3), 3, '');
        $containers[] = [
            'name' => $name,
            'status' => $status,
            'ports' => $ports,
        ];
    }

    return [
        'ok' => count($containers) > 0,
        'containers' => $containers,
    ];
}

function moova_topology_pos_db_links(array $options): array
{
    if (!class_exists('mysqli')) {
        return ['ok' => false, 'error' => 'mysqli_missing', 'links' => []];
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(
        (string) $options['mysql_host'],
        (string) $options['mysql_user'],
        (string) $options['mysql_pass'],
        (string) $options['mysql_db'],
        (int) $options['mysql_port']
    );
    if ($conn->connect_error) {
        return ['ok' => false, 'error' => $conn->connect_error, 'links' => []];
    }
    $conn->set_charset('utf8mb4');

    $columns = moova_topology_table_columns($conn, 'moova_pos_shop_links');
    if (!$columns) {
        $conn->close();
        return ['ok' => false, 'error' => 'moova_pos_shop_links_missing', 'links' => []];
    }

    $selectable = array_values(array_intersect([
        'id',
        'status',
        'moova_shop_id',
        'moova_branch_id',
        'moova_device_token_last4',
        'pos_tenant',
        'pos_branch',
        'widget_url',
        'locale',
        'updated_at',
    ], $columns));
    $where = in_array('status', $columns, true) ? "WHERE status = 'active'" : '';
    $sql = 'SELECT ' . implode(', ', array_map(static fn ($column) => '`' . $column . '`', $selectable)) . '
            FROM moova_pos_shop_links
            ' . $where . '
            ORDER BY id DESC
            LIMIT 5';
    $result = $conn->query($sql);
    $links = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $links[] = $row;
        }
        $result->free();
    }
    $conn->close();

    return [
        'ok' => count($links) > 0,
        'links' => $links,
    ];
}

function moova_topology_table_columns(mysqli $conn, string $table): array
{
    $safeTable = str_replace('`', '``', $table);
    $result = $conn->query('SHOW COLUMNS FROM `' . $safeTable . '`');
    if (!$result) {
        return [];
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['Field'];
    }
    $result->free();

    return $columns;
}

function moova_topology_diagnosis(array $checks, string $moovaUrl): array
{
    $items = [];
    if (empty($checks['pos_tcp']['ok'])) {
        $items[] = [
            'severity' => 'error',
            'code' => 'POS_TCP_DOWN',
            'message' => 'POS port is not accepting TCP connections.',
        ];
    } elseif (empty($checks['pos_http']['ok'])) {
        $items[] = [
            'severity' => 'warning',
            'code' => 'POS_HTTP_UNHEALTHY',
            'message' => 'POS port is open, but the POS HTTP page did not return a normal response.',
        ];
    }

    if (empty($checks['moova_tcp']['ok'])) {
        $items[] = [
            'severity' => 'error',
            'code' => 'MOOVA_TCP_DOWN',
            'message' => 'Moova/Cofe service is not accepting TCP connections.',
        ];
    } elseif (empty($checks['moova_readyz']['ok'])) {
        $items[] = [
            'severity' => 'error',
            'code' => 'MOOVA_READYZ_DOWN',
            'message' => 'Moova/Cofe service is listening, but /readyz is not healthy.',
        ];
    }

    if (empty($checks['pos_db_moova_links']['ok'])) {
        $items[] = [
            'severity' => 'error',
            'code' => 'MOOVA_POS_LINK_MISSING',
            'message' => 'POS database does not have an active Moova link row.',
        ];
    }

    $links = $checks['pos_db_moova_links']['links'] ?? [];
    foreach ($links as $link) {
        $widgetUrl = (string) ($link['widget_url'] ?? '');
        if ($widgetUrl !== '' && str_contains($widgetUrl, '3001') && empty($checks['moova_tcp']['ok'])) {
            $items[] = [
                'severity' => 'error',
                'code' => 'ACTIVE_LINK_POINTS_TO_DOWN_MOOVA',
                'message' => 'Active POS Moova link points to port 3001, but the Moova/Cofe service is down.',
                'widget_url' => $widgetUrl,
                'expected_moova_url' => $moovaUrl,
            ];
            break;
        }
    }

    if (!$items) {
        $items[] = [
            'severity' => 'info',
            'code' => 'TOPOLOGY_LOOKS_REACHABLE',
            'message' => 'POS and Moova local topology checks passed.',
        ];
    }

    return $items;
}
