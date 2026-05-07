<?php
/**
 * POS Database Migrations Runner
 * Purpose: تنفيذ migrations بشكل آمن ومنظم
 * Date: 2025-10-17
 */

// إيقاف التنفيذ التلقائي (حماية)
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    die("⚠️ لتنفيذ Migrations، افتح: run_migrations.php?confirm=yes");
}

include('includes/connect.php');

echo "<!DOCTYPE html>
<html lang='ar' dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Database Migration Runner</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-light'>
<div class='container mt-5'>
    <div class='card shadow'>
        <div class='card-header bg-danger text-white'>
            <h3><i class='fas fa-database'></i> Database Migration Runner</h3>
        </div>
        <div class='card-body'>";

// قائمة Migrations
$migrations = [
    '001_add_indexes.sql' => [
        'name' => 'إضافة Indexes للأداء',
        'safe' => true,
        'required' => true
    ],
    '002_add_missing_pos_tables.sql' => [
        'name' => 'إضافة جداول POS المفقودة',
        'safe' => true,
        'required' => true
    ],
    '003_add_missing_columns.sql' => [
        'name' => 'إضافة أعمدة إضافية',
        'safe' => true,
        'required' => false
    ],
    '004_create_useful_views.sql' => [
        'name' => 'إنشاء Views للتقارير',
        'safe' => true,
        'required' => false
    ],
    '005_optimize_datatypes.sql' => [
        'name' => 'تحسين أنواع البيانات',
        'safe' => false,
        'required' => false
    ]
];

$success_count = 0;
$error_count = 0;

foreach ($migrations as $file => $info) {
    $required_badge = $info['required'] ? '<span class="badge bg-danger">إلزامي</span>' : '<span class="badge bg-secondary">اختياري</span>';
    $safe_badge = $info['safe'] ? '<span class="badge bg-success">آمن</span>' : '<span class="badge bg-warning text-dark">بحذر</span>';
    
    echo "<div class='alert alert-info'>";
    echo "<h5>🔄 {$info['name']} $required_badge $safe_badge</h5>";
    echo "<small>الملف: <code>$file</code></small><br>";
    
    if (!file_exists($file)) {
        echo "<div class='alert alert-danger mt-2'>❌ الملف غير موجود!</div>";
        $error_count++;
        echo "</div>";
        continue;
    }
    
    // قراءة الملف
    $sql = file_get_contents($file);
    
    // تقسيم الاستعلامات
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    $query_count = count($queries);
    
    echo "<p class='mb-2'>عدد الاستعلامات: <strong>$query_count</strong></p>";
    
    // تنفيذ
    $executed = 0;
    $failed = 0;
    
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0 || strpos($query, 'USE ') === 0) {
            continue;
        }
        
        if ($conn->query($query)) {
            $executed++;
        } else {
            // تجاهل أخطاء "already exists"
            if (strpos($conn->error, 'already exists') === false && 
                strpos($conn->error, 'Duplicate') === false) {
                $failed++;
                echo "<div class='alert alert-warning'>⚠️ خطأ: " . htmlspecialchars($conn->error) . "</div>";
            } else {
                $executed++; // موجود مسبقاً = نجاح
            }
        }
    }
    
    if ($failed === 0) {
        echo "<div class='alert alert-success mt-2'>✅ تم التنفيذ بنجاح! ($executed استعلام)</div>";
        $success_count++;
    } else {
        echo "<div class='alert alert-danger mt-2'>❌ فشل $failed استعلام من $query_count</div>";
        $error_count++;
    }
    
    echo "</div>";
}

// الملخص
echo "
<div class='card mt-4 border-primary'>
    <div class='card-header bg-primary text-white'>
        <h4>📊 ملخص التنفيذ</h4>
    </div>
    <div class='card-body'>
        <div class='row text-center'>
            <div class='col-md-6'>
                <h2 class='text-success'>$success_count</h2>
                <p>Migrations ناجحة</p>
            </div>
            <div class='col-md-6'>
                <h2 class='text-danger'>$error_count</h2>
                <p>Migrations فاشلة</p>
            </div>
        </div>
    </div>
</div>

<div class='alert alert-info mt-4'>
    <h5>📋 الخطوات التالية:</h5>
    <ol>
        <li>تحقق من عمل النظام</li>
        <li>اختبر POS (pos_barcode.php)</li>
        <li>افتح التقارير</li>
        <li>راجع الأداء</li>
    </ol>
</div>

<div class='text-center mt-4'>
    <a href='pos_barcode.php' class='btn btn-lg btn-primary'>
        <i class='fas fa-cash-register'></i> افتح POS
    </a>
    <a href='dashboard.php' class='btn btn-lg btn-success'>
        <i class='fas fa-home'></i> الرئيسية
    </a>
</div>

</div>
</div>
</div>

<script src='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js'></script>
</body>
</html>";

$conn->close();
?>

