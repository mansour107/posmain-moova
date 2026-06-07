<?php
// ajax/pulse_ajax.php — Pulse AJAX Handler
require_once __DIR__ . '/../includes/connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['login'])) {
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ─── Get types filtered by category ───
    case 'get_types':
        $cat = $_GET['category'] ?? '';
        $sql = "SELECT * FROM pulse_types WHERE isdeleted = 0";
        if ($cat === 'positive' || $cat === 'negative') {
            $sql .= " AND category = '" . $conn->real_escape_string($cat) . "'";
        }
        $sql .= " ORDER BY name ASC";
        $result = $conn->query($sql);
        $types = [];
        while ($row = $result->fetch_assoc()) {
            $types[] = $row;
        }
        echo json_encode($types);
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
            // Get the type points
            $typeRes = $conn->query("SELECT points FROM pulse_types WHERE id = $type_id");
            $typeRow = $typeRes->fetch_assoc();
            $points = $typeRow['points'] ?? 0;

            echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'points' => $points]);
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
        $limit = intval($_GET['limit'] ?? 50);
        $sql = "SELECT pl.*, e.name AS emp_name, pt.name AS type_name, pt.icon AS type_icon, pt.points,
                       u.uname AS recorded_by_name
                FROM pulse_logs pl
                LEFT JOIN employees e ON pl.employee_id = e.id
                LEFT JOIN pulse_types pt ON pl.type_id = pt.id
                LEFT JOIN users u ON pl.recorded_by = u.id
                ORDER BY pl.recorded_at DESC
                LIMIT $limit";
        $result = $conn->query($sql);
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        echo json_encode($logs);
        break;

    // ─── Get stats for leaderboard ───
    case 'get_stats':
        $period = $_GET['period'] ?? 'month';
        $from   = $_GET['from'] ?? '';
        $to     = $_GET['to'] ?? '';

        $dateFilter = '';
        switch ($period) {
            case 'today':
                $dateFilter = "AND DATE(pl.recorded_at) = CURDATE()";
                break;
            case 'week':
                $dateFilter = "AND pl.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $dateFilter = "AND pl.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
            case 'custom':
                if ($from && $to) {
                    $from = $conn->real_escape_string($from);
                    $to   = $conn->real_escape_string($to);
                    $dateFilter = "AND DATE(pl.recorded_at) BETWEEN '$from' AND '$to'";
                }
                break;
            default: // 'all'
                $dateFilter = '';
        }

        // Summary cards
        $summarySQL = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN pl.category='positive' THEN 1 ELSE 0 END) AS positive_count,
            SUM(CASE WHEN pl.category='negative' THEN 1 ELSE 0 END) AS negative_count,
            ROUND(AVG(pl.rating),1) AS avg_rating
            FROM pulse_logs pl WHERE 1 $dateFilter";
        $summaryRes = $conn->query($summarySQL);
        $summary = $summaryRes->fetch_assoc();

        // Leaderboard
        $leaderSQL = "SELECT e.id, e.name,
            SUM(CASE WHEN pl.category='positive' THEN pt.points ELSE 0 END) AS positive_pts,
            SUM(CASE WHEN pl.category='negative' THEN pt.points ELSE 0 END) AS negative_pts,
            SUM(pt.points) AS net_pts,
            COUNT(*) AS total_evals,
            ROUND(AVG(pl.rating),1) AS avg_rating
            FROM pulse_logs pl
            LEFT JOIN employees e ON pl.employee_id = e.id
            LEFT JOIN pulse_types pt ON pl.type_id = pt.id
            WHERE 1 $dateFilter
            GROUP BY e.id, e.name
            ORDER BY net_pts DESC";
        $leaderRes = $conn->query($leaderSQL);
        $leaderboard = [];
        while ($row = $leaderRes->fetch_assoc()) {
            $leaderboard[] = $row;
        }

        // Daily chart data
        $chartSQL = "SELECT DATE(pl.recorded_at) AS day,
            SUM(CASE WHEN pl.category='positive' THEN 1 ELSE 0 END) AS pos,
            SUM(CASE WHEN pl.category='negative' THEN 1 ELSE 0 END) AS neg
            FROM pulse_logs pl WHERE 1 $dateFilter
            GROUP BY DATE(pl.recorded_at)
            ORDER BY day ASC";
        $chartRes = $conn->query($chartSQL);
        $chart = [];
        while ($row = $chartRes->fetch_assoc()) {
            $chart[] = $row;
        }

        // Top types
        $typesSQL = "SELECT pt.name, pt.category, COUNT(*) AS cnt
            FROM pulse_logs pl
            LEFT JOIN pulse_types pt ON pl.type_id = pt.id
            WHERE 1 $dateFilter
            GROUP BY pt.id, pt.name, pt.category
            ORDER BY cnt DESC LIMIT 10";
        $typesRes = $conn->query($typesSQL);
        $topTypes = [];
        while ($row = $typesRes->fetch_assoc()) {
            $topTypes[] = $row;
        }

        echo json_encode([
            'summary'      => $summary,
            'leaderboard'  => $leaderboard,
            'chart'        => $chart,
            'topTypes'     => $topTypes
        ]);
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
