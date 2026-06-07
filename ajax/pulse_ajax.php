<?php
// ajax/pulse_ajax.php — Pulse AJAX Handler
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!isset($_SESSION['login'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$writeActions = ['save_log', 'delete_log', 'save_type', 'delete_type'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $writeActions, true)) {
    require_csrf('pulse');
}

function pulse_stats_date_filter(string $period, string $from, string $to, array &$params, string &$types): string
{
    switch ($period) {
        case 'today':
            return 'AND DATE(pl.recorded_at) = CURDATE()';
        case 'week':
            return 'AND pl.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        case 'month':
            return 'AND pl.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        case 'custom':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return '';
            }
            $params[] = $from;
            $params[] = $to;
            $types .= 'ss';
            return 'AND DATE(pl.recorded_at) BETWEEN ? AND ?';
        default:
            return '';
    }
}

function pulse_stats_fetch_all(mysqli $conn, string $sql, array $params, string $types): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function pulse_stats_fetch_one(mysqli $conn, string $sql, array $params, string $types): array
{
    $rows = pulse_stats_fetch_all($conn, $sql, $params, $types);
    return $rows[0] ?? [];
}

switch ($action) {

    // ─── Get types filtered by category ───
    case 'get_types':
        $cat = $_GET['category'] ?? '';
        if ($cat === 'positive' || $cat === 'negative') {
            $stmt = $conn->prepare("SELECT * FROM pulse_types WHERE isdeleted = 0 AND category = ? ORDER BY name ASC");
            $stmt->bind_param('s', $cat);
        } else {
            $stmt = $conn->prepare("SELECT * FROM pulse_types WHERE isdeleted = 0 ORDER BY name ASC");
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $types = [];
        while ($row = $result->fetch_assoc()) {
            $types[] = $row;
        }
        $stmt->close();
        echo json_encode($types, JSON_UNESCAPED_UNICODE);
        break;

    // ─── Save a new pulse log ───
    case 'save_log':
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $type_id     = intval($_POST['type_id'] ?? 0);
        $category    = $_POST['category'] ?? '';
        $rating      = intval($_POST['rating'] ?? 5);
        $notes       = trim($_POST['notes'] ?? '');
        $recorded_by = intval($_SESSION['userid'] ?? 0);

        if ($employee_id <= 0 || $type_id <= 0 || !in_array($category, ['positive','negative'])) {
            echo json_encode(['error' => 'بيانات غير صالحة']);
            exit;
        }

        $rating = max(1, min(10, $rating));

        $stmt = $conn->prepare("INSERT INTO pulse_logs (employee_id, type_id, category, rating, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisisi", $employee_id, $type_id, $category, $rating, $notes, $recorded_by);

        if ($stmt->execute()) {
            $points = 0;
            $pointsStmt = $conn->prepare('SELECT points FROM pulse_types WHERE id = ? LIMIT 1');
            if ($pointsStmt) {
                $pointsStmt->bind_param('i', $type_id);
                $pointsStmt->execute();
                $typeRes = $pointsStmt->get_result();
                $typeRow = $typeRes ? $typeRes->fetch_assoc() : null;
                $points = (int) ($typeRow['points'] ?? 0);
                $pointsStmt->close();
            }

            echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'points' => $points], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'فشل في الحفظ']);
        }
        $stmt->close();
        break;

    // ─── Delete a pulse log ───
    case 'delete_log':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'معرف غير صالح']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM pulse_logs WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'فشل في الحذف']);
        }
        $stmt->close();
        break;

    // ─── Get recent logs ───
    case 'get_logs':
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $stmt = $conn->prepare(
            "SELECT pl.*, e.name AS emp_name, pt.name AS type_name, pt.icon AS type_icon, pt.points,
                    u.uname AS recorded_by_name
             FROM pulse_logs pl
             LEFT JOIN employees e ON pl.employee_id = e.id
             LEFT JOIN pulse_types pt ON pl.type_id = pt.id
             LEFT JOIN users u ON pl.recorded_by = u.id
             ORDER BY pl.recorded_at DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
        echo json_encode($logs, JSON_UNESCAPED_UNICODE);
        break;

    // ─── Get stats for leaderboard ───
    case 'get_stats':
        $period = (string) ($_GET['period'] ?? 'month');
        $from   = trim((string) ($_GET['from'] ?? ''));
        $to     = trim((string) ($_GET['to'] ?? ''));

        $params = [];
        $types = '';
        $dateFilter = pulse_stats_date_filter($period, $from, $to, $params, $types);

        $summary = pulse_stats_fetch_one(
            $conn,
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN pl.category='positive' THEN 1 ELSE 0 END) AS positive_count,
                SUM(CASE WHEN pl.category='negative' THEN 1 ELSE 0 END) AS negative_count,
                ROUND(AVG(pl.rating),1) AS avg_rating
             FROM pulse_logs pl
             WHERE 1=1 {$dateFilter}",
            $params,
            $types
        );

        $leaderboard = pulse_stats_fetch_all(
            $conn,
            "SELECT e.id, e.name,
                SUM(CASE WHEN pl.category='positive' THEN pt.points ELSE 0 END) AS positive_pts,
                SUM(CASE WHEN pl.category='negative' THEN pt.points ELSE 0 END) AS negative_pts,
                SUM(pt.points) AS net_pts,
                COUNT(*) AS total_evals,
                ROUND(AVG(pl.rating),1) AS avg_rating
             FROM pulse_logs pl
             LEFT JOIN employees e ON pl.employee_id = e.id
             LEFT JOIN pulse_types pt ON pl.type_id = pt.id
             WHERE 1=1 {$dateFilter}
             GROUP BY e.id, e.name
             ORDER BY net_pts DESC",
            $params,
            $types
        );

        $chart = pulse_stats_fetch_all(
            $conn,
            "SELECT DATE(pl.recorded_at) AS day,
                SUM(CASE WHEN pl.category='positive' THEN 1 ELSE 0 END) AS pos,
                SUM(CASE WHEN pl.category='negative' THEN 1 ELSE 0 END) AS neg
             FROM pulse_logs pl
             WHERE 1=1 {$dateFilter}
             GROUP BY DATE(pl.recorded_at)
             ORDER BY day ASC",
            $params,
            $types
        );

        $topTypes = pulse_stats_fetch_all(
            $conn,
            "SELECT pt.name, pt.category, COUNT(*) AS cnt
             FROM pulse_logs pl
             LEFT JOIN pulse_types pt ON pl.type_id = pt.id
             WHERE 1=1 {$dateFilter}
             GROUP BY pt.id, pt.name, pt.category
             ORDER BY cnt DESC
             LIMIT 10",
            $params,
            $types
        );

        echo json_encode([
            'summary'      => $summary,
            'leaderboard'  => $leaderboard,
            'chart'        => $chart,
            'topTypes'     => $topTypes,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ─── CRUD for pulse_types ───
    case 'save_type':
        $id       = intval($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'positive';
        $icon     = trim($_POST['icon'] ?? 'fas fa-star');
        $points   = intval($_POST['points'] ?? 1);

        if (empty($name)) {
            echo json_encode(['error' => 'الاسم مطلوب']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE pulse_types SET name=?, category=?, icon=?, points=? WHERE id=?");
            $stmt->bind_param("sssii", $name, $category, $icon, $points, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO pulse_types (name, category, icon, points) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $name, $category, $icon, $points);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $id > 0 ? $id : $stmt->insert_id]);
        } else {
            echo json_encode(['error' => 'فشل في الحفظ']);
        }
        $stmt->close();
        break;

    case 'delete_type':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'معرف غير صالح']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE pulse_types SET isdeleted = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'فشل في الحذف']);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['error' => 'إجراء غير معروف']);
}
