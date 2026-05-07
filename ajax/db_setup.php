<?php
// ajax/db_setup.php - Database Setup Backend
header('Content-Type: application/json');

$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'kody2';

mysqli_report(MYSQLI_REPORT_OFF);

function execute_sql_file($conn, $file_path) {
    if (!file_exists($file_path)) {
        return ["success" => false, "message" => "ملف SQL غير موجود: " . basename($file_path)];
    }

    $lines = file($file_path);
    if ($lines === false) {
        return ["success" => false, "message" => "فشل في قراءة ملف SQL"];
    }

    $query = '';
    $delimiter = ';';
    $in_multi_line_comment = false;

    foreach ($lines as $line) {
        $line = trim($line);
        
        if ($line === '') continue;

        // Multi-line comment handling
        if (!$in_multi_line_comment && str_starts_with($line, '/*')) {
            if (!str_contains($line, '*/')) {
                $in_multi_line_comment = true;
            }
            continue;
        }
        if ($in_multi_line_comment) {
            if (str_contains($line, '*/')) {
                $in_multi_line_comment = false;
            }
            continue;
        }

        // Single line comments
        if (str_starts_with($line, '--') || str_starts_with($line, '#')) {
            continue;
        }

        // Check for delimiter change
        if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }

        $query .= $line . " ";

        // If line ends with current delimiter, execute
        if (str_ends_with($line, $delimiter)) {
            // Remove delimiter from end of query for execution
            $exec_query = substr(trim($query), 0, -strlen($delimiter));
            
            if ($exec_query !== '') {
                if (!$conn->query($exec_query)) {
                    return [
                        "success" => false, 
                        "message" => "خطأ في تنفيذ SQL: " . $conn->error . " <br> في الاستعلام: " . substr($exec_query, 0, 150) . "..."
                    ];
                }
            }
            $query = '';
        }
    }

    return ["success" => true, "message" => "تم تهيئة قاعدة البيانات بنجاح"];
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $conn = @new mysqli($dbhost, $dbuser, $dbpass);
    if ($conn->connect_error) {
        echo json_encode(["success" => false, "message" => "فشل الاتصال بـ MySQL: " . $conn->connect_error]);
        exit;
    }

    // Create database
    $sql_create = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$conn->query($sql_create)) {
        echo json_encode(["success" => false, "message" => "فشل إنشاء قاعدة البيانات: " . $conn->error]);
        exit;
    }

    $conn->select_db($dbname);
    
    // Disable foreign key checks for import
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Path to db.sql
    $sql_file = "../db/db.sql";
    if (!file_exists($sql_file)) {
        // Fallback to backup if db/db.sql not found (should be there based on previous command)
        $sql_file = "../backup/DB.sql";
    }

    $result = execute_sql_file($conn, $sql_file);

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo json_encode($result);
    $conn->close();

} elseif ($action === 'restore') {
    if (!isset($_FILES['backup_file'])) {
        echo json_encode(["success" => false, "message" => "لم يتم رفع أي ملف"]);
        exit;
    }

    $file = $_FILES['backup_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "message" => "خطأ في رفع الملف: " . $file['error']]);
        exit;
    }

    $conn = @new mysqli($dbhost, $dbuser, $dbpass);
    if ($conn->connect_error) {
        echo json_encode(["success" => false, "message" => "فشل الاتصال بـ MySQL: " . $conn->connect_error]);
        exit;
    }

    // Create database if not exists
    $sql_create = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    $conn->query($sql_create);
    $conn->select_db($dbname);

    // Disable foreign key checks for import
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $result = execute_sql_file($conn, $file['tmp_name']);

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo json_encode($result);
    $conn->close();

} else {
    echo json_encode(["success" => false, "message" => "إجراء غير صالح"]);
}
