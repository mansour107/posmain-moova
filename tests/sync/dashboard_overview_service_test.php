<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Dashboard/DashboardOverviewService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "dashboard-overview-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_dashboard_overview_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    $conn->query('CREATE TABLE ot_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pro_tybe INT NOT NULL,
        pro_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        pro_date DATE NOT NULL,
        isdeleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE myinstallments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ins_case INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        isdeleted TINYINT NULL
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        duration INT NULL
    ) ENGINE=InnoDB');
    $conn->query("CREATE TABLE drawer_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'open'
    ) ENGINE=InnoDB");

    $today = date('Y-m-d');
    $conn->query("INSERT INTO ot_head (pro_tybe, pro_value, pro_date, isdeleted) VALUES
        (3, 100.00, '{$today}', 0),
        (9, 50.00, '{$today}', 0),
        (3, 999.00, '{$today}', 1),
        (3, 200.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 0),
        (9, 25.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 0)");
    $conn->query('INSERT INTO myinstallments (ins_case) VALUES (2), (2), (3)');
    $conn->query('INSERT INTO tasks (isdeleted) VALUES (NULL), (NULL), (1)');
    $conn->query('INSERT INTO reservations (duration) VALUES (30), (NULL), (NULL)');
    $conn->query("INSERT INTO drawer_sessions (status) VALUES ('open'), ('closed')");

    $service = new DashboardOverviewService();
    $flags = [
        'sid_rents' => true,
        'sid_hr' => true,
        'clinic.enabled' => true,
        'sid_clinics' => true,
        'reports.cash_flow' => true,
        'menu.edit' => true,
        'reports.view' => true,
        'accounting.view' => true,
    ];
    $overview = $service->build($conn, $flags);

    dashboardServiceAssert((bool) $overview['kpis'][0]['available'], 'sales KPIs available');
    dashboardServiceAssert((int) $overview['kpis'][1]['value'] === 2, 'today order count excludes deleted');
    dashboardServiceAssert(abs((float) $overview['kpis'][0]['value'] - 150.0) < 0.01, 'today sales sum excludes deleted');
    dashboardServiceAssert(abs((float) $overview['kpis'][2]['value'] - 75.0) < 0.01, 'today AOV');

    $types = array_column($overview['attention'], 'type');
    dashboardServiceAssert(in_array('overdue_installments', $types, true), 'installments attention');
    dashboardServiceAssert(in_array('pending_tasks', $types, true), 'tasks attention');
    dashboardServiceAssert(in_array('pending_reservations', $types, true), 'reservations attention');
    dashboardServiceAssert(in_array('open_drawers', $types, true), 'open drawers attention');

    $pendingReservations = null;
    foreach ($overview['attention'] as $row) {
        if ($row['type'] === 'pending_reservations') {
            $pendingReservations = $row;
            break;
        }
    }
    dashboardServiceAssert(
        $pendingReservations !== null && (int) $pendingReservations['count'] === 2,
        'pending reservations count excludes completed visits'
    );

    $installment = null;
    foreach ($overview['attention'] as $row) {
        if ($row['type'] === 'overdue_installments') {
            $installment = $row;
            break;
        }
    }
    dashboardServiceAssert($installment !== null && (int) $installment['count'] === 2, 'installment count');
    dashboardServiceAssert($installment['url'] === 'myrentables.php', 'installment url');

    $clinicHiddenFlags = $flags;
    $clinicHiddenFlags['clinic.enabled'] = false;
    $clinicHidden = $service->build($conn, $clinicHiddenFlags);
    $clinicHiddenTypes = array_column($clinicHidden['attention'], 'type');
    dashboardServiceAssert(
        !in_array('pending_reservations', $clinicHiddenTypes, true),
        'hidden clinic module excludes reservation attention'
    );
    dashboardServiceAssert(
        in_array('open_drawers', $clinicHiddenTypes, true),
        'hiding clinic leaves adjacent dashboard attention intact'
    );

    $noFlags = $service->build($conn, []);
    dashboardServiceAssert($noFlags['attention'] === [], 'attention empty without flags');
    dashboardServiceAssert($noFlags['quick_actions'] === [], 'quick actions empty without flags');

    $conn->query('DROP TABLE ot_head');
    $missingSales = $service->loadSalesMetrics($conn);
    dashboardServiceAssert($missingSales['available'] === false, 'missing ot_head marks sales unavailable');
    $kpisMissing = $service->buildKpis($missingSales);
    dashboardServiceAssert(
        $kpisMissing[0]['formatted'] === DashboardOverviewService::UNAVAILABLE_LABEL,
        'missing sales shows غير متاح'
    );

    echo "dashboard-overview-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function dashboardServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "dashboard-overview-service-fail: {$message}\n");
        exit(1);
    }
}
