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
            'do/save_customer.php',
            'do/search_customer.php'
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
        
        <div class="row">
            <div class="col-md-6">
                <h6>اختبار البحث عن عميل:</h6>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="test_phone" placeholder="رقم الهاتف">
                    <button class="btn btn-primary" onclick="testSearch()">بحث</button>
                </div>
                <div id="search_result"></div>
            </div>
            
            <div class="col-md-6">
                <h6>اختبار حفظ عميل جديد:</h6>
                <div class="mb-2">
                    <input type="text" class="form-control mb-2" id="test_phone_save" placeholder="رقم الهاتف">
                    <input type="text" class="form-control mb-2" id="test_name" placeholder="اسم العميل">
                    <textarea class="form-control mb-2" id="test_address" placeholder="العنوان"></textarea>
                    <button class="btn btn-success" onclick="testSave()">حفظ</button>
                </div>
                <div id="save_result"></div>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function testSearch() {
            const phone = $('#test_phone').val();
            if (!phone) {
                alert('يرجى إدخال رقم الهاتف');
                return;
            }
            
            $('#search_result').html('<div class="spinner-border spinner-border-sm"></div> جاري البحث...');
            
            $.ajax({
                url: 'do/search_customer.php',
                method: 'POST',
                data: { phone: phone },
                success: function(data) {
                    console.log('Search response:', data);
                    $('#search_result').html('<div class="alert alert-info"><strong>النتيجة:</strong><br><pre>' + data + '</pre></div>');
                },
                error: function(xhr, status, error) {
                    console.error('Search error:', error);
                    $('#search_result').html('<div class="alert alert-danger">خطأ: ' + error + '</div>');
                }
            });
        }
        
        function testSave() {
            const phone = $('#test_phone_save').val();
            const name = $('#test_name').val();
            const address = $('#test_address').val();
            
            if (!phone || !name || !address) {
                alert('يرجى ملء جميع الحقول');
                return;
            }
            
            $('#save_result').html('<div class="spinner-border spinner-border-sm"></div> جاري الحفظ...');
            
            $.ajax({
                url: 'do/save_customer.php',
                method: 'POST',
                data: {
                    phone: phone,
                    name: name,
                    address: address
                },
                success: function(data) {
                    console.log('Save response:', data);
                    $('#save_result').html('<div class="alert alert-info"><strong>النتيجة:</strong><br><pre>' + data + '</pre></div>');
                },
                error: function(xhr, status, error) {
                    console.error('Save error:', error);
                    $('#save_result').html('<div class="alert alert-danger">خطأ: ' + error + '</div>');
                }
            });
        }
    </script>
</body>
</html>