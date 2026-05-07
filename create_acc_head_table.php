<?php
// ملف إنشاء جدول acc_head إذا لم يكن موجوداً
$conn = new mysqli("localhost", "root", "", "focus");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>إنشاء جدول acc_head</h2>";

// التحقق من وجود الجدول
$result = $conn->query("SHOW TABLES LIKE 'acc_head'");

if ($result->num_rows == 0) {
    echo "<p>جدول acc_head غير موجود. جاري الإنشاء...</p>";
    
    $sql = "CREATE TABLE `acc_head` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `code` varchar(50) NOT NULL,
        `aname` varchar(255) NOT NULL,
        `info` text,
        `parent_id` int(11) DEFAULT 0,
        `is_basic` tinyint(1) DEFAULT 0,
        `is_stock` tinyint(1) DEFAULT 0,
        `is_fund` tinyint(1) DEFAULT 0,
        `isdeleted` tinyint(1) DEFAULT 0,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `code` (`code`),
        KEY `parent_id` (`parent_id`),
        KEY `isdeleted` (`isdeleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($sql) === TRUE) {
        echo "<div style='color: green;'>✓ تم إنشاء جدول acc_head بنجاح</div>";
        
        // إضافة بعض البيانات التجريبية
        $sample_data = [
            "INSERT INTO acc_head (code, aname, info, parent_id, is_basic) VALUES ('122', 'العملاء', 'مجموعة العملاء الرئيسية', 0, 1)",
            "INSERT INTO acc_head (code, aname, info, parent_id, is_basic) VALUES ('1221001', 'عميل تجريبي - 01234567890', 'شارع التحرير، القاهرة', 122, 0)"
        ];
        
        foreach ($sample_data as $sql) {
            if ($conn->query($sql) === TRUE) {
                echo "<div style='color: blue;'>✓ تم إضافة بيانات تجريبية</div>";
            } else {
                echo "<div style='color: orange;'>تحذير: " . $conn->error . "</div>";
            }
        }
        
    } else {
        echo "<div style='color: red;'>✗ خطأ في إنشاء الجدول: " . $conn->error . "</div>";
    }
} else {
    echo "<p style='color: green;'>✓ جدول acc_head موجود بالفعل</p>";
    
    // عرض بنية الجدول
    $structure = $conn->query("DESCRIBE acc_head");
    echo "<h3>بنية الجدول:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // عرض عدد السجلات
    $count = $conn->query("SELECT COUNT(*) as count FROM acc_head");
    $count_row = $count->fetch_assoc();
    echo "<p>عدد السجلات في الجدول: " . $count_row['count'] . "</p>";
    
    // عرض العملاء الموجودين
    $customers = $conn->query("SELECT * FROM acc_head WHERE code LIKE '122%' AND isdeleted = 0 LIMIT 5");
    if ($customers->num_rows > 0) {
        echo "<h3>العملاء الموجودين (أول 5):</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Code</th><th>Name</th><th>Info</th></tr>";
        while ($customer = $customers->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $customer['id'] . "</td>";
            echo "<td>" . $customer['code'] . "</td>";
            echo "<td>" . htmlspecialchars($customer['aname']) . "</td>";
            echo "<td>" . htmlspecialchars($customer['info']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا يوجد عملاء في النظام</p>";
    }
}

$conn->close();
?>

<hr>
<p><a href="debug_delivery.php">← العودة لصفحة التشخيص</a></p>