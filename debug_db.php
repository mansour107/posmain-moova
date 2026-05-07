<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص قاعدة البيانات</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; }
        .sql-query { background: #e9ecef; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <h2 class="mb-4">🔍 فحص قاعدة البيانات - Debug</h2>

        <!-- آخر 5 سندات قبض -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">آخر 5 سندات قبض (pro_tybe = 1)</h5>
            </div>
            <div class="card-body">
                <div class="sql-query">
                    <strong>SQL:</strong> SELECT * FROM ot_head WHERE pro_tybe = 1 ORDER BY id DESC LIMIT 5
                </div>
                <?php
                $sql = "SELECT * FROM ot_head WHERE pro_tybe = 1 ORDER BY id DESC LIMIT 5";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr>';
                    
                    // عرض أسماء الأعمدة
                    $fields = $result->fetch_fields();
                    foreach ($fields as $field) {
                        echo '<th>' . $field->name . '</th>';
                    }
                    echo '</tr></thead><tbody>';
                    
                    // عرض البيانات
                    $result->data_seek(0);
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        foreach ($row as $value) {
                            echo '<td>' . htmlspecialchars($value ?? 'NULL') . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                } else {
                    echo '<p class="text-danger">لا توجد بيانات</p>';
                }
                ?>
            </div>
        </div>

        <!-- آخر 10 قيود من journal_entries -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">آخر 10 قيود من journal_entries</h5>
            </div>
            <div class="card-body">
                <div class="sql-query">
                    <strong>SQL:</strong> SELECT je.*, ah.aname, ah.code FROM journal_entries je LEFT JOIN acc_head ah ON je.account_id = ah.id ORDER BY je.id DESC LIMIT 10
                </div>
                <?php
                $sql = "SELECT je.*, ah.aname, ah.code 
                        FROM journal_entries je 
                        LEFT JOIN acc_head ah ON je.account_id = ah.id 
                        ORDER BY je.id DESC LIMIT 10";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr>';
                    echo '<th>ID</th><th>journal_id</th><th>account_id</th><th>اسم الحساب</th><th>كود الحساب</th>';
                    echo '<th>debit</th><th>credit</th><th>tybe</th><th>op_id</th><th>op2</th>';
                    echo '</tr></thead><tbody>';
                    
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . $row['id'] . '</td>';
                        echo '<td>' . $row['journal_id'] . '</td>';
                        echo '<td>' . $row['account_id'] . '</td>';
                        echo '<td>' . htmlspecialchars($row['aname'] ?? 'N/A') . '</td>';
                        echo '<td>' . htmlspecialchars($row['code'] ?? 'N/A') . '</td>';
                        echo '<td class="text-success"><strong>' . $row['debit'] . '</strong></td>';
                        echo '<td class="text-danger"><strong>' . $row['credit'] . '</strong></td>';
                        echo '<td>' . $row['tybe'] . '</td>';
                        echo '<td>' . ($row['op_id'] ?? 'NULL') . '</td>';
                        echo '<td>' . ($row['op2'] ?? 'NULL') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                } else {
                    echo '<p class="text-danger">لا توجد بيانات</p>';
                }
                ?>
            </div>
        </div>

        <!-- فحص نوع البيانات في الأعمدة -->
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">نوع البيانات في جدول journal_entries</h5>
            </div>
            <div class="card-body">
                <div class="sql-query">
                    <strong>SQL:</strong> DESCRIBE journal_entries
                </div>
                <?php
                $sql = "DESCRIBE journal_entries";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
                    
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td><strong>' . $row['Field'] . '</strong></td>';
                        echo '<td>' . $row['Type'] . '</td>';
                        echo '<td>' . $row['Null'] . '</td>';
                        echo '<td>' . $row['Key'] . '</td>';
                        echo '<td>' . ($row['Default'] ?? 'NULL') . '</td>';
                        echo '<td>' . $row['Extra'] . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
                ?>
            </div>
        </div>

        <!-- اختبار INSERT مباشر -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">اختبار INSERT مباشر</h5>
            </div>
            <div class="card-body">
                <?php
                if (isset($_GET['test_insert'])) {
                    // حذف الاختبار السابق
                    $conn->query("DELETE FROM journal_entries WHERE account_id = 999999");
                    
                    // اختبار INSERT مباشر
                    $test_sql = "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe) 
                                 VALUES (999999, 999999, 123.45, 0, 0)";
                    
                    echo '<div class="alert alert-info">محاولة تنفيذ: <code>' . $test_sql . '</code></div>';
                    
                    if ($conn->query($test_sql)) {
                        echo '<div class="alert alert-success">✅ تم الإدخال بنجاح!</div>';
                        
                        // قراءة البيانات المُدخلة
                        $check = $conn->query("SELECT * FROM journal_entries WHERE account_id = 999999");
                        if ($check && $check->num_rows > 0) {
                            $test_row = $check->fetch_assoc();
                            echo '<pre>' . print_r($test_row, true) . '</pre>';
                            
                            echo '<div class="alert alert-warning">القيمة المُخزنة: debit = ' . $test_row['debit'] . ' (نوع: ' . gettype($test_row['debit']) . ')</div>';
                        }
                        
                        // حذف الاختبار
                        $conn->query("DELETE FROM journal_entries WHERE account_id = 999999");
                    } else {
                        echo '<div class="alert alert-danger">❌ فشل الإدخال: ' . $conn->error . '</div>';
                    }
                }
                ?>
                <a href="?test_insert=1" class="btn btn-info">تشغيل اختبار INSERT</a>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="pos_barcode.php" class="btn btn-primary">العودة للنظام</a>
            <a href="check_payments.php" class="btn btn-success">عرض القيود</a>
        </div>
    </div>
</body>
</html>
