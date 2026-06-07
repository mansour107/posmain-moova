<?php

function kody_merge_e2e_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kody_merge_e2e_db_config(): array
{
    return [
        'host' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307),
        'user' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'pass' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    ];
}

function kody_merge_e2e_connect_server(): ?mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $cfg = kody_merge_e2e_db_config();
    $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], '', $cfg['port']);
    if ($conn->connect_errno) {
        return null;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $conn;
}

function kody_merge_e2e_child_env(string $db): array
{
    $cfg = kody_merge_e2e_db_config();
    return [
        'POSMAIN_DB_HOST' => $cfg['host'],
        'POSMAIN_DB_PORT' => (string) $cfg['port'],
        'POSMAIN_DB_USER' => $cfg['user'],
        'POSMAIN_DB_PASS' => $cfg['pass'],
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_ENV' => 'test',
        'POSMAIN_PRODUCTION_MODE' => '0',
        'POSMAIN_ENABLE_RECIPES' => '0',
        'POSMAIN_RECIPE_AVAILABILITY' => '0',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
    ];
}

function kody_merge_e2e_run_child(string $testFile, string $db, array $payload): array
{
    $command = [
        PHP_BINARY,
        $testFile,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), kody_merge_e2e_child_env($db));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start kody merge e2e child process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Kody merge e2e child failed ({$exitCode}): {$stderr}\n{$stdout}");
    }

    $stdout = (string) $stdout;
    if (trim($stdout) === '') {
        return [];
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Kody merge e2e child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function kody_merge_e2e_create_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT '',
            showpulse INT DEFAULT 1,
            show_customer_visits INT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass) VALUES ('ar', '')");

    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE process (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            show_customer_visits TINYINT(1) NOT NULL DEFAULT 1,
            showpulse TINYINT(1) NOT NULL DEFAULT 1,
            add_sales TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE customer_visits (
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
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE employees (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE pulse_types (
            id INT(11) NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            category ENUM('positive','negative') NOT NULL DEFAULT 'positive',
            icon VARCHAR(50) DEFAULT 'fas fa-star',
            points INT DEFAULT 1,
            isdeleted TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE pulse_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            employee_id INT(11) NOT NULL,
            type_id INT(11) NOT NULL,
            category ENUM('positive','negative') NOT NULL,
            rating INT DEFAULT 5,
            notes TEXT,
            recorded_by INT(11) NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            iname VARCHAR(200) NOT NULL,
            name2 VARCHAR(200) NULL,
            code INT(11) NULL,
            barcode VARCHAR(25) NULL,
            price1 FLOAT NOT NULL DEFAULT 0,
            price2 FLOAT NOT NULL DEFAULT 0,
            price3 FLOAT NOT NULL DEFAULT 0,
            group1 INT NOT NULL DEFAULT 0,
            group2 INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            item_type VARCHAR(32) NOT NULL DEFAULT 'sellable',
            user INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE item_units (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            unit_id INT NOT NULL DEFAULT 1,
            u_val DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            unit_barcode VARCHAR(64) NULL,
            price1 FLOAT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE item_availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            channel VARCHAR(40) NOT NULL DEFAULT 'all',
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            unavailable_reason VARCHAR(255) NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_availability_scope (item_id, tenant, branch, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function kody_merge_e2e_seed_rows(mysqli $conn): void
{
    $conn->query("INSERT INTO usr_pwrs (id, rollname, show_customer_visits, showpulse, add_sales, isdeleted)
                  VALUES (1, 'admin', 1, 1, 1, 0)");

    $conn->query("INSERT INTO customer_visits (id, gender, age_group, mode, start_time, order_value, visit_type, created_by)
                  VALUES (1, 'male', '18_25', 'solo', '10:00:00', 'under60', 'new', 7)");
    $conn->query("INSERT INTO customer_visits (id, gender, age_group, mode, start_time, order_value, visit_type, created_by)
                  VALUES (2, 'female', '25_40', 'group', '11:00:00', 'over60', 'returning', 7)");

    $conn->query("INSERT INTO employees (id, name, isdeleted) VALUES (1, 'E2E Employee', 0)");
    $conn->query("INSERT INTO pulse_types (id, name, category, icon, points, isdeleted)
                  VALUES (1, 'جودة العمل', 'positive', 'fas fa-award', 5, 0)");

    $conn->query("INSERT INTO myitems (id, iname, code, barcode, price1, isdeleted, is_active, item_type)
                  VALUES (1001, 'E2E Available Item', 910001, 'E2E-AVAIL-001', 35, 0, 1, 'sellable')");
    $conn->query("INSERT INTO myitems (id, iname, code, barcode, price1, isdeleted, is_active, item_type)
                  VALUES (1002, 'E2E Inactive Item', 910002, 'E2E-INACT-002', 20, 0, 0, 'sellable')");
    $conn->query("INSERT INTO myitems (id, iname, code, barcode, price1, isdeleted, is_active, item_type)
                  VALUES (1003, 'E2E Ingredient Item', 910003, 'E2E-INGR-003', 10, 0, 1, 'ingredient')");
    $conn->query("INSERT INTO myitems (id, iname, code, barcode, price1, isdeleted, is_active, item_type)
                  VALUES (1004, 'E2E Blocked Item', 910004, 'E2E-BLOCK-004', 50, 0, 1, 'sellable')");
    $conn->query("INSERT INTO item_availability (item_id, tenant, branch, channel, is_available, unavailable_reason)
                  VALUES (1004, 0, 0, 'pos', 0, 'Blocked for E2E test')");
}

function kody_merge_e2e_bootstrap_session(array $options = []): void
{
    $csrfNamespaces = (array) ($options['csrf_namespaces'] ?? []);
    $tokens = [];
    foreach ($csrfNamespaces as $namespace) {
        $tokens[$namespace] = 'kody-e2e-csrf-' . $namespace . '-' . getmypid();
    }

    $_SESSION = [
        'login' => $options['login'] ?? 'kody_e2e_user',
        'userid' => (int) ($options['userid'] ?? 7),
        'user_id' => (int) ($options['userid'] ?? 7),
        'usrole' => 1,
        'userrole' => 1,
        'usty' => 2,
        'posmain_csrf_tokens' => $tokens,
    ];

    if (!empty($options['pos_authenticated'])) {
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = (int) ($options['userid'] ?? 7);
        $_SESSION['pos_user_name'] = 'kody_e2e_pos';
    }
}

function kody_merge_e2e_reset_request_state(): void
{
    $_GET = [];
    $_POST = [];
    unset(
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'],
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_ACCEPT']
    );
}

function kody_merge_e2e_child_finish(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

function kody_merge_e2e_invoke_endpoint(string $path): array
{
    ob_start();
    require $path;
    $raw = (string) ob_get_clean();
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return ['body' => $raw];
}
