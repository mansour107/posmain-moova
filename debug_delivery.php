<?php
// ملف تشخيص مشكلة الدليفري
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تشخيص مشكلة الدليفري</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <h1 class="text-center mb-4">تشخيص مشكلة الدليفري</h1>
        
        <?php
        echo "<div class='card mb-3'>";
        echo "<div class='card-header bg-primary text-white'><h5>1. اختبار الاتصال بقاعدة البيانات</h5></div>";
        echo "<div class='card-body'>";
        
        try {
            $conn = new mysqli("localhost", "root", "", "focus");
            
            if ($conn->connect_error) {
                echo "<div class='alert alert-danger'>فشل الاتصال: " . $conn->connect_error . "</div>";
            } else {
                echo "<div class='alert alert-success'>✓ تم الاتصال بقاعدة البيانات بنجاح</div>";
                
                // التحقق من وجود جدول acc_head
                $result = $conn->query("SHOW TABLES LIKE 'acc_head'");
                if ($result->num_rows > 0) {
                    echo "<div class='alert alert-success'>✓ جدول acc_head موجود</div>";
                    
                    // عرض عدد العملاء
                    $count = $conn->query("SELECT COUNT(*) as count FROM acc_head WHERE code LIKE '122%' AND isdeleted = 0");
                    if ($count) {
                        $count_row = $count->fetch_assoc();
                        echo "<div class='alert alert-info'>عدد العملاء الموجودين: " . $count_row['count'] . "</div>";
                    }
                    
                } else {
                    echo "<div class='alert alert-danger'>✗ جدول acc_head غير موجود!</div>";
                    
                    // عرض الجداول الموجودة
                    $tables = $conn->query("SHOW TABLES");
                    echo "<h6>الجداول الموجودة:</h6><ul>";
                    while ($table = $tables->fetch_array()) {
                        echo "<li>" . $table[0] . "</li>";
                    }
                    echo "</ul>";
                }
            }
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>خطأ: " . $e->getMessage() . "</div>";
        }
        
        echo "</div></div>";
        
        // اختبار ملفات PHP
        echo "<div class='card mb-3'>";
        echo "<div class='card-header bg-info text-white'><h5>2. اختبار ملفات PHP</h5></div>";
        echo "<div class='card-body'>";
        
        $files_to_check = [
            'ajax/pos_customer_search.php',
            'ajax/pos_customer_save.php',
            'classes/Pos/Service/PosCustomerService.php',
        ];
        
        foreach ($files_to_check as $file) {
            if (file_exists($file)) {
                echo "<div class='alert alert-success'>✓ الملف موجود: $file</div>";
            } else {
                echo "<div class='alert alert-danger'>✗ الملف مفقود: $file</div>";
            }
        }
        
        echo "</div></div>";
        
        // اختبار AJAX
        echo "<div class='card mb-3'>";
        echo "<div class='card-header bg-warning text-dark'><h5>3. اختبار AJAX</h5></div>";
        echo "<div class='card-body'>";
        ?>
        
        <p class="text-muted small">اختبار CRM يتطلب تسجيل الدخول — استخدم <a href="pos_customers.php">شاشة العملاء</a> أو نقطة البيع.</p>
        <div class="row">
            <div class="col-md-12">
                <h6>اختبار مباشر عبر الخدمة (بدون جلسة):</h6>
                <?php
                try {
                    require_once __DIR__ . '/includes/db_bootstrap.php';
                    require_once __DIR__ . '/classes/Pos/Service/PosCustomerService.php';
                    require_once __DIR__ . '/includes/pos_customer_bootstrap.php';
                    $diagConn = posmain_db_connect();
                    posmain_ensure_pos_customer_schema($diagConn);
                    $diagService = new PosCustomerService();
                    $diagSearch = $diagService->searchByPhone($diagConn, '01000000000');
                    echo '<div class="alert alert-info">PosCustomerService::searchByPhone — '
                        . (empty($diagSearch['exact']) ? 'لا نتائج (متوقع لرقم وهمي)' : 'وجد عميل')
                        . '</div>';
                    $diagConn->close();
                } catch (Throwable $e) {
                    echo '<div class="alert alert-danger">CRM: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>
            </div>
        </div>
        
        <?php
        echo "</div></div>";
        ?>
        
        <div class="card">
            <div class='card-header bg-secondary text-white'><h5>4. معلومات النظام</h5></div>
            <div class='card-body'>
                <p><strong>إصدار PHP:</strong> <?php echo phpversion(); ?></p>
                <p><strong>إصدار MySQL:</strong> <?php echo isset($conn) ? $conn->server_info : 'غير متصل'; ?></p>
                <p><strong>مجلد العمل:</strong> <?php echo getcwd(); ?></p>
                <p><strong>الوقت الحالي:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
        </div>
    </div>
</body>
</html>