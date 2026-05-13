<?php
require_once __DIR__ . '/includes/production_guard.php';
production_guard_deny_route('setup_demo_data.php');

/**
 * ملف إنشاء بيانات تجريبية للنظام
 * قم بتشغيل هذا الملف مرة واحدة فقط لإنشاء البيانات التجريبية
 */

include('includes/connect.php');

echo "<!DOCTYPE html>
<html dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>إنشاء البيانات التجريبية</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .success { color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0; border-radius: 5px; }
        .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0; border-radius: 5px; }
        .info { color: #004085; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; margin: 10px 0; border-radius: 5px; }
        h1 { color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class='container'>
<h1>🚀 إعداد البيانات التجريبية</h1>";

try {
    // 1. إنشاء فئات الأصناف إذا لم تكن موجودة
    echo "<div class='info'><strong>الخطوة 1:</strong> إنشاء فئات الأصناف...</div>";
    
    $categories = [
        ['id' => 1, 'name' => 'مشروبات ساخنة'],
        ['id' => 2, 'name' => 'مشروبات باردة'],
        ['id' => 3, 'name' => 'مأكولات'],
        ['id' => 4, 'name' => 'حلويات'],
        ['id' => 5, 'name' => 'سلطات']
    ];
    
    foreach ($categories as $cat) {
        $check = $conn->query("SELECT id FROM item_group WHERE id = {$cat['id']}");
        if ($check->num_rows == 0) {
            $conn->query("INSERT INTO item_group (id, gname, isdeleted) VALUES ({$cat['id']}, '{$cat['name']}', 0)");
            echo "<div class='success'>✓ تم إنشاء فئة: {$cat['name']}</div>";
        } else {
            echo "<div class='info'>→ الفئة موجودة: {$cat['name']}</div>";
        }
    }
    
    // 2. إنشاء الأصناف التجريبية
    echo "<div class='info'><strong>الخطوة 2:</strong> إنشاء الأصناف...</div>";
    
    $items = [
        // مشروبات ساخنة
        ['barcode' => '100001', 'name' => 'شاي', 'price' => 10.00, 'group' => 1],
        ['barcode' => '100002', 'name' => 'قهوة تركي', 'price' => 15.00, 'group' => 1],
        ['barcode' => '100003', 'name' => 'قهوة أمريكي', 'price' => 20.00, 'group' => 1],
        ['barcode' => '100004', 'name' => 'كابتشينو', 'price' => 25.00, 'group' => 1],
        ['barcode' => '100005', 'name' => 'نسكافيه', 'price' => 18.00, 'group' => 1],
        
        // مشروبات باردة
        ['barcode' => '200001', 'name' => 'عصير برتقال', 'price' => 20.00, 'group' => 2],
        ['barcode' => '200002', 'name' => 'عصير مانجو', 'price' => 25.00, 'group' => 2],
        ['barcode' => '200003', 'name' => 'عصير فراولة', 'price' => 25.00, 'group' => 2],
        ['barcode' => '200004', 'name' => 'ليموناضة', 'price' => 15.00, 'group' => 2],
        ['barcode' => '200005', 'name' => 'مياه معدنية', 'price' => 5.00, 'group' => 2],
        ['barcode' => '200006', 'name' => 'بيبسي', 'price' => 10.00, 'group' => 2],
        ['barcode' => '200007', 'name' => 'كوكاكولا', 'price' => 10.00, 'group' => 2],
        
        // مأكولات
        ['barcode' => '300001', 'name' => 'بيتزا مارجريتا', 'price' => 60.00, 'group' => 3],
        ['barcode' => '300002', 'name' => 'برجر لحم', 'price' => 45.00, 'group' => 3],
        ['barcode' => '300003', 'name' => 'برجر دجاج', 'price' => 40.00, 'group' => 3],
        ['barcode' => '300004', 'name' => 'ساندويتش شاورما', 'price' => 35.00, 'group' => 3],
        ['barcode' => '300005', 'name' => 'بطاطس مقلية', 'price' => 20.00, 'group' => 3],
        ['barcode' => '300006', 'name' => 'دجاج مشوي', 'price' => 70.00, 'group' => 3],
        
        // حلويات
        ['barcode' => '400001', 'name' => 'آيس كريم فانيليا', 'price' => 15.00, 'group' => 4],
        ['barcode' => '400002', 'name' => 'آيس كريم شوكولاتة', 'price' => 15.00, 'group' => 4],
        ['barcode' => '400003', 'name' => 'كيك شوكولاتة', 'price' => 25.00, 'group' => 4],
        ['barcode' => '400004', 'name' => 'كيك فراولة', 'price' => 25.00, 'group' => 4],
        ['barcode' => '400005', 'name' => 'بسبوسة', 'price' => 20.00, 'group' => 4],
        
        // سلطات
        ['barcode' => '500001', 'name' => 'سلطة خضراء', 'price' => 15.00, 'group' => 5],
        ['barcode' => '500002', 'name' => 'سلطة سيزر', 'price' => 30.00, 'group' => 5],
        ['barcode' => '500003', 'name' => 'فتوش', 'price' => 20.00, 'group' => 5],
        ['barcode' => '500004', 'name' => 'تبولة', 'price' => 18.00, 'group' => 5]
    ];
    
    $count = 0;
    foreach ($items as $item) {
        $check = $conn->query("SELECT id FROM myitems WHERE barcode = '{$item['barcode']}'");
        if ($check->num_rows == 0) {
            $sql = "INSERT INTO myitems (barcode, iname, price1, sprice, group1, isdeleted, info) 
                    VALUES ('{$item['barcode']}', '{$item['name']}', {$item['price']}, {$item['price']}, {$item['group']}, 0, 'صنف تجريبي')";
            $conn->query($sql);
            $count++;
            echo "<div class='success'>✓ تم إضافة: {$item['name']} - {$item['price']} ج</div>";
        }
    }
    
    if ($count > 0) {
        echo "<div class='success'><strong>تم إنشاء {$count} صنف جديد!</strong></div>";
    } else {
        echo "<div class='info'><strong>جميع الأصناف موجودة مسبقاً</strong></div>";
    }
    
    // 3. التحقق من الطاولات
    echo "<div class='info'><strong>الخطوة 3:</strong> التحقق من الطاولات...</div>";
    
    $tables_check = $conn->query("SELECT COUNT(*) as count FROM tables WHERE isdeleted = 0");
    if ($tables_check) {
        $tables_count = $tables_check->fetch_assoc()['count'];
        echo "<div class='success'>✓ يوجد {$tables_count} طاولة في النظام</div>";
    }
    
    echo "<hr>
    <div class='success'>
        <h2>✅ تم إعداد النظام بنجاح!</h2>
        <p>يمكنك الآن البدء باستخدام النظام:</p>
        <ul>
            <li><a href='tables.php' style='color: #007bff; font-size: 18px;'>→ افتح صفحة الطاولات</a></li>
            <li><a href='pos_barcode.php' style='color: #007bff; font-size: 18px;'>→ افتح نقطة البيع</a></li>
            <li><a href='pos_tables.php' style='color: #007bff; font-size: 18px;'>→ افتح الشاشة المتكاملة</a></li>
        </ul>
    </div>";
    
} catch (Exception $e) {
    echo "<div class='error'><strong>خطأ:</strong> " . $e->getMessage() . "</div>";
}

echo "
<hr>
<div style='text-align: center; color: #666; margin-top: 30px;'>
    <p><strong>ملاحظة:</strong> يمكنك تشغيل هذا الملف مرة أخرى في أي وقت لإضافة البيانات التجريبية</p>
    <p style='font-size: 12px;'>تم التطوير بواسطة Claude AI - 2025</p>
</div>
</div>
</body>
</html>";

$conn->close();
?>
